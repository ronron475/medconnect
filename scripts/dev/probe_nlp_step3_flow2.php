<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function dump(string $label, array $t): void
{
    echo "=== {$label} ===\n";
    echo 'status=' . ($t['assessment_status'] ?? '') . "\n";
    echo 'info=' . ($t['information'] ?? '') . "\n";
    echo 'triage=' . json_encode($t['triage_final'] ?? null) . "\n";
    echo 'summary=' . json_encode($t['complaint_summary'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
    echo 'qid=' . ($t['followup_question']['question_id'] ?? '(none)') . "\n";
    echo 'q=' . ($t['followup_question']['text'] ?? '') . "\n";
    echo 'facts=' . json_encode($t['facts'] ?? [], JSON_UNESCAPED_UNICODE) . "\n\n";
}

$a = NlpStep3DemoTrial::assess('sakit');
$b = NlpStep3DemoTrial::assess('7', $a['interview_context'] ?? []);
$c = NlpStep3DemoTrial::assess('tiyan', $b['interview_context'] ?? []);
$d = NlpStep3DemoTrial::assess('gahapon', $c['interview_context'] ?? []);
dump('after gahapon', $d);

$e = NlpStep3DemoTrial::assess('sakit akon tiyan 7/10 halin gahapon');
dump('complete one-shot', $e);

$f = NlpStep3DemoTrial::assess('sakit ulo ko');
dump('sakit ulo ko', $f);

$dur = ClinicalFeatureExtractors::extractDuration('sakit akon tiyan 7/10 halin gahapon');
$onset = ClinicalFeatureExtractors::extractOnset('sakit akon tiyan 7/10 halin gahapon');
echo "extractDuration=" . json_encode($dur, JSON_UNESCAPED_UNICODE) . "\n";
echo "extractOnset={$onset}\n";
