<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

// Confirm follow-up location gap is closed when CSV resolves the site.
$cases = [
    'gasakit tiyan ko' => true,
    'gasakit ulo ko' => true,
    'gasakit munay ko' => false, // munay not in validated datasets
];

foreach ($cases as $text => $expectKnown) {
    $locs = ClinicalFeatureExtractors::extractBodyLocations($text);
    $known = $locs !== [];
    $skipLocationQ = $known; // AdaptivePolicy uses body_locations !== []
    $ok = $known === $expectKnown;
    echo ($ok ? 'OK' : 'FAIL') . "\t{$text}\tlocs=" . json_encode($locs)
        . "\tskip_PAIN_LOCATION=" . ($skipLocationQ ? 'yes' : 'no') . "\n";
}

$original = 'gasakit tiyan ko';
$details = BodyLocationLexicon::extractDetailed($original, $original);
echo 'original_preserved=' . (($details[0]['original_term'] ?? '') === $original ? 'yes' : 'no') . "\n";
