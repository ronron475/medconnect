<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Universal adaptive clinical interview (demo) ===\n";

// TEST 1
$t1 = NlpStep3DemoTrial::assess('sakit');
ok(($t1['followup_question']['question_id'] ?? '') === 'PAIN_SEVERITY', 'T1 asks severity', (string) ($t1['followup_question']['question_id'] ?? ''));
ok(($t1['information_status'] ?? $t1['information'] ?? '') === 'INSUFFICIENT', 'T1 insufficient');

// TEST 2
$t2 = NlpStep3DemoTrial::assess('Masakit akon ulo 5/10.');
ok(($t2['complaint_summary']['location'] ?? '') === 'head', 'T2 location');
ok(($t2['complaint_summary']['pain_severity'] ?? '') === '5/10', 'T2 severity');
ok(!in_array(($t2['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION'], true), 'T2 skips severity/location', (string) ($t2['followup_question']['question_id'] ?? 'null'));
ok(($t2['assessment_status'] ?? '') === 'IN_PROGRESS', 'T2 still needs more');

// TEST 3
$t3 = NlpStep3DemoTrial::assess('Masakit akon ulo 5/10 halin gahapon.');
ok(($t3['complaint_summary']['location'] ?? '') === 'head', 'T3 location');
ok(($t3['complaint_summary']['pain_severity'] ?? '') === '5/10', 'T3 severity');
ok(str_contains((string) ($t3['complaint_summary']['duration'] ?? ''), 'yesterday'), 'T3 onset', (string) ($t3['complaint_summary']['duration'] ?? ''));
ok(!in_array(($t3['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true), 'T3 skips core triad', (string) ($t3['followup_question']['question_id'] ?? 'null'));

// TEST 4
$t4 = NlpStep3DemoTrial::assess(
    'Masakit akon ulo 5/10 halin gahapon, daw pulsing kag mas nagagrabe kung maglihok. May hilo ko pero wala ko nagsuka kag wala hilanat.'
);
ok(($t4['assessment_status'] ?? '') === 'COMPLETED', 'T4 completes', (string) ($t4['assessment_status'] ?? ''));
ok(!empty($t4['followup_skipped']), 'T4 skips further follow-up');
ok(($t4['complaint_summary']['character'] ?? '') === 'pulsating', 'T4 character', (string) ($t4['complaint_summary']['character'] ?? ''));
ok(str_contains((string) ($t4['complaint_summary']['associated_symptoms'] ?? ''), 'dizziness'), 'T4 dizziness');
ok(str_contains((string) ($t4['complaint_summary']['pertinent_negatives'] ?? ''), 'vomit')
    || str_contains((string) ($t4['complaint_summary']['pertinent_negatives'] ?? ''), 'fever'), 'T4 negatives', (string) ($t4['complaint_summary']['pertinent_negatives'] ?? ''));
ok(in_array(($t4['triage_final'] ?? ''), ['EMERGENCY', 'URGENT', 'NON-URGENT'], true), 'T4 triage', (string) ($t4['triage_final'] ?? ''));

// TEST 5 ligad pa
$a = NlpStep3DemoTrial::assess('sakit');
$a = NlpStep3DemoTrial::assess('7', $a['interview_context'] ?? []);
$a = NlpStep3DemoTrial::assess('ulo', $a['interview_context'] ?? []);
$t5 = NlpStep3DemoTrial::assess('ligad pa', $a['interview_context'] ?? []);
ok(str_contains((string) (($t5['facts']['duration_label'] ?? '') . ($t5['complaint_summary']['duration'] ?? '')), 'earlier')
    || str_contains((string) ($t5['complaint_summary']['duration'] ?? ''), 'earlier'), 'T5 ligad pa stored', (string) ($t5['complaint_summary']['duration'] ?? ($t5['facts']['duration_label'] ?? '')));
ok(($t5['followup_question']['question_id'] ?? '') !== 'ONSET', 'T5 does not re-ask onset', (string) ($t5['followup_question']['question_id'] ?? 'null'));

// TEST 6 multi-fact out of order while answering onset
$b = NlpStep3DemoTrial::assess('sakit');
$b = NlpStep3DemoTrial::assess('5', $b['interview_context'] ?? []); // will ask location next? actually after sakit asks severity, then 5 asks location
// Restart: get to ONSET awaiting
$b = NlpStep3DemoTrial::assess('sakit');
$b = NlpStep3DemoTrial::assess('5', $b['interview_context'] ?? []);
$b = NlpStep3DemoTrial::assess('ulo', $b['interview_context'] ?? []);
ok(($b['followup_question']['question_id'] ?? '') === 'ONSET', 'T6 setup awaiting onset', (string) ($b['followup_question']['question_id'] ?? ''));
$t6 = NlpStep3DemoTrial::assess('Halín gahapon, 7/10 ang sakit sang ulo ko.', $b['interview_context'] ?? []);
ok(str_contains((string) ($t6['complaint_summary']['duration'] ?? ''), 'yesterday'), 'T6 onset', (string) ($t6['complaint_summary']['duration'] ?? ''));
ok(($t6['complaint_summary']['pain_severity'] ?? '') === '7/10', 'T6 severity updated from multi-fact answer', (string) ($t6['complaint_summary']['pain_severity'] ?? ''));
ok(($t6['complaint_summary']['location'] ?? '') === 'head', 'T6 location');
ok(!in_array(($t6['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true), 'T6 skips answered', (string) ($t6['followup_question']['question_id'] ?? 'null'));

// TEST 7 eye pain
$t7 = NlpStep3DemoTrial::assess('Masakit akon mata 5/10.');
ok(($t7['complaint_summary']['location'] ?? '') === 'eye' || str_contains((string) ($t7['complaint_summary']['chief_complaint'] ?? ''), 'eye'), 'T7 eye', (string) ($t7['complaint_summary']['chief_complaint'] ?? '') . '/' . (string) ($t7['complaint_summary']['location'] ?? ''));
ok(($t7['triage_final'] ?? null) !== 'URGENT' || ($t7['assessment_status'] ?? '') === 'IN_PROGRESS', 'T7 not auto-URGENT', (string) (($t7['triage_final'] ?? 'none') . '/' . ($t7['assessment_status'] ?? '')));
ok(($t7['assessment_status'] ?? '') === 'IN_PROGRESS', 'T7 may ask eye follow-up');

// TEST 8 chest + dyspnea
$t8 = NlpStep3DemoTrial::assess('Masakit gid akon dughan 8/10 kag budlay magginhawa.');
ok(($t8['assessment_status'] ?? '') === 'COMPLETED', 'T8 completes', (string) ($t8['assessment_status'] ?? ''));
ok(($t8['triage_final'] ?? '') === 'EMERGENCY', 'T8 emergency', (string) ($t8['triage_final'] ?? ''));

// TEST 9 fever
$t9 = NlpStep3DemoTrial::assess('May hilanat ako 38.5 degrees halin kagab-i kag ginapanakit ang lawas ko.');
ok(($t9['complaint_summary']['temperature'] ?? '') === '38.5°C' || str_contains((string) ($t9['complaint_summary']['vital_signs'] ?? ''), '38.5'), 'T9 temperature', (string) (($t9['complaint_summary']['temperature'] ?? '') . '|' . ($t9['complaint_summary']['vital_signs'] ?? '')));
ok(str_contains((string) ($t9['complaint_summary']['duration'] ?? ''), 'night') || str_contains((string) ($t9['complaint_summary']['duration'] ?? ''), 'Night'), 'T9 onset', (string) ($t9['complaint_summary']['duration'] ?? ''));
ok(str_contains((string) ($t9['complaint_summary']['associated_symptoms'] ?? ''), 'myalgia')
    || str_contains((string) ($t9['complaint_summary']['associated_symptoms'] ?? ''), 'body'), 'T9 myalgia', (string) ($t9['complaint_summary']['associated_symptoms'] ?? ''));
ok(($t9['followup_question']['question_id'] ?? '') !== 'ONSET', 'T9 does not re-ask onset', (string) ($t9['followup_question']['question_id'] ?? 'null'));
ok(($t9['followup_question']['question_id'] ?? '') !== 'FEVER_CONFIRM', 'T9 does not re-ask fever', (string) ($t9['followup_question']['question_id'] ?? 'null'));

// TEST 10 abdominal
$t10 = NlpStep3DemoTrial::assess('Masakit akon tiyan 7/10 halin kagab-i, sa tuo nga bahin, kag nagsuka ko duha ka beses.');
ok(($t10['complaint_summary']['location'] ?? '') === 'abdomen', 'T10 location');
ok(($t10['complaint_summary']['pain_severity'] ?? '') === '7/10', 'T10 severity');
ok(($t10['complaint_summary']['laterality'] ?? '') === 'right', 'T10 laterality', (string) ($t10['complaint_summary']['laterality'] ?? ''));
ok(str_contains((string) ($t10['complaint_summary']['associated_symptoms'] ?? ''), 'vomit'), 'T10 vomiting', (string) ($t10['complaint_summary']['associated_symptoms'] ?? ''));
ok(!in_array(($t10['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true), 'T10 skips answered core', (string) ($t10['followup_question']['question_id'] ?? 'null'));
