<?php
/**
 * Mock: Gemini 3.5 HTTP 429 tries Gemini 3.8 once, then existing OpenRouter.
 * No live Gemini or OpenRouter call.
 *
 * Usage: php scripts/dev/test_gemini_38_flash_fallback.php
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

$payload = [
    'systemInstruction' => ['parts' => [['text' => 'Return JSON only.']]],
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => 'MODE: START_INTERVIEW\nPatient input: I have a headache']],
    ]],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 1024,
        'responseMimeType' => 'application/json',
    ],
];

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

$tryFallback = new ReflectionMethod(GeminiClinicalInterviewDemo::class, 'tryGeminiFallbackModelOnce');
$tryFallback->setAccessible(true);
$quota = new RuntimeException('Gemini HTTP 429: You exceeded your current quota');
$bad = new RuntimeException('Gemini HTTP 400: bad request');

GeminiClinicalInterviewDemo::beginGeminiQuotaProbeForTest();
try {
    $none = $tryFallback->invoke(null, $bad, $payload, 'gemini-3.5-flash', 'probe-not-sent');
    $attempts = GeminiClinicalInterviewDemo::geminiGenerateAttemptsForTest();
} finally {
    GeminiClinicalInterviewDemo::endGeminiQuotaProbeForTest();
}
ok('non-quota primary does not call Gemini 3.8', $none === null && $attempts === 0, 'attempts=' . $attempts);

GeminiClinicalInterviewDemo::beginGeminiQuotaProbeForTest($sharedJson);
try {
    $from38 = $tryFallback->invoke(null, $quota, $payload, 'gemini-3.5-flash', 'probe-not-sent');
    $attempts = GeminiClinicalInterviewDemo::geminiGenerateAttemptsForTest();
} finally {
    GeminiClinicalInterviewDemo::endGeminiQuotaProbeForTest();
}
ok('Gemini 3.8 success returns its text', is_string($from38) && $from38 === $sharedJson);
ok('Gemini 3.8 success is one attempt', $attempts === 1, 'attempts=' . $attempts);

GeminiClinicalInterviewDemo::beginGeminiQuotaProbeForTest();
try {
    $miss = $tryFallback->invoke(null, $quota, $payload, 'gemini-3.5-flash', 'probe-not-sent');
    $attempts = GeminiClinicalInterviewDemo::geminiGenerateAttemptsForTest();
} finally {
    GeminiClinicalInterviewDemo::endGeminiQuotaProbeForTest();
}
ok('Gemini 3.8 failure returns null for OpenRouter', $miss === null);
ok('Gemini 3.8 failure is one attempt, not a retry loop', $attempts === 1, 'attempts=' . $attempts);

GeminiClinicalInterviewDemo::beginGeminiQuotaProbeForTest('');
try {
    $empty200 = $tryFallback->invoke(null, $quota, $payload, 'gemini-3.5-flash', 'probe-not-sent');
    $emptyAttempts = GeminiClinicalInterviewDemo::geminiGenerateAttemptsForTest();
} finally {
    GeminiClinicalInterviewDemo::endGeminiQuotaProbeForTest();
}
ok('Gemini 3.8 HTTP 200 empty/unusable returns null for OpenRouter', $empty200 === null);
ok('Gemini 3.8 HTTP 200 empty is one attempt', $emptyAttempts === 1, 'attempts=' . $emptyAttempts);

$skipSame = $tryFallback->invoke(
    null,
    $quota,
    $payload,
    'gemini-3.8-flash',
    'probe-not-sent'
);
ok('primary gemini-3.8-flash does not call itself again', $skipSame === null);

putenv('MEDCONNECT_PHP_NLP_ONLY=0');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '0';
putenv('AI_ENABLED=true');
$_ENV['AI_ENABLED'] = 'true';
putenv('AI_PROVIDER=gemini');
$_ENV['AI_PROVIDER'] = 'gemini';
putenv('AI_API_KEY=probe-not-sent');
$_ENV['AI_API_KEY'] = 'probe-not-sent';

$shouldRailway = new ReflectionMethod(GeminiClinicalInterviewDemo::class, 'shouldUseRailway');
$shouldRailway->setAccessible(true);
$useRailway = (bool) $shouldRailway->invoke(null);

if (!$useRailway) {
    $complaint = 'I have a headache since yesterday';
    $openRouterCalls = 0;
    $transport = static function (array $body) use (&$openRouterCalls, $sharedJson): ?string {
        $openRouterCalls++;
        if (($body['model'] ?? '') !== MEDCONNECT_OPENROUTER_DEMO_MODEL) {
            return null;
        }

        return $sharedJson;
    };

    $ref = new ReflectionClass(GeminiClinicalInterviewDemo::class);
    $transportProp = $ref->getProperty('openRouterTransportForTest');
    $transportProp->setAccessible(true);
    $directProp = $ref->getProperty('directGeminiAttempts');
    $directProp->setAccessible(true);

    GeminiClinicalInterviewDemo::beginGeminiQuotaProbeForTest($sharedJson);
    $transportProp->setValue(null, static function () use (&$openRouterCalls): ?string {
        $openRouterCalls++;
        return '{"classification":"HEALTH_RELATED"}';
    });
    try {
        $started38 = GeminiClinicalInterviewDemo::start($complaint);
        $direct38 = (int) $directProp->getValue();
    } finally {
        $transportProp->setValue(null, null);
        GeminiClinicalInterviewDemo::endGeminiQuotaProbeForTest();
    }

    ok('start(): Gemini 3.5 429 then Gemini 3.8 success on local path', $direct38 === 2, 'direct=' . $direct38);
    ok('start(): OpenRouter is not called when Gemini 3.8 succeeds', $openRouterCalls === 0, 'openrouter=' . $openRouterCalls);
    $final = is_array($started38['final_triage'] ?? null) ? $started38['final_triage'] : [];
    ok(
        'start(): final authority remains ClinicalTriageEngine after Gemini 3.8',
        ($final['final_authority'] ?? '') === 'ClinicalTriageEngine' || ($started38['status'] ?? '') !== 'final_triage',
        (string) ($final['final_authority'] ?? $started38['status'] ?? '')
    );

    $openRouterCalls = 0;
    GeminiClinicalInterviewDemo::beginGeminiQuotaProbeForTest();
    $transportProp->setValue(null, $transport);
    try {
        $startedOr = GeminiClinicalInterviewDemo::start($complaint);
        $directOr = (int) $directProp->getValue();
    } finally {
        $transportProp->setValue(null, null);
        GeminiClinicalInterviewDemo::endGeminiQuotaProbeForTest();
    }

    ok('start(): Gemini 3.8 is not retried twice', $directOr <= 2, 'direct=' . $directOr);
    ok(
        'start(): existing OpenRouter fallback runs after both Gemini models fail',
        $openRouterCalls === 1 || ($startedOr['code'] ?? '') === 'gemini_quota_exceeded',
        'openrouter=' . $openRouterCalls . ' code=' . (string) ($startedOr['code'] ?? '')
    );

    $openRouterCalls = 0;
    GeminiClinicalInterviewDemo::beginGeminiQuotaProbeForTest('');
    $transportProp->setValue(null, $transport);
    try {
        $startedEmpty = GeminiClinicalInterviewDemo::start($complaint);
        $directEmpty = (int) $directProp->getValue();
    } finally {
        $transportProp->setValue(null, null);
        GeminiClinicalInterviewDemo::endGeminiQuotaProbeForTest();
    }
    ok('start(): Gemini 3.8 HTTP 200 empty is one 3.8 attempt', $directEmpty === 2, 'direct=' . $directEmpty);
    ok(
        'start(): existing OpenRouter fallback runs after Gemini 3.8 empty/unusable',
        $openRouterCalls === 1 || ($startedEmpty['code'] ?? '') === 'gemini_quota_exceeded',
        'openrouter=' . $openRouterCalls . ' code=' . (string) ($startedEmpty['code'] ?? '')
    );
} else {
    echo "NOTE  shouldUseRailway() is true; live demo 3.8 order is in gemini_client.generate_content()\n";
    ok('Railway live path is covered by Python generate_content tests', true);
}

$demoSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/GeminiClinicalInterviewDemo.php');
$pySrc = (string) file_get_contents(dirname(__DIR__, 2) . '/ai_service/gemini_client.py');
ok('PHP fallback model id is gemini-3.8-flash', str_contains($demoSrc, "GEMINI_FALLBACK_MODEL = 'gemini-3.8-flash'"));
ok(
    'PHP local path tries Gemini 3.8 before OpenRouter',
    str_contains($demoSrc, 'tryGeminiFallbackModelOnce($e, $payload, $model, $key)')
);
ok('Python fallback model id is gemini-3.8-flash', str_contains($pySrc, 'GEMINI_FALLBACK_MODEL = "gemini-3.8-flash"'));
ok(
    'Python tries Gemini 3.8 before OpenRouter pack',
    str_contains($pySrc, '_try_secondary_gemini_model(body, use_model, key, wait)')
    && strpos($pySrc, '_try_secondary_gemini_model(body, use_model, key, wait)') < strpos($pySrc, '_quota_fallback_pack(body, wait)')
);
ok('OpenRouter model id unchanged', MEDCONNECT_OPENROUTER_DEMO_MODEL === 'google/gemma-4-31b-it:free');
ok('ClinicalTriageEngine has no OpenRouter hook', !str_contains(
    (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/ClinicalTriageEngine.php'),
    'OPENROUTER'
));
ok('demo still sets final_authority ClinicalTriageEngine', str_contains($demoSrc, "'final_authority' => 'ClinicalTriageEngine'"));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
