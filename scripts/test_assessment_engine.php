<?php
require dirname(__DIR__) . '/bootstrap.php';
$a = MedicalAssessmentEngine::assess('grabe sakit ulo kag ginahilanat', ['fever']);
echo json_encode([
    'symptoms' => $a['detected_symptoms'],
    'confidence' => $a['confidence']['score'],
    'triage' => $a['triage']['triage_display'],
    'severity' => $a['severity']['severity_label'],
    'conditions' => $a['possible_conditions'],
    'english' => $a['english_translation'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
