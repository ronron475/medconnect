<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

putenv('MEDCONNECT_SKIP_GEMINI_BODY_LOCATION=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_BODY_LOCATION'] = '1';

$cases = [
    'gasakit ulo ko' => ['expect_loc' => 'head', 'gemini' => false, 'status' => 'local_dataset_match'],
    'gasakit tiyan ko' => ['expect_loc' => 'abdomen', 'gemini' => false, 'status' => 'local_dataset_match'],
    'gasakit bilat ko' => ['expect_loc' => 'vagina', 'gemini' => false, 'status' => 'local_dataset_match'],
    'gasakit munay ko' => ['expect_loc' => null, 'gemini' => false, 'status' => 'unresolved'],
];

$fail = 0;
foreach ($cases as $text => $expect) {
    $r = BodyLocationLexicon::resolveMultiLevel($text, 'hiligaynon');
    $got = $r['body_locations'][0] ?? null;
    $ok = ($expect['expect_loc'] === null ? $got === null : $got === $expect['expect_loc'])
        && ((bool) $r['gemini_called'] === (bool) $expect['gemini'])
        && (($r['verification_status'] ?? '') === $expect['status']);
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? 'OK' : 'FAIL') . "\t{$text}\t"
        . 'loc=' . ($got ?? 'null')
        . ' status=' . ($r['verification_status'] ?? '')
        . ' gemini=' . (!empty($r['gemini_called']) ? 'yes' : 'no')
        . ' clarify=' . (!empty($r['needs_clarification']) ? 'yes' : 'no')
        . ' original=' . (($r['matches'][0]['original_term'] ?? $text) === $text ? 'kept' : 'changed')
        . "\n";
}

foreach ([
    'app/core/ClinicalInterviewEngine.php',
    'app/core/HealthComplaintDomainDetector.php',
    'app/core/BodyLocationLexicon.php',
    'app/core/GeminiBodyLocationVerifier.php',
] as $file) {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    echo ($code === 0 ? 'OK' : 'FAIL') . "\tlint\t{$file}\t" . ($out[0] ?? '') . "\n";
    if ($code !== 0) {
        $fail++;
    }
}

echo $fail === 0 ? "ALL_PASS\n" : "FAILURES={$fail}\n";
exit($fail === 0 ? 0 : 1);
