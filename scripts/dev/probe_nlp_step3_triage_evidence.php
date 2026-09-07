<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $c, string $l, string $d = ''): void
{
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1;
    }
}
$GLOBALS['fails'] = 0;

function assess(string $text): array
{
    return NlpStep3DemoTrial::assess($text, [], ['allow_gemini' => false]);
}

echo "=== Universal demo triage (evidence-based) ===\n";

// Mild/gradual headache with moderate severity and no other symptoms → NON-URGENT
$mild = assess('kasakit ulo ko, pila na ka adlaw, 5/10, hinay-hinay, wala iban nga sintomas');
ok(($mild['assessment_status'] ?? '') === 'COMPLETED', 'mild completes', (string) ($mild['assessment_status'] ?? ''));
ok(($mild['triage_final'] ?? '') === 'NON-URGENT', 'mild NON-URGENT not severity-escalated', (string) ($mild['triage_final'] ?? ''));

$mild2 = assess('pila na adlaw kasakit ulo ko, 5/10, gradual, wala');
ok(($mild2['triage_final'] ?? $mild2['assessment_status'] ?? '') === 'NON-URGENT'
    || (($mild2['assessment_status'] ?? '') === 'IN_PROGRESS'),
    'variant mild not auto-URGENT', (string) (($mild2['triage_final'] ?? '') . '/' . ($mild2['assessment_status'] ?? '')));
if (($mild2['assessment_status'] ?? '') === 'COMPLETED') {
    ok(($mild2['triage_final'] ?? '') === 'NON-URGENT', 'variant mild final NON-URGENT', (string) ($mild2['triage_final'] ?? ''));
}

// Engine consistency must not treat "5/10" as score 10
$engine = ClinicalTriageEngine::assess(
    'kasakit ulo ko, pila na ka adlaw, 5/10, hinay-hinay, wala iban nga sintomas',
    'kasakit ulo ko, pila na ka adlaw, 5/10, hinay-hinay, wala iban nga sintomas'
);
ok(($engine['triage_display'] ?? '') !== 'URGENT'
    || !str_contains((string) ($engine['clinical_reasoning'] ?? ''), 'pain_scale → URGENT'),
    'engine no false pain_scale escalate from 5/10',
    (string) (($engine['triage_display'] ?? '') . ' | ' . ($engine['clinical_reasoning'] ?? '')));

// Emergency still works
$er = assess('Masakit dughan ko kag budlay magginhawa');
ok(($er['triage_final'] ?? '') === 'EMERGENCY', 'chest+dyspnea EMERGENCY', (string) ($er['triage_final'] ?? ''));

// Rich headache with 7/10 + dizziness + worsening can remain URGENT
$rich = assess('Masakit akon ulo 7/10 halin gahapon, daw pulsing kag mas nagagrabe kung maglihok. May hilo man ko pero wala ko nagsuka kag wala hilanat.');
ok(($rich['assessment_status'] ?? '') === 'COMPLETED', 'rich completes', (string) ($rich['assessment_status'] ?? ''));
ok(in_array(($rich['triage_final'] ?? ''), ['URGENT', 'NON-URGENT'], true), 'rich class allowed', (string) ($rich['triage_final'] ?? ''));
ok(($rich['triage_final'] ?? '') === 'URGENT', 'rich with dizziness+7/10+worsening is URGENT', (string) ($rich['triage_final'] ?? ''));

// Incomplete → ask, do not invent EMERGENCY from severity words alone
$partial = assess('kasakit ulo ko');
ok(($partial['assessment_status'] ?? '') === 'IN_PROGRESS', 'incomplete stays interview', (string) ($partial['assessment_status'] ?? ''));
ok(($partial['triage_final'] ?? null) === null || ($partial['triage_final'] ?? '') === '', 'incomplete no final triage', (string) ($partial['triage_final'] ?? 'null'));

echo "\nFails: " . (int) ($GLOBALS['fails'] ?? 0) . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
