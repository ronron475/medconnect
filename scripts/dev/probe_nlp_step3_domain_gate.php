<?php
/**
 * Regression: Step 3 demo domain gate must send valid complaints to original NLP.
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
echo "=== Domain detection + demo NLP gate ===\n";

$healthYes = [
    'tatlo na ka bulan kasakit mata ko',
    'kasakit mata ko',
    'nagasakit akon ulo',
    'dugay na ko ginaubo',
    'masakit ulo ko',
    'sumasakit ang tiyan ko',
    'my chest hurts',
    'naga sakit akon tiyan',
    'gapula mata ko kag gakatol',
    'gahabok mata ko',
    'ginasuka ko',
    'nahilo ko',
    'ginahilanat ko',
    'gasakit likod ko',
    'sakitgbgjgbvd', // nonsense — expect NOT healthcare
];

foreach ([
    'tatlo na ka bulan kasakit mata ko',
    'kasakit mata ko',
    'gapula mata ko kag gakatol',
    'my chest hurts',
    'sumasakit ang tiyan ko',
    'dugay na ko ginaubo',
    'gahabok mata ko',
    'ginasuka ko',
] as $text) {
    ok(FaqChatbotDomainScope::isHealthcareRelated($text), "local health: {$text}");
    ok(!FaqChatbotDomainScope::isMeaningfulOutOfScope($text), "not OOS: {$text}");
}

ok(!FaqChatbotDomainScope::isHealthcareRelated('hello'), 'hello not healthcare');
ok(!FaqChatbotDomainScope::isHealthcareRelated('what is the weather'), 'weather not healthcare');
ok(FaqChatbotDomainScope::isMeaningfulOutOfScope('what is the weather'), 'weather is OOS');
ok(FaqChatbotDomainScope::isMeaningfulOutOfScope('tell me a joke'), 'joke is OOS');
ok(FaqChatbotDomainScope::isMeaningfulOutOfScope('who is the president'), 'president is OOS');
ok(!FaqChatbotDomainScope::isHealthcareRelated('sakitgbgjgbvd'), 'gibberish not healthcare');

$main = NlpStep3DemoTrial::assess('tatlo na ka bulan kasakit mata ko', [], ['allow_gemini' => false]);
ok(!empty($main['health_related']), 'MAIN health_related YES');
ok(($main['domain_class'] ?? '') === 'HEALTH_RELATED', 'MAIN domain HEALTH_RELATED', (string) ($main['domain_class'] ?? ''));
ok(($main['clinical_status'] ?? '') !== 'SKIPPED', 'MAIN clinical not SKIPPED', (string) ($main['clinical_status'] ?? ''));
ok(($main['assessment_status'] ?? '') !== 'SKIPPED', 'MAIN assessment not SKIPPED', (string) ($main['assessment_status'] ?? ''));
ok(
    str_contains((string) ($main['engine'] ?? ''), 'clinical')
        || str_contains((string) ($main['engine_chain'] ?? ''), 'Clinical')
        || ($main['followup_question']['question_id'] ?? '') !== '',
    'MAIN continues original NLP',
    (string) (($main['engine'] ?? '') . ' / ' . ($main['followup_question']['question_id'] ?? ''))
);

$eye = NlpStep3DemoTrial::assess('kasakit mata ko', [], ['allow_gemini' => false]);
ok(!empty($eye['health_related']) && ($eye['domain_class'] ?? '') === 'HEALTH_RELATED', 'kasakit mata ko HEALTH_RELATED');
ok(($eye['clinical_status'] ?? '') !== 'SKIPPED', 'kasakit mata ko not SKIPPED');

$red = NlpStep3DemoTrial::assess('gapula mata ko kag gakatol', [], ['allow_gemini' => false]);
ok(!empty($red['health_related']) && ($red['clinical_status'] ?? '') !== 'SKIPPED', 'gapula/gakatol enters NLP');

$chest = NlpStep3DemoTrial::assess('my chest hurts', [], ['allow_gemini' => false]);
ok(!empty($chest['health_related']) && ($chest['clinical_status'] ?? '') !== 'SKIPPED', 'chest hurts enters NLP');

$hello = NlpStep3DemoTrial::assess('hello', [], ['allow_gemini' => false]);
ok(in_array(($hello['domain_class'] ?? ''), ['NON_HEALTH_RELATED', 'OUT_OF_SCOPE'], true), 'hello not clinical', (string) ($hello['domain_class'] ?? ''));
ok(($hello['clinical_status'] ?? '') === 'SKIPPED' || ($hello['triage_final'] ?? null) === null, 'hello no triage');

$weather = NlpStep3DemoTrial::assess('what is the weather', [], ['allow_gemini' => false]);
ok(($weather['domain_class'] ?? '') === 'OUT_OF_SCOPE', 'weather OUT_OF_SCOPE', (string) ($weather['domain_class'] ?? ''));
ok(($weather['clinical_status'] ?? '') === 'SKIPPED', 'weather SKIPPED');

// Gemini unavailable must not crash; clear health still works without Gemini.
$noGemini = NlpStep3DemoTrial::assess('tatlo na ka bulan kasakit mata ko', [], ['allow_gemini' => false]);
ok(!empty($noGemini['health_related']), 'gemini-off still HEALTH_RELATED');
ok(empty($noGemini['gemini_called']), 'gemini-off does not call Gemini');

echo "\nFails: " . (int) $GLOBALS['fails'] . "\n";
exit($GLOBALS['fails'] > 0 ? 1 : 0);
