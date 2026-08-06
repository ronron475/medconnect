<?php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$complaint = 'sakit mata ko';
$result = ClinicalTriageEngine::assess($complaint, $complaint, [], [], 80);

echo json_encode([
    'input' => $complaint,
    'display' => $result['triage_display'] ?? '',
    'symptoms' => $result['detected_symptoms'] ?? [],
    'kb_matched' => array_map(static fn ($s) => [
        'name' => $s['symptom_name'] ?? '',
        'matched_term' => $s['matched_term'] ?? '',
    ], $result['kb_matched_symptoms'] ?? []),
    'symptom_evidence' => $result['symptom_evidence'] ?? [],
    'english' => $result['english_translation'] ?? '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
