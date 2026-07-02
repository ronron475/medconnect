<?php
/**
 * API: Hiligaynon symptom recognition (teleconsultation / triage NLP).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
Api::startJson();

if (empty($_SESSION['user_id'])) {
    Api::error('Unauthorized.', 403);
}

Api::requirePost();

$text = trim((string) ($_POST['text'] ?? $_POST['transcript'] ?? ''));
if ($text === '') {
    Api::error('Text is required.');
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
