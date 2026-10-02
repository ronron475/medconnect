<?php
/**
 * Shared interview controller: laterality answers follow the active slot.
 * Gemini and BITS both end in applyGeminiTurn. No live HTTP.
 *
 * Usage: php scripts/dev/test_laterality_slot_answer.php
 */
require __DIR__ . '/nlp_cli_bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/includes/openrouter_demo_fallback.php';

$fail = 0;
$pass = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        echo "PASS  $label" . ($detail !== '' ? " [$detail]" : '') . "\n";
        $pass++;
        return;
    }
    echo "FAIL  $label" . ($detail !== '' ? " [$detail]" : '') . "\n";
    $fail++;
}

function callDemo(string $method, array $args): mixed
{
    $m = new ReflectionMethod(GeminiClinicalInterviewDemo::class, $method);
    $m->setAccessible(true);

    return $m->invokeArgs(null, $args);
}

$question = 'Sa imo kamot, wala ukon tuo ang masakit?';
$badModel = [
    'classification' => 'HEALTH_RELATED',
    'patient_subject' => 'HUMAN',
    'answer_status' => 'VALID',
    'clinical_facts' => [
        'symptom' => 'pain',
        'location' => 'hand',
        'laterality' => 'negative',
        'finding_status' => ['laterality' => 'negative'],
    ],
    'missing_information' => ['onset'],
    'question_needed' => true,
    'next_question' => 'San-o nagsugod ang sakit sa imo kamot?',
    'interview_sufficient' => false,
    'targeted_findings' => ['laterality'],
    'facts_supported_by_patient_wording' => true,
    'extraction_confidence' => 0.95,
];

ok('laterality slot ignores wala-as-negation', callDemo('answerPolarityForSlot', ['laterality', 'sa wala']) === '');
ok('bare wala on laterality is a side, not a denial', callDemo('canonicalLaterality', ['wala', true]) === 'left');
ok('sa wala canonical side is left', callDemo('canonicalLaterality', ['sa wala', true]) === 'left');
ok('sa tuo is right', callDemo('canonicalLaterality', ['sa tuo', true]) === 'right');
ok('kaliwa is left', callDemo('canonicalLaterality', ['kaliwa', false]) === 'left');
ok('both sides stay both', callDemo('canonicalLaterality', ['both sides', true]) === 'both');
ok('yes/no wala stays negative', callDemo('answerPolarityForSlot', ['associated_symptoms', 'wala']) === 'negative');
ok('indi on trauma stays negative', callDemo('answerPolarityForSlot', ['trauma', 'indi']) === 'negative');
ok('ambot on laterality is uncertain', callDemo('answerPolarityForSlot', ['laterality', 'ambot']) === 'uncertain');
ok('I don\'t know is uncertain', callDemo('answerPolarityForSlot', ['onset', "I don't know"]) === 'uncertain');

$absorbed = callDemo('absorbDirectAnswer', [callDemo('blankFacts', []), $question, 'sa wala']);
ok('absorb stores left', ($absorbed['laterality'] ?? '') === 'left', var_export($absorbed['laterality'] ?? null, true));
ok('absorb does not mark laterality negative', ($absorbed['finding_status']['laterality'] ?? '') === 'positive');
ok('absorb does not invent a pain score', ($absorbed['pain_score'] ?? null) === null);

$denied = callDemo('absorbDirectAnswer', [callDemo('blankFacts', []), 'May ara pa iban nga nabatyagan mo?', 'wala']);
ok('yes/no wala still marks the asked slot negative', ($denied['finding_status']['associated_symptoms'] ?? '') === 'negative');
ok('yes/no wala does not become left', ($denied['laterality'] ?? null) === null);

$max = (new ReflectionClass(GeminiClinicalInterviewDemo::class))->getReflectionConstant('MAX_FOLLOWUP_QUESTIONS');
ok('follow-up hard cap remains 4', $max && (int) $max->getValue() === 4, (string) ($max ? $max->getValue() : ''));
$preferred = (new ReflectionClass(GeminiClinicalInterviewDemo::class))->getReflectionConstant('PREFERRED_FOLLOWUP_QUESTIONS');
ok('follow-up target remains 3', $preferred && (int) $preferred->getValue() === 3);

