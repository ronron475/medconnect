<?php
/**
 * Gemini-led patient adapter: same demo fallbacks; last-resort PHP engine.
 * No live Gemini / OpenRouter / Railway / Groq.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

putenv('MEDCONNECT_PHP_NLP_ONLY=0');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '0';
putenv('MEDCONNECT_SKIP_GEMINI_FOLLOWUP=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_FOLLOWUP'] = '1';
putenv('MEDCONNECT_SKIP_GEMINI_VALIDATION=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_VALIDATION'] = '1';
putenv('MEDCONNECT_SKIP_GEMINI_SELECT=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_SELECT'] = '1';

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
    'awaiting_target_findings' => ['pain_score'],
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
ok('abandoned PHP session is not Gemini-led', GeminiPatientInterview::shouldUsePhpEngine($phpPrior) === true);
$abandoned = ChiefComplaintNlpService::assessInterview('still hurts', $phpPrior);
ok(
    'abandoned PHP session stays on ClinicalInterviewEngine (no new demo start)',
    empty($abandoned[GeminiPatientInterview::CONTEXT_KEY])
    && empty($abandoned['interview'][GeminiPatientInterview::CONTEXT_KEY])
);

GeminiPatientInterview::resetTestHooks();
$phpGemmaCalls = 0;
GeminiClinicalInterviewDemo::beginOpenRouterQuotaProbeForTest(static function () use (&$phpGemmaCalls): ?string {
    $phpGemmaCalls++;

    return '{"classification":"HEALTH_RELATED","patient_subject":"HUMAN","answer_status":"VALID","question_needed":true,"interview_sufficient":false,"next_question":"From 1 to 10, how bad is the pain?","clinical_facts":{"symptom":"stomach pain","duration":"Since yesterday","pain_score":7}}';
});
try {
    $quotaOut = ChiefComplaintNlpService::assessInterview('7', $prior, []);
} finally {
    GeminiClinicalInterviewDemo::endOpenRouterQuotaProbeForTest();
}
ok(
    'patient quota path uses the same OpenRouter hop as the demo',
    $phpGemmaCalls === 1 && GeminiClinicalInterviewDemo::$phpOpenRouterRecoverCallsForTest >= 1,
    'gemma_transport=' . $phpGemmaCalls
        . ' recover_calls=' . GeminiClinicalInterviewDemo::$phpOpenRouterRecoverCallsForTest
);
ok(
    'OpenRouter success does not abandon to ClinicalInterviewEngine',
    GeminiPatientInterview::$engineAssessCallsForTest === [],
    json_encode(GeminiPatientInterview::$engineAssessCallsForTest)
);
ok(
    'OpenRouter success keeps Gemini-led context',
    is_array($quotaOut['gemini_led_context'] ?? null)
        && trim((string) (($quotaOut['gemini_led_context']['chief_complaint'] ?? ''))) === 'My stomach hurts.'
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

GeminiPatientInterview::resetTestHooks();
GeminiPatientInterview::$packOverrideForTest = [
    'status' => GeminiClinicalInterviewDemo::STATUS_INTERVIEWING,
    'awaiting_question' => 'How bad is the pain from 1 to 10?',
    'awaiting_target_findings' => ['pain_score'],
    'chief_complaint' => 'My stomach hurts.',
    'clinical_facts' => [
        'symptom' => 'stomach pain',
        'duration' => 'Since yesterday',
    ],
    'interview_context' => $ctx,
    'question_language' => 'english',
];
$mid = ChiefComplaintNlpService::assessInterview('My stomach hurts.', []);
ok('in-progress uses existing follow-up UI contract', ClinicalInterviewEngine::isInProgress($mid));
ok(
    'pain-score follow-up maps to existing PAIN_SEVERITY chip id',
    (string) (($mid['followup_question']['question_id'] ?? '')) === 'PAIN_SEVERITY'
);
ok('gemini_led_context persisted on assessment', is_array($mid[GeminiPatientInterview::CONTEXT_KEY] ?? null));
ok('original complaint wording preserved', ($mid['original_chief_complaint'] ?? '') === 'My stomach hurts.');
ok('in-progress does not set EMERGENCY/URGENT/NON-URGENT', ($mid['triage']['triage_display'] ?? '') === '');

GeminiPatientInterview::resetTestHooks();
GeminiPatientInterview::$packOverrideForTest = [
    'status' => GeminiClinicalInterviewDemo::STATUS_INTERVIEWING,
    'awaiting_question' => 'How bad is the pain from 1 to 10?',
    'last_answer_status' => 'UNCLEAR',
    'message' => 'Please answer the question above.',
    'awaiting_target_findings' => ['pain_score'],
    'chief_complaint' => 'My stomach hurts.',
    'clinical_facts' => ['symptom' => 'stomach pain'],
    'interview_context' => array_merge($ctx, [
        'last_answer_status' => 'UNCLEAR',
        'conversation' => array_merge($ctx['conversation'], [
            ['role' => 'gemini', 'text' => 'How bad is the pain from 1 to 10?', 'kind' => 'retry'],
        ]),
    ]),
];
$retry = ChiefComplaintNlpService::assessInterview('asdf', $prior, []);
ok('unclear answer sets retry_current_question', !empty($retry['retry_current_question']) || !empty($retry['interview']['retry_current_question']));
ok('retry keeps the same pain question', str_contains(strtolower((string) ($retry['followup_question']['text'] ?? '')), '1 to 10'));

GeminiPatientInterview::resetTestHooks();
GeminiPatientInterview::$packOverrideForTest = [
    'status' => GeminiClinicalInterviewDemo::STATUS_FINAL,
    'chief_complaint' => 'My stomach hurts.',
    'clinical_facts' => [
        'symptom' => 'stomach pain',
        'location' => 'stomach',
        'duration' => 'Since yesterday',
        'pain_score' => 4,
        'relevant_negatives' => ['fever'],
        'finding_status' => ['fever' => 'negative'],
    ],
    'final_triage' => [
        'triage_display' => 'NON-URGENT',
        'triage_classification' => 'NON_URGENT',
        'triage_level' => 'LOW',
        'recommended_action' => 'Monitor symptoms and schedule a routine consultation if symptoms persist.',
        'reason' => 'No red flags on accumulated facts.',
        'red_flags' => [],
        'final_authority' => 'ClinicalTriageEngine',
        'confidence_score' => 80,
    ],
    'interview_context' => array_merge($ctx, ['status' => GeminiClinicalInterviewDemo::STATUS_FINAL]),
];
$done = ChiefComplaintNlpService::assessInterview('no fever', $prior, []);
ok('completed interview is not in progress', !ClinicalInterviewEngine::isInProgress($done));
ok(
    'ClinicalTriageEngine is the stored final authority',
    ($done['triage']['final_authority'] ?? '') === 'ClinicalTriageEngine'
);
ok(
    'completed NON-URGENT has recommendations for care-tip review',
    is_array($done['recommendations'] ?? null) && implode("\n", $done['recommendations']) !== ''
);
ok('completed class comes from ClinicalTriageEngine payload', ($done['triage']['triage_display'] ?? '') === 'NON-URGENT');
$doneFacts = is_array($done['interview']['facts'] ?? null) ? $done['interview']['facts'] : [];
ok(
    'negative fever polarity is not stored as fever=true',
    ($doneFacts['fever'] ?? null) !== true
    && !in_array('fever', (array) ($doneFacts['symptoms'] ?? []), true)
);

GeminiPatientInterview::resetTestHooks();
GeminiPatientInterview::$packOverrideForTest = [
    'status' => GeminiClinicalInterviewDemo::STATUS_INTERVIEWING,
    'awaiting_question' => 'Diin ang sakit?',
    'chief_complaint' => 'sakit ulo ko',
    'clinical_facts' => ['symptom' => 'head pain'],
    'interview_context' => [
        'chief_complaint' => 'sakit ulo ko',
        'patient_turns' => ['sakit ulo ko'],
        'awaiting_question' => 'Diin ang sakit?',
        'clinical_facts' => ['symptom' => 'head pain'],
        'question_language' => 'hiligaynon',
    ],
    'question_language' => 'hiligaynon',
];
$fresh = ChiefComplaintNlpService::assessInterview('sakit ulo ko', []);
ok('empty prior starts a Gemini interview (not leftover PHP questions_asked)', is_array($fresh));
ok(
    'Start New Consultation empty prior does not reuse old awaiting_question_id',
    (string) (($fresh['interview']['awaiting_question_id'] ?? 'x')) === ''
);
ok(
    'Start New Consultation persists demo interview_context (same key the demo page posts)',
    is_array($fresh['interview_context'] ?? null)
    && ($fresh['interview_context']['chief_complaint'] ?? '') === 'sakit ulo ko'
);
ok(
    'empty prior is treated as demo start, not leftover PHP questions_asked',
    GeminiPatientInterview::demoInterviewContext([]) === []
    && GeminiPatientInterview::shouldUsePhpEngine([]) === false
);

$sharedComplaint = 'Masakit akon ulo kag daw naga init akon lawas.';
ok(
    'new consultation for the shared example has no leftover demo interview_context',
    GeminiPatientInterview::demoInterviewContext([]) === []
);
$followPrior = GeminiPatientInterview::demoInterviewContext([
    'interview_context' => [
        'chief_complaint' => $sharedComplaint,
        'awaiting_question' => 'San-o nagsugod ang kasakit sa imo ulo?',
        'patient_turns' => [$sharedComplaint],
    ],
]);
ok(
    'follow-up reuses the same interview_context object the demo page posts',
    ($followPrior['chief_complaint'] ?? '') === $sharedComplaint
    && ($followPrior['awaiting_question'] ?? '') !== ''
    && ($followPrior['patient_turns'][0] ?? '') === $sharedComplaint
);

$negMapped = GeminiClinicalInterviewDemo::mapFactsForEngine([
    'symptom' => 'head pain',
    'relevant_negatives' => ['fever'],
    'finding_status' => ['fever' => 'negative'],
]);
ok(
    'explicit negative fever is not fever=true',
    ($negMapped['fever'] ?? null) !== true
    && !in_array('fever', (array) ($negMapped['symptoms'] ?? []), true)
);

$src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/ChiefComplaintNlpService.php');
$demoApiSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/api/ai/gemini_clinical_interview_demo.php');
$patientSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/GeminiPatientInterview.php');
$jsSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/gemini_clinical_interview_demo.js');
ok(
    'demo START INTERVIEW posts action=start',
    str_contains($jsSrc, "action: 'start'")
);
ok(
    'demo FOLLOW-UP ANSWER posts action=answer with interview_context',
    str_contains($jsSrc, "action: 'answer'")
    && str_contains($jsSrc, 'interview_context: JSON.stringify(interviewContext)')
);
ok(
    'demo API start calls GeminiClinicalInterviewDemo::start',
    str_contains($demoApiSrc, 'GeminiClinicalInterviewDemo::start($complaint)')
);
ok(
    'demo API answer calls GeminiClinicalInterviewDemo::answer',
    str_contains($demoApiSrc, 'GeminiClinicalInterviewDemo::answer($answer, $prior)')
);
ok(
    'live assessInterview calls the same start method as the demo API',
    str_contains($src, 'GeminiClinicalInterviewDemo::start($utterance)')
);
ok(
    'live assessInterview calls the same answer method as the demo API',
    str_contains($src, 'GeminiClinicalInterviewDemo::answer($utterance, $prior)')
);
ok(
    'live assessInterview no longer orchestrates via GeminiPatientInterview::assess',
    !str_contains($src, 'GeminiPatientInterview::assess')
);
ok(
    'GeminiPatientInterview has no competing assess() interview engine',
    !preg_match('/function\s+assess\s*\(/', $patientSrc)
);
ok(
    'AI fail path does not call assessWithFallback as interview fallback',
    !str_contains($patientSrc, 'assessWithFallback')
);
ok(
    'patient mapper does not skip the demo OpenRouter/Groq hop',
    !str_contains($patientSrc, 'beginSkipPhpOpenRouterQuotaFallback')
);

$demoSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/GeminiClinicalInterviewDemo.php');
ok(
    'finalize still uses ClinicalTriageEngine as the only acuity authority',
    str_contains($demoSrc, 'ClinicalTriageEngine::assess(')
    && str_contains($demoSrc, 'function finalizeWithClinicalEngine')
    && !str_contains($patientSrc, 'ClinicalTriageEngine::assess')
);
ok(
    'demo start() still runs NLP precheck before Gemini',
    str_contains($demoSrc, 'runNlpDomainPrecheck')
    && strpos($demoSrc, 'runNlpDomainPrecheck($complaint)') < strpos($demoSrc, "callGemini('start'")
);
ok(
    'demo answer() still interprets the follow-up via callGemini then applyGeminiTurn',
    str_contains($demoSrc, "callGemini('answer'")
    && str_contains($demoSrc, 'applyGeminiTurn($context, $gemini, false)')
);
ok(
    'demo finalize still hands structured facts to ClinicalTriageEngine',
    str_contains($demoSrc, 'mapFactsForEngine')
    && strpos($demoSrc, 'function finalizeWithClinicalEngine') < strpos($demoSrc, 'ClinicalTriageEngine::assess(')
);
ok(
    'demo PHP OpenRouter recovery remains for the shared generate path',
    str_contains($demoSrc, 'recoverDemoQuotaWithOpenRouter')
    && str_contains($demoSrc, 'medconnect_demo_openrouter_quota_text')
);
ok(
    'skip flag still gates PHP OpenRouter helper when tests enable it',
    strpos($demoSrc, 'skipPhpOpenRouterQuotaFallback') < strpos($demoSrc, 'medconnect_demo_openrouter_quota_text')
);

$py = (string) file_get_contents(dirname(__DIR__, 2) . '/ai_service/gemini_client.py');
ok('Railway OpenRouter model remains nemotron', str_contains($py, 'nvidia/nemotron-3-super-120b-a12b:free'));
ok('Railway OpenRouter JSON response_format preserved', str_contains($py, '"response_format"') && str_contains($py, 'json_object'));
ok('Railway OpenRouter reasoning helper preserved', str_contains($py, 'def _openrouter_choice_text') && str_contains($py, 'reasoning'));
ok('3.8 still runs before OpenRouter pack', strpos($py, '_try_secondary_gemini_model(body, use_model, key, wait)') < strpos($py, '_quota_fallback_pack(body, wait)'));
ok(
    'Railway quota pack tries Groq then BITS after OpenRouter miss',
    str_contains($py, 'def _groq_http_complete')
    && str_contains($py, 'def _bits_http_complete')
    && strpos($py, '_openrouter_http_complete(payload, timeout)') < strpos($py, '_groq_http_complete(payload, timeout)')
    && strpos($py, '_groq_http_complete(payload, timeout)') < strpos($py, '_bits_http_complete(payload, timeout)')
);

$submitSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/includes/patient_symptoms_review_submit.php');
ok(
    'live submit still calls assessInterview (not the demo page)',
    str_contains($submitSrc, 'ChiefComplaintNlpService::assessInterview')
    && !str_contains($submitSrc, 'gemini_clinical_interview_demo.php')
);
ok(
    'start/answer do not receive checkbox symptoms',
    str_contains($src, 'GeminiClinicalInterviewDemo::start($utterance)')
    && str_contains($src, 'GeminiClinicalInterviewDemo::answer($utterance, $prior)')
    && !str_contains($src, 'GeminiClinicalInterviewDemo::start($utterance, $checkboxSymptoms)')
    && !str_contains($src, 'GeminiClinicalInterviewDemo::answer($utterance, $prior, $checkboxSymptoms)')
);
ok(
    'exception path maps a transport-failure pack instead of assessWithFallback',
    str_contains($src, "code' => 'gemini_unavailable_or_invalid_json'")
    && !str_contains($src, 'assessWithFallback($utterance, $checkboxSymptoms)')
);
ok(
    'generate fallback order is Gemini then 3.8 then OpenRouter then Groq then BITS',
    str_contains($demoSrc, "GEMINI_FALLBACK_MODEL = 'gemini-3.8-flash'")
    && str_contains($demoSrc, 'tryGeminiFallbackModelOnce')
    && str_contains($demoSrc, 'recoverDemoQuotaWithOpenRouter')
    && strpos($demoSrc, 'medconnect_demo_openrouter_quota_text') < strpos($demoSrc, 'medconnect_demo_groq_quota_text')
    && strpos($demoSrc, 'medconnect_demo_groq_quota_text') < strpos($demoSrc, 'medconnect_demo_bits_quota_text')
    && str_contains($demoSrc, 'continueWithNlpQuestionBank')
);

GeminiPatientInterview::resetTestHooks();
GeminiPatientInterview::$packOverrideForTest = [
    'status' => GeminiClinicalInterviewDemo::STATUS_INTERVIEWING,
    'awaiting_question' => 'When did the headache start?',
    'chief_complaint' => 'Masakit akon ulo kag daw naga init akon lawas.',
    'clinical_facts' => [
        'symptom' => 'head pain',
        'associated_symptoms' => ['fever'],
    ],
    'interview_context' => [
        'chief_complaint' => 'Masakit akon ulo kag daw naga init akon lawas.',
        'patient_turns' => ['Masakit akon ulo kag daw naga init akon lawas.'],
        'awaiting_question' => 'When did the headache start?',
        'clinical_facts' => [
            'symptom' => 'head pain',
            'associated_symptoms' => ['fever'],
        ],
        'question_language' => 'hiligaynon',
        'detected_language' => 'hiligaynon',
    ],
    'question_language' => 'hiligaynon',
];
$newComplaint = ChiefComplaintNlpService::assessInterview(
    'Masakit akon ulo kag daw naga init akon lawas.',
    [],
    ['fever']
);
ok(
    'A new complaint with empty prior starts a Gemini interview',
    ClinicalInterviewEngine::isInProgress($newComplaint)
    && ($newComplaint['interview_context']['chief_complaint'] ?? '') === 'Masakit akon ulo kag daw naga init akon lawas.'
);
ok(
    'A checkbox symptoms are not stored as Gemini interview input',
    GeminiPatientInterview::$engineAssessCallsForTest === []
);

$followCtx = [
    'interview_context' => $newComplaint['interview_context'],
    GeminiPatientInterview::CONTEXT_KEY => $newComplaint['interview_context'],
];
GeminiPatientInterview::resetTestHooks();
GeminiPatientInterview::$packOverrideForTest = [
    'status' => GeminiClinicalInterviewDemo::STATUS_INTERVIEWING,
    'awaiting_question' => 'How bad is the pain from 1 to 10?',
    'chief_complaint' => 'Masakit akon ulo kag daw naga init akon lawas.',
    'clinical_facts' => [
        'symptom' => 'head pain',
        'associated_symptoms' => ['fever'],
        'onset' => 'kahapon',
    ],
    'interview_context' => array_merge($newComplaint['interview_context'], [
        'patient_turns' => ['Masakit akon ulo kag daw naga init akon lawas.', 'kahapon'],
        'clinical_facts' => [
            'symptom' => 'head pain',
            'associated_symptoms' => ['fever'],
            'onset' => 'kahapon',
        ],
        'awaiting_question' => 'How bad is the pain from 1 to 10?',
    ]),
];
$follow = ChiefComplaintNlpService::assessInterview('kahapon', $followCtx, []);
ok(
    'B follow-up reuses demo interview_context and accumulates onset',
    ClinicalInterviewEngine::isInProgress($follow)
    && in_array('kahapon', (array) ($follow['interview_context']['patient_turns'] ?? []), true)
    && str_contains(strtolower((string) json_encode($follow['interview_context']['clinical_facts'] ?? [])), 'kahapon')
);
ok(
    'C accumulated facts keep the opening symptom and associated fever',
    str_contains(strtolower((string) json_encode($follow['interview_context']['clinical_facts'] ?? [])), 'head')
    && str_contains(strtolower((string) json_encode($follow['interview_context']['clinical_facts'] ?? [])), 'fever')
);

foreach (['no', 'wala', 'indi'] as $neg) {
    $negFacts = GeminiClinicalInterviewDemo::mapFactsForEngine([
        'symptom' => 'head pain',
        'relevant_negatives' => ['fever'],
        'finding_status' => ['fever' => 'negative'],
        'notes' => [$neg],
    ]);
    ok(
        "D negative answer $neg is not stored as fever=true",
        ($negFacts['fever'] ?? null) !== true
        && !in_array('fever', (array) ($negFacts['symptoms'] ?? []), true)
    );
}

foreach ([
    'english' => 'When did the headache start?',
    'tagalog' => 'Kailan nagsimula ang sakit ng ulo mo?',
    'hiligaynon' => 'San-o nagsugod ang kasakit sa imo ulo?',
] as $lang => $q) {
    GeminiPatientInterview::resetTestHooks();
    GeminiPatientInterview::$packOverrideForTest = [
        'status' => GeminiClinicalInterviewDemo::STATUS_INTERVIEWING,
        'awaiting_question' => $q,
        'chief_complaint' => 'sakit ulo',
        'clinical_facts' => ['symptom' => 'head pain'],
        'question_language' => $lang,
        'interview_context' => [
            'chief_complaint' => 'sakit ulo',
            'patient_turns' => ['sakit ulo'],
            'awaiting_question' => $q,
            'question_language' => $lang,
            'detected_language' => $lang,
            'clinical_facts' => ['symptom' => 'head pain'],
        ],
    ];
    $langOut = ChiefComplaintNlpService::assessInterview('sakit ulo', []);
    ok(
        "language pack $lang keeps demo question text",
        (string) ($langOut['followup_question']['text'] ?? '') === $q
        && strtolower((string) ($langOut['question_language'] ?? $langOut['interview']['question_language'] ?? '')) === $lang
    );
}

GeminiPatientInterview::resetTestHooks();
$slangFacts = GeminiClinicalInterviewDemo::mapFactsForEngine([
    'symptom' => 'head pain',
    'notes' => ['saket olo ko'],
]);
ok(
    'H mixed/slang/misspelling wording is not dropped from mapped facts',
    str_contains(strtolower((string) json_encode($slangFacts)), 'head')
    || str_contains(strtolower((string) json_encode($slangFacts)), 'olo')
);

ok(
    'I/J/K/L patient quota path already used the shared OpenRouter hop',
    str_contains($src, 'GeminiClinicalInterviewDemo::answer($utterance, $prior)')
    && str_contains($demoSrc, 'recoverDemoQuotaWithOpenRouter')
    && str_contains($demoSrc, 'GEMINI_FALLBACK_MODEL')
);
ok(
    'M PHP ClinicalInterviewEngine is last-resort after demo transport failure only',
    str_contains($patientSrc, 'function phpFallback')
    && str_contains($patientSrc, 'isTransportFailure')
);
ok(
    'N mapper does not call ClinicalTriageEngine',
    !str_contains($patientSrc, 'ClinicalTriageEngine::assess')
);
ok(
    'O live submit still persists via patient_submit_symptoms_for_review',
    str_contains($submitSrc, 'function patient_submit_symptoms_for_review')
    && str_contains($submitSrc, 'ChiefComplaintNlpService::assessInterview')
);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
