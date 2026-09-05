<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Demo clinical interview tests ===\n";

$t1 = NlpStep3DemoTrial::assess('sakit');
ok(($t1['followup_question']['question_id'] ?? '') === 'PAIN_SEVERITY', 'T1 severity first', (string) ($t1['followup_question']['question_id'] ?? ''));
ok(($t1['triage_final'] ?? null) === null, 'T1 no triage yet');
ok(($t1['clinical_status'] ?? '') === 'NEEDS_FOLLOW_UP', 'T1 clinical status NEEDS_FOLLOW_UP', (string) ($t1['clinical_status'] ?? ''));
ok(($t1['triage_display'] ?? '') === '', 'T1 empty triage_display');
ok(str_contains((string) ($t1['patient_message'] ?? ''), '0–10') || str_contains((string) ($t1['patient_message'] ?? ''), '1–10') || str_contains((string) ($t1['patient_message'] ?? ''), '0-10') || str_contains((string) ($t1['patient_message'] ?? ''), '1-10'), 'T1 asks 0–10 or 1–10 scale');

$t2a = NlpStep3DemoTrial::assess('sakit');
$t2 = NlpStep3DemoTrial::assess('7', $t2a['interview_context'] ?? []);
ok(($t2['complaint_summary']['pain_severity'] ?? '') === '7/10', 'T2 severity stored', (string) ($t2['complaint_summary']['pain_severity'] ?? ''));
ok(($t2['followup_question']['question_id'] ?? '') === 'PAIN_LOCATION', 'T2 asks location', (string) ($t2['followup_question']['question_id'] ?? ''));

$t3 = NlpStep3DemoTrial::assess('tiyan', $t2['interview_context'] ?? []);
$t3b = NlpStep3DemoTrial::assess('gahapon', $t3['interview_context'] ?? []);
ok(($t3b['complaint_summary']['location'] ?? '') === 'abdomen', 'T3 location', (string) ($t3b['complaint_summary']['location'] ?? ''));
ok(str_contains((string) ($t3b['complaint_summary']['duration'] ?? ''), 'yesterday'), 'T3 duration', (string) ($t3b['complaint_summary']['duration'] ?? ''));
ok(($t3b['followup_question']['question_id'] ?? '') !== 'PAIN_SEVERITY', 'T3 not re-asking severity');
ok(($t3b['followup_question']['question_id'] ?? '') !== 'PAIN_LOCATION', 'T3 not re-asking location');
ok(($t3b['followup_question']['question_id'] ?? '') !== 'ONSET', 'T3 not re-asking onset');

$t4 = NlpStep3DemoTrial::assess('sakit akon tiyan 7/10 halin gahapon');
ok(($t4['complaint_summary']['location'] ?? '') === 'abdomen', 'T4 extracted location');
ok(($t4['complaint_summary']['pain_severity'] ?? '') === '7/10', 'T4 extracted severity');
ok(str_contains((string) ($t4['complaint_summary']['duration'] ?? ''), 'yesterday'), 'T4 extracted duration');
ok(!in_array(($t4['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true), 'T4 skips known questions', (string) ($t4['followup_question']['question_id'] ?? ''));

$t5 = NlpStep3DemoTrial::assess('Masakit gid akon dughan kag budlay magginhawa.');
ok(($t5['assessment_status'] ?? '') === 'COMPLETED', 'T5 completed');
ok(($t5['triage_final'] ?? '') === 'EMERGENCY', 'T5 emergency', (string) ($t5['triage_final'] ?? ''));

$t6 = NlpStep3DemoTrial::assess('sakitgbgjgbvd');
ok(($t6['domain_class'] ?? '') === 'UNCLEAR', 'T6 unclear', (string) ($t6['domain_class'] ?? ''));

$t7 = NlpStep3DemoTrial::assess('hello');
ok(($t7['domain_class'] ?? '') === 'NON_HEALTH_RELATED', 'T7 non-health', (string) ($t7['domain_class'] ?? ''));

$t8 = NlpStep3DemoTrial::assess('nagasuka ko', $t3b['interview_context'] ?? []);
ok(($t8['assessment_status'] ?? '') === 'COMPLETED', 'T8 completes after enough info', (string) ($t8['assessment_status'] ?? ''));
ok(in_array(($t8['triage_final'] ?? ''), ['EMERGENCY', 'URGENT', 'NON-URGENT'], true), 'T8 final class only 3', (string) ($t8['triage_final'] ?? ''));
