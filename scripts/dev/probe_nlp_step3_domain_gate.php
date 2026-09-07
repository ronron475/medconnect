<?php
/**
 * Regression: universal domain detector + Step 3 demo NLP gate.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
    if (!$cond) {
        $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1;
    }
}

$GLOBALS['fails'] = 0;
echo "=== Universal health complaint detector ===\n";

$mustHealth = [
    // Hiligaynon
    'kasakit mata ko',
    'nagasakit akon ulo',
    'dugay na ko ginaubo',
    'tatlo na ka bulan kasakit mata ko',
    'gapula mata ko kag gakatol',
    'gasakit likod ko',
    'ginasuka ko',
    'nahilo ko',
    'ginahilanat ko',
    // Tagalog
    'masakit ang mata ko',
    'tatlong buwan nang masakit ang mata ko',
    'sumasakit ang tiyan ko',
    // English
    'my eye hurts',
    'I have had eye pain for three months',
    'my stomach hurts',
    'I am having difficulty breathing',
    // Mixed
    'masakit gid akon eye',
    'my mata is swollen',
    'gasakit my head',
    // Misspellings
    'saket mata ko',
    'kasaket matta ko',
    'gasakit olo ko',
];

foreach ($mustHealth as $text) {
    $d = NlpStep3DemoHealthComplaintDetector::detect($text);
    ok(!empty($d['health_related']), "detect health: {$text}", ($d['domain'] ?? '') . '/' . ($d['confidence'] ?? ''));
    ok(($d['routing'] ?? '') !== NlpStep3DemoHealthComplaintDetector::ROUTE_OOS, "not OOS route: {$text}", (string) ($d['routing'] ?? ''));
}

$mainDet = NlpStep3DemoHealthComplaintDetector::detect('tatlo na ka bulan kasakit mata ko');
$types = [];
foreach ($mainDet['signals'] ?? [] as $s) {
    $types[(string) ($s['type'] ?? '')] = true;
}
ok(!empty($types['duration']) || !empty($types['symptom']), 'MAIN has duration or symptom signal');
ok(!empty($types['symptom']), 'MAIN has symptom signal');
ok(!empty($types['body_part']), 'MAIN has body_part signal');
ok(!empty($types['patient_reference']), 'MAIN has patient_reference signal');
ok(($mainDet['confidence'] ?? '') === 'HIGH', 'MAIN confidence HIGH', (string) ($mainDet['confidence'] ?? ''));
ok(($mainDet['routing'] ?? '') === NlpStep3DemoHealthComplaintDetector::ROUTE_NLP, 'MAIN routes ORIGINAL NLP');

foreach (['hello', 'tell me a joke', 'what is 2+2', 'what is the weather', 'who is the president'] as $text) {
    $d = NlpStep3DemoHealthComplaintDetector::detect($text);
    $okDomain = in_array(($d['domain'] ?? ''), [
        NlpStep3DemoHealthComplaintDetector::DOMAIN_OOS,
        NlpStep3DemoHealthComplaintDetector::DOMAIN_GREETING,
        NlpStep3DemoHealthComplaintDetector::DOMAIN_UNCLEAR,
    ], true);
    ok($okDomain && empty($d['health_related']), "non-medical: {$text}", (string) ($d['domain'] ?? ''));
}

ok(
    NlpStep3DemoHealthComplaintDetector::validateGeminiDomain(['domain' => 'HEALTH_RELATED']) === 'HEALTH_RELATED',
    'validate Gemini HEALTH_RELATED'
);
ok(
    NlpStep3DemoHealthComplaintDetector::validateGeminiDomain('{"domain":"OUT_OF_SCOPE"}') === 'OUT_OF_SCOPE',
    'validate Gemini JSON OOS'
);
ok(
    NlpStep3DemoHealthComplaintDetector::validateGeminiDomain(['domain' => 'BANANA']) === null,
    'reject invalid Gemini domain'
);

echo "\n=== Demo assess gate ===\n";

$main = NlpStep3DemoTrial::assess('tatlo na ka bulan kasakit mata ko', [], ['allow_gemini' => false]);
ok(!empty($main['health_related']), 'MAIN health_related YES');
ok(($main['domain_class'] ?? '') === 'HEALTH_RELATED', 'MAIN domain HEALTH_RELATED', (string) ($main['domain_class'] ?? ''));
ok(($main['clinical_status'] ?? '') !== 'SKIPPED', 'MAIN clinical not SKIPPED', (string) ($main['clinical_status'] ?? ''));
ok(($main['assessment_status'] ?? '') !== 'SKIPPED', 'MAIN assessment not SKIPPED', (string) ($main['assessment_status'] ?? ''));
ok(is_array($main['domain_detection'] ?? null), 'MAIN has domain_detection debug');
ok(
    str_contains((string) ($main['engine'] ?? ''), 'clinical')
        || ($main['followup_question']['question_id'] ?? '') !== '',
    'MAIN continues original NLP',
    (string) (($main['engine'] ?? '') . ' / ' . ($main['followup_question']['question_id'] ?? ''))
);

foreach (['kasakit mata ko', 'gapula mata ko kag gakatol', 'my chest hurts', 'tatlong buwan nang masakit ang mata ko'] as $text) {
    $p = NlpStep3DemoTrial::assess($text, [], ['allow_gemini' => false]);
    ok(!empty($p['health_related']) && ($p['clinical_status'] ?? '') !== 'SKIPPED', "assess NLP: {$text}");
}

$hello = NlpStep3DemoTrial::assess('hello', [], ['allow_gemini' => false]);
ok(in_array(($hello['domain_class'] ?? ''), ['NON_HEALTH_RELATED', 'OUT_OF_SCOPE'], true), 'hello not clinical');

$weather = NlpStep3DemoTrial::assess('what is the weather', [], ['allow_gemini' => false]);
ok(($weather['domain_class'] ?? '') === 'OUT_OF_SCOPE', 'weather OUT_OF_SCOPE');
ok(($weather['clinical_status'] ?? '') === 'SKIPPED', 'weather SKIPPED');

$noGemini = NlpStep3DemoTrial::assess('tatlo na ka bulan kasakit mata ko', [], ['allow_gemini' => false]);
ok(!empty($noGemini['health_related']) && empty($noGemini['gemini_called']), 'gemini-off still HEALTH + no Gemini call');

echo "\nFails: " . (int) $GLOBALS['fails'] . "\n";
exit($GLOBALS['fails'] > 0 ? 1 : 0);
