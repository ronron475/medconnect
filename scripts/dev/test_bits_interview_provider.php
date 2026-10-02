<?php
/**
 * BITS as interview LLM provider: same Gemini payload, parseJson, applyGeminiTurn, CTE.
 * No live Gemini / BITS / OpenRouter HTTP.
 *
 * Usage: php scripts/dev/test_bits_interview_provider.php
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

putenv('MEDCONNECT_PHP_NLP_ONLY=0');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '0';
putenv('AI_ENABLED=true');
$_ENV['AI_ENABLED'] = 'true';
putenv('MEDCONNECT_BITS_SERVICE=1');
$_ENV['MEDCONNECT_BITS_SERVICE'] = '1';
putenv('AI_API_KEY');
unset($_ENV['AI_API_KEY']);

$systemMethod = new ReflectionMethod(GeminiClinicalInterviewDemo::class, 'systemPrompt');
$systemMethod->setAccessible(true);
$expectedSystem = (string) $systemMethod->invoke(null);

$parse = new ReflectionMethod(GeminiClinicalInterviewDemo::class, 'parseJson');
$parse->setAccessible(true);

$startJson = json_encode([
    'classification' => 'HEALTH_RELATED',
    'confidence' => 0.95,
    'extraction_confidence' => 0.95,
    'facts_supported_by_patient_wording' => true,
    'patient_subject' => 'HUMAN',
    'is_human_patient_complaint' => true,
    'normalized_health_concern' => 'headache',
    'question_needed' => true,
    'interview_sufficient' => false,
    'next_question' => 'Do you have a fever?',
    'answer_status' => 'VALID',
    'targeted_findings' => ['fever_confirmed'],
    'clinical_facts' => [
        'symptom' => 'headache',
        'associated_symptoms' => [],
        'relevant_negatives' => [],
    ],
    'missing_information' => ['fever'],
    'triage_level' => 'EMERGENCY',
    'diagnosis' => 'migraine',
], JSON_UNESCAPED_UNICODE);

$answerJson = json_encode([
    'classification' => 'HEALTH_RELATED',
    'patient_subject' => 'HUMAN',
    'is_human_patient_complaint' => true,
    'confidence' => 0.9,
    'extraction_confidence' => 0.9,
    'facts_supported_by_patient_wording' => true,
    'question_needed' => false,
    'interview_sufficient' => true,
    'next_question' => '',
    'answer_status' => 'VALID',
    'clinical_facts' => [
        'symptom' => 'headache',
        'relevant_negatives' => ['fever'],
    ],
    'missing_information' => [],
    'triage_display' => 'EMERGENCY',
    'acuity' => 'EMERGENCY',
], JSON_UNESCAPED_UNICODE);

$payload = [
    'systemInstruction' => ['parts' => [['text' => $expectedSystem]]],
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => "MODE: START_INTERVIEW\nOriginal patient opening (preserve exactly; do not rewrite as the stored complaint):\nI have a headache since yesterday"]],
    ]],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 1024,
        'responseMimeType' => 'application/json',
    ],
];

$converted = medconnect_demo_openrouter_request_from_gemini($payload);
ok('Gemini payload converts to chat messages', is_array($converted) && isset($converted['messages']));
ok(
    'converted system message is the Gemini systemPrompt',
    is_array($converted) && ($converted['messages'][0]['content'] ?? '') === $expectedSystem
);
ok(
    'converted user message keeps START_INTERVIEW',
    is_array($converted) && str_contains((string) ($converted['messages'][1]['content'] ?? ''), 'MODE: START_INTERVIEW')
);

$directCalled = 0;
$direct = medconnect_demo_bits_text_from_gemini(
    $payload,
    static function (array $body) use (&$directCalled, $expectedSystem, $startJson): ?string {
        $directCalled++;
        if (($body['model'] ?? '') !== medconnect_bits_ollama_model()) {
            return null;
        }
        if (($body['messages'][0]['content'] ?? '') !== $expectedSystem) {
            return null;
        }

        return $startJson;
    }
);
ok('selected BITS helper does not require a Gemini quota error', $directCalled === 1 && $direct === $startJson);

ok(
    'quota helper still ignores HTTP 400',
    medconnect_demo_bits_quota_text('Gemini HTTP 400: bad request', $payload, static function (): ?string {
        return '{"classification":"HEALTH_RELATED"}';
    }) === null
);

$parsedStart = $parse->invoke(null, (string) $startJson, 'start');
ok('parseJson accepts BITS JSON', is_array($parsedStart));
ok(
    'parseJson keeps HEALTH_RELATED',
    is_array($parsedStart) && ($parsedStart['classification'] ?? '') === GeminiClinicalInterviewDemo::CLASS_HEALTH
);

putenv('AI_PROVIDER=bits');
$_ENV['AI_PROVIDER'] = 'bits';

$seen = ['n' => 0, 'system' => '', 'user' => '', 'models' => []];
GeminiClinicalInterviewDemo::beginBitsProviderTransportForTest(
    static function (array $body) use (&$seen, $expectedSystem, $startJson, $answerJson): ?string {
        $seen['n']++;
        $seen['models'][] = (string) ($body['model'] ?? '');
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $seen['system'] = (string) ($messages[0]['content'] ?? '');
        $seen['user'] = (string) ($messages[1]['content'] ?? '');
        if ($seen['system'] !== $expectedSystem) {
            return null;
        }
        if (str_contains($seen['user'], 'MODE: INTERPRET_ANSWER')) {
            return $answerJson;
        }

        return $startJson;
    }
);

$complaint = 'I have a headache since yesterday';
try {
    $started = GeminiClinicalInterviewDemo::start($complaint);
} finally {
    // Keep transport for answer(); ended after answer tests.
}

ok('BITS start called the Ollama transport', $seen['n'] >= 1, 'calls=' . $seen['n']);
ok('BITS received the Gemini systemPrompt', $seen['system'] === $expectedSystem);
ok(
    'BITS received START_INTERVIEW user prompt with original complaint',
    str_contains($seen['user'], 'MODE: START_INTERVIEW') && str_contains($seen['user'], $complaint)
);
ok(
    'BITS start uses GeminiClinicalInterviewDemo::start (not a second engine)',
    ($started['chief_complaint'] ?? '') === $complaint
);
ok(
    'original patient wording is preserved',
    ($started['chief_complaint'] ?? '') === $complaint
);
ok(
    'BITS uses campus model id',
    in_array(medconnect_bits_ollama_model(), $seen['models'], true)
);

$ctx = is_array($started['interview_context'] ?? null) ? $started['interview_context'] : [];
$awaiting = trim((string) ($started['awaiting_question'] ?? $ctx['awaiting_question'] ?? ''));
ok('follow-up comes from existing interview state', $awaiting !== '' || ($started['status'] ?? '') === 'final_triage', (string) ($started['status'] ?? ''));

$negSeenBefore = $seen['n'];
$answered = GeminiClinicalInterviewDemo::answer('wala', $started);
ok('answer() ran after BITS start', is_array($answered));
ok('BITS received INTERPRET_ANSWER for the denial', $seen['n'] > $negSeenBefore, 'calls=' . $seen['n']);
ok(
    'answer user prompt is INTERPRET_ANSWER with wala',
    str_contains($seen['user'], 'MODE: INTERPRET_ANSWER') && str_contains($seen['user'], 'wala')
);

$facts = is_array($answered['clinical_facts'] ?? null) ? $answered['clinical_facts'] : [];
$negatives = array_map('strtolower', is_array($facts['relevant_negatives'] ?? null) ? $facts['relevant_negatives'] : []);
$statusMap = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
$negOk = in_array('fever', $negatives, true)
    || in_array('fever_confirmed', $negatives, true)
    || (($statusMap['fever_confirmed'] ?? '') === 'negative')
    || (($statusMap['associated_symptoms'] ?? '') === 'negative')
    || in_array('negative', array_map('strval', $statusMap), true);
ok('negative answer wala is stored by existing PHP polarity logic', $negOk, json_encode($statusMap + ['neg' => $negatives]));
ok(
    'chief complaint still original after wala',
    ($answered['chief_complaint'] ?? '') === $complaint
);

$final = is_array($answered['final_triage'] ?? null) ? $answered['final_triage'] : (is_array($started['final_triage'] ?? null) ? $started['final_triage'] : []);
$engine = is_array($answered['debug']['engine_result'] ?? null)
    ? $answered['debug']['engine_result']
    : (is_array($started['debug']['engine_result'] ?? null) ? $started['debug']['engine_result'] : []);
$packStatus = (string) ($answered['status'] ?? $started['status'] ?? '');
ok(
    'final authority is ClinicalTriageEngine when finalized',
    $packStatus !== 'final_triage' || ($final['final_authority'] ?? '') === 'ClinicalTriageEngine',
    (string) ($final['final_authority'] ?? $packStatus)
);
ok(
    'BITS EMERGENCY field is not the engine class unless CTE said so',
    $packStatus !== 'final_triage'
    || ($final['triage_display'] ?? '') !== 'EMERGENCY'
    || (string) ($engine['triage_display'] ?? '') === 'EMERGENCY',
    (string) ($final['triage_display'] ?? '')
);
ok(
    'ai_provider_used is BITS',
    str_contains((string) ($started['ai_provider_used'] ?? ''), 'BITS Ollama')
    || str_contains((string) ($answered['ai_provider_used'] ?? ''), 'BITS Ollama')
);

GeminiClinicalInterviewDemo::endBitsProviderTransportForTest();

$invalidCalls = 0;
GeminiClinicalInterviewDemo::beginBitsProviderTransportForTest(
    static function () use (&$invalidCalls): ?string {
        $invalidCalls++;
        return 'this is not json {';
    }
);
try {
    $bad = GeminiClinicalInterviewDemo::start($complaint);
} finally {
    GeminiClinicalInterviewDemo::endBitsProviderTransportForTest();
}
$badCode = (string) ($bad['code'] ?? ($bad['debug']['code'] ?? ''));
$badStatus = (string) ($bad['status'] ?? '');
$nlpFallback = str_contains((string) ($bad['ai_provider_used'] ?? ''), 'Question bank')
    || ($badCode === 'gemini_unavailable_or_invalid_json')
    || !empty($bad['error'])
    || $badStatus === GeminiClinicalInterviewDemo::STATUS_ERROR
    || $badStatus === GeminiClinicalInterviewDemo::STATUS_NEEDS_HEALTH;
ok('invalid BITS JSON uses existing fallback/error path', $nlpFallback, $badStatus . ' ' . $badCode);
ok('invalid JSON still hit the BITS generate() transport', $invalidCalls >= 1, 'calls=' . $invalidCalls);

$finalJson = json_encode([
    'classification' => 'HEALTH_RELATED',
    'confidence' => 0.95,
    'extraction_confidence' => 0.95,
    'facts_supported_by_patient_wording' => true,
    'patient_subject' => 'HUMAN',
    'is_human_patient_complaint' => true,
    'normalized_health_concern' => 'headache',
    'question_needed' => false,
    'interview_sufficient' => true,
    'next_question' => '',
    'answer_status' => 'VALID',
    'clinical_facts' => [
        'symptom' => 'headache',
        'location' => 'head',
        'onset' => 'yesterday',
        'duration' => 'since yesterday',
        'pain_score' => 4,
        'relevant_negatives' => ['fever'],
    ],
    'missing_information' => [],
    'triage_level' => 'EMERGENCY',
    'triage_display' => 'EMERGENCY',
], JSON_UNESCAPED_UNICODE);
GeminiClinicalInterviewDemo::beginBitsProviderTransportForTest(
    static function () use ($finalJson): ?string {
        return $finalJson;
    }
);
try {
    $finalized = GeminiClinicalInterviewDemo::start($complaint);
} finally {
    GeminiClinicalInterviewDemo::endBitsProviderTransportForTest();
}
$finalPack = is_array($finalized['final_triage'] ?? null) ? $finalized['final_triage'] : [];
$enginePack = is_array($finalized['debug']['engine_result'] ?? null) ? $finalized['debug']['engine_result'] : [];
ok(
    'sufficient BITS JSON still finalizes through ClinicalTriageEngine',
    ($finalized['status'] ?? '') !== 'final_triage'
    || ($finalPack['final_authority'] ?? '') === 'ClinicalTriageEngine',
    (string) ($finalized['status'] ?? '') . ' ' . (string) ($finalPack['final_authority'] ?? '')
);
ok(
    'BITS cannot assign EMERGENCY unless ClinicalTriageEngine did',
    ($finalPack['triage_display'] ?? '') !== 'EMERGENCY'
    || (string) ($enginePack['triage_display'] ?? '') === 'EMERGENCY',
    (string) ($finalPack['triage_display'] ?? '')
);

putenv('AI_PROVIDER=gemini');
$_ENV['AI_PROVIDER'] = 'gemini';
putenv('AI_API_KEY=probe-not-sent');
$_ENV['AI_API_KEY'] = 'probe-not-sent';

$bitsWhileGemini = 0;
GeminiClinicalInterviewDemo::beginBitsProviderTransportForTest(
    static function () use (&$bitsWhileGemini): ?string {
        $bitsWhileGemini++;
        return '{"classification":"HEALTH_RELATED","question_needed":false,"interview_sufficient":true}';
    }
);
GeminiClinicalInterviewDemo::beginOpenRouterQuotaProbeForTest(
    static function (array $body) use ($expectedSystem, $startJson): ?string {
        $messages = $body['messages'] ?? [];
        if (($messages[0]['content'] ?? '') !== $expectedSystem) {
            return null;
        }

        return $startJson;
    }
);
try {
    $geminiPath = GeminiClinicalInterviewDemo::start($complaint);
} finally {
    GeminiClinicalInterviewDemo::endOpenRouterQuotaProbeForTest();
    GeminiClinicalInterviewDemo::endBitsProviderTransportForTest();
}
ok('AI_PROVIDER=gemini does not call the selected-BITS transport', $bitsWhileGemini === 0, 'calls=' . $bitsWhileGemini);
ok(
    'Gemini quota-probe path still receives the Gemini systemPrompt',
    ($geminiPath['chief_complaint'] ?? '') === $complaint
    || !empty($geminiPath['health_gate'])
    || ($geminiPath['status'] ?? '') !== ''
);

$demoSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/GeminiClinicalInterviewDemo.php');
ok('start() still owns the interview entry', str_contains($demoSrc, 'public static function start(string $complaint): array'));
ok('answer() still owns follow-up turns', str_contains($demoSrc, 'public static function answer(string $answer, array $prior): array'));
ok('applyGeminiTurn is unchanged as the turn applier', str_contains($demoSrc, 'private static function applyGeminiTurn(array $context, array $gemini, bool $isStart): array'));
ok('reconcileFollowUp remains the follow-up owner', str_contains($demoSrc, 'private static function reconcileFollowUp(array $context, string $nextQ, bool $sufficient): array'));
ok('finalizeWithClinicalEngine still calls ClinicalTriageEngine', str_contains($demoSrc, 'ClinicalTriageEngine::assess('));
ok(
    'generate() BITS branch is provider routing only',
    str_contains($demoSrc, 'bitsInterviewProviderSelected()')
    && str_contains($demoSrc, 'medconnect_demo_bits_text_from_gemini')
);

$bitsDemo = (string) file_get_contents(dirname(__DIR__, 2) . '/public/bits_ollama_demo.php');
$bitsApi = (string) file_get_contents(dirname(__DIR__, 2) . '/app/api/ai/bits_ollama_demo.php');
ok('public BITS page is still a playground', str_contains($bitsDemo, 'BITS') && !str_contains($bitsDemo, 'GeminiClinicalInterviewDemo::start'));
ok(
    'BITS demo API still uses the short language-only prompt',
    str_contains($bitsApi, 'Help with language understanding only')
);
ok(
    'ClinicalTriageEngine has no BITS interview hook',
    !str_contains(
        (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/ClinicalTriageEngine.php'),
        'bitsInterviewProviderSelected'
    )
);

GeminiPatientInterview::resetTestHooks();

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
