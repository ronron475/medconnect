<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

$a = NlpStep3DemoTrial::assess('sakit');
echo 'T1 gemini=' . (!empty($a['gemini_called']) ? 'Y' : 'N') . ' status=' . ($a['gemini']['status'] ?? '') . "\n";

$b = NlpStep3DemoTrial::assess('7', $a['interview_context'] ?? []);
echo 'T2 gemini=' . (!empty($b['gemini_called']) ? 'Y' : 'N')
    . ' sev=' . ($b['complaint_summary']['pain_severity'] ?? '')
    . ' reason=' . ($b['gemini']['reason'] ?? '') . "\n";

$c = NlpStep3DemoTrial::assess('ulo', $b['interview_context'] ?? []);
echo 'T3 gemini=' . (!empty($c['gemini_called']) ? 'Y' : 'N')
    . ' loc=' . ($c['complaint_summary']['location'] ?? '') . "\n";

$d = NlpStep3DemoTrial::assess('gahapon', $c['interview_context'] ?? []);
echo 'T4 gemini=' . (!empty($d['gemini_called']) ? 'Y' : 'N')
    . ' dur=' . ($d['complaint_summary']['duration'] ?? '') . "\n";

$e = NlpStep3DemoTrial::assess('sakit');
$e = NlpStep3DemoTrial::assess('7', $e['interview_context'] ?? []);
$e = NlpStep3DemoTrial::assess('ulo', $e['interview_context'] ?? []);
$e = NlpStep3DemoTrial::assess('ligad pa gid', $e['interview_context'] ?? []);
echo 'T5 ligad status=' . ($e['gemini']['status'] ?? '')
    . ' assess=' . ($e['assessment_status'] ?? '')
    . ' qid=' . ($e['followup_question']['question_id'] ?? '') . "\n";

$f = NlpStep3DemoTrial::assess('Masakit gid akon dughan kag budlay magginhawa.');
echo 'T6 emerg=' . ($f['triage_final'] ?? '')
    . ' gemini=' . (!empty($f['gemini_called']) ? 'Y' : 'N') . "\n";
