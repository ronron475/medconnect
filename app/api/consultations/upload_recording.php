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
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_recording_segments.php';

clinical_tables_ensure($pdo);

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!auth_csrf_validate($csrf)) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$token = trim((string) ($_POST['token'] ?? ''));
$video_file = $_FILES['video'] ?? null;
$transcribe_recording = ($_POST['transcribe_recording'] ?? '0') === '1';
$upload_key = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($_POST['upload_key'] ?? '')) ?: '', 0, 80);
$segment_index = (int) ($_POST['segment_index'] ?? 0);
$started_at_raw = trim((string) ($_POST['started_at'] ?? ''));
$ended_at_raw = trim((string) ($_POST['ended_at'] ?? ''));

$started_at = ($started_at_raw !== '' && strtotime($started_at_raw)) ? date('Y-m-d H:i:s', strtotime($started_at_raw)) : null;
$ended_at = ($ended_at_raw !== '' && strtotime($ended_at_raw)) ? date('Y-m-d H:i:s', strtotime($ended_at_raw)) : date('Y-m-d H:i:s');

if (!$token || !$video_file) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Token and video file required.']);
    exit;
}

if ((int) ($video_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Recording upload failed.']);
    exit;
}

$maxBytes = 512 * 1024 * 1024;
if ((int) ($video_file['size'] ?? 0) <= 0 || (int) $video_file['size'] > $maxBytes) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Recording file is empty or too large.']);
    exit;
}

$clientType = strtolower((string) ($video_file['type'] ?? ''));
if ($clientType !== '' && !preg_match('#^video/(webm|mp4|ogg)#', $clientType) && $clientType !== 'application/octet-stream') {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unsupported recording type.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT vs.id, vs.consultation_id, vs.recording_path, c.patient_id, c.provider_id
        FROM video_sessions vs
        JOIN consultations c ON vs.consultation_id = c.id
        WHERE vs.room_token = ? AND c.provider_id = ?
        LIMIT 1
    ");
    $stmt->execute([$token, $_SESSION['user_id']]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session not found.']);
        exit;
    }

    $consultationId = (int) $session['consultation_id'];
    $videoSessionId = (int) $session['id'];
    consultation_recording_segments_ensure($pdo);

    if ($upload_key !== '') {
        $existing = consultation_recording_segment_find_by_key($pdo, $upload_key);
        if ($existing) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'path' => $existing['path'],
                'segment_id' => $existing['id'],
                'duplicate' => true,
                'ai' => null,
            ]);
            exit;
        }
    } else {
        $upload_key = substr($token . '-s' . time() . '-' . bin2hex(random_bytes(4)), 0, 80);
    }

    $filename = 'recording_' . $token . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.webm';
    $upload_dir = STORAGE_PATH . '/recordings/';
    $upload_path = $upload_dir . $filename;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if (!move_uploaded_file($video_file['tmp_name'], $upload_path)) {
        throw new Exception('Failed to move uploaded file.');
    }

    $db_path = 'storage/recordings/' . $filename;
    if ($segment_index <= 0) {
        $segment_index = consultation_recording_segments_next_index($pdo, $consultationId);
    }

    $saved = consultation_recording_segment_save(
        $pdo,
        $consultationId,
        $videoSessionId,
        $db_path,
        $upload_key,
        $segment_index,
        $started_at,
        $ended_at
    );

    // Legacy pointer: keep the first saved path, never delete prior files.
    $legacyPath = trim((string) ($session['recording_path'] ?? ''));
    $pointerPath = $legacyPath !== '' ? $legacyPath : $saved['path'];
    $cols = [];
    try {
        $colStmt = $pdo->query('SHOW COLUMNS FROM video_sessions');
        $cols = $colStmt ? $colStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        $cols = [];
    }
    $hasPath = in_array('recording_path', $cols, true);
    $hasUrl = in_array('recording_url', $cols, true);

    if ($legacyPath === '') {
        if ($hasPath && $hasUrl) {
            $upd = $pdo->prepare('UPDATE video_sessions SET recording_path = ?, recording_url = ? WHERE room_token = ?');
            $upd->execute([$pointerPath, $pointerPath, $token]);
        } elseif ($hasPath) {
            $upd = $pdo->prepare('UPDATE video_sessions SET recording_path = ? WHERE room_token = ?');
            $upd->execute([$pointerPath, $token]);
        } elseif ($hasUrl) {
            $upd = $pdo->prepare('UPDATE video_sessions SET recording_url = ? WHERE room_token = ?');
            $upd->execute([$pointerPath, $token]);
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
                    $consultationId,
                    (int) $_SESSION['user_id'],
                    (string) ($ai_result['hiligaynon_transcript'] ?? ''),
                    (string) ($ai_result['english_transcript'] ?? ''),
                    json_encode($ai_result['symptoms'] ?? []),
                    json_encode($ai_result['medicines'] ?? []),
                    json_encode($ai_result['urgent_flags'] ?? []),
                    (string) ($ai_result['summary'] ?? ''),
                ]);
            }
        } catch (Exception $e) {
            error_log('Recording transcription failed: ' . $e->getMessage());
        }
    }

    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'path' => $saved['path'],
        'segment_id' => $saved['id'],
        'segment_index' => $segment_index,
        'duplicate' => !empty($saved['duplicate']),
        'ai' => $ai_result,
    ]);
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
