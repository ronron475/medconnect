<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

$a = NlpStep3DemoTrial::assess('sakit');
$b = NlpStep3DemoTrial::assess('7', $a['interview_context'] ?? []);
$c = NlpStep3DemoTrial::assess('tiyan', $b['interview_context'] ?? []);
$d = NlpStep3DemoTrial::assess('gahapon', $c['interview_context'] ?? []);
$e = NlpStep3DemoTrial::assess('nagasuka ko', $d['interview_context'] ?? []);

echo "after associated answer:\n";
echo 'status=' . ($e['assessment_status'] ?? '') . "\n";
echo 'info=' . ($e['information'] ?? '') . "\n";
echo 'triage=' . json_encode($e['triage_final'] ?? null) . "\n";
echo 'qid=' . ($e['followup_question']['question_id'] ?? '(none)') . "\n";
echo 'q=' . ($e['followup_question']['text'] ?? '') . "\n";
echo 'summary=' . json_encode($e['complaint_summary'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo 'patient_message=' . ($e['patient_message'] ?? '') . "\n";
echo 'facts_abdominal=' . json_encode($e['facts']['abdominal_associated'] ?? null) . "\n";
echo 'facts_other=' . json_encode($e['facts']['has_other_symptoms'] ?? null) . "\n";
