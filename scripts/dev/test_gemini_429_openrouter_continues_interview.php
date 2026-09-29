<?php
/**
 * Gemini HTTP 429 → existing OpenRouter fallback → parser → interview continues.
 * No live Gemini or OpenRouter call.
 *
 * Usage: php scripts/dev/test_gemini_429_openrouter_continues_interview.php
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

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
putenv('AI_PROVIDER=gemini');
$_ENV['AI_PROVIDER'] = 'gemini';
putenv('AI_API_KEY=probe-not-sent');
$_ENV['AI_API_KEY'] = 'probe-not-sent';

$complaint = 'I have a headache since yesterday';
$modelJson = json_encode([
    'classification' => 'HEALTH_RELATED',
    'confidence' => 0.95,
    'patient_subject' => 'HUMAN',
    'is_human_patient_complaint' => true,
    'normalized_health_concern' => 'headache',
    'question_needed' => true,
    'interview_sufficient' => false,
    'next_question' => 'Have you vomited?',
    'answer_status' => 'VALID',
    'clinical_facts' => [
        'symptom' => 'headache',
        'location' => 'head',
    ],
    'triage_level' => 'EMERGENCY',
    'diagnosis' => 'migraine',
], JSON_UNESCAPED_UNICODE);

$parse = new ReflectionMethod(GeminiClinicalInterviewDemo::class, 'parseJson');
$parse->setAccessible(true);
$parsed = $parse->invoke(null, (string) $modelJson, 'start');
ok('existing parser accepts fallback JSON', is_array($parsed));
ok(
    'parser keeps the follow-up',
    is_array($parsed) && ($parsed['question_needed'] ?? false) === true && trim((string) ($parsed['next_question'] ?? '')) !== ''
);

$calls = 0;
GeminiClinicalInterviewDemo::beginOpenRouterQuotaProbeForTest(static function (array $body) use (&$calls, $modelJson): ?string {
    $calls++;
    $messages = $body['messages'] ?? [];
    $user = (string) ($messages[1]['content'] ?? '');
    if (!str_contains($user, 'MODE: START_INTERVIEW')) {
        return null;
    }

    return $modelJson;
});
try {
    $started = GeminiClinicalInterviewDemo::start($complaint);
} finally {
    GeminiClinicalInterviewDemo::endOpenRouterQuotaProbeForTest();
}

ok('Gemini HTTP 429 calls OpenRouter once', $calls === 1, 'calls=' . $calls);
ok(
    'quota code is not returned',
    ($started['code'] ?? '') !== 'gemini_quota_exceeded',
    (string) ($started['code'] ?? '')
);
ok(
    'health gate accepts the fallback JSON',
    ($started['health_classification'] ?? '') === 'HEALTH_RELATED'
    || (($started['debug']['health_gate']['passed'] ?? false) === true)
);
ok(
    'interview continues',
    ($started['status'] ?? '') === GeminiClinicalInterviewDemo::STATUS_INTERVIEWING
    && trim((string) ($started['awaiting_question'] ?? '')) !== '',
    (string) ($started['status'] ?? '')
);
ok('fallback does not finalize triage', !is_array($started['final_triage'] ?? null));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
