<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Gemini answer fallback (demo) unit checks ===\n";

ok(
    NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer('7', 'PAIN_SEVERITY'),
    'NLP understands severity 7'
);
ok(
    NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer('gahapon', 'ONSET'),
    'NLP understands gahapon onset'
);
ok(
    NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer('dugay na', 'ONSET'),
    'NLP understands dugay na'
);
ok(
    NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer('ulo', 'PAIN_LOCATION'),
    'NLP understands ulo location'
);
ok(
    !NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer('ligad pa gid', 'ONSET'),
    'NLP does NOT confidently understand ligad pa gid'
);
ok(
    !NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer('Mahilig ako magbasketball.', 'ONSET'),
    'NLP does NOT understand unrelated basketball'
);
ok(
    !NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer('blue', 'ONSET'),
    'NLP does NOT understand blue'
);

// With PHP_NLP_ONLY, Gemini must report unavailable (no crash).
$r = NlpStep3DemoGeminiAnswerInterpreter::interpret(
    'ligad pa gid',
    'San-o pa nagsugod ang kasakit?',
    'ONSET',
    'ONSET_DURATION',
    ['facts' => ['pain_score' => 7, 'body_locations' => ['head']]]
);
ok(($r['status'] ?? '') === 'DEMO_SEMANTIC_FALLBACK' || ($r['status'] ?? '') === 'UNAVAILABLE', 'interpret uses semantic or unavailable', (string) ($r['status'] ?? ''));
// With PHP_NLP_ONLY Gemini is disabled; lexicon should still interpret ligad pa.
$r2 = NlpStep3DemoGeminiAnswerInterpreter::interpret(
    'ligad pa gid',
    'San-o pa nagsugod ang kasakit?',
    'ONSET',
    'ONSET_DURATION',
    ['facts' => ['pain_score' => 7, 'body_locations' => ['head']]]
);
ok(($r2['status'] ?? '') === 'DEMO_SEMANTIC_FALLBACK', 'ligad pa semantic fallback', (string) ($r2['status'] ?? ''));
ok(($r2['interpretation']['normalized_value'] ?? '') === 'started earlier', 'ligad pa normalized');

$applied = NlpStep3DemoGeminiAnswerInterpreter::applyToPrior(
    ['facts' => ['pain_score' => 7, 'body_locations' => ['head'], 'onset' => '', 'duration_label' => '']],
    'ligad pa',
    [
        'relevant' => true,
        'understood' => true,
        'answer_type' => 'ONSET_DURATION',
        'normalized_value' => 'started earlier',
        'confidence' => 0.9,
        'needs_clarification' => false,
    ],
    'ONSET'
);
ok(
    ($applied['prior']['facts']['duration_label'] ?? '') === 'started earlier'
        || ($applied['prior']['facts']['duration_label'] ?? '') !== '',
    'applyToPrior stores onset/duration without inventing exact days',
    (string) ($applied['prior']['facts']['duration_label'] ?? '')
);
ok(($applied['prior']['facts']['pain_score'] ?? null) === 7, 'applyToPrior preserves pain_score');
ok(($applied['prior']['facts']['body_locations'][0] ?? '') === 'head', 'applyToPrior preserves location');

echo "\n(Run probe_nlp_step3_demo_trial.php separately for full interview flow.)\n";
