<?php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$complaint = $argv[1] ?? 'sakit kag d nko kaginhawa';
$norm = HiligaynonTextNormalizer::normalize($complaint);
$corrected = MedicalMisspellingsLoader::applyCorrections($complaint);
$result = ClinicalTriageEngine::assess($complaint, $complaint, [], [], 80);

echo json_encode([
    'input' => $complaint,
    'normalized' => $norm,
    'corrected' => $corrected,
    'display' => $result['triage_display'] ?? '',
    'reason' => $result['reason'] ?? '',
    'symptoms' => $result['detected_symptoms'] ?? [],
    'red_flags' => $result['red_flags'] ?? [],
    'validation' => $result['validation'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
