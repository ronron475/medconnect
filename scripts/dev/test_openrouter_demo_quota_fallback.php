<?php
/**
 * Mock: Gemini HTTP 429 on the clinical interview demo tries OpenRouter once.
 * No live Gemini or OpenRouter call.
 *
 * Usage: php scripts/dev/test_openrouter_demo_quota_fallback.php
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

$system = 'Return JSON only. Never assign Emergency, Urgent, or Non-Urgent.';
$user = 'Patient input: I have a headache since yesterday';
$payload = [
    'systemInstruction' => ['parts' => [['text' => $system]]],
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => $user]],
    ]],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 1024,
        'responseMimeType' => 'application/json',
    ],
];
$modelJson = '{"classification":"HEALTH_RELATED","confidence":0.9,"patient_subject":"HUMAN","question_needed":true,"interview_sufficient":false,"next_question":"Where is the pain?","clinical_facts":{"symptom":"headache"},"triage_level":"EMERGENCY"}';

$called = 0;
$text = medconnect_demo_openrouter_quota_text(
    'railway gemini failed: HTTP 429: You exceeded your current quota',
    $payload,
    static function (array $body) use (&$called, $system, $user, $modelJson): ?string {
        $called++;
        if (($body['model'] ?? '') !== MEDCONNECT_OPENROUTER_DEMO_MODEL) {
            return null;
        }
        $messages = $body['messages'] ?? [];
        if (($messages[0]['role'] ?? '') !== 'system' || ($messages[0]['content'] ?? '') !== $system) {
            return null;
        }
        if (($messages[1]['role'] ?? '') !== 'user' || ($messages[1]['content'] ?? '') !== $user) {
            return null;
        }

        return $modelJson;
    }
);
ok('HTTP 429 calls OpenRouter once', $called === 1, 'calls=' . $called);
ok('HTTP 429 returns OpenRouter text', $text === $modelJson);
ok('fixed free model id', MEDCONNECT_OPENROUTER_DEMO_MODEL === 'google/gemma-4-31b-it:free');

$parse = new ReflectionMethod(GeminiClinicalInterviewDemo::class, 'parseJson');
$parse->setAccessible(true);
$parsed = $parse->invoke(null, (string) $text, 'start');
ok('existing parser accepts OpenRouter text', is_array($parsed));
ok(
    'parser keeps health classification',
    is_array($parsed) && ($parsed['classification'] ?? '') === GeminiClinicalInterviewDemo::CLASS_HEALTH
);
$demoSrc = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/GeminiClinicalInterviewDemo.php'));
ok('demo still drops model acuity before the engine', str_contains($demoSrc, "unset(\n                \$parsed['triage'],"));
ok('ClinicalTriageEngine has no OpenRouter hook', !str_contains(
    (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/ClinicalTriageEngine.php'),
    'OPENROUTER'
));

foreach ([
    'Gemini HTTP 400: bad request',
    'Gemini HTTP 500: internal',
    'Gemini HTTP 502: bad gateway',
    'Gemini HTTP 503: high demand',
    'Gemini HTTP 503: You exceeded your current quota',
] as $error) {
    $called = 0;
    $result = medconnect_demo_openrouter_quota_text($error, $payload, static function () use (&$called): ?string {
        $called++;
        return '{"classification":"HEALTH_RELATED"}';
    });
    ok('no OpenRouter for ' . $error, $called === 0 && $result === null, 'calls=' . $called);
}

$called = 0;
$miss = medconnect_demo_openrouter_quota_text('Gemini HTTP 429: quota', $payload, static function () use (&$called): ?string {
    $called++;
    return null;
});
ok('failed OpenRouter is one attempt', $called === 1, 'calls=' . $called);
ok('failed OpenRouter returns null', $miss === null);

putenv('OPENROUTER_API_KEY');
unset($_ENV['OPENROUTER_API_KEY']);
ok(
    'missing OPENROUTER_API_KEY returns null',
    medconnect_demo_openrouter_quota_text('Gemini HTTP 429: quota', $payload) === null
);
ok(
    'key check reports missing and does not return the key',
    medconnect_demo_openrouter_key_is_configured() === false
);
ok(
    'server config api_key is accepted',
    medconnect_demo_openrouter_api_key_from_config_value(['api_key' => 'unit-test-key']) !== ''
);
ok(
    'empty server config api_key is ignored',
    medconnect_demo_openrouter_api_key_from_config_value(['api_key' => '   ']) === ''
);

$systemMethod = new ReflectionMethod(GeminiClinicalInterviewDemo::class, 'systemPrompt');
$systemMethod->setAccessible(true);
$expectedSystem = (string) $systemMethod->invoke(null);

putenv('MEDCONNECT_PHP_NLP_ONLY=0');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '0';
putenv('AI_ENABLED=true');
$_ENV['AI_ENABLED'] = 'true';
putenv('AI_PROVIDER=gemini');
$_ENV['AI_PROVIDER'] = 'gemini';
putenv('AI_API_KEY=probe-not-sent');
$_ENV['AI_API_KEY'] = 'probe-not-sent';

$complaint = 'I have a headache since yesterday';
$sharedJson = json_encode([
    'classification' => 'HEALTH_RELATED',
    'confidence' => 0.95,
    'patient_subject' => 'HUMAN',
    'is_human_patient_complaint' => true,
    'normalized_health_concern' => 'headache',
    'question_needed' => false,
    'interview_sufficient' => true,
    'next_question' => '',
    'answer_status' => 'VALID',
    'clinical_facts' => [
        'symptom' => 'unicornitis',
        'location' => 'head',
        'pain_score' => 9,
    ],
    'triage_level' => 'EMERGENCY',
    'diagnosis' => 'migraine',
], JSON_UNESCAPED_UNICODE);

$seen = ['n' => 0, 'system' => '', 'user' => ''];
GeminiClinicalInterviewDemo::beginOpenRouterQuotaProbeForTest(static function (array $body) use (&$seen, $expectedSystem, $sharedJson): ?string {
    $seen['n']++;
    $messages = $body['messages'] ?? [];
    $seen['system'] = (string) ($messages[0]['content'] ?? '');
    $seen['user'] = (string) ($messages[1]['content'] ?? '');
    if ($seen['system'] !== $expectedSystem) {
        return null;
    }

    return $sharedJson;
});
try {
    $started = GeminiClinicalInterviewDemo::start($complaint);
} finally {
    GeminiClinicalInterviewDemo::endOpenRouterQuotaProbeForTest();
}

ok('probe calls OpenRouter once with the Gemini system prompt', $seen['n'] === 1 && $seen['system'] === $expectedSystem, 'calls=' . $seen['n']);
ok('same user context includes the complaint and NLP block', str_contains($seen['user'], 'MODE: START_INTERVIEW') && str_contains($seen['user'], $complaint));
ok('health gate accepts the shared JSON', ($started['health_classification'] ?? '') === 'HEALTH_RELATED' || ($started['debug']['health_gate']['passed'] ?? false) === true || ($started['status'] ?? '') === 'final_triage');
$facts = is_array($started['clinical_facts'] ?? null) ? $started['clinical_facts'] : [];
$symptom = strtolower((string) ($facts['symptom'] ?? ''));
ok('grounding drops the invented symptom', $symptom !== 'unicornitis', $symptom);
ok('grounding keeps the NLP location', strtolower((string) ($facts['location'] ?? '')) === 'head');
ok('model pain score without patient number is dropped', ($facts['pain_score'] ?? null) === null);
$final = is_array($started['final_triage'] ?? null) ? $started['final_triage'] : [];
$engine = is_array($started['debug']['engine_result'] ?? null) ? $started['debug']['engine_result'] : [];
ok('final authority is ClinicalTriageEngine', ($final['final_authority'] ?? '') === 'ClinicalTriageEngine', (string) ($final['final_authority'] ?? ''));
ok('final level comes from the engine result', ($final['triage_level'] ?? null) === (string) ($engine['triage_level'] ?? ''), (string) ($final['triage_level'] ?? ''));
ok('model EMERGENCY label is not copied as the engine level', ($final['triage_display'] ?? '') !== 'EMERGENCY' || (string) ($engine['triage_display'] ?? '') === 'EMERGENCY');
$openRouterSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/includes/openrouter_demo_fallback.php');
ok('OpenRouter file has no triage authority', !str_contains($openRouterSrc, 'ClinicalTriageEngine') && !str_contains($openRouterSrc, 'triage_display'));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
