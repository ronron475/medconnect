<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function line(string $label, mixed $value): void
{
    echo $label . ': ' . (is_string($value) || is_numeric($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)) . "\n";
}

echo "==== TEST 1 sakit ====\n";
$t1 = NlpStep3DemoTrial::assess('sakit');
line('health', $t1['health_related'] ? 'YES' : 'NO');
line('info', $t1['information']);
line('triage', $t1['triage_final'] ?? 'null');
line('status', $t1['assessment_status']);
line('q', $t1['followup_question']['text'] ?? '');
line('qid', $t1['followup_question']['question_id'] ?? '');

echo "\n==== TEST 2 sakit akon tiyan ====\n";
$t2 = NlpStep3DemoTrial::assess('sakit akon tiyan');
line('summary', $t2['complaint_summary']);
line('qid', $t2['followup_question']['question_id'] ?? '');
line('q', $t2['followup_question']['text'] ?? '');
line('triage', $t2['triage_final'] ?? 'null');
$asksWhere = stripos((string) ($t2['followup_question']['text'] ?? ''), 'diin ang masakit') !== false
    || ($t2['followup_question']['question_id'] ?? '') === 'PAIN_LOCATION';
line('asks_where', $asksWhere ? 'YES (bad)' : 'NO (good)');

echo "\n==== TEST 3 sakit -> tiyan -> 7 ====\n";
$c1 = NlpStep3DemoTrial::assess('sakit');
$c2 = NlpStep3DemoTrial::assess('tiyan', $c1['interview_context'] ?? []);
$c3 = NlpStep3DemoTrial::assess('7', $c2['interview_context'] ?? []);
line('summary', $c3['complaint_summary']);
line('transcript', $c3['clinical_transcript']);
line('status', $c3['assessment_status']);
line('triage', $c3['triage_final'] ?? 'null');
line('qid', $c3['followup_question']['question_id'] ?? '');

echo "\n==== TEST 4 serious ====\n";
$t4 = NlpStep3DemoTrial::assess('Masakit gid akon dughan kag budlay magginhawa.');
line('status', $t4['assessment_status']);
line('triage', $t4['triage_final'] ?? 'null');
line('qid', $t4['followup_question']['question_id'] ?? '(none)');
line('asks_where', (($t4['followup_question']['question_id'] ?? '') === 'PAIN_LOCATION') ? 'YES (bad)' : 'NO (good)');

echo "\n==== TEST 5 gibberish ====\n";
$t5 = NlpStep3DemoTrial::assess('sakitgbgjgbvd');
line('domain', $t5['domain_class']);
line('health', $t5['health_related'] ? 'YES' : 'NO');
line('triage', $t5['triage_final'] ?? 'null');
line('msg', $t5['patient_message']);
line('gemini', $t5['gemini_called'] ? 'yes' : 'no');

echo "\n==== TEST 6 hello ====\n";
$t6 = NlpStep3DemoTrial::assess('hello');
line('domain', $t6['domain_class']);
line('health', $t6['health_related'] ? 'YES' : 'NO');
line('triage', $t6['triage_final'] ?? 'null');
line('msg', $t6['patient_message']);
line('gemini', $t6['gemini_called'] ? 'yes' : 'no');
