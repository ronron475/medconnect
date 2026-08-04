<?php

require dirname(__DIR__, 2) . '/bootstrap/app.php';

$cases = [
    'Wala akong lagnat',
    'Wala ko ginaubo',
    'Indi budlay ginhawa',
    'No fever',
    'No chest pain',
    'May lagnat ako',
    'Budlay gid ang ginhawa ko',
    'fevr for 3 days',
    'ginakalagnatt ako',
    'Masakit akon dughan',
];

foreach ($cases as $c) {
    $r = ClinicalTriageEngine::assess($c, $c, [['english_term' => '']], [], 80);
    echo json_encode([
        'in' => $c,
        'cls' => $r['triage_display'],
        'sx' => $r['detected_symptoms'],
        'rf' => $r['red_flags'],
        'score' => $r['severity_score'],
        'conf' => $r['confidence'],
        'rec' => $r['recommendation'],
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
