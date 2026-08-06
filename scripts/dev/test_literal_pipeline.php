<?php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$cases = [
    'dugo ulo ko',
    'budlay gid ginhawa',
    'putol ang kamot ko',
    'fevr',
    'd nko',
];

foreach ($cases as $c) {
    $r = MedicalAssessmentEngine::assess($c, []);
    echo $c . PHP_EOL;
    echo '  lang: ' . ($r['detected_language'] ?? '') . PHP_EOL;
    echo '  corrected: ' . ($r['corrected_text'] ?? '') . PHP_EOL;
    echo '  literal EN: ' . ($r['english_translation'] ?? '') . PHP_EOL;
    $concepts = $r['standardized_medical_concepts'] ?? [];
    echo '  concepts: ' . implode(', ', array_map(static fn ($x) => $x['canonical_name'] ?? '', $concepts)) . PHP_EOL;
    echo '  triage: ' . ($r['triage']['triage_display'] ?? '') . PHP_EOL;
    echo PHP_EOL;
}
