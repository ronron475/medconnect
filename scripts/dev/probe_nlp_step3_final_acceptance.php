<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Final universal acceptance suite ===\n";

// TEST 1 vague
$t1 = NlpStep3DemoTrial::assess('sakit');
ok(($t1['followup_question']['question_id'] ?? '') === 'PAIN_SEVERITY', 'T1 severity question');
ok(($t1['triage_final'] ?? null) === null, 'T1 no final triage yet');
ok(str_contains((string) ($t1['patient_message'] ?? ''), '0–10') || str_contains((string) ($t1['patient_message'] ?? ''), '0-10'), 'T1 0-10 scale');

// TEST 2 complete headache
$t2 = NlpStep3DemoTrial::assess(
    'Masakit akon ulo 5/10 halin gahapon, daw nagapulsar, may hilo pero wala pagsuka.'
);
ok(($t2['assessment_status'] ?? '') === 'COMPLETED' || !empty($t2['followup_skipped']), 'T2 sufficient/skip', (string) ($t2['assessment_status'] ?? ''));
ok(($t2['complaint_summary']['location'] ?? '') === 'head', 'T2 head');
ok(($t2['complaint_summary']['pain_severity'] ?? '') === '5/10', 'T2 5/10');
ok(str_contains((string) ($t2['complaint_summary']['character'] ?? ''), 'puls'), 'T2 character');
ok(str_contains((string) ($t2['complaint_summary']['associated_symptoms'] ?? ''), 'dizziness'), 'T2 dizziness');
ok(str_contains((string) ($t2['complaint_summary']['pertinent_negatives'] ?? ''), 'vomit'), 'T2 vomit denied');

// TEST 3 kagapong
$a = NlpStep3DemoTrial::assess('sakit');
$a = NlpStep3DemoTrial::assess('7', $a['interview_context'] ?? []);
$a = NlpStep3DemoTrial::assess('ulo', $a['interview_context'] ?? []);
$t3 = NlpStep3DemoTrial::assess('kagapong', $a['interview_context'] ?? []);
ok(($t3['gemini']['primary_nlp'] ?? '') !== 'SUCCESS', 'T3 primary not SUCCESS on typo', (string) ($t3['gemini']['primary_nlp'] ?? ''));
ok(!str_contains((string) ($t3['gemini']['reason'] ?? ''), 'PRIMARY NLP SUFFICIENT'), 'T3 no false sufficient');
ok(
    ($t3['gemini']['called'] ?? false)
    || in_array(($t3['gemini']['fallback'] ?? ''), ['demo_fuzzy', 'demo_semantic_lexicon', 'gemini'], true),
    'T3 fallback engaged',
    (string) (($t3['gemini']['fallback'] ?? '') . '/' . (($t3['gemini']['called'] ?? false) ? 'called' : 'not'))
);
ok(str_contains((string) ($t3['complaint_summary']['duration'] ?? ''), 'yesterday'), 'T3 onset yesterday');

// TEST 3b kagapon exact synonym = SUCCESS
$b = NlpStep3DemoTrial::assess('sakit');
$b = NlpStep3DemoTrial::assess('7', $b['interview_context'] ?? []);
$b = NlpStep3DemoTrial::assess('ulo', $b['interview_context'] ?? []);
$t3b = NlpStep3DemoTrial::assess('kagapon', $b['interview_context'] ?? []);
ok(($t3b['gemini']['primary_nlp'] ?? '') === 'SUCCESS', 'T3b kagapon primary SUCCESS', (string) ($t3b['gemini']['primary_nlp'] ?? ''));
ok(empty($t3b['gemini']['called']), 'T3b Gemini not called');
ok(str_contains((string) ($t3b['gemini']['reason'] ?? ''), 'PRIMARY NLP SUFFICIENT'), 'T3b sufficient reason', (string) ($t3b['gemini']['reason'] ?? ''));

// TEST 4 tian location
$c = NlpStep3DemoTrial::assess('sakit');
$c = NlpStep3DemoTrial::assess('7', $c['interview_context'] ?? []);
$t4 = NlpStep3DemoTrial::assess('tian', $c['interview_context'] ?? []);
ok(($t4['complaint_summary']['location'] ?? '') === 'abdomen', 'T4 tian→abdomen', (string) ($t4['complaint_summary']['location'] ?? ''));

