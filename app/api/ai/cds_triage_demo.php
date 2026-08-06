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
$debugMode = filter_var($_POST['debug'] ?? $_GET['debug'] ?? getenv('MEDCONNECT_NLP_DEBUG'), FILTER_VALIDATE_BOOLEAN);
if ($debugMode) {
    NlpPipelineDebug::enable(true);
    NlpPipelineDebug::reset();
}

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
        'original_chief_complaint' => (string) ($assessment['original_chief_complaint'] ?? $complaint),
        'detected_language' => (string) ($assessment['detected_language'] ?? 'unknown'),
        'corrected_text' => (string) ($assessment['corrected_text'] ?? ''),
        'english_translation' => (string) ($assessment['english_translation'] ?? ''),
        'standardized_medical_concepts' => is_array($assessment['standardized_medical_concepts'] ?? null)
            ? $assessment['standardized_medical_concepts']
            : [],
        'detected_symptoms' => is_array($assessment['detected_symptoms'] ?? null) ? $assessment['detected_symptoms'] : [],
        'associated_symptoms' => is_array($assessment['associated_symptoms'] ?? null) ? $assessment['associated_symptoms'] : [],
        'red_flags' => is_array($assessment['triage']['red_flags'] ?? null) ? $assessment['triage']['red_flags'] : [],
        'clinical_reasoning' => (string) ($assessment['triage']['clinical_reasoning'] ?? ($assessment['triage']['reason'] ?? '')),
        'classification' => (string) ($assessment['triage']['triage_display'] ?? 'NON-URGENT'),
        'confidence' => (int) ($assessment['confidence']['score'] ?? 0),
        'recommended_action' => (string) ($assessment['recommended_action'] ?? ''),
        'reason' => (string) ($assessment['triage']['reason'] ?? ($assessment['triage']['clinical_reasoning'] ?? '')),
        'engine' => (string) ($assessment['engine'] ?? ''),
        'service_used' => (bool) ($assessment['service_used'] ?? false),
        'severity_score' => (int) ($assessment['severity']['severity_score'] ?? ($assessment['triage']['severity_score'] ?? 0)),
        'needs_provider_review' => (bool) ($assessment['triage']['needs_provider_review'] ?? false),
        'validation_passed' => (bool) ($validation['passed'] ?? true),
        'winning_rule' => (string) ($validation['winning_rule'] ?? ($assessment['clinical_recommendation']['winning_rule'] ?? '')),
        'pipeline_stages' => is_array($assessment['pipeline_stages'] ?? null) ? $assessment['pipeline_stages'] : [],
        'symptom_evidence' => is_array($assessment['symptom_evidence'] ?? null) ? $assessment['symptom_evidence'] : [],
        'triggered_rules' => is_array($assessment['triage']['matched_rules'] ?? null)
            ? $assessment['triage']['matched_rules']
            : (is_array($assessment['triage']['assessment_factors']['matched_rules'] ?? null)
                ? $assessment['triage']['assessment_factors']['matched_rules']
                : []),
    ],
    'pipeline_debug' => NlpPipelineDebug::isEnabled() ? NlpPipelineDebug::trace() : null,
    'service' => AiServiceClient::connectionStatus(),
], 'CDS triage analysis complete.');
