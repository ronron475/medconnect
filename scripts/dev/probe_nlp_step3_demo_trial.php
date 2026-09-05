<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function line(string $label, mixed $value): void
{
    echo $label . ': ' . (is_string($value) || is_numeric($value) || is_bool($value)
        ? (is_bool($value) ? ($value ? 'YES' : 'NO') : $value)
        : json_encode($value, JSON_UNESCAPED_UNICODE)) . "\n";
}

function qid(array $t): string
{
    return (string) ($t['followup_question']['question_id'] ?? '');
}

function qtext(array $t): string
{
    return (string) ($t['followup_question']['text'] ?? '');
}

echo "==== TEST 1 sakit ====\n";
$t1 = NlpStep3DemoTrial::assess('sakit');
line('health', !empty($t1['health_related']));
line('info', $t1['information']);
line('triage', $t1['triage_final'] ?? 'null');
line('summary', $t1['complaint_summary']);
line('qid', qid($t1));
line('q', qtext($t1));
line('helper', $t1['followup_question']['helper_text'] ?? '');

echo "\n==== TEST 2 sakit -> 7 ====\n";
$c1 = NlpStep3DemoTrial::assess('sakit');
$c2 = NlpStep3DemoTrial::assess('7', $c1['interview_context'] ?? []);
line('severity', $c2['complaint_summary']['pain_severity'] ?? '');
line('qid', qid($c2));
line('q', qtext($c2));

echo "\n==== TEST 3 sakit -> 7 -> tiyan ====\n";
$c3 = NlpStep3DemoTrial::assess('tiyan', $c2['interview_context'] ?? []);
line('summary', $c3['complaint_summary']);
line('qid', qid($c3));
line('q', qtext($c3));

echo "\n==== TEST 4 sakit akon tiyan ====\n";
$t4 = NlpStep3DemoTrial::assess('sakit akon tiyan');
line('summary', $t4['complaint_summary']);
line('qid', qid($t4));
line('asks_location', qid($t4) === 'PAIN_LOCATION' ? 'YES (bad)' : 'NO (good)');

echo "\n==== TEST 5 sakit akon tiyan 7/10 ====\n";
$t5 = NlpStep3DemoTrial::assess('sakit akon tiyan 7/10');
line('summary', $t5['complaint_summary']);
line('qid', qid($t5));
line('asks_sev_or_loc', in_array(qid($t5), ['PAIN_SEVERITY', 'PAIN_LOCATION'], true) ? 'YES (bad)' : 'NO (good)');

echo "\n==== TEST 6 serious ====\n";
$t6 = NlpStep3DemoTrial::assess('Masakit gid akon dughan kag budlay magginhawa.');
line('status', $t6['assessment_status']);
line('triage', $t6['triage_final'] ?? 'null');
line('qid', qid($t6) !== '' ? qid($t6) : '(none)');

echo "\n==== TEST 7 gibberish ====\n";
$t7 = NlpStep3DemoTrial::assess('sakitgbgjgbvd');
line('domain', $t7['domain_class']);
line('health', !empty($t7['health_related']));
line('msg', $t7['patient_message']);

echo "\n==== TEST 8 hello ====\n";
$t8 = NlpStep3DemoTrial::assess('hello');
line('domain', $t8['domain_class']);
line('health', !empty($t8['health_related']));
line('msg', $t8['patient_message']);
