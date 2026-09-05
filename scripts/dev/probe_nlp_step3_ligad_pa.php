<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== ligad pa / onset fallback ===\n";

$a = NlpStep3DemoTrial::assess('sakit');
$a = NlpStep3DemoTrial::assess('7', $a['interview_context'] ?? []);
$a = NlpStep3DemoTrial::assess('ulo', $a['interview_context'] ?? []);
ok(($a['followup_question']['question_id'] ?? '') === 'ONSET', 'awaiting ONSET', (string) ($a['followup_question']['question_id'] ?? ''));

$b = NlpStep3DemoTrial::assess('ligad pa', $a['interview_context'] ?? []);
ok(in_array(($b['gemini']['primary_nlp'] ?? ''), ['LOW_CONFIDENCE', 'FAILED'], true), 'primary NLP low confidence', (string) ($b['gemini']['primary_nlp'] ?? ''));
ok(
    in_array(($b['gemini']['status'] ?? ''), ['DEMO_SEMANTIC_FALLBACK', 'OK'], true),
    'fallback applied for ligad pa',
    (string) ($b['gemini']['status'] ?? '')
);
ok(
    str_contains(strtolower((string) ($b['complaint_summary']['duration'] ?? $b['facts']['duration_label'] ?? '')), 'earlier')
        || str_contains(strtolower((string) ($b['complaint_summary']['duration'] ?? '')), 'earlier')
        || ($b['facts']['duration_label'] ?? '') === 'started earlier',
    'stores started earlier',
    (string) (($b['complaint_summary']['duration'] ?? '') ?: ($b['facts']['duration_label'] ?? ''))
);
ok(($b['followup_question']['question_id'] ?? '') !== 'ONSET', 'does not re-ask ONSET', (string) ($b['followup_question']['question_id'] ?? 'none'));

$c = NlpStep3DemoTrial::assess('sakit');
$c = NlpStep3DemoTrial::assess('7', $c['interview_context'] ?? []);
$c = NlpStep3DemoTrial::assess('ulo', $c['interview_context'] ?? []);
$c = NlpStep3DemoTrial::assess('gahapon', $c['interview_context'] ?? []);
ok(empty($c['gemini']['called']), 'gahapon does not call Gemini');
ok(($c['gemini']['primary_nlp'] ?? '') === 'SUCCESS' || ($c['gemini']['reason'] ?? '') !== '', 'gahapon primary NLP', (string) ($c['gemini']['primary_nlp'] ?? $c['gemini']['reason'] ?? ''));
ok(str_contains(strtolower((string) ($c['complaint_summary']['duration'] ?? '')), 'yesterday'), 'gahapon = yesterday');

$d = NlpStep3DemoTrial::assess('sakit');
$d = NlpStep3DemoTrial::assess('7', $d['interview_context'] ?? []);
$d = NlpStep3DemoTrial::assess('ulo', $d['interview_context'] ?? []);
$d = NlpStep3DemoTrial::assess('Mahilig ako magbasketball.', $d['interview_context'] ?? []);
ok(($d['assessment_status'] ?? '') === 'IN_PROGRESS', 'unrelated stays in progress');
ok(($d['followup_question']['question_id'] ?? '') === 'ONSET', 'unrelated keeps ONSET', (string) ($d['followup_question']['question_id'] ?? ''));
ok(($d['gemini']['status'] ?? '') === 'UNRELATED', 'unrelated status', (string) ($d['gemini']['status'] ?? ''));

$e = NlpStep3DemoTrial::assess('sakit');
$e = NlpStep3DemoTrial::assess('7', $e['interview_context'] ?? []);
$e = NlpStep3DemoTrial::assess('ulo', $e['interview_context'] ?? []);
$e = NlpStep3DemoTrial::assess('blue', $e['interview_context'] ?? []);
ok(($e['followup_question']['question_id'] ?? '') === 'ONSET', 'blue keeps ONSET');
ok(($e['triage_final'] ?? null) === null, 'blue does not triage');

$local = NlpStep3DemoGeminiAnswerInterpreter::tryLocalSemanticInterpretation('ligad pa', 'ONSET');
ok(($local['normalized_value'] ?? '') === 'started earlier', 'lexicon ligad pa');
ok(empty($local['needs_clarification']), 'ligad pa no forced clarification');