$finalizeSrc = (string) (new ReflectionMethod(GeminiClinicalInterviewDemo::class, 'finalizeWithClinicalEngine'))->getFileName();
$src = (string) file_get_contents($finalizeSrc);
ok(
    'final triage still calls ClinicalTriageEngine::assess',
    str_contains($src, 'ClinicalTriageEngine::assess(')
);

function swellingContext(): array
{
    $ctx = callDemo('blankContext', ['gahubag akon kamot']);
    $ctx['question_language'] = 'hiligaynon';
    $ctx['detected_language'] = 'HILIGAYNON';
    $nlp = callDemo('collectLocalNlpEvidence', ['gahubag akon kamot']);
    $ctx['clinical_facts'] = callDemo('factsFromNlpEvidence', [$nlp]);
    $ctx['clinical_facts'] = callDemo('bindComplaintFacts', [$ctx['clinical_facts'], $ctx, 'gahubag akon kamot', true]);
    $ctx['awaiting_question'] = 'Sa imo kamot, wala ukon tuo ang masakit?';
    $ctx['awaiting_slot'] = 'laterality';
    $ctx['awaiting_complaint_id'] = 'c1';
    $ctx['active_complaint_id'] = 'c1';
    $ctx['awaiting_target_findings'] = ['laterality'];
    $ctx['conversation'] = [
        ['role' => 'patient', 'text' => 'gahubag akon kamot', 'kind' => 'complaint'],
        ['role' => 'gemini', 'text' => 'Sa imo kamot, wala ukon tuo ang masakit?', 'kind' => 'followup'],
    ];
    $ctx['status'] = 'interviewing';

    return $ctx;
}

function assertSideResolved(array $out, string $label): void
{
    $facts = is_array($out['clinical_facts'] ?? null) ? $out['clinical_facts'] : [];
    $status = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
    $next = (string) ($out['awaiting_question'] ?? '');
    $nextSlot = (string) callDemo('questionFactSlot', [$next]);
    ok($label . ' laterality is left', ($facts['laterality'] ?? '') === 'left', var_export($facts['laterality'] ?? null, true));
    ok($label . ' laterality is not negative', ($status['laterality'] ?? '') !== 'negative', (string) ($status['laterality'] ?? ''));
    ok($label . ' pain score was not invented', ($facts['pain_score'] ?? null) === null);
    $symptom = mb_strtolower(trim((string) ($facts['symptom'] ?? '')));
    ok($label . ' symptom was not rewritten as pain', !in_array($symptom, ['pain', 'sakit', 'chronic pain'], true), $symptom);
    ok($label . ' next question is a different slot', $nextSlot !== '' && $nextSlot !== 'laterality', $nextSlot . ' / ' . $next);
    ok(
        $label . ' next question does not invent pain',
        !preg_match('/\b(sakit|masakit|pain|hapdi|kasakit|gasakit)\b/ui', $next),
        $next
    );
    $missing = is_array($out['missing_information'] ?? null) ? $out['missing_information'] : [];
    $asksLaterality = false;
    foreach ($missing as $entry) {
        if (callDemo('missingSlotId', [(string) $entry]) === 'laterality') {
            $asksLaterality = true;
        }
    }
    ok($label . ' resolved laterality is not still missing', !$asksLaterality, json_encode($missing));
    ok(
        $label . ' authority field stays with the triage engine when finalized',
        ($out['status'] ?? '') !== 'final_triage' || (($out['final_triage']['final_authority'] ?? '') === 'ClinicalTriageEngine')
    );
}

$geminiCtx = swellingContext();
$geminiCtx['patient_turns'][] = 'sa wala';
$geminiCtx['conversation'][] = ['role' => 'patient', 'text' => 'sa wala', 'kind' => 'answer'];
$geminiOut = callDemo('applyGeminiTurn', [$geminiCtx, $badModel, false]);
assertSideResolved($geminiOut, 'Gemini');

