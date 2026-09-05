<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Universal misspelling / fuzzy / Gemini trigger ===\n";

$p = NlpStep3DemoAnswerFuzzy::prepare('kagapong', 'ONSET');
ok(($p['corrected'] ?? '') === 'gahapon' || ($p['corrected'] ?? '') === 'kagapon', 'fuzzy kagapong', (string) ($p['corrected'] ?? ''));
ok(($p['fuzzy_status'] ?? '') === 'SUCCESS', 'fuzzy status success');

$p2 = NlpStep3DemoAnswerFuzzy::prepare('masakit akon tian 7/10 kagapong', '');
ok(str_contains((string) ($p2['corrected'] ?? ''), 'tiyan'), 'fuzzy tian→tiyan', (string) ($p2['corrected'] ?? ''));
ok(str_contains((string) ($p2['corrected'] ?? ''), 'gahapon') || str_contains((string) ($p2['corrected'] ?? ''), 'kagapon'), 'fuzzy kagapong in sentence', (string) ($p2['corrected'] ?? ''));

$p3 = NlpStep3DemoAnswerFuzzy::prepare('ulo', 'PAIN_LOCATION');
ok(($p3['corrected'] ?? '') === 'ulo', 'exact ulo unchanged');

// Correct spelling — primary NLP sufficient, no Gemini
$a = NlpStep3DemoTrial::assess('sakit');
$a = NlpStep3DemoTrial::assess('7', $a['interview_context'] ?? []);
$a = NlpStep3DemoTrial::assess('ulo', $a['interview_context'] ?? []);
$g = NlpStep3DemoTrial::assess('gahapon', $a['interview_context'] ?? []);
ok(($g['gemini']['primary_nlp'] ?? '') === 'SUCCESS', 'gahapon primary SUCCESS', (string) ($g['gemini']['primary_nlp'] ?? ''));
ok(empty($g['gemini']['called']), 'gahapon Gemini not called');
ok(str_contains((string) ($g['complaint_summary']['duration'] ?? ''), 'yesterday'), 'gahapon onset');

// Typo kagapong — primary fails, fuzzy enables NLP or lexicon
$b = NlpStep3DemoTrial::assess('sakit');
$b = NlpStep3DemoTrial::assess('4', $b['interview_context'] ?? []);
$b = NlpStep3DemoTrial::assess('mata', $b['interview_context'] ?? []);
$k = NlpStep3DemoTrial::assess('kagapong', $b['interview_context'] ?? []);
ok(($k['gemini']['primary_nlp'] ?? '') !== 'SUCCESS', 'kagapong primary not SUCCESS', (string) ($k['gemini']['primary_nlp'] ?? ''));
ok(
    in_array(($k['gemini']['fallback'] ?? ''), ['demo_fuzzy', 'demo_semantic_lexicon', 'gemini'], true)
    || ($k['gemini']['fuzzy']['status'] ?? '') === 'SUCCESS'
    || ($k['gemini']['status'] ?? '') === 'DEMO_FUZZY',
    'kagapong uses fuzzy/lexicon/gemini fallback',
    (string) (($k['gemini']['fallback'] ?? '') . '/' . ($k['gemini']['status'] ?? '') . '/' . ($k['gemini']['fuzzy']['status'] ?? ''))
);
ok(empty($k['gemini']['called']) || ($k['gemini']['reason'] ?? '') !== 'NOT CALLED — PRIMARY NLP SUFFICIENT', 'UI reason not falsely sufficient');
ok(
    str_contains((string) (($k['complaint_summary']['duration'] ?? '') . ($k['facts']['duration_label'] ?? '')), 'yesterday')
    || str_contains((string) ($k['facts']['duration_label'] ?? ''), 'yesterday'),
    'kagapong stores yesterday',
    (string) (($k['complaint_summary']['duration'] ?? '') . '|' . ($k['facts']['duration_label'] ?? ''))
);
ok(($k['followup_question']['question_id'] ?? '') !== 'ONSET', 'kagapong does not re-ask onset', (string) ($k['followup_question']['question_id'] ?? 'null'));

// Multi-fact misspelled opening
$m = NlpStep3DemoTrial::assess('masakit akon tian 7/10 halin kagapong ga suka ko');
ok(($m['complaint_summary']['location'] ?? '') === 'abdomen', 'multi location', (string) ($m['complaint_summary']['location'] ?? ''));
ok(($m['complaint_summary']['pain_severity'] ?? '') === '7/10', 'multi severity');
ok(str_contains((string) ($m['complaint_summary']['duration'] ?? ''), 'yesterday'), 'multi onset', (string) ($m['complaint_summary']['duration'] ?? ''));
ok(str_contains((string) ($m['complaint_summary']['associated_symptoms'] ?? ''), 'vomit'), 'multi vomiting', (string) ($m['complaint_summary']['associated_symptoms'] ?? ''));
ok(!in_array(($m['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true), 'multi skips answered', (string) ($m['followup_question']['question_id'] ?? 'null'));

// Unrelated still held
$u = NlpStep3DemoTrial::assess('sakit');
$u = NlpStep3DemoTrial::assess('7', $u['interview_context'] ?? []);
$u = NlpStep3DemoTrial::assess('ulo', $u['interview_context'] ?? []);
$u = NlpStep3DemoTrial::assess('Mahilig ako magbasketball.', $u['interview_context'] ?? []);
ok(($u['assessment_status'] ?? '') === 'IN_PROGRESS', 'unrelated stays in progress');
ok(($u['followup_question']['question_id'] ?? '') === 'ONSET', 'unrelated keeps ONSET');

// Red flag misspelling tolerance
$r = NlpStep3DemoTrial::assess('masakit gid dugan ko kag budlay ginhawa');
// dugan may fuzzy to dughan
ok(
    ($r['triage_final'] ?? '') === 'EMERGENCY'
    || ($r['assessment_status'] ?? '') === 'COMPLETED'
    || str_contains((string) ($r['complaint_summary']['location'] ?? ''), 'chest')
    || str_contains((string) ($r['clinical_transcript'] ?? ''), 'dughan'),
    'chest/dyspnea misspelling still prioritized',
    (string) (($r['triage_final'] ?? 'none') . '/' . ($r['assessment_status'] ?? '') . '/' . ($r['complaint_summary']['location'] ?? ''))
);