// TEST 5 dyspnea
$t5 = NlpStep3DemoTrial::assess('budlay gd ginhawa');
ok(
    ($t5['facts']['breathing_difficulty'] ?? null) === true
    || ($t5['complaint_summary']['family'] ?? '') === 'dyspnea'
    || str_contains((string) ($t5['complaint_summary']['chief_complaint'] ?? ''), 'breath'),
    'T5 dyspnea'
);

// TEST 6 multi-fact misspelled
$t6 = NlpStep3DemoTrial::assess('masakit akon tian 7/10 sa tuo halin kagapong kag ga suka ko');
ok(($t6['complaint_summary']['location'] ?? '') === 'abdomen', 'T6 location');
ok(($t6['complaint_summary']['laterality'] ?? '') === 'right', 'T6 laterality', (string) ($t6['complaint_summary']['laterality'] ?? ''));
ok(($t6['complaint_summary']['pain_severity'] ?? '') === '7/10', 'T6 severity');
ok(str_contains((string) ($t6['complaint_summary']['duration'] ?? ''), 'yesterday'), 'T6 onset');
ok(str_contains((string) ($t6['complaint_summary']['associated_symptoms'] ?? ''), 'vomit'), 'T6 vomiting');
ok(!in_array(($t6['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true), 'T6 skips core');

// TEST 7 negatives
$t7 = NlpStep3DemoTrial::assess('wala ko hilanat kag wala ko ga suka');
$neg = (string) ($t7['complaint_summary']['pertinent_negatives'] ?? '');
ok(str_contains($neg, 'fever') && str_contains($neg, 'vomit'), 'T7 negatives', $neg);

// TEST 8 unrelated
$d = NlpStep3DemoTrial::assess('sakit');
$d = NlpStep3DemoTrial::assess('7', $d['interview_context'] ?? []);
$d = NlpStep3DemoTrial::assess('ulo', $d['interview_context'] ?? []);
$t8 = NlpStep3DemoTrial::assess('Mahilig ako magbasketball.', $d['interview_context'] ?? []);
ok(($t8['followup_question']['question_id'] ?? '') === 'ONSET', 'T8 keeps ONSET');
ok(($t8['assessment_status'] ?? '') === 'IN_PROGRESS', 'T8 no advance');

// TEST 9 dugay
$e = NlpStep3DemoTrial::assess('sakit');
$e = NlpStep3DemoTrial::assess('5', $e['interview_context'] ?? []);
$e = NlpStep3DemoTrial::assess('ulo', $e['interview_context'] ?? []);
$t9 = NlpStep3DemoTrial::assess('dugay na', $e['interview_context'] ?? []);
$dur = (string) ($t9['complaint_summary']['duration'] ?? ($t9['facts']['duration_label'] ?? ''));
ok($dur !== '' && !preg_match('/\b\d+\s*(days?|adlaw)\b/i', $dur), 'T9 long-standing no invented days', $dur);

// TEST 10 red flag
$t10 = NlpStep3DemoTrial::assess('Masakit gid akon dughan kag budlay ginhawa.');
ok(($t10['assessment_status'] ?? '') === 'COMPLETED', 'T10 completed');
ok(($t10['triage_final'] ?? '') === 'EMERGENCY', 'T10 emergency', (string) ($t10['triage_final'] ?? ''));

// TEST 30 eye skip
$tEye = NlpStep3DemoTrial::assess('Masakit akon mata 4/10 kagapong.');
ok(($tEye['complaint_summary']['location'] ?? '') === 'eye', 'eye location');
ok(($tEye['complaint_summary']['pain_severity'] ?? '') === '4/10', 'eye severity');
ok(str_contains((string) ($tEye['complaint_summary']['duration'] ?? ''), 'yesterday'), 'eye onset');
ok(!in_array(($tEye['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true), 'eye skips answered');

echo "\nProduction MedConnect was NOT modified.\n";