putenv('MEDCONNECT_PHP_NLP_ONLY=0');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '0';
putenv('AI_ENABLED=true');
$_ENV['AI_ENABLED'] = 'true';
putenv('AI_PROVIDER=bits');
$_ENV['AI_PROVIDER'] = 'bits';
putenv('MEDCONNECT_BITS_SERVICE=1');
$_ENV['MEDCONNECT_BITS_SERVICE'] = '1';
putenv('AI_API_KEY');
unset($_ENV['AI_API_KEY']);

$bitsJson = json_encode($badModel, JSON_UNESCAPED_UNICODE);
GeminiClinicalInterviewDemo::beginBitsProviderTransportForTest(
    static function (array $body) use ($bitsJson): ?string {
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $user = (string) ($messages[1]['content'] ?? '');
        if (str_contains($user, 'MODE: INTERPRET_ANSWER') || str_contains($user, 'sa wala') || str_contains($user, 'ambot')) {
            return $bitsJson;
        }

        return '{"next_question":"San-o nagsugod ang imo kamot?"}';
    }
);
try {
    $bitsOut = GeminiClinicalInterviewDemo::answer('sa wala', swellingContext());
} finally {
    GeminiClinicalInterviewDemo::endBitsProviderTransportForTest();
}
ok('BITS answer used the campus provider label', ($bitsOut['ai_provider_used'] ?? '') === 'BITS Ollama (Gemini quota fallback)', (string) ($bitsOut['ai_provider_used'] ?? ''));
assertSideResolved($bitsOut, 'BITS');

$unsureModel = $badModel;
$unsureModel['answer_status'] = 'UNCERTAIN';
$unsureModel['clinical_facts'] = ['laterality' => 'negative', 'symptom' => 'pain', 'finding_status' => ['laterality' => 'negative']];
$unsureCtx = swellingContext();
$unsureCtx['patient_turns'][] = 'ambot';
$unsureCtx['conversation'][] = ['role' => 'patient', 'text' => 'ambot', 'kind' => 'answer'];
$unsureOut = callDemo('applyGeminiTurn', [$unsureCtx, $unsureModel, false]);
$unsureFacts = is_array($unsureOut['clinical_facts'] ?? null) ? $unsureOut['clinical_facts'] : [];
$unsureStatus = (string) ($unsureFacts['finding_status']['laterality'] ?? '');
$unsureNext = (string) callDemo('questionFactSlot', [(string) ($unsureOut['awaiting_question'] ?? '')]);
ok('uncertain answer is not stored as left or negative', !in_array($unsureStatus, ['positive', 'negative'], true) || ($unsureFacts['laterality'] ?? null) === null, $unsureStatus . ' / ' . var_export($unsureFacts['laterality'] ?? null, true));
ok('uncertain laterality is resolved', $unsureStatus === 'uncertain' || in_array('laterality', (array) ($unsureOut['interview_context']['asked_slots'] ?? []), true), $unsureStatus);
ok('uncertain laterality is not asked again', $unsureNext !== 'laterality', $unsureNext);

$engineCtx = swellingContext();
$engineCtx['clinical_facts']['laterality'] = 'left';
$engineCtx['clinical_facts']['finding_status']['laterality'] = 'positive';
$final = callDemo('finalizeWithClinicalEngine', [$engineCtx, $badModel]);
ok(
    'finalized display comes from ClinicalTriageEngine',
    ($final['final_triage']['final_authority'] ?? '') === 'ClinicalTriageEngine'
        && in_array((string) ($final['final_triage']['triage_display'] ?? ''), ['EMERGENCY', 'URGENT', 'NON-URGENT'], true),
    (string) ($final['final_triage']['triage_display'] ?? '')
);
ok(
    'model acuity fields are not the authority',
    ($final['final_triage']['final_authority'] ?? '') !== 'gemini'
        && ($final['final_triage']['final_authority'] ?? '') !== 'bits'
);

echo ($fail === 0 ? "OK" : "FAILED") . "  pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
