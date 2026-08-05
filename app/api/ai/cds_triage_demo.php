<?php
/**
 * Public demo API: rule-based CDS triage on chief complaint (no login required).
 * For testing only — use patient/BHW APIs in production workflows.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePost();

set_time_limit(210);

$complaint = trim((string) ($_POST['chief_complaint'] ?? $_POST['complaint'] ?? $_POST['text'] ?? ''));

if ($complaint === '') {
    Api::error('Enter a chief complaint to analyze.');
}

if (mb_strlen($complaint) > 1000) {
    Api::error('Chief complaint is too long (max 1000 characters).');
}

$assessment = MedicalAssessmentEngine::assess($complaint, []);

if (!empty($assessment['error'])) {
    Api::error('Unable to analyze complaint.');
}

$validation = [];
if (is_array($assessment['clinical_recommendation']['validation'] ?? null)) {
    $validation = $assessment['clinical_recommendation']['validation'];
} elseif (is_array($assessment['triage']['validation'] ?? null)) {
    $validation = $assessment['triage']['validation'];
}

Api::success([
    'assessment' => $assessment,
    'summary' => [
        'classification' => (string) ($assessment['triage']['triage_display'] ?? 'NON-URGENT'),
        'confidence' => (int) ($assessment['confidence']['score'] ?? 0),
        'recommended_action' => (string) ($assessment['recommended_action'] ?? ''),
        'reason' => (string) ($assessment['triage']['reason'] ?? ($assessment['triage']['clinical_reasoning'] ?? '')),
        'detected_language' => (string) ($assessment['detected_language'] ?? 'unknown'),
        'engine' => (string) ($assessment['engine'] ?? ''),
        'service_used' => (bool) ($assessment['service_used'] ?? false),
        'severity_score' => (int) ($assessment['severity']['severity_score'] ?? ($assessment['triage']['severity_score'] ?? 0)),
        'needs_provider_review' => (bool) ($assessment['triage']['needs_provider_review'] ?? false),
        'validation_passed' => (bool) ($validation['passed'] ?? true),
        'winning_rule' => (string) ($validation['winning_rule'] ?? ($assessment['clinical_recommendation']['winning_rule'] ?? '')),
    ],
    'service' => AiServiceClient::connectionStatus(),
], 'CDS triage analysis complete.');
