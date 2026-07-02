<?php
/**
 * API: AI-assisted symptom assessment (real-time, AJAX).
 * POST: chief_complaint, symptoms[] (optional)
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requireRole('patient');
Api::requirePost();

$complaint = trim((string) ($_POST['chief_complaint'] ?? $_POST['complaint'] ?? ''));
$symptoms = $_POST['symptoms'] ?? [];

if (is_string($symptoms)) {
    $decoded = json_decode($symptoms, true);
    $symptoms = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $symptoms)));
}

if (!is_array($symptoms)) {
    $symptoms = [];
}

if ($complaint === '' && $symptoms === []) {
    Api::error('Please describe your symptoms or select at least one symptom.');
}

$assessment = MedicalAssessmentEngine::assess($complaint, $symptoms);

if (!empty($assessment['error'])) {
    Api::error('Unable to analyze symptoms. Please try again.');
}

Api::success([
    'assessment' => $assessment,
], 'AI assessment complete.');
