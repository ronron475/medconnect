<?php
/**
 * API: Live audio chunk transcription via Python AI service.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
Api::startJson();

Api::requireRole('provider');
Api::requirePost();

$token      = trim((string) ($_POST['token'] ?? ''));
$audio_file = $_FILES['audio'] ?? null;

if ($token === '' || !$audio_file || empty($audio_file['tmp_name'])) {
    Api::error('Token and audio chunk are required.');
}

try {
    $stmt = $pdo->prepare("
        SELECT vs.consultation_id
        FROM video_sessions vs
        JOIN consultations c ON c.id = vs.consultation_id
        WHERE vs.room_token = ? AND c.provider_id = ? AND vs.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$token, (int) $_SESSION['user_id']]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        Api::error('Active consultation room not found.', 403);
    }

    $data = AiServiceClient::transcribeFile(
        $audio_file['tmp_name'],
        $audio_file['type'] ?: 'audio/webm',
        $audio_file['name'] ?: 'live_audio.webm',
        'audio'
    );

    if (!$data) {
        throw new RuntimeException('AI service did not return a valid transcription.');
    }

    $transcript = trim((string) ($data['hiligaynon_transcript'] ?? ''));

    if ($transcript !== '') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS consultation_ai_live_chunks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                consultation_id INT UNSIGNED NOT NULL,
                provider_id INT UNSIGNED NOT NULL,
                transcript TEXT NOT NULL,
                translated_transcript TEXT NULL,
                symptoms_json JSON NULL,
                medicines_json JSON NULL,
                urgent_flags_json JSON NULL,
                summary TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_live_consultation_created (consultation_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $pdo->prepare("
            INSERT INTO consultation_ai_live_chunks
                (consultation_id, provider_id, transcript, translated_transcript,
                 symptoms_json, medicines_json, urgent_flags_json, summary)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int) $session['consultation_id'],
            (int) $_SESSION['user_id'],
            $transcript,
            (string) ($data['english_transcript'] ?? ''),
            json_encode($data['symptoms'] ?? []),
            json_encode($data['medicines'] ?? []),
            json_encode($data['urgent_flags'] ?? []),
            (string) ($data['summary'] ?? ''),
        ]);
    }

    Api::success(['data' => $data]);
} catch (Exception $e) {
    Api::error('Live transcription failed: ' . $e->getMessage(), 500);
}
