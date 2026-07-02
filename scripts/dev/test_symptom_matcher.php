<?php
require dirname(__DIR__, 2) . '/bootstrap.php';

        $cases = [
    'May ara ko kakatol sa bilog ko nga lawas.',
    'Kakatul gid ang akon panit.',
    'Ga katol akon lawas.',
    'Grabeeeeee gid akon kakatol!!!',
    'Aking ulo kag kapoy gid ko subong.',
    'kakatul',
    'kakatol',
    'katol',
    'makatol',
];

foreach ($cases as $text) {
    $r = HiligaynonSymptomMatcher::recognize($text);
    echo "INPUT: {$text}\n";
    echo "NORMALIZED: {$r['normalized_text']}\n";
    foreach ($r['detections'] as $d) {
        echo "  - {$d['detected_symptom']} => {$d['english_translation']} ({$d['medical_term']}) confidence={$d['confidence']}\n";
    }
    echo "\n";
}
