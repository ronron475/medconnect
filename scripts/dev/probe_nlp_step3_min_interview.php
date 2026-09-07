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

function assess(string $text, array $prior = []): array
{
    return NlpStep3DemoTrial::assess($text, $prior, ['allow_gemini' => false]);
}

echo "=== Minimum sufficient adaptive interview ===\n";

$dur = ClinicalFeatureExtractors::extractDuration('pila na ka adlaw kasakit ulo ko');
ok(str_contains(mb_strtolower((string) ($dur['label'] ?? '')), 'day')
    || str_contains(mb_strtolower((string) ($dur['label'] ?? '')), 'several'),
    'pila na ka adlaw = duration', (string) ($dur['label'] ?? ''));

$multi = assess('pila na ka adlaw kasakit ulo ko kag sakit akon tiyan');
$concepts = (array) ($multi['active_concepts'] ?? []);
ok(in_array('headache', $concepts, true) && in_array('abdominal_pain', $concepts, true),
    'multi concepts head+abdomen', json_encode($concepts));
ok(($multi['followup_question']['question_id'] ?? '') === 'PAIN_SEVERITY',
    'multi first asks severity (triage-critical)', (string) ($multi['followup_question']['question_id'] ?? ''));
ok(!in_array(($multi['followup_question']['question_id'] ?? ''), ['NEURO_WEAKNESS', 'NEURO_SPEECH', 'NEURO_VISION', 'COUGH_TYPE'], true),
    'multi does not open with unrelated bank items', (string) ($multi['followup_question']['question_id'] ?? ''));
$miss = (array) ($multi['missing_fields'] ?? []);
ok(count($miss) <= 4, 'multi keeps short missing list', json_encode($miss));

// After severity, next should still be triage-relevant (associated), not every location detail.
$ctx = [
    'facts' => $multi['facts'] ?? [],
    'patient_turns' => [$multi['input'] ?? 'pila na ka adlaw kasakit ulo ko kag sakit akon tiyan'],
    'awaiting_question_id' => 'PAIN_SEVERITY',
    'questions_asked' => ['PAIN_SEVERITY'],
];
$turn2 = assess('5/10', is_array($multi['interview_context'] ?? null) ? $multi['interview_context'] : $ctx);
$q2 = (string) ($turn2['followup_question']['question_id'] ?? '');
if (($turn2['assessment_status'] ?? '') === 'COMPLETED') {
    ok(true, 'multi can complete after severity if already sufficient');
    ok(in_array(($turn2['triage_final'] ?? ''), ['EMERGENCY', 'URGENT', 'NON-URGENT'], true),
        'multi final class', (string) ($turn2['triage_final'] ?? ''));
} else {
    ok(in_array($q2, ['ABDOMINAL_ASSOCIATED', 'ASSOCIATED_SYMPTOMS', 'SPECIFIC_LOCATION', 'ONSET'], true),
        'multi next still triage-relevant', $q2);
    ok($q2 !== 'NEURO_WEAKNESS' && $q2 !== 'COUGH_TYPE', 'multi next not unrelated', $q2);
}

$head = assess('pila na ka adlaw kasakit ulo ko, 5/10, wala iban');
ok(($head['assessment_status'] ?? '') === 'COMPLETED', 'mild head with timing+sev+denial completes', (string) ($head['assessment_status'] ?? ''));
ok(($head['triage_final'] ?? '') === 'NON-URGENT', 'mild head NON-URGENT', (string) ($head['triage_final'] ?? ''));

$chest = assess('Masakit dughan ko');
ok(($chest['followup_question']['question_id'] ?? '') === 'BREATHING_SEVERITY',
    'chest prioritizes breathing over generic bank walk', (string) ($chest['followup_question']['question_id'] ?? ''));

$cough = assess('ginaubo ko');
ok(($cough['followup_question']['question_id'] ?? '') === 'BREATHING_SEVERITY',
    'cough asks breathing first', (string) ($cough['followup_question']['question_id'] ?? ''));
ok(($cough['followup_question']['question_id'] ?? '') !== 'PAIN_SEVERITY', 'cough not pain scale', (string) ($cough['followup_question']['question_id'] ?? ''));

echo "\nFails: " . (int) ($GLOBALS['fails'] ?? 0) . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
