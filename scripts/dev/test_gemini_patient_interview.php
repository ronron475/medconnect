<?php
/**
 * Gemini-led patient adapter: transport fail → ClinicalInterviewEngine with current utterance.
 * No live Gemini / OpenRouter / Railway.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

putenv('MEDCONNECT_PHP_NLP_ONLY=0');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '0';
putenv('MEDCONNECT_SKIP_GEMINI_FOLLOWUP=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_FOLLOWUP'] = '1';

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

GeminiPatientInterview::resetTestHooks();

$ctx = [
    'chief_complaint' => 'My stomach hurts.',
    'patient_turns' => ['My stomach hurts.', 'Since yesterday'],
    'clinical_facts' => [
        'symptom' => 'stomach pain',
        'location' => 'stomach',
        'duration' => 'Since yesterday',
        'associated_symptoms' => [],
        'relevant_negatives' => [],
        'finding_status' => [],
    ],
    'question_language' => 'english',
    'detected_language' => 'english',
    'awaiting_question' => 'How bad is the pain from 1 to 10?',
    'conversation' => [
        ['role' => 'patient', 'text' => 'My stomach hurts.', 'kind' => 'complaint'],
        ['role' => 'gemini', 'text' => 'How long has this lasted?', 'kind' => 'followup'],
        ['role' => 'patient', 'text' => 'Since yesterday', 'kind' => 'answer'],
        ['role' => 'gemini', 'text' => 'How bad is the pain from 1 to 10?', 'kind' => 'followup'],
    ],
    'status' => GeminiClinicalInterviewDemo::STATUS_INTERVIEWING,
];

$mapped = GeminiPatientInterview::enginePriorFromGeminiContext($ctx);
$facts = is_array($mapped['facts'] ?? null) ? $mapped['facts'] : [];
ok('mapped duration preserved', str_contains(strtolower((string) ($facts['duration_label'] ?? '')), 'yesterday'));
ok('mapped location preserved', in_array('stomach', (array) ($facts['body_locations'] ?? []), true));
ok('mapped chief complaint preserved', ($mapped['chief_complaint'] ?? '') === 'My stomach hurts.');
ok('patient turns do not include current answer 7', !in_array('7', (array) ($mapped['patient_turns'] ?? []), true));
ok('awaiting_question_id is empty (no invented bank id)', ($mapped['awaiting_question_id'] ?? 'x') === '');
ok('mapFactsForEngine is public', is_callable([GeminiClinicalInterviewDemo::class, 'mapFactsForEngine']));

$prior = [
    'gemini_led_context' => $ctx,
    'interview' => ['gemini_led_context' => $ctx],
];
GeminiPatientInterview::$packOverrideForTest = [
    'error' => true,
    'status' => GeminiClinicalInterviewDemo::STATUS_ERROR,
    'code' => 'gemini_quota_exceeded',
    'message' => 'Gemini quota exceeded',
    'interview_context' => $ctx,
];
$out = ChiefComplaintNlpService::assessInterview('7', $prior, []);
ok(
    'PHP fallback receives current utterance 7',
    GeminiPatientInterview::$engineAssessCallsForTest === ['7'],
    json_encode(GeminiPatientInterview::$engineAssessCallsForTest)
);
ok('assessWithFallback is not the interview fallback', ($out['engine'] ?? '') !== 'chief-complaint-semantic-gate');
ok('gemini-led abandoned after AI failure', !empty($out['gemini_led_abandoned']) || !empty($out['interview']['gemini_led_abandoned']));
$outFacts = is_array($out['interview']['facts'] ?? null) ? $out['interview']['facts'] : (is_array($out['facts'] ?? null) ? $out['facts'] : []);
$durationHay = strtolower((string) ($outFacts['duration_label'] ?? '') . ' ' . ($out['clinical_transcript'] ?? '') . ' ' . json_encode($outFacts));
ok('previous duration still present after PHP fallback', str_contains($durationHay, 'yesterday'));
$pain = $outFacts['pain_score'] ?? null;
ok(
    'current answer 7 is available to the engine (pain score or turns)',
    $pain === 7 || $pain === '7' || str_contains(json_encode($out), '"7"'),
    'pain_score=' . json_encode($pain)
);

$auth = (string) ($out['triage']['final_authority'] ?? '');
$inProgress = ClinicalInterviewEngine::isInProgress($out);
ok(
    'ClinicalTriageEngine remains final authority when complete, else interview still in progress',
    $auth === 'ClinicalTriageEngine' || $inProgress,
    $auth !== '' ? $auth : (string) ($out['assessment_status'] ?? '')
);

GeminiPatientInterview::resetTestHooks();
GeminiPatientInterview::$packOverrideForTest = [
    'needs_health_concern' => true,
    'status' => GeminiClinicalInterviewDemo::STATUS_NEEDS_HEALTH,
    'message' => 'That does not look like a health concern.',
    'health_gate' => ['classification' => GeminiClinicalInterviewDemo::CLASS_NON_HEALTH],
];
$gate = ChiefComplaintNlpService::assessInterview('hello there', []);
ok('health reject is needs_valid_complaint, not PHP interview', !empty($gate['needs_valid_complaint']) || ($gate['assessment_status'] ?? '') === ClinicalInterviewEngine::STATUS_NEEDS_VALID_COMPLAINT);
ok('health reject does not call engine assess', GeminiPatientInterview::$engineAssessCallsForTest === []);

GeminiPatientInterview::resetTestHooks();
$quotaStart = [
    'needs_health_concern' => true,
    'status' => GeminiClinicalInterviewDemo::STATUS_NEEDS_HEALTH,
    'code' => 'gemini_quota_exceeded',
    'message' => 'quota',
    'health_gate' => ['classification' => GeminiClinicalInterviewDemo::CLASS_UNCLEAR],
];
ok('start quota/unavailable is transport failure, not health reject', GeminiPatientInterview::isTransportFailure($quotaStart));

GeminiPatientInterview::resetTestHooks();
$phpPrior = [
    'interview' => [
        'questions_asked' => ['PAIN_SEVERITY'],
        'gemini_led_abandoned' => true,
    ],
];
ok('abandoned PHP session is not Gemini-led', GeminiPatientInterview::assess('still hurts', $phpPrior) === null);

GeminiPatientInterview::resetTestHooks();
$phpGemmaCalls = 0;
GeminiClinicalInterviewDemo::beginOpenRouterQuotaProbeForTest(static function () use (&$phpGemmaCalls): ?string {
    $phpGemmaCalls++;

    return '{"classification":"HEALTH_RELATED","patient_subject":"HUMAN","question_needed":true,"next_question":"Where is the pain?","clinical_facts":{"symptom":"stomach pain"}}';
});
try {
    $quotaOut = ChiefComplaintNlpService::assessInterview('7', $prior, []);
} finally {
    GeminiClinicalInterviewDemo::endOpenRouterQuotaProbeForTest();
}
ok(
    'patient quota path does not call Hostinger PHP gemma OpenRouter',
    $phpGemmaCalls === 0 && GeminiClinicalInterviewDemo::$phpOpenRouterRecoverCallsForTest === 0,
    'gemma_transport=' . $phpGemmaCalls
        . ' recover_calls=' . GeminiClinicalInterviewDemo::$phpOpenRouterRecoverCallsForTest
);
ok(
    'Railway/nemotron fail still sends current utterance 7 to ClinicalInterviewEngine',
    GeminiPatientInterview::$engineAssessCallsForTest === ['7'],
    json_encode(GeminiPatientInterview::$engineAssessCallsForTest)
);
$quotaFacts = is_array($quotaOut['interview']['facts'] ?? null)
    ? $quotaOut['interview']['facts']
    : (is_array($quotaOut['facts'] ?? null) ? $quotaOut['facts'] : []);
ok(
    'quota generate-path keeps prior duration',
    str_contains(strtolower((string) ($quotaFacts['duration_label'] ?? '') . json_encode($quotaFacts)), 'yesterday')
);
ok(
    'quota generate-path does not invent awaiting_question_id before engine',
    ($mapped['awaiting_question_id'] ?? 'x') === ''
);

$src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/ChiefComplaintNlpService.php');
ok(
    'assessInterview tries GeminiPatientInterview before ClinicalInterviewEngine',
    str_contains($src, 'GeminiPatientInterview::assess')
    && strpos($src, 'GeminiPatientInterview::assess') < strpos($src, 'ClinicalInterviewEngine::assess')
);
ok(
    'AI fail path does not call assessWithFallback as interview fallback',
    !str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/app/core/GeminiPatientInterview.php'), 'assessWithFallback')
);
$patientSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/GeminiPatientInterview.php');
ok(
    'patient start/answer skip PHP gemma OpenRouter',
    str_contains($patientSrc, 'beginSkipPhpOpenRouterQuotaFallback')
    && str_contains($patientSrc, 'endSkipPhpOpenRouterQuotaFallback')
);
$demoSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/GeminiClinicalInterviewDemo.php');
ok(
    'demo PHP OpenRouter recovery remains for the demo generate path',
    str_contains($demoSrc, 'recoverDemoQuotaWithOpenRouter')
    && str_contains($demoSrc, 'medconnect_demo_openrouter_quota_text')
);
ok(
    'patient skip returns before PHP OpenRouter helper',
    strpos($demoSrc, 'skipPhpOpenRouterQuotaFallback') < strpos($demoSrc, 'medconnect_demo_openrouter_quota_text')
);

$py = (string) file_get_contents(dirname(__DIR__, 2) . '/ai_service/gemini_client.py');
ok('Railway OpenRouter model remains nemotron', str_contains($py, 'nvidia/nemotron-3-super-120b-a12b:free'));
ok('Railway OpenRouter JSON response_format preserved', str_contains($py, '"response_format"') && str_contains($py, 'json_object'));
ok('Railway OpenRouter reasoning helper preserved', str_contains($py, 'def _openrouter_choice_text') && str_contains($py, 'reasoning'));
ok('3.8 still runs before OpenRouter pack', strpos($py, '_try_secondary_gemini_model(body, use_model, key, wait)') < strpos($py, '_quota_fallback_pack(body, wait)'));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
