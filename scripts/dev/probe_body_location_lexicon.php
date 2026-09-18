<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

$cases = [
    'gasakit ulo ko' => 'head',
    'gasakit tiyan ko' => 'abdomen',
    'gasakit dalunggan ko' => 'ear',
    'gasakit dulunggan ko' => 'ear',
    'gasakit bukton ko' => 'arm',
    'gasakit braso ko' => 'arm',
    'gasakit tiil ko' => 'foot',
    'gasakit munay ko' => null,
    'gasakit bilat ko' => 'vagina',
    'gasakit tutunlan ko' => 'throat',
    'my head hurts' => 'head',
    'sakit sa dughan' => 'chest',
    'headache' => 'head',
    'gasakit butkon ko' => 'arm',
];

$fail = 0;
foreach ($cases as $text => $expect) {
    $locs = ClinicalFeatureExtractors::extractBodyLocations($text);
    $details = BodyLocationLexicon::extractDetailed($text, $text);
    $got = $locs[0] ?? null;
    $src = $details[0]['source'] ?? '-';
    $ok = ($expect === null) ? ($got === null) : ($got === $expect);
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? 'OK' : 'FAIL') . "\t{$text}\texpect=" . ($expect ?? 'null') . "\tgot=" . ($got ?? 'null') . "\tsrc={$src}\n";
}

echo 'alias_count=' . count(BodyLocationLexicon::knownAliasSet()) . "\n";
echo $fail === 0 ? "ALL_PASS\n" : "FAILURES={$fail}\n";
exit($fail === 0 ? 0 : 1);
