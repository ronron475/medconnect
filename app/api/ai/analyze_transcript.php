<?php
/**
 * API: Analyze consultation transcript (Python AI with PHP fallback).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
Api::startJson();

Api::requireRole('provider');
Api::requirePost();

$consultation_id = (int) ($_POST['consultation_id'] ?? 0);
$transcript      = trim((string) ($_POST['transcript'] ?? ''));

if (!$consultation_id || $transcript === '') {
    Api::error('Transcript is required.');
}

try {
    $stmt = $pdo->prepare(
        'SELECT id FROM consultations WHERE id = ? AND provider_id = ? LIMIT 1'
    );
    $stmt->execute([$consultation_id, (int) $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        Api::error('Consultation not found or access denied.', 403);
    }

    $service_data = AiServiceClient::analyzeTranscript($transcript, $consultation_id);
    $analysis     = $service_data ?: TranscriptAnalyzer::analyze($transcript);

    $english          = (string) ($analysis['english_transcript'] ?? '');
    $symptoms         = array_values(array_unique($analysis['symptoms'] ?? []));
    $medicines        = array_values(array_unique($analysis['medicines'] ?? []));
    $urgent_flags     = array_values(array_unique($analysis['urgent_flags'] ?? []));
    $suggested_summary = (string) ($analysis['summary'] ?? '');
    $engine           = (string) ($analysis['engine'] ?? ($service_data ? 'python-ai-service' : 'php-fallback-analyzer'));

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
            (consultation_id, provider_id, original_transcript, translated_transcript,
             symptoms_json, medicines_json, urgent_flags_json, summary)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $consultation_id,
        (int) $_SESSION['user_id'],
        $transcript,
        $english,
        json_encode($symptoms),
        json_encode($medicines),
        json_encode($urgent_flags),
        $suggested_summary,
    ]);

    Api::success([
        'data' => [
            'hiligaynon_transcript' => $transcript,
            'english_transcript'    => $english,
            'symptoms'              => $symptoms,
            'medicines'             => $medicines,
            'urgent_flags'          => $urgent_flags,
            'summary'               => $suggested_summary,
            'engine'                => $engine,
            'service_used'          => (bool) $service_data,
            'model_symptoms'        => array_values($analysis['model_symptoms'] ?? []),
            'disease_predictions'   => array_values($analysis['disease_predictions'] ?? []),
            'triage'                => $analysis['triage'] ?? null,
            'ml_available'          => (bool) ($analysis['ml_available'] ?? false),
            'ml_disclaimer'         => (string) ($analysis['ml_disclaimer'] ?? ''),
        ],
    ], 'Transcript analyzed.');
} catch (Exception $e) {
    Api::error('AI analysis failed: ' . $e->getMessage(), 500);
}
