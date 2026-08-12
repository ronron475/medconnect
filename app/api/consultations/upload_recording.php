<?php
ob_start();
session_start();

if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'provider') {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';

clinical_tables_ensure($pdo);

$token = $_POST['token'] ?? '';
$video_file = $_FILES['video'] ?? null;
$transcribe_recording = ($_POST['transcribe_recording'] ?? '0') === '1';

if (!$token || !$video_file) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Token and video file required.']);
    exit;
}

try {
    // 1. Verify session exists and belongs to this provider
    $stmt = $pdo->prepare("
        SELECT vs.id, vs.consultation_id, c.patient_id
        FROM video_sessions vs
        JOIN consultations c ON vs.consultation_id = c.id
        WHERE vs.room_token = ? AND c.provider_id = ?
    ");
    $stmt->execute([$token, $_SESSION['user_id']]);
    $session = $stmt->fetch();

    if (!$session) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session not found.']);
        exit;
    }

    // 2. Save file
    $filename = 'recording_' . $token . '_' . time() . '.webm';
    $upload_dir = STORAGE_PATH . '/recordings/';
    $upload_path = $upload_dir . $filename;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if (move_uploaded_file($video_file['tmp_name'], $upload_path)) {
        // 3. Update DB (write both columns — dashboard historically queried recording_url)
        $db_path = 'storage/recordings/' . $filename;
        $cols = [];
        try {
            $colStmt = $pdo->query('SHOW COLUMNS FROM video_sessions');
            $cols = $colStmt ? $colStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable $e) {
            $cols = [];
        }
        $hasPath = in_array('recording_path', $cols, true);
        $hasUrl = in_array('recording_url', $cols, true);

        if ($hasPath && $hasUrl) {
            $stmt = $pdo->prepare('UPDATE video_sessions SET recording_path = ?, recording_url = ? WHERE room_token = ?');
            $stmt->execute([$db_path, $db_path, $token]);
        } elseif ($hasPath) {
            $stmt = $pdo->prepare('UPDATE video_sessions SET recording_path = ? WHERE room_token = ?');
            $stmt->execute([$db_path, $token]);
        } elseif ($hasUrl) {
            $stmt = $pdo->prepare('UPDATE video_sessions SET recording_url = ? WHERE room_token = ?');
            $stmt->execute([$db_path, $token]);
        } else {
            throw new Exception('Recording metadata columns missing on video_sessions.');
        }

        if ($stmt->rowCount() < 1) {
            // Row may already have same path; verify the token still resolves.
            $verify = $pdo->prepare('SELECT id FROM video_sessions WHERE room_token = ? LIMIT 1');
            $verify->execute([$token]);
            if (!$verify->fetchColumn()) {
                throw new Exception('Recording saved to disk but session metadata update failed.');
            }
        }

        $ai_result = null;
        if ($transcribe_recording) {
        try {
            $ai_result = AiServiceClient::transcribeFile(
                $upload_path,
                'video/webm',
                $filename,
                'video',
                240
            );

            if ($ai_result) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS consultation_ai_notes (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        consultation_id INT UNSIGNED NOT NULL,
                        provider_id INT UNSIGNED NOT NULL,
                        original_transcript TEXT NOT NULL,
                        translated_transcript TEXT NOT NULL,
                        symptoms_json JSON NULL,
                        medicines_json JSON NULL,
                        urgent_flags_json JSON NULL,
                        summary TEXT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_consultation_ai (consultation_id, created_at),
                        CONSTRAINT fk_ai_consultation FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
                        CONSTRAINT fk_ai_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                $stmt = $pdo->prepare("
                    INSERT INTO consultation_ai_notes
                        (consultation_id, provider_id, original_transcript, translated_transcript, symptoms_json, medicines_json, urgent_flags_json, summary)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    (int)$session['consultation_id'],
                    (int)$_SESSION['user_id'],
                    (string)($ai_result['hiligaynon_transcript'] ?? ''),
                    (string)($ai_result['english_transcript'] ?? ''),
                    json_encode($ai_result['symptoms'] ?? []),
                    json_encode($ai_result['medicines'] ?? []),
                    json_encode($ai_result['urgent_flags'] ?? []),
                    (string)($ai_result['summary'] ?? ''),
                ]);
            }
        } catch (Exception $e) {
            error_log('Recording transcription failed: ' . $e->getMessage());
        }
        }

        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'path' => $db_path, 'ai' => $ai_result]);
    } else {
        throw new Exception("Failed to move uploaded file.");
    }

} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
