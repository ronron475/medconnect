<?php
/**
 * API: Hiligaynon symptom recognition (teleconsultation / triage NLP).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once BASE_PATH . '/app/includes/ai_endpoint_security.php';
Api::startJson();

Api::requirePost();
ai_endpoint_require_roles(['patient', 'provider', 'bhw', 'admin', 'superadmin']);
ai_endpoint_rate_limit('ai_recognize_symptoms', 60, 60);
Api::requireCsrf();

$text = trim((string) ($_POST['text'] ?? $_POST['transcript'] ?? ''));
if ($text === '') {
    Api::error('Text is required.');
}
if (mb_strlen($text) > 8000) {
    Api::error('Text is too long.');
}

$threshold = isset($_POST['fuzzy_threshold']) ? (int) $_POST['fuzzy_threshold'] : null;

$data = AiServiceClient::recognizeSymptoms($text);
if (!$data) {
    $data = HiligaynonSymptomMatcher::recognize($text, $threshold);
}

Api::success(
    ($data['detection_count'] ?? 0) . ' symptom(s) detected.',
    $data
);
