<?php

require dirname(__DIR__, 2) . '/bootstrap/app.php';

$cases = [
    'May lagnat ako',
    'Budlay gid ang ginhawa ko',
    'Masakit akon dughan',
    'Fever for 5 days',
    "I don't feel well",
    'May dugo sa akon suka',
];

foreach ($cases as $c) {
    $r = ClinicalTriageEngine::assess($c, $c, [['english_term' => '']], [], 80);
    echo json_encode([
        'in' => $c,
        'cls' => $r['triage_display'],
        'score' => $r['severity_score'],
        'conf' => $r['confidence'],
        'sx' => $r['detected_symptoms'],
        'rf' => $r['red_flags'],
        'rec' => $r['recommendation'],
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
