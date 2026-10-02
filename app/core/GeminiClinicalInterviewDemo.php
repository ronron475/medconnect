<?php
/**
 * DEMO ONLY — Gemini Flash clinical interview experiment.
 *
 * Pipeline (authoritative order):
 *   Patient input
 *   → existing NLP/domain validation (mappings first)
 *   → Gemini Flash semantic interpretation when needed
 *   → confirmed clinical facts only
 *   → ClinicalTriageEngine (WHO IITT + clinical rules)
 *   → final EMERGENCY / URGENT / NON-URGENT
 *
 * Gemini Flash must NEVER assign acuity, diagnose, or invent unsupported facts.
 * Does NOT modify production ClinicalInterviewEngine / AdaptivePolicy / patient portal.
 */
final class GeminiClinicalInterviewDemo
{
    public const STATUS_INTERVIEWING = 'interviewing';
    public const STATUS_SUFFICIENT = 'information_sufficient';
    public const STATUS_FINAL = 'final_triage';
    public const STATUS_ERROR = 'error';
    public const STATUS_NEEDS_HEALTH = 'needs_health_concern';

    public const CLASS_HEALTH = 'HEALTH_RELATED';
    public const CLASS_NON_HEALTH = 'NON_HEALTH_RELATED';
    public const CLASS_UNCLEAR = 'UNCLEAR';

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    /** Second Gemini model after primary HTTP 429/quota. Not a replacement for gemini-3.5-flash. */
    private const GEMINI_FALLBACK_MODEL = 'gemini-3.8-flash';
    private const MAX_TURNS = 12;
    /** Hard cap on follow-up questions (Gemini and BITS share applyGeminiTurn). Never exceeded. */
    private const MAX_FOLLOWUP_QUESTIONS = 4;
    /** Ask this many when clinically useful; do not pad with low-priority slots to reach it. */
    private const PREFERRED_FOLLOWUP_QUESTIONS = 3;
    private const TIMEOUT = 45;
    /** Minimum Gemini extraction confidence to accept semantic fallback when NLP has no mapping. */
    private const GEMINI_SEMANTIC_MIN_CONFIDENCE = 0.8;

    /**
     * Start interview from a primary complaint.
     *
     * Order: NLP/domain validation first → Gemini Flash semantic gate/interview
     * when needed → confirmed facts. NON_HEALTH / UNCLEAR (after NLP+Gemini) → reject.
     *
     * @return array<string, mixed>
     */
    public static function start(string $complaint): array
    {
        self::$aiProviderUsed = '';
        $complaint = trim($complaint);
        if ($complaint === '') {
            return self::errorResult(self::blankContext(), 'Enter a health concern or symptom you are experiencing.', null);
        }
        if (mb_strlen($complaint) > 1000) {
            return self::errorResult(self::blankContext(), 'Complaint is too long (max 1000 characters).', null);
        }
        if (!self::geminiReady()) {
            return self::errorResult(self::blankContext($complaint), 'Gemini is not configured or disabled (AI_ENABLED / API key). Health gate cannot run — interview not started.', null);
        }

        $context = self::blankContext($complaint);
        self::syncQuestionLanguage($context, $complaint, true);
        $context['conversation'][] = [
            'role' => 'patient',
            'text' => $complaint,
            'kind' => 'complaint',
        ];

        // 1) Existing NLP/domain validation FIRST — seed confirmed mappings before Gemini.
        $nlpPrecheck = self::runNlpDomainPrecheck($complaint);
        $context['nlp_precheck'] = $nlpPrecheck;
        $context['clinical_facts'] = self::mergeFacts(
            self::blankFacts(),
            self::factsFromNlpEvidence(is_array($nlpPrecheck['evidence'] ?? null) ? $nlpPrecheck['evidence'] : [])
        );

        // 2) Gemini Flash semantic interpretation (health gate + first follow-up).
        $gemini = self::callGemini('start', $context, '');
        if ($gemini === null) {
            $quota = self::isGeminiQuotaError(self::$lastError);
            if (self::canContinueWithNlpQuestionBank($nlpPrecheck, $context)) {
                return self::continueWithNlpQuestionBank($context, $nlpPrecheck, true);
            }
            $detail = self::$lastError !== '' ? (' (' . self::$lastError . ')') : '';
            $message = $quota
                ? 'Gemini is unavailable because the Gemini quota was exceeded. Interview not started.'
                : (self::isRailwayApplicationMissing(self::$lastError)
                    ? 'The Railway AI service was not running (Application not found). Interview not started.' . $detail
                    : 'Gemini unavailable or returned invalid JSON. Interview not started.' . $detail);

            return self::rejectNonHealth(
                $context,
                self::CLASS_UNCLEAR,
                $message,
                [
                    'raw_error' => self::$lastError,
                    'code' => $quota ? 'gemini_quota_exceeded' : 'gemini_unavailable_or_invalid_json',
                    'nlp_precheck' => $nlpPrecheck,
                    'health_gate' => [
                        'classification' => self::CLASS_UNCLEAR,
                        'confidence' => null,
                        'normalized_health_concern' => '',
                        'passed' => false,
                        'error' => $quota ? 'gemini_quota_exceeded' : 'gemini_unavailable_or_invalid_json',
                    ],
                ]
            );
        }

        $gate = self::normalizeHealthGate($gemini);
        // Non-human / animal subjects are out of scope — never let NLP symptom hits override that.
        $gate = self::applyNlpHealthGateOverride($gate, $nlpPrecheck, $gemini);
        $context['health_gate'] = $gate;
        $context['gemini_called'] = true;

        if (($gate['classification'] ?? '') !== self::CLASS_HEALTH) {
            $class = (string) ($gate['classification'] ?? self::CLASS_UNCLEAR);
            $subject = strtoupper((string) ($gate['patient_subject'] ?? ''));
            if ($subject === 'NON_HUMAN' || !empty($gate['out_of_scope_non_human'])) {
                $msg = 'medConnect is for human patient health concerns only. Animal or other non-human complaints are out of scope.';
            } elseif ($class === self::CLASS_NON_HEALTH) {
                $msg = 'That does not look like a health concern. Please describe a health problem or symptom you are experiencing.';
            } else {
                $msg = 'Please describe your health concern more clearly so we can continue.';
            }

            return self::rejectNonHealth($context, $class, $msg, [
                'health_gate' => $gate,
                'nlp_precheck' => $nlpPrecheck,
                'gemini_raw_structured' => $gemini,
                'routing' => (!empty($gate['out_of_scope_non_human']) || $subject === 'NON_HUMAN')
                    ? 'OUT_OF_SCOPE'
                    : null,
            ]);
        }

        // Preserve original wording; optional semantic gloss is debug-only / additive.
        $normalized = trim((string) ($gate['normalized_health_concern'] ?? ''));
        if ($normalized !== '') {
            $context['normalized_health_concern'] = $normalized;
        }

        return self::applyGeminiTurn($context, $gemini, true);
    }

    /**
     * Continue interview with a patient answer to the current question.
     *
     * @param array<string, mixed> $prior
     * @return array<string, mixed>
     */
    public static function answer(string $answer, array $prior): array
    {
        self::$aiProviderUsed = '';
        $answer = trim($answer);
        $context = self::normalizeContext($prior);
        if (($context['chief_complaint'] ?? '') === '') {
            return self::errorResult($context, 'No active interview. Start with a complaint first.', null);
        }
        if ($answer === '') {
            return self::errorResult($context, 'Enter an answer to the current follow-up question.', null);
        }
        if (mb_strlen($answer) > 1000) {
            return self::errorResult($context, 'Answer is too long (max 1000 characters).', null);
        }
        if (($context['status'] ?? '') === self::STATUS_FINAL) {
            return self::pack($context, 'Interview already finalized. Reset to start again.', null);
        }
        if (($context['awaiting_question'] ?? '') === '') {
            return self::errorResult($context, 'No pending follow-up question.', null);
        }
        if (!self::geminiReady()) {
            return self::errorResult($context, 'Gemini is not configured or disabled.', null);
        }

        $turns = (int) ($context['turn_count'] ?? 0);
        if ($turns >= self::MAX_TURNS) {
            // Safety cap only — prefer Gemini sufficiency; if stuck, force triage with what we have.
            $context['patient_turns'][] = $answer;
            $context['conversation'][] = [
                'role' => 'patient',
                'text' => $answer,
                'kind' => 'answer',
            ];
            $context['status_note'] = 'Reached demo turn safety cap; finalizing with collected facts.';

            return self::finalizeWithClinicalEngine($context, [
                'answer_status' => 'VALID',
                'note' => 'max_turns_safety_cap',
            ]);
        }

        $context['patient_turns'][] = $answer;
        $context['conversation'][] = [
            'role' => 'patient',
            'text' => $answer,
            'kind' => 'answer',
        ];
        $previousQuestionLanguage = (string) ($context['question_language'] ?? '');
        $previousDetectedLanguage = (string) ($context['detected_language'] ?? '');
        self::syncQuestionLanguage($context, $answer, false);

        $gemini = self::callGemini('answer', $context, $answer);
        if ($gemini === null) {
            if (self::canContinueWithNlpQuestionBank(
                is_array($context['nlp_precheck'] ?? null) ? $context['nlp_precheck'] : [],
                $context
            )) {
                return self::continueWithNlpQuestionBank(
                    $context,
                    is_array($context['nlp_precheck'] ?? null) ? $context['nlp_precheck'] : [],
                    false
                );
            }
            // Do not invent facts; keep prior facts and surface error.
            array_pop($context['patient_turns']);
            array_pop($context['conversation']);
            $context['question_language'] = $previousQuestionLanguage;
            $context['detected_language'] = $previousDetectedLanguage;
            $detail = self::$lastError !== '' ? (' (' . self::$lastError . ')') : '';

            $quota = self::isGeminiQuotaError(self::$lastError);
            $message = $quota
                ? 'Gemini is unavailable because the Gemini quota was exceeded. No facts were updated.'
                : (self::isRailwayApplicationMissing(self::$lastError)
                    ? 'The Railway AI service was not running (Application not found). No facts were updated.' . $detail
                    : 'Gemini unavailable or returned invalid JSON for this answer. No facts were updated.' . $detail);

            return self::errorResult($context, $message, [
                'raw_error' => self::$lastError,
                'code' => $quota ? 'gemini_quota_exceeded' : '',
            ]);
        }

        return self::applyGeminiTurn($context, $gemini, false);
    }

    /**
     * When Gemini/OpenRouter/Groq cannot run, keep interviewing from NLP-mapped
     * facts and the local question bank. Shared by the demo page and the live
     * patient flow (both call start/answer).
     *
     * @param array<string, mixed> $nlpPrecheck
     * @param array<string, mixed> $context
     */
    private static function canContinueWithNlpQuestionBank(array $nlpPrecheck, array $context): bool
    {
        if (self::$skipPhpOpenRouterQuotaFallback) {
            return false;
        }
        if (!empty($nlpPrecheck['explicit_non_human_subject'])) {
            return false;
        }
        $facts = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : [];
        if (self::hasClinicallyUsefulFacts($facts)) {
            return true;
        }

        return !empty($nlpPrecheck['nlp_says_health']) || !empty($nlpPrecheck['has_validated_mappings']);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $nlpPrecheck
     * @return array<string, mixed>
     */
    private static function continueWithNlpQuestionBank(array $context, array $nlpPrecheck, bool $isStart): array
    {
        self::$aiProviderUsed = 'nlp';
        $evidence = is_array($nlpPrecheck['evidence'] ?? null) ? $nlpPrecheck['evidence'] : [];
        $gloss = trim((string) ($evidence['english_gloss'] ?? ''));
        if ($gloss === '') {
            $gloss = trim((string) ($context['normalized_health_concern'] ?? $context['chief_complaint'] ?? ''));
        }
        $gate = [
            'classification' => self::CLASS_HEALTH,
            'patient_subject' => 'HUMAN',
            'is_human_patient_complaint' => true,
            'confidence' => 0.8,
            'normalized_health_concern' => $gloss,
            'passed' => true,
            'nlp_override' => true,
        ];
        $context['health_gate'] = $gate;
        $context['status_note'] = 'Gemini quota exceeded; continuing with the NLP question bank.';
        if ($gloss !== '' && trim((string) ($context['normalized_health_concern'] ?? '')) === '') {
            $context['normalized_health_concern'] = $gloss;
        }
        $synthetic = [
            'classification' => self::CLASS_HEALTH,
            'patient_subject' => 'HUMAN',
            'is_human_patient_complaint' => true,
            'confidence' => 0.8,
            'normalized_health_concern' => $gloss,
            'answer_status' => 'VALID',
            'question_needed' => true,
            'interview_sufficient' => false,
            'next_question' => '',
            'clinical_facts' => [],
            'missing_information' => [],
        ];

        return self::applyGeminiTurn($context, $synthetic, $isStart);
    }

    private static string $lastError = '';

    /** gemini | openrouter | empty. Display only. Set from the model on the response that supplied this turn's text. */
    private static string $aiProviderUsed = '';

    /** Test-only: count generateContent attempts and stop before a live Google/Railway call. */
    private static bool $geminiQuotaProbe = false;

    /** Test-only. When quota-probing, return this text for gemini-3.8-flash instead of HTTP 429. */
    private static ?string $geminiFallbackSuccessTextForTest = null;

    /** Test-only. When set, generate() does not call Gemini and treats the call as HTTP 429. */
    private static bool $openRouterQuotaProbe = false;

    /** @var (callable(array<string, mixed>): ?string)|null */
    private static $openRouterTransportForTest = null;

    /** @var (callable(array<string, mixed>): ?string)|null */
    private static $bitsTransportForTest = null;

    /**
     * Test-only. Live patient Gemini-led interviews use the same generate()
     * fallbacks as this demo (OpenRouter → Groq → NLP question bank).
     */
    private static bool $skipPhpOpenRouterQuotaFallback = false;

    /** Test-only: times recoverDemoQuotaWithOpenRouter invoked the PHP OpenRouter helper. */
    public static int $phpOpenRouterRecoverCallsForTest = 0;

    public static function beginSkipPhpOpenRouterQuotaFallback(): void
    {
        self::$skipPhpOpenRouterQuotaFallback = true;
    }

    public static function endSkipPhpOpenRouterQuotaFallback(): void
    {
        self::$skipPhpOpenRouterQuotaFallback = false;
    }

    /**
     * @param callable(array<string, mixed>): ?string $transport
     */
    public static function beginOpenRouterQuotaProbeForTest(callable $transport): void
    {
        self::$openRouterQuotaProbe = true;
        self::$openRouterTransportForTest = $transport;
    }

    public static function endOpenRouterQuotaProbeForTest(): void
    {
        self::$openRouterQuotaProbe = false;
        self::$openRouterTransportForTest = null;
    }

    /**
     * Test-only. Mock campus BITS/Ollama for AI_PROVIDER=bits (same Gemini payload).
     *
     * @param callable(array<string, mixed>): ?string $transport
     */
    public static function beginBitsProviderTransportForTest(callable $transport): void
    {
        self::$bitsTransportForTest = $transport;
    }

    public static function endBitsProviderTransportForTest(): void
    {
        self::$bitsTransportForTest = null;
    }

    private static int $directGeminiAttempts = 0;

    public static function beginGeminiQuotaProbeForTest(?string $fallbackSuccessText = null): void
    {
        self::$geminiQuotaProbe = true;
        self::$directGeminiAttempts = 0;
        self::$geminiFallbackSuccessTextForTest = $fallbackSuccessText;
        if (class_exists('AiServiceClient')) {
            AiServiceClient::beginGeminiQuotaProbeForTest();
        }
    }

    public static function endGeminiQuotaProbeForTest(): void
    {
        self::$geminiQuotaProbe = false;
        self::$geminiFallbackSuccessTextForTest = null;
        if (class_exists('AiServiceClient')) {
            AiServiceClient::endGeminiQuotaProbeForTest();
        }
    }

    public static function geminiGenerateAttemptsForTest(): int
    {
        $railway = class_exists('AiServiceClient') ? AiServiceClient::geminiQuotaProbePostsForTest() : 0;

        return self::$directGeminiAttempts + $railway;
    }

    private static function isGeminiQuotaError(string $message): bool
    {
        $msg = strtolower($message);

        return str_contains($msg, '429') || str_contains($msg, 'quota');
    }

    /**
     * Railway's edge returns this JSON when the public host has no running deployment.
     */
    private static function isRailwayApplicationMissing(string $message): bool
    {
        $msg = strtolower($message);

        return str_contains($msg, 'application not found');
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $gemini
     * @return array<string, mixed>
     */
    private static function applyGeminiTurn(array $context, array $gemini, bool $isStart): array
    {
        $factsBeforeTurn = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        // NLP-first grounding: drop invented / non-clinical labels before merge.
        $gemini = self::groundGeminiAgainstEvidence($gemini, $context, $isStart);
        $context['nlp_grounding'] = is_array($gemini['_nlp_grounding'] ?? null) ? $gemini['_nlp_grounding'] : null;
        unset($gemini['_nlp_grounding']);

        $status = strtoupper((string) ($gemini['answer_status'] ?? 'VALID'));
        if (!in_array($status, ['VALID', 'UNCERTAIN', 'UNCLEAR', 'UNRELATED'], true)) {
            $status = 'VALID';
        }

        $latestPatient = '';
        $turns = is_array($context['patient_turns'] ?? null) ? $context['patient_turns'] : [];
        if ($turns !== []) {
            $latestPatient = trim((string) end($turns));
        }
        // PHP owns answer meaning. Uncertain / yes / no must never become an UNCLEAR retry.
        if (!$isStart && $latestPatient !== '') {
            if (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksPatientUncertain($latestPatient)) {
                $status = 'UNCERTAIN';
                $gemini['answer_status'] = 'UNCERTAIN';
            } elseif (in_array($status, ['UNCLEAR', 'UNRELATED'], true)
                && (self::isContextualShortReply($latestPatient)
                    || (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::extractYesNo($latestPatient) !== null))
            ) {
                $status = 'VALID';
                $gemini['answer_status'] = 'VALID';
            }
        }

        // Merge clinical facts (never replace original wording); scrub discourse particles.
        $incoming = is_array($gemini['clinical_facts'] ?? null) ? $gemini['clinical_facts'] : [];
        $merged = self::sanitizeClinicalFacts(self::mergeFacts(
            is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts(),
            $incoming
        ));

        // Map this answer onto findings targeted by the immediately preceding Gemini question.
        if (!$isStart && $latestPatient !== '') {
            $merged = self::applyAnswerToTargetedFindings(
                $merged,
                self::normalizeFindingKeyList($context['awaiting_target_findings'] ?? []),
                $latestPatient,
                $gemini
            );
            // Consumed once applied — do not bleed onto later unrelated questions.
            $context['awaiting_target_findings'] = [];
        }

        $context['clinical_facts'] = $merged;
        $context['last_gemini'] = $gemini;
        $context['last_answer_status'] = $status;
        $context['gemini_called'] = true;
        $context['turn_count'] = (int) ($context['turn_count'] ?? 0) + ($isStart ? 0 : 1);

        // Unrelated / unclear: retry same question once; do not advance.
        if (!$isStart && in_array($status, ['UNCLEAR', 'UNRELATED'], true) && !self::lastQuestionWasRetry($context)) {
            $q = trim((string) ($context['awaiting_question'] ?? ''));
            $retry = trim((string) ($gemini['next_question'] ?? ''));
            if ($retry !== '') {
                $q = $retry;
            }
            if ($q === '') {
                $q = self::neutralRetryQuestion($context);
            }
            $q = self::alignFollowUpQuestionLanguage($q, $context);
            $context['awaiting_question'] = $q;
            // Keep / refresh targets for the same clinical probe.
            $context['awaiting_target_findings'] = self::resolveTargetedFindings($gemini, $q, $context);
            $context['status'] = self::STATUS_INTERVIEWING;
            $context['conversation'][] = [
                'role' => 'gemini',
                'text' => $q,
                'kind' => 'retry',
            ];
            $context['status_note'] = 'Answer was ' . $status . '; asking again. Negative/uncertain replies can still be valid.';

            return self::pack($context, $context['status_note'], $gemini);
        }

        // Patient wording is the source of truth: keep every stated fact, including "no / wala / indi / hindi".
        if (!$isStart && $latestPatient !== '') {
            $context['clinical_facts'] = self::absorbDirectAnswer(
                $context['clinical_facts'],
                (string) ($context['awaiting_question'] ?? ''),
                $latestPatient
            );
        }
        $context['clinical_facts'] = self::sanitizeClinicalFacts(self::harvestStatedFacts(
            $context['clinical_facts'],
            self::patientEvidenceCorpus($context),
            self::corpusTurnStarts($context)
        ));
        $utterance = $isStart
            ? trim((string) ($context['chief_complaint'] ?? ''))
            : $latestPatient;
        $context['clinical_facts'] = self::bindComplaintFacts($context['clinical_facts'], $context, $utterance, $isStart);
        $context['clinical_facts'] = self::fillComplaintsFromGemini($context['clinical_facts'], $incoming, $context, $utterance, $isStart);
        if (!$isStart && $latestPatient !== '') {
            $context = self::resolveAwaitingAnswer(
                $context,
                $latestPatient,
                $status,
                self::ledgerFingerprint($factsBeforeTurn) !== self::ledgerFingerprint($context['clinical_facts'])
            );
            $remember = trim((string) ($context['awaiting_slot'] ?? ''));
            if ($remember === '') {
                $remember = self::questionFactSlot((string) ($context['awaiting_question'] ?? ''));
            }
            self::rememberAskedSlot($context, $remember);
        }

        $sufficient = !empty($gemini['interview_sufficient']) || empty($gemini['question_needed']);
        $nextQ = self::stripAcuityLanguage(trim((string) ($gemini['next_question'] ?? '')));
        $reconciled = self::reconcileFollowUp($context, $nextQ, $sufficient);
        $context = $reconciled['context'];
        $sufficient = $reconciled['sufficient'];
        $nextQ = $reconciled['next_question'];
        $gemini['next_question'] = $nextQ;
        $gemini['missing_information'] = $context['missing_information'];
        $gemini['question_needed'] = !$sufficient && $nextQ !== '';
        $gemini['interview_sufficient'] = $sufficient;
        if (!empty($reconciled['replaced_slot'])) {
            $gemini['targeted_findings'] = [(string) $reconciled['replaced_slot']];
        }

        if (!$sufficient && $nextQ !== '') {
            $lang = self::detectLanguageHint($context);
            if (!self::followUpMatchesLanguage($nextQ, $lang)) {
                $slot = self::questionFactSlot($nextQ);
                if ($slot === '' || !self::missingListContainsSlot($context['missing_information'], $slot)) {
                    $slot = self::missingSlotId((string) ($context['missing_information'][0] ?? 'symptom'));
                }
                $nextQ = self::simpleQuestionForSlot($slot, $context);
                $gemini['targeted_findings'] = [$slot];
            }
            $gemini['next_question'] = $nextQ;
        }

        $askedFollowups = self::followUpQuestionCount($context);
        $missingNow = is_array($context['missing_information'] ?? null) ? $context['missing_information'] : [];
        if (!$sufficient && $nextQ !== ''
            && !self::shouldAskAnotherFollowUp($context, $missingNow, $askedFollowups)
        ) {
            $sufficient = true;
            $nextQ = '';
            $gemini['next_question'] = '';
            $gemini['question_needed'] = false;
            $gemini['interview_sufficient'] = true;
            $context['awaiting_slot'] = '';
            $context['awaiting_complaint_id'] = '';
            $context['awaiting_target_findings'] = [];
        }

        if ($sufficient || $nextQ === '') {
            $context['awaiting_question'] = '';
            $context['awaiting_target_findings'] = [];
            $context['status'] = self::STATUS_SUFFICIENT;

            return self::finalizeWithClinicalEngine($context, $gemini);
        }

        $context['awaiting_question'] = $nextQ;
        $context['awaiting_target_findings'] = self::resolveTargetedFindings($gemini, $nextQ, $context);
        $context['status'] = self::STATUS_INTERVIEWING;
        $context['conversation'][] = [
            'role' => 'gemini',
            'text' => $nextQ,
            'kind' => 'followup',
        ];

        return self::pack($context, 'Follow-up question ready.', $gemini);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $gemini
     * @return array<string, mixed>
     */
    private static function finalizeWithClinicalEngine(array $context, ?array $gemini): array
    {
        // Only genuine patient-supported clinical facts — never filler/particle translations.
        $facts = self::sanitizeClinicalFacts(
            is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts()
        );
        $context['clinical_facts'] = $facts;
        $mapped = self::mapFactsForEngine($facts);
        $patientEvidence = self::patientEvidenceText($context);
        // Engine haystack: patient wording first; structured facts only if clinically genuine.
        $factsText = class_exists('ClinicalInterviewEngine')
            ? ClinicalInterviewEngine::factsHaystack($mapped)
            : self::simpleFactsHaystack($mapped);

        $caseParts = array_filter([
            $patientEvidence !== '' ? $patientEvidence : trim((string) ($context['chief_complaint'] ?? '')),
            $factsText,
        ], static fn (string $p): bool => $p !== '');
        $caseText = trim(implode('. ', $caseParts));

        $interviewFacts = self::interviewFactsForEngine(
            $facts,
            $mapped,
            $patientEvidence !== '' ? $patientEvidence : (string) ($context['chief_complaint'] ?? ''),
            (string) ($context['active_complaint_id'] ?? '')
        );

        $engineResult = null;
        $display = 'NON-URGENT';
        $engineError = '';
        try {
            if (!class_exists('ClinicalTriageEngine')) {
                throw new RuntimeException('ClinicalTriageEngine not available');
            }
            $engineResult = ClinicalTriageEngine::assess(
                $caseText,
                $caseText,
                [],
                [],
                0,
                true,
                $interviewFacts
            );
            $display = strtoupper(str_replace('_', '-', (string) ($engineResult['triage_display'] ?? 'NON-URGENT')));
            if (!in_array($display, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
                $display = 'NON-URGENT';
            }
        } catch (Throwable $e) {
            $engineError = $e->getMessage();
            error_log('GeminiClinicalInterviewDemo triage: ' . $e->getMessage());
        }

        // Strip any Gemini urgency opinion from the public demo payload.
        if (is_array($gemini)) {
            unset(
                $gemini['triage'],
                $gemini['urgency'],
                $gemini['triage_level'],
                $gemini['triage_display'],
                $gemini['diagnosis'],
                $gemini['prescription'],
                $gemini['treatment']
            );
        }
        // Acuity words must never appear in Gemini interview text.
        if (is_array($gemini) && isset($gemini['next_question'])) {
            $gemini['next_question'] = self::stripAcuityLanguage((string) $gemini['next_question']);
        }

        $context['status'] = self::STATUS_FINAL;
        $context['awaiting_question'] = '';
        $context['engine_case_text'] = $caseText;
        $context['engine_mapped_facts'] = $mapped;
        $context['final_triage'] = [
            'triage_display' => $display,
            'triage_classification' => (string) ($engineResult['triage_classification'] ?? ''),
            'triage_level' => (string) ($engineResult['triage_level'] ?? ''),
            'confidence_score' => (int) ($engineResult['confidence_score'] ?? 0),
            'red_flags' => is_array($engineResult['red_flags'] ?? null) ? $engineResult['red_flags'] : [],
            'reason' => (string) ($engineResult['reason'] ?? ($engineResult['clinical_reasoning'] ?? '')),
            'recommended_action' => (string) ($engineResult['recommended_action'] ?? ($engineResult['recommendation'] ?? '')),
            'final_authority' => 'ClinicalTriageEngine',
            'engine_error' => $engineError,
        ];
        $context['engine_result'] = $engineResult;
        $context['conversation'][] = [
            'role' => 'system',
            'text' => 'Interview complete. Final triage from ClinicalTriageEngine: ' . $display,
            'kind' => 'final',
        ];

        return self::pack($context, 'Final triage from existing ClinicalTriageEngine.', $gemini);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $debugGemini
     * @return array<string, mixed>
     */
    private static function pack(array $context, string $message, ?array $debugGemini): array
    {
        return [
            'demo' => true,
            'message' => $message,
            'status' => (string) ($context['status'] ?? self::STATUS_INTERVIEWING),
            'status_label' => self::statusLabel((string) ($context['status'] ?? '')),
            'chief_complaint' => (string) ($context['chief_complaint'] ?? ''),
            'awaiting_question' => (string) ($context['awaiting_question'] ?? ''),
            'awaiting_target_findings' => self::normalizeFindingKeyList($context['awaiting_target_findings'] ?? []),
            'conversation' => is_array($context['conversation'] ?? null) ? $context['conversation'] : [],
            'clinical_facts' => is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts(),
            'missing_information' => is_array($context['missing_information'] ?? null) ? $context['missing_information'] : [],
            'last_answer_status' => (string) ($context['last_answer_status'] ?? ''),
            'final_triage' => is_array($context['final_triage'] ?? null) ? $context['final_triage'] : null,
            'interview_context' => $context,
            'gemini_called' => !empty($context['gemini_called']),
            'ai_provider_used' => self::aiProviderUsedLabel(),
            'health_gate' => is_array($context['health_gate'] ?? null) ? $context['health_gate'] : null,
            'debug' => [
                'gemini_raw_structured' => $debugGemini,
                'health_gate' => is_array($context['health_gate'] ?? null) ? $context['health_gate'] : null,
                'nlp_grounding' => is_array($context['nlp_grounding'] ?? null) ? $context['nlp_grounding'] : null,
                'nlp_precheck' => is_array($context['nlp_precheck'] ?? null) ? $context['nlp_precheck'] : null,
                'engine_case_text' => (string) ($context['engine_case_text'] ?? ''),
                'engine_mapped_facts' => $context['engine_mapped_facts'] ?? null,
                'engine_result' => $context['engine_result'] ?? null,
                'pipeline' => 'Patient input → NLP/domain validation → Gemini Flash semantic (when needed) → confirmed facts → ClinicalTriageEngine (WHO IITT) → final triage',
                'gemini_must_not' => [
                    'set_final_triage',
                    'assign_emergency_urgent_non_urgent',
                    'diagnose',
                    'prescribe',
                    'bypass_who_iitt',
                    'start_interview_on_non_health',
                    'invent_unsupported_clinical_facts',
                    'influence_final_acuity',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $extra
     * @return array<string, mixed>
     */
    private static function errorResult(array $context, string $message, ?array $extra): array
    {
        $context['status'] = self::STATUS_ERROR;
        $pack = self::pack($context, $message, null);
        $pack['error'] = true;
        if (is_array($extra)) {
            if (($extra['code'] ?? '') === 'gemini_quota_exceeded') {
                $pack['code'] = 'gemini_quota_exceeded';
            }
            $pack['debug'] = array_merge(is_array($pack['debug'] ?? null) ? $pack['debug'] : [], $extra);
        }

        return $pack;
    }

    /**
     * Reject opening input that is not a confirmed health concern. Interview must not start.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $extra
     * @return array<string, mixed>
     */
    private static function rejectNonHealth(array $context, string $classification, string $message, ?array $extra): array
    {
        $context['status'] = self::STATUS_NEEDS_HEALTH;
        $context['awaiting_question'] = '';
        $context['chief_complaint'] = ''; // do not lock a non-health opening as the complaint
        $gate = is_array($extra['health_gate'] ?? null)
            ? $extra['health_gate']
            : [
                'classification' => $classification,
                'confidence' => null,
                'normalized_health_concern' => '',
                'passed' => false,
            ];
        $gate['passed'] = false;
        $context['health_gate'] = $gate;
        // Never carry NLP-seeded facts into an out-of-scope / non-health rejection.
        $context['clinical_facts'] = self::blankFacts();
        $context['missing_information'] = [];
        $context['conversation'][] = [
            'role' => 'system',
            'text' => $message,
            'kind' => 'health_gate_reject',
        ];

        $pack = self::pack($context, $message, is_array($extra['gemini_raw_structured'] ?? null) ? $extra['gemini_raw_structured'] : null);
        $pack['error'] = true;
        $pack['rejected'] = true;
        $pack['needs_health_concern'] = true;
        if (is_array($extra) && ($extra['code'] ?? '') === 'gemini_quota_exceeded') {
            $pack['code'] = 'gemini_quota_exceeded';
        }
        $pack['health_classification'] = $classification;
        if (!empty($gate['out_of_scope_non_human']) || strtoupper((string) ($gate['patient_subject'] ?? '')) === 'NON_HUMAN') {
            $pack['out_of_scope'] = true;
            $pack['routing'] = 'OUT_OF_SCOPE';
            $pack['patient_subject'] = 'NON_HUMAN';
        }
        if (is_string($extra['routing'] ?? null) && ($extra['routing'] ?? '') !== '') {
            $pack['routing'] = (string) $extra['routing'];
        }
        // Do not leave a half-started interview context for the client.
        $pack['interview_context'] = [];
        $pack['awaiting_question'] = '';
        $pack['clinical_facts'] = self::blankFacts();
        if (is_array($extra)) {
            $pack['debug'] = array_merge(is_array($pack['debug'] ?? null) ? $pack['debug'] : [], $extra);
        }

        return $pack;
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_INTERVIEWING => 'Interviewing',
            self::STATUS_SUFFICIENT => 'Information sufficient',
            self::STATUS_FINAL => 'Final triage',
            self::STATUS_ERROR => 'Error',
            self::STATUS_NEEDS_HEALTH => 'Needs a health concern',
            default => $status,
        };
    }

    /**
     * Normalize and whitelist Gemini health-gate classification.
     * Only HEALTH_RELATED | NON_HEALTH_RELATED | UNCLEAR are accepted; anything else fails closed.
     * Also enforces human-patient scope: NON_HUMAN subjects → NON_HEALTH_RELATED / OUT_OF_SCOPE.
     *
     * @param array<string, mixed> $gemini
     * @return array{
     *   classification:string,
     *   confidence:float|null,
     *   normalized_health_concern:string,
     *   passed:bool,
     *   patient_subject:string,
     *   out_of_scope_non_human:bool
     * }
     */
    private static function normalizeHealthGate(array $gemini): array
    {
        $class = strtoupper(trim((string) ($gemini['classification'] ?? '')));
        $class = str_replace([' ', '-'], '_', $class);

        // Strict allow-list only (plus trivial spelling aliases of the three labels).
        if ($class === 'HEALTH' || $class === 'HEALTH_RELATED') {
            $class = self::CLASS_HEALTH;
        } elseif ($class === 'NON_HEALTH' || $class === 'NON_HEALTH_RELATED' || $class === 'OUT_OF_SCOPE') {
            $class = self::CLASS_NON_HEALTH;
        } elseif ($class === 'UNCLEAR') {
            $class = self::CLASS_UNCLEAR;
        } else {
            // Unexpected label → fail closed (do not start interview).
            $class = self::CLASS_UNCLEAR;
        }

        $subject = self::normalizePatientSubject($gemini);
        $outOfScopeNonHuman = ($subject === 'NON_HUMAN');
        // Animal / non-human health talk is never a medConnect human clinical interview.
        if ($outOfScopeNonHuman) {
            $class = self::CLASS_NON_HEALTH;
        }

        $confidence = null;
        if (isset($gemini['confidence']) && is_numeric($gemini['confidence'])) {
            $confidence = (float) $gemini['confidence'];
            if ($confidence > 1.0 && $confidence <= 100.0) {
                $confidence /= 100.0;
            }
            $confidence = max(0.0, min(1.0, $confidence));
        }

        $normalized = trim((string) ($gemini['normalized_health_concern'] ?? ''));
        if ($outOfScopeNonHuman) {
            $normalized = '';
        }

        return [
            'classification' => $class,
            'confidence' => $confidence,
            'normalized_health_concern' => $normalized,
            'passed' => $class === self::CLASS_HEALTH && !$outOfScopeNonHuman,
            'patient_subject' => $subject,
            'out_of_scope_non_human' => $outOfScopeNonHuman,
        ];
    }

    /**
     * Semantic subject of the complaint: HUMAN patient vs animal/non-human.
     *
     * @param array<string, mixed> $gemini
     */
    private static function normalizePatientSubject(array $gemini): string
    {
        $raw = strtoupper(trim((string) (
            $gemini['patient_subject']
            ?? $gemini['subject_scope']
            ?? $gemini['complaint_subject']
            ?? ''
        )));
        $raw = str_replace([' ', '-'], '_', $raw);

        if (in_array($raw, ['HUMAN', 'PERSON', 'PATIENT', 'HUMAN_PATIENT', 'SELF'], true)) {
            return 'HUMAN';
        }
        if (in_array($raw, [
            'NON_HUMAN', 'ANIMAL', 'PET', 'VETERINARY', 'VET', 'LIVESTOCK',
            'NONHUMAN', 'OTHER_SPECIES', 'ANIMAL_PATIENT',
        ], true)) {
            return 'NON_HUMAN';
        }
        if ($raw === 'UNCLEAR' || $raw === 'UNKNOWN' || $raw === 'AMBIGUOUS') {
            return 'UNCLEAR';
        }

        // Explicit boolean flags from the model (if provided).
        if (array_key_exists('is_human_patient_complaint', $gemini)) {
            if ($gemini['is_human_patient_complaint'] === false || $gemini['is_human_patient_complaint'] === 0
                || $gemini['is_human_patient_complaint'] === 'false'
            ) {
                return 'NON_HUMAN';
            }
            if ($gemini['is_human_patient_complaint'] === true || $gemini['is_human_patient_complaint'] === 1
                || $gemini['is_human_patient_complaint'] === 'true'
            ) {
                return 'HUMAN';
            }
        }

        return 'UNCLEAR';
    }

    /**
     * @return array<string, mixed>
     */
    private static function blankContext(string $complaint = ''): array
    {
        return [
            'chief_complaint' => $complaint,
            'patient_turns' => [],
            'conversation' => [],
            'awaiting_question' => '',
            /** @var list<string> Canonical finding keys targeted by the pending Gemini question */
            'awaiting_target_findings' => [],
            'clinical_facts' => self::blankFacts(),
            'missing_information' => [],
            'active_complaint_id' => '',
            'awaiting_complaint_id' => '',
            'awaiting_slot' => '',
            /** @var list<string> Clinical slots already asked and answered this interview */
            'asked_slots' => [],
            /** @var array<string, string> Patient wording kept for slots it did not resolve (never a fact) */
            'unresolved_answers' => [],
            'question_language' => '',
            'detected_language' => '',
            'status' => self::STATUS_INTERVIEWING,
            'turn_count' => 0,
            'gemini_called' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function blankFacts(): array
    {
        return [
            'symptom' => null,
            'location' => null,
            'laterality' => null,
            'pain_score' => null,
            'onset' => null,
            'duration' => null,
            'frequency' => null,
            'associated_symptoms' => [],
            'relevant_negatives' => [],
            'warning_signs' => [],
            'notes' => [],
            // Existing ClinicalInterviewEngine polarity / provenance architecture
            'finding_status' => [],
            'symptoms_patient' => [],
            'symptoms_kb' => [],
            'symptoms_ai' => [],
            'negative_symptoms' => [],
            'patient_uncertain' => false,
            'complaints' => [],
        ];
    }

    /**
     * @param array<string, mixed> $prior
     * @return array<string, mixed>
     */
    private static function normalizeContext(array $prior): array
    {
        if (isset($prior['interview_context']) && is_array($prior['interview_context'])) {
            $prior = $prior['interview_context'];
        }
        $base = self::blankContext(trim((string) ($prior['chief_complaint'] ?? '')));
        foreach (array_keys($base) as $key) {
            if (array_key_exists($key, $prior)) {
                $base[$key] = $prior[$key];
            }
        }
        if (!is_array($base['clinical_facts'] ?? null)) {
            $base['clinical_facts'] = self::blankFacts();
        } else {
            $base['clinical_facts'] = self::mergeFacts(self::blankFacts(), $base['clinical_facts']);
        }
        if (!is_array($base['conversation'] ?? null)) {
            $base['conversation'] = [];
        }
        if (!is_array($base['patient_turns'] ?? null)) {
            $base['patient_turns'] = [];
        }
        if (!is_array($base['missing_information'] ?? null)) {
            $base['missing_information'] = [];
        }
        if (!is_array($base['awaiting_target_findings'] ?? null)) {
            $base['awaiting_target_findings'] = [];
        } else {
            $base['awaiting_target_findings'] = self::normalizeFindingKeyList($base['awaiting_target_findings']);
        }
        if (!is_array($base['asked_slots'] ?? null)) {
            $base['asked_slots'] = [];
        } else {
            $clean = [];
            foreach ($base['asked_slots'] as $slot) {
                $slot = self::canonicalInterviewSlot((string) $slot);
                if ($slot !== '' && !in_array($slot, $clean, true)) {
                    $clean[] = $slot;
                }
            }
            $base['asked_slots'] = $clean;
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private static function mergeFacts(array $base, array $incoming): array
    {
        foreach (['symptom', 'location', 'laterality', 'onset', 'duration', 'frequency'] as $key) {
            if (!array_key_exists($key, $incoming)) {
                continue;
            }
            $val = $incoming[$key];
            if ($val === null || $val === '' || !is_scalar($val)) {
                continue;
            }
            $incomingVal = trim((string) $val);
            if ($incomingVal === '' || self::isNonClinicalDiscourseLabel($incomingVal)) {
                // Bare "wala" is Hiligaynon for left when it is stored as laterality.
                if (!($key === 'laterality' && self::isLateralityToken($incomingVal))) {
                    continue;
                }
            }
            $existing = trim((string) ($base[$key] ?? ''));
            if ($existing === '') {
                $base[$key] = $incomingVal;
                continue;
            }
            if (self::sameClinicalLabel($existing, $incomingVal)) {
                continue;
            }
            // A second complaint or site must be kept, not written over the first.
            if ($key === 'symptom') {
                $assoc = self::stringList($base['associated_symptoms'] ?? []);
                if (!self::listHasLabel($assoc, $incomingVal)) {
                    $assoc[] = $incomingVal;
                    $base['associated_symptoms'] = $assoc;
                }
                continue;
            }
            $note = 'also ' . $key . ': ' . $incomingVal;
            $notes = self::stringList($base['notes'] ?? []);
            if (!in_array($note, $notes, true)) {
                $notes[] = $note;
                $base['notes'] = $notes;
            }
        }

        if (array_key_exists('pain_score', $incoming) && $incoming['pain_score'] !== null && $incoming['pain_score'] !== '') {
            if (is_numeric($incoming['pain_score'])) {
                $score = (int) $incoming['pain_score'];
                if ($score >= 1 && $score <= 10) {
                    $base['pain_score'] = $score;
                }
            }
        }

        foreach (['associated_symptoms', 'relevant_negatives', 'warning_signs', 'notes', 'symptoms_patient', 'symptoms_kb', 'symptoms_ai', 'negative_symptoms'] as $listKey) {
            $cur = self::stringList($base[$listKey] ?? []);
            $add = self::stringList($incoming[$listKey] ?? []);
            foreach ($add as $item) {
                if ($item !== '' && !in_array($item, $cur, true)) {
                    $cur[] = $item;
                }
            }
            $base[$listKey] = $cur;
        }

        if (array_key_exists('patient_uncertain', $incoming) && $incoming['patient_uncertain']) {
            $base['patient_uncertain'] = true;
        }

        // finding_status: later writes win per key; normalize to positive|negative|uncertain|not_assessed
        if (isset($incoming['finding_status']) && is_array($incoming['finding_status'])) {
            $cur = is_array($base['finding_status'] ?? null) ? $base['finding_status'] : [];
            foreach ($incoming['finding_status'] as $rawKey => $rawVal) {
                $key = self::normalizeFindingKey((string) $rawKey);
                if ($key === '') {
                    continue;
                }
                $norm = self::normalizeFindingStatusValue($rawVal);
                if ($norm === null) {
                    continue;
                }
                $cur[$key] = $norm;
            }
            $base['finding_status'] = $cur;
        }

        // Per-complaint records are owned by the interview. A model payload must
        // not replace a ledger already built from the patient's words.
        $baseList = is_array($base['complaints'] ?? null) ? $base['complaints'] : [];
        $incomingList = is_array($incoming['complaints'] ?? null) ? $incoming['complaints'] : [];
        $base['complaints'] = $baseList !== [] ? $baseList : $incomingList;

        return $base;
    }

    /**
     * Map demo facts → ClinicalInterviewEngine / ClinicalTriageEngine fact shape.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    public static function mapFactsForEngine(array $facts): array
    {
        $mapped = class_exists('ClinicalInterviewEngine')
            ? ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts']
            : [];

        $symptoms = [];
        $symptom = trim((string) ($facts['symptom'] ?? ''));
        if ($symptom !== '' && !self::isNonClinicalDiscourseLabel($symptom)) {
            $symptoms[] = $symptom;
        }
        foreach (self::stringList($facts['associated_symptoms'] ?? []) as $s) {
            if ($s !== '' && !self::isNonClinicalDiscourseLabel($s) && !in_array($s, $symptoms, true)) {
                $symptoms[] = $s;
            }
        }
        $mapped['symptoms'] = $symptoms;
        $mapped['associated_symptoms'] = array_values(array_filter(
            self::stringList($facts['associated_symptoms'] ?? []),
            static fn (string $s): bool => !self::isNonClinicalDiscourseLabel($s)
        ));

        $locs = [];
        $loc = trim((string) ($facts['location'] ?? ''));
        if ($loc !== '' && !self::isNonClinicalDiscourseLabel($loc)) {
            $locs[] = $loc;
        }
        $side = trim((string) ($facts['laterality'] ?? ''));
        if ($side !== '' && !self::isNonClinicalDiscourseLabel($side)) {
            $locs[] = $side;
        }
        $mapped['body_locations'] = $locs;

        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
            $mapped['pain_score'] = (int) $facts['pain_score'];
        }
        $onset = trim((string) ($facts['onset'] ?? ''));
        if ($onset !== '') {
            $mapped['onset'] = $onset;
        }
        $duration = trim((string) ($facts['duration'] ?? ''));
        if ($duration !== '') {
            $mapped['duration_label'] = $duration;
        }

        // factsHaystack prefixes "no " / "wala ko " — store bare symptom labels only.
        $negatives = [];
        foreach (self::stringList($facts['relevant_negatives'] ?? []) as $neg) {
            $n = mb_strtolower(trim($neg));
            $bare = trim((string) preg_replace(
                '/^(no|wala(\s+ko)?|walang|without|denies)\s+/ui',
                '',
                $n
            ));
            if ($bare === '') {
                $bare = $n;
            }
            if ($bare !== '' && !self::isNonClinicalDiscourseLabel($bare) && !in_array($bare, $negatives, true)) {
                $negatives[] = $bare;
            }
            if (preg_match('/\b(breath|ginhawa|dyspnea|shortness)\b/u', $n)) {
                $mapped['breathing_difficulty'] = false;
            }
            if (preg_match('/\b(weakness|kaluya|numb)\b/u', $n)) {
                $mapped['weakness'] = false;
            }
            if (preg_match('/\b(fever|lagnat|hilanat)\b/u', $n)) {
                $mapped['fever_confirmed'] = false;
            }
            if (preg_match('/\b(other\s+symptom|iban.*sintomas|additional)\b/u', $n)
                || $n === 'no other symptoms'
                || $n === 'wala'
                || $bare === 'other symptoms'
            ) {
                $mapped['has_other_symptoms'] = false;
            }
        }
        $mapped['negative_symptoms'] = $negatives;

        foreach (self::stringList($facts['warning_signs'] ?? []) as $w) {
            if (self::isNonClinicalDiscourseLabel($w)) {
                continue;
            }
            $wLow = mb_strtolower($w);
            if (preg_match('/\b(breath|ginhawa|dyspnea)\b/u', $wLow)) {
                $mapped['breathing_difficulty'] = true;
            }
            if (preg_match('/\b(weakness|kaluya|one[- ]sided)\b/u', $wLow)) {
                $mapped['weakness'] = true;
            }
            if (preg_match('/\b(chest|dughan|dibdib)\b/u', $wLow) && preg_match('/\b(radiat|spread|kakagat)\b/u', $wLow)) {
                $mapped['chest_radiation'] = true;
            }
            if (!in_array($w, $symptoms, true)) {
                $symptoms[] = $w;
            }
        }
        $mapped['symptoms'] = $symptoms;

        // Pass structured finding_status / provenance through to ClinicalTriageEngine facts.
        $findingStatus = [];
        if (isset($facts['finding_status']) && is_array($facts['finding_status'])) {
            foreach ($facts['finding_status'] as $rawKey => $rawVal) {
                $key = self::normalizeFindingKey((string) $rawKey);
                $norm = self::normalizeFindingStatusValue($rawVal);
                if ($key === '' || $norm === null) {
                    continue;
                }
                $findingStatus[$key] = $norm;
            }
        }
        $mapped['finding_status'] = $findingStatus;
        foreach (['symptoms_patient', 'symptoms_kb', 'symptoms_ai'] as $provKey) {
            $mapped[$provKey] = array_values(array_filter(
                self::stringList($facts[$provKey] ?? []),
                static fn (string $s): bool => !self::isNonClinicalDiscourseLabel($s)
            ));
        }
        if (!empty($facts['patient_uncertain'])) {
            $mapped['patient_uncertain'] = true;
        }

        // Apply finding_status polarity onto engine boolean keys (no triage assignment).
        $mapped = self::applyFindingStatusToEngineBooleans($mapped, $findingStatus);

        return $mapped;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function patientEvidenceText(array $context): string
    {
        $parts = [];
        $cc = trim((string) ($context['chief_complaint'] ?? ''));
        if ($cc !== '') {
            $parts[] = $cc;
        }
        foreach ((array) ($context['patient_turns'] ?? []) as $turn) {
            $t = trim((string) $turn);
            if ($t !== '' && $t !== $cc) {
                $parts[] = $t;
            }
        }

        return trim(implode('. ', $parts));
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function simpleFactsHaystack(array $facts): string
    {
        $parts = [];
        if (($facts['pain_score'] ?? null) !== null) {
            $parts[] = 'pain ' . (int) $facts['pain_score'] . '/10';
        }
        foreach (self::stringList($facts['body_locations'] ?? []) as $loc) {
            $parts[] = $loc;
        }
        foreach (self::stringList($facts['symptoms'] ?? []) as $s) {
            $parts[] = $s;
        }
        foreach (self::stringList($facts['negative_symptoms'] ?? []) as $n) {
            $parts[] = 'no ' . $n;
        }

        return implode('. ', $parts);
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     */
    private static function needsPainScore(array $facts, array $context, string $scope = ''): bool
    {
        if (($facts['pain_score'] ?? null) !== null && (int) $facts['pain_score'] >= 1 && (int) $facts['pain_score'] <= 10) {
            return false;
        }
        // Only patient-stated wording counts — never Gemini-invented fact labels.
        $hay = $scope !== '' ? mb_strtolower($scope) : mb_strtolower(self::patientEvidenceCorpus($context));

        return (bool) preg_match(
            '/\b(sakit|masakit|pain|hapdi|kasakit|gasakit|hurts?|sumasakit)\b/u',
            $hay
        );
    }

    /**
     * Locked follow-up language. Later turns stay in this language unless the
     * patient clearly switches. Uses HiligaynonLanguageDetector plus the existing
     * ClinicalInterviewEngine dominant-language resolution (mixed → dominant).
     *
     * @param array<string, mixed> $context
     */
    private static function syncQuestionLanguage(array &$context, string $utterance, bool $isOpening): void
    {
        $utterance = trim($utterance);
        $current = strtolower(trim((string) ($context['question_language'] ?? '')));
        if (!in_array($current, ['english', 'tagalog', 'hiligaynon'], true)) {
            $seed = trim((string) ($context['chief_complaint'] ?? ''));
            if ($seed === '') {
                $seed = $utterance;
            }
            if ($seed === '') {
                return;
            }
            $context['question_language'] = self::resolveQuestionLanguage($seed);
            $context['detected_language'] = self::detectedLanguageLabel($seed);
            $current = (string) $context['question_language'];
            if ($isOpening || $utterance === '' || $utterance === $seed) {
                return;
            }
        }
        if ($isOpening || $utterance === '') {
            return;
        }
        $resolved = self::resolveQuestionLanguage($utterance);
        if (!self::utteranceClearlySwitchesLanguage($utterance, $current, $resolved)) {
            return;
        }
        $context['question_language'] = $resolved;
        $context['detected_language'] = self::detectedLanguageLabel($utterance);
    }

    /**
     * Question language for one patient-authored string. Mixed input follows
     * the detector's dominant language. Does not rewrite the string.
     */
    private static function resolveQuestionLanguage(string $text): string
    {
        $text = trim($text);
        if ($text === '' || !class_exists('HiligaynonLanguageDetector')) {
            return 'english';
        }
        try {
            $detected = HiligaynonLanguageDetector::detect($text);
        } catch (Throwable) {
            return 'english';
        }
        if (class_exists('ClinicalInterviewEngine')) {
            $lang = strtolower(ClinicalInterviewEngine::questionLanguageFromDetection($detected, $text));
            if (in_array($lang, ['english', 'tagalog', 'hiligaynon'], true)) {
                return $lang;
            }
        }
        $primary = strtolower((string) ($detected['primary'] ?? ''));
        $dominant = strtolower((string) ($detected['dominant'] ?? $primary));
        $pick = $primary === 'mixed' ? $dominant : ($primary !== '' ? $primary : $dominant);

        return match ($pick) {
            'tagalog', 'filipino' => 'tagalog',
            'english', 'en' => 'english',
            'hiligaynon', 'ilonggo' => 'hiligaynon',
            default => 'english',
        };
    }

    private static function detectedLanguageLabel(string $text): string
    {
        if ($text === '' || !class_exists('HiligaynonLanguageDetector')) {
            return 'ENGLISH';
        }
        try {
            $primary = (string) (HiligaynonLanguageDetector::detect($text)['primary'] ?? 'english');
        } catch (Throwable) {
            return 'ENGLISH';
        }
        if (class_exists('ClinicalInterviewEngine')) {
            return ClinicalInterviewEngine::languageLabel($primary);
        }

        return strtoupper($primary !== '' ? $primary : 'english');
    }

    /**
     * Short answers, scores, and brief fragments do not change question language.
     */
    private static function utteranceClearlySwitchesLanguage(string $utterance, string $current, string $resolved): bool
    {
        if ($resolved === '' || $resolved === $current) {
            return false;
        }
        if (class_exists('HiligaynonLanguageDetector') && !HiligaynonLanguageDetector::hasLexicalEvidence($utterance)) {
            return false;
        }
        if (self::isContextualShortReply($utterance) || self::isLanguageNeutralUtterance($utterance)) {
            return false;
        }
        $words = preg_split('/\s+/u', trim($utterance)) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));

        return count($words) >= 4;
    }

    private static function isLanguageNeutralUtterance(string $text): bool
    {
        $low = mb_strtolower(trim($text));
        $low = trim((string) preg_replace('/[.!?…]+$/u', '', $low));
        if (preg_match('/^(\d{1,2})(\s*(\/|out of|tubtob|hanggang|to)\s*10)?$/ui', $low)) {
            return true;
        }
        $letters = preg_replace('/[^\p{L}]+/u', '', $low) ?? '';

        return mb_strlen($letters) < 3;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function detectLanguageHint(array $context): string
    {
        $locked = strtolower(trim((string) ($context['question_language'] ?? '')));
        if (in_array($locked, ['english', 'tagalog', 'hiligaynon'], true)) {
            return $locked;
        }
        $seed = trim((string) ($context['chief_complaint'] ?? ''));

        return $seed !== '' ? self::resolveQuestionLanguage($seed) : 'english';
    }

    private static function questionLanguageName(string $lang): string
    {
        return match (self::detectLanguageHint(['question_language' => $lang])) {
            'hiligaynon' => 'Hiligaynon/Ilonggo',
            'tagalog' => 'Tagalog',
            default => 'English',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function questionLanguagePromptBlock(array $context): string
    {
        $lang = self::detectLanguageHint($context);
        $name = self::questionLanguageName($lang);

        return "QUESTION LANGUAGE: {$name}.\n"
            . "The patient's latest message sets this language. Write next_question only in {$name}.\n"
            . "Use simple everyday words a patient can answer easily. Do not translate word-for-word from English.\n"
            . "Do not use deep, technical, or formal wording. Sound like a real person asking one short question.\n"
            . "Keep this language on later questions unless the patient clearly switches language.\n"
            . "Do not translate or rewrite the patient's complaint or answers. Only next_question is phrased in {$name}.\n"
            . "Preserve the patient's own wording in clinical_facts. Do not add findings. Do not output EMERGENCY, URGENT, or NON-URGENT.\n\n";
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function neutralRetryQuestion(array $context): string
    {
        return match (self::detectLanguageHint($context)) {
            'hiligaynon' => 'Palihog sabat liwat sa pamangkot.',
            'tagalog' => 'Pakisagot ulit ang tanong.',
            default => 'Please answer the question again.',
        };
    }

    /**
     * If Gemini phrased the follow-up in another language, rephrase that same
     * clinical question into the locked patient language. Facts are not touched.
     *
     * @param array<string, mixed> $context
     */
    private static function alignFollowUpQuestionLanguage(string $question, array $context): string
    {
        $question = trim($question);
        if ($question === '') {
            return '';
        }
        $lang = self::detectLanguageHint($context);
        if (self::followUpMatchesLanguage($question, $lang)) {
            return $question;
        }
        $rewritten = self::stripAcuityLanguage(self::rephraseFollowUpInLanguage($question, $lang));
        if ($rewritten !== '' && self::followUpMatchesLanguage($rewritten, $lang)) {
            return $rewritten;
        }

        return $question;
    }

    private static function rephraseFollowUpInLanguage(string $question, string $lang): string
    {
        $name = self::questionLanguageName($lang);
        $user = "Rewrite this clinical follow-up question into {$name} only.\n"
            . "Preserve the same clinical meaning and the same missing information being asked.\n"
            . "Do not add symptoms, body locations, severity, or a second question.\n"
            . "Do not assign triage or urgency.\n"
            . "Return JSON only: {\"next_question\":\"...\"}\n\n"
            . "Question:\n" . $question;
        $payload = self::requestPayload($user, false);
        $payload['systemInstruction'] = [
            'parts' => [[
                'text' => 'You rewrite one clinical follow-up question into the requested patient language. '
                    . 'Preserve clinical meaning. Do not diagnose, prescribe, or assign EMERGENCY, URGENT, or NON-URGENT. '
                    . 'Respond with JSON only: {"next_question":"..."}',
            ]],
        ];
        try {
            $raw = trim(self::generate($payload));
        } catch (Throwable) {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            return self::stripAcuityLanguage($raw);
        }

        return self::stripAcuityLanguage(trim((string) ($decoded['next_question'] ?? '')));
    }

    /**
     * @param array<string, mixed> $detection
     */
    private static function questionSurfaceIsEnglish(string $question, array $detection): bool
    {
        if (class_exists('MedicalDictionary') && MedicalDictionary::isLikelyEnglish($question)) {
            return true;
        }
        $primary = strtolower((string) ($detection['primary'] ?? ''));
        $dominant = strtolower((string) ($detection['dominant'] ?? ''));

        return $primary === 'english' || $dominant === 'english';
    }

    /**
     * English medical lexicon with no local-term hit (for example a bare English
     * symptom word). Reuses MedicalDictionary; not a second language detector.
     */
    private static function englishLexiconOnly(string $question): bool
    {
        if (!class_exists('MedicalDictionary')) {
            return false;
        }
        $tokens = preg_split('/[^\p{L}]+/u', mb_strtolower($question)) ?: [];
        $english = 0;
        $local = 0;
        foreach ($tokens as $token) {
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }
            $byLocal = MedicalDictionary::lookup($token);
            $byEnglish = MedicalDictionary::lookupByEnglish($token);
            if (is_array($byLocal)) {
                $localTerm = mb_strtolower((string) ($byLocal['local_term'] ?? ''));
                $englishTerm = mb_strtolower((string) ($byLocal['english_term'] ?? ''));
                if ($localTerm !== '' && $localTerm !== $englishTerm) {
                    $local++;
                    continue;
                }
            }
            if (is_array($byEnglish)) {
                $english++;
            }
        }

        return $english > 0 && $local === 0;
    }

    private static function followUpMatchesLanguage(string $question, string $lang): bool
    {
        $question = trim($question);
        $lang = strtolower(trim($lang));
        if (!in_array($lang, ['english', 'tagalog', 'hiligaynon'], true) || $question === '') {
            return false;
        }
        if (!class_exists('HiligaynonLanguageDetector')) {
            return false;
        }
        try {
            $detected = HiligaynonLanguageDetector::detect($question);
        } catch (Throwable) {
            return false;
        }
        $resolved = class_exists('ClinicalInterviewEngine')
            ? strtolower(ClinicalInterviewEngine::questionLanguageFromDetection($detected, $question))
            : $lang;
        $englishSurface = self::questionSurfaceIsEnglish($question, $detected);
        if ($lang === 'english') {
            return $resolved === 'english' || $englishSurface;
        }
        if ($englishSurface || $resolved !== $lang) {
            return false;
        }

        return !self::englishLexiconOnly($question);
    }

    /**
     * Patient-stated wording only (chief complaint + answers). Never includes Gemini labels.
     *
     * @param array<string, mixed> $context
     */
    private static function patientEvidenceCorpus(array $context, string $extra = ''): string
    {
        $parts = [];
        $cc = trim((string) ($context['chief_complaint'] ?? ''));
        if ($cc !== '') {
            $parts[] = $cc;
        }
        foreach ((array) ($context['patient_turns'] ?? []) as $turn) {
            $t = trim((string) $turn);
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        $extra = trim($extra);
        if ($extra !== '') {
            $parts[] = $extra;
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Character offsets where each patient turn begins inside patientEvidenceCorpus().
     *
     * @param array<string, mixed> $context
     * @return list<int>
     */
    private static function corpusTurnStarts(array $context): array
    {
        $parts = [];
        $cc = trim((string) ($context['chief_complaint'] ?? ''));
        if ($cc !== '') {
            $parts[] = $cc;
        }
        foreach ((array) ($context['patient_turns'] ?? []) as $turn) {
            $t = trim((string) $turn);
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        $starts = [];
        $pos = 0;
        foreach ($parts as $part) {
            $starts[] = $pos;
            $pos += mb_strlen($part) + 1;
        }

        return $starts;
    }

    /**
     * Validated local NLP mappings for the current patient text (dictionary / KB / body lexicon).
     * No hard-coded example phrases — uses existing MedConnect NLP datasets only.
     *
     * @return array{
     *   patient_text:string,
     *   english_gloss:string,
     *   symptoms:list<string>,
     *   symptom_mappings:list<array{local:string,english:string}>,
     *   locations:list<string>,
     *   location_mappings:list<array{local:string,english:string,surface?:string}>
     * }
     */
    private static function collectLocalNlpEvidence(string $patientText): array
    {
        $patientText = trim($patientText);
        $englishGloss = '';
        $symptoms = [];
        $symptomMappings = [];
        $locations = [];
        $locationMappings = [];

        if ($patientText === '') {
            return [
                'patient_text' => '',
                'english_gloss' => '',
                'symptoms' => [],
                'symptom_mappings' => [],
                'locations' => [],
                'location_mappings' => [],
            ];
        }

        if (class_exists('MedicalDictionary')) {
            try {
                $englishGloss = self::stripDiscourseParticlesFromGloss(
                    trim((string) MedicalDictionary::translateText($patientText))
                );
            } catch (Throwable) {
                $englishGloss = '';
            }
        }

        if (class_exists('SymptomKnowledgeBase')) {
            try {
                foreach (SymptomKnowledgeBase::matchSymptoms($patientText, $englishGloss) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $name = trim((string) ($row['symptom_name'] ?? ''));
                    $matched = trim((string) ($row['matched_term'] ?? ''));
                    if ($name === '' || self::isNonClinicalDiscourseLabel($name)
                        || ($matched !== '' && self::isNonClinicalDiscourseLabel($matched))
                    ) {
                        continue;
                    }
                    if (!in_array($name, $symptoms, true)) {
                        $symptoms[] = $name;
                    }
                    if ($matched !== '') {
                        $pair = ['local' => $matched, 'english' => $name];
                        if (!in_array($pair, $symptomMappings, true)) {
                            $symptomMappings[] = $pair;
                        }
                    }
                }
            } catch (Throwable) {
                // keep empty
            }
        }

        if (class_exists('BodyLocationLexicon')) {
            try {
                foreach (BodyLocationLexicon::extractDetailed($patientText, $patientText) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $canonical = trim((string) ($row['canonical_body_location'] ?? ''));
                    $alias = trim((string) ($row['normalized_term'] ?? ''));
                    if ($canonical === '') {
                        continue;
                    }
                    if (!in_array($canonical, $locations, true)) {
                        $locations[] = $canonical;
                    }
                    if ($alias !== '') {
                        $pair = ['local' => $alias, 'english' => $canonical];
                        if (!in_array($pair, $locationMappings, true)) {
                            $locationMappings[] = $pair;
                        }
                    }
                }
            } catch (Throwable) {
                // keep empty
            }
        }

        foreach ($locationMappings as $i => $pair) {
            $local = (string) ($pair['local'] ?? '');
            if ($local === '' || self::appearsInPatientText($local, $patientText)) {
                continue;
            }
            $surface = self::patientSurfaceFor($local, $patientText);
            if ($surface !== '') {
                $locationMappings[$i]['surface'] = $surface;
            }
        }

        return [
            'patient_text' => $patientText,
            'english_gloss' => $englishGloss,
            'symptoms' => $symptoms,
            'symptom_mappings' => $symptomMappings,
            'locations' => $locations,
            'location_mappings' => $locationMappings,
        ];
    }

    /**
     * The patient's own spelling of a spell-corrected lexicon term (e.g. typed "tyan" for "tiyan"),
     * found by running the same normalizers over each run of patient words.
     */
    private static function patientSurfaceFor(string $normalizedTerm, string $patientText): string
    {
        $target = mb_strtolower(trim($normalizedTerm));
        $words = preg_split('/[^\p{L}\p{N}\-]+/u', $patientText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count(preg_split('/\s+/u', $target, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($target === '' || $n === 0 || count($words) < $n) {
            return '';
        }
        for ($i = 0; $i + $n <= count($words); $i++) {
            $window = implode(' ', array_slice($words, $i, $n));
            if (!self::appearsInPatientText($window, $patientText)) {
                continue;
            }
            $candidates = [];
            if (class_exists('BodyLocationLexicon')) {
                $candidates[] = BodyLocationLexicon::normalizeForMatch($window);
            }
            if (class_exists('MedicalMisspellingsLoader')) {
                $candidates[] = MedicalMisspellingsLoader::applyCorrections($window);
            }
            foreach ($candidates as $candidate) {
                if (mb_strtolower(trim((string) $candidate)) === $target) {
                    return $window;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $nlp
     */
    private static function formatNlpEvidenceForPrompt(array $nlp): string
    {
        $lines = ['VALIDATED LOCAL NLP MAPPINGS (authoritative when present; do not contradict):'];
        $maps = is_array($nlp['symptom_mappings'] ?? null) ? $nlp['symptom_mappings'] : [];
        if ($maps === []) {
            $lines[] = '- Symptom mappings: (none for this text)';
        } else {
            foreach ($maps as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $local = trim((string) ($row['local'] ?? ''));
                $eng = trim((string) ($row['english'] ?? ''));
                if ($local !== '' && $eng !== '') {
                    $lines[] = '- Symptom: "' . $local . '" → ' . $eng;
                }
            }
        }
        $locMaps = is_array($nlp['location_mappings'] ?? null) ? $nlp['location_mappings'] : [];
        if ($locMaps === []) {
            $lines[] = '- Body-location mappings: (none for this text)';
        } else {
            foreach ($locMaps as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $local = trim((string) ($row['local'] ?? ''));
                $eng = trim((string) ($row['english'] ?? ''));
                if ($local !== '' && $eng !== '') {
                    $lines[] = '- Body location: "' . $local . '" → ' . $eng;
                }
            }
        }
        $gloss = trim((string) ($nlp['english_gloss'] ?? ''));
        if ($gloss !== '' && mb_strtolower($gloss) !== mb_strtolower((string) ($nlp['patient_text'] ?? ''))) {
            $lines[] = '- Dictionary gloss (supporting): ' . $gloss;
        }
        $lines[] = 'Pipeline role: validated NLP mappings are authoritative when present.';
        $lines[] = 'Gemini Flash is the semantic interpretation/fallback layer for wording not covered above.';
        $lines[] = 'If a local expression is listed above, extract ONLY that supported clinical meaning.';
        $lines[] = 'Never treat discourse particles/fillers/intensifiers (or their English glosses) as symptoms.';
        $lines[] = 'If meaning is uncertain and not listed, leave fields null, set answer_status=UNCLEAR or UNCERTAIN, and ask a neutral clarification — never guess.';
        $lines[] = 'Never output EMERGENCY, URGENT, or NON-URGENT. Final acuity is decided only by ClinicalTriageEngine later.';

        return implode("\n", $lines);
    }

    /**
     * Closed-class discourse particles / fillers / short replies — never clinical findings.
     * Linguistic category filter (not complaint-phrase hardcoding). Covers common EN glosses
     * of local particles that noisy dictionary rows may emit (e.g. intensifier → "really").
     */
    private static function isNonClinicalDiscourseLabel(string $label): bool
    {
        $low = mb_strtolower(trim($label));
        if ($low === '') {
            return true;
        }
        // Normalize punctuation / reduplication noise.
        $low = trim((string) preg_replace('/[^\p{L}\p{N}\s\-]+/u', '', $low));
        $low = trim((string) preg_replace('/\s+/u', ' ', $low));
        if ($low === '') {
            return true;
        }

        static $closed = null;
        if ($closed === null) {
            $closed = array_fill_keys([
                // Local particles / fillers / intensifiers / clitics
                'gid', 'guid', 'gyud', 'jud', 'lang', 'man', 'bala', 'gani', 'gali', 'naman',
                'syempre', 'siyempre', 'daw', 'yata', 'kay', 'nga', 'sang', 'ang', 'sa',
                'ko', 'ako', 'mo', 'imo', 'iya', 'niya', 'na', 'pa', 'po', 'ba', 'ano',
                'ay', 'eh', 'ah', 'oh', 'ha', 'ho', 'uy',
                // Short conversational replies (not symptoms by themselves)
                'oo', 'opo', 'o', 'yes', 'yeah', 'yep', 'no', 'nope', 'indi', 'di', 'hindi',
                'wala', 'walang', 'sige', 'okay', 'ok', 'aye', 'sure',
                // English glosses of particles / intensifiers (dictionary noise)
                'really', 'only', 'just', 'very', 'so', 'quite', 'rather', 'too', 'also',
                'well', 'like', 'kinda', 'sorta', 'actually', 'basically', 'literally',
                'please', 'thanks', 'thank you', 'salamat',
                // Closed-class time / aspect adverbs and conjunctions (dictionary noise, never a finding)
                'now', 'then', 'already', 'still', 'again', 'but', 'and', 'or', 'because',
            ], true);
        }

        if (isset($closed[$low])) {
            return true;
        }

        $tokens = preg_split('/\s+/u', $low) ?: [];
        if ($tokens === []) {
            return true;
        }
        foreach ($tokens as $tok) {
            if ($tok === '' || !isset($closed[$tok])) {
                return false;
            }
        }

        // All tokens were closed-class discourse words.
        return true;
    }

    /**
     * Interview slot / control vocabulary. These name what the interview asks about;
     * they are never findings the patient reported.
     */
    private static function isInterviewControlLabel(string $label): bool
    {
        $key = mb_strtolower(trim($label));
        $key = trim((string) preg_replace('/[\s\-]+/u', '_', $key), '_');

        return in_array($key, [
            'symptom', 'symptoms', 'location', 'laterality', 'onset', 'duration', 'frequency',
            'pain_score', 'trauma', 'associated_symptoms', 'associated_detail',
            'has_other_symptoms', 'symptom_clarification',
        ], true);
    }

    private static function stripDiscourseParticlesFromGloss(string $gloss): string
    {
        if ($gloss === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', mb_strtolower($gloss)) ?: [];
        $kept = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || self::isNonClinicalDiscourseLabel($p)) {
                continue;
            }
            $kept[] = $p;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function sanitizeClinicalFacts(array $facts): array
    {
        $out = self::blankFacts();
        foreach (['symptom', 'location', 'laterality', 'onset', 'duration', 'frequency'] as $key) {
            $val = trim((string) ($facts[$key] ?? ''));
            if ($val === '') {
                $out[$key] = null;
                continue;
            }
            if (self::isNonClinicalDiscourseLabel($val) && !($key === 'laterality' && self::isLateralityToken($val))) {
                $out[$key] = null;
                continue;
            }
            $out[$key] = $val;
        }
        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '' && is_numeric($facts['pain_score'])) {
            $score = (int) $facts['pain_score'];
            if ($score >= 1 && $score <= 10) {
                $out['pain_score'] = $score;
            }
        }
        foreach (['associated_symptoms', 'relevant_negatives', 'warning_signs', 'notes', 'symptoms_patient', 'symptoms_kb', 'symptoms_ai', 'negative_symptoms'] as $listKey) {
            $kept = [];
            foreach (self::stringList($facts[$listKey] ?? []) as $item) {
                if (self::isNonClinicalDiscourseLabel($item)
                    || ($listKey !== 'notes' && self::isInterviewControlLabel($item))
                ) {
                    continue;
                }
                $kept[] = $item;
            }
            $out[$listKey] = $kept;
        }

        $status = [];
        if (isset($facts['finding_status']) && is_array($facts['finding_status'])) {
            foreach ($facts['finding_status'] as $rawKey => $rawVal) {
                $key = self::normalizeFindingKey((string) $rawKey);
                $norm = self::normalizeFindingStatusValue($rawVal);
                if ($key === '' || $norm === null) {
                    continue;
                }
                $status[$key] = $norm;
            }
        }
        $out['finding_status'] = $status;
        $out['patient_uncertain'] = !empty($facts['patient_uncertain']);
        $out['complaints'] = self::complaintRecords($facts);

        return $out;
    }

    /**
     * Public test hook — maps a patient answer onto only the targeted findings.
     *
     * @param array<string, mixed> $facts
     * @param list<string>|array<int, string> $targets
     * @param array<string, mixed> $gemini
     * @return array<string, mixed>
     */
    public static function mapAnswerFindingStatusForTest(array $facts, array $targets, string $answer, array $gemini = []): array
    {
        $facts = self::mergeFacts(self::blankFacts(), $facts);

        return self::applyAnswerToTargetedFindings($facts, self::normalizeFindingKeyList($targets), $answer, $gemini);
    }

    /**
     * Language lock used by the demo follow-up path. Later utterances update it
     * only when the patient clearly switches language.
     *
     * @param list<string> $laterUtterances
     * @return array{question_language:string, detected_language:string, after_turns:list<array{utterance:string, question_language:string}>}
     */
    public static function questionLanguageStateForTest(string $complaint, array $laterUtterances = []): array
    {
        $context = self::blankContext($complaint);
        self::syncQuestionLanguage($context, $complaint, true);
        $after = [];
        foreach ($laterUtterances as $utterance) {
            self::syncQuestionLanguage($context, (string) $utterance, false);
            $after[] = [
                'utterance' => (string) $utterance,
                'question_language' => (string) ($context['question_language'] ?? ''),
            ];
        }

        return [
            'question_language' => (string) ($context['question_language'] ?? ''),
            'detected_language' => (string) ($context['detected_language'] ?? ''),
            'after_turns' => $after,
        ];
    }

    public static function followUpQuestionLanguageMatchesForTest(string $question, string $expectedLanguage): bool
    {
        return self::followUpMatchesLanguage($question, $expectedLanguage);
    }

    /**
     * Resolve which clinical findings the pending Gemini question is asking about.
     *
     * @param array<string, mixed> $gemini
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private static function resolveTargetedFindings(array $gemini, string $question, array $context): array
    {
        $fromGemini = self::normalizeFindingKeyList($gemini['targeted_findings'] ?? []);
        if ($fromGemini !== []) {
            return $fromGemini;
        }
        // Also accept finding keys Gemini stuffed into missing_information for this turn.
        $fromMissing = [];
        foreach (self::stringList($gemini['missing_information'] ?? []) as $m) {
            $key = self::normalizeFindingKey($m);
            if ($key !== '' && isset(self::findingLexicon()[$key])) {
                $fromMissing[] = $key;
            }
        }
        if ($fromMissing !== []) {
            return array_values(array_unique($fromMissing));
        }

        return self::inferTargetedFindingsFromQuestion($question);
    }

    /**
     * Map the patient's answer onto findings from the immediately preceding question only.
     *
     * @param array<string, mixed> $facts
     * @param list<string> $targets
     * @param array<string, mixed> $gemini
     * @return array<string, mixed>
     */
    private static function applyAnswerToTargetedFindings(array $facts, array $targets, string $answer, array $gemini): array
    {
        $targets = self::normalizeFindingKeyList($targets);
        if ($targets === []) {
            // No targeted findings — do not invent status keys from a bare yes/no.
            return $facts;
        }

        $status = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        foreach ($targets as $t) {
            if (!isset($status[$t])) {
                $status[$t] = 'not_assessed';
            }
        }

        // Prefer Gemini-provided finding_status only for keys in this question's targets.
        $geminiFs = [];
        if (isset($gemini['clinical_facts']) && is_array($gemini['clinical_facts'])
            && isset($gemini['clinical_facts']['finding_status'])
            && is_array($gemini['clinical_facts']['finding_status'])
        ) {
            foreach ($gemini['clinical_facts']['finding_status'] as $rawKey => $rawVal) {
                $key = self::normalizeFindingKey((string) $rawKey);
                $norm = self::normalizeFindingStatusValue($rawVal);
                if ($key === '' || $norm === null || !in_array($key, $targets, true)) {
                    continue;
                }
                $geminiFs[$key] = $norm;
            }
        }

        $answerStatus = strtoupper((string) ($gemini['answer_status'] ?? ''));
        $uncertain = $answerStatus === 'UNCERTAIN'
            || (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksPatientUncertain($answer));
        $yn = null;
        if (class_exists('ClinicalFeatureExtractors')) {
            $yn = ClinicalFeatureExtractors::extractYesNo($answer);
        }
        if ($answerStatus === 'UNCERTAIN') {
            $yn = null;
        }
        // A left/right answer is a side, even when the wording contains "wala".
        // Do not let a generic yes/no or a model "negative" replace that side.
        if (in_array('laterality', $targets, true) && self::canonicalLaterality($answer, true) !== '') {
            $yn = null;
            $uncertain = false;
            $status['laterality'] = 'positive';
            unset($geminiFs['laterality']);
        }

        // Semantic per-finding mentions in the answer (EN / Tagalog / Hiligaynon / mixed).
        $mentioned = self::detectFindingMentionsInAnswer($answer, $targets);

        if ($mentioned !== []) {
            foreach ($targets as $t) {
                if (isset($mentioned[$t])) {
                    $status[$t] = $mentioned[$t];
                } elseif (isset($geminiFs[$t]) && in_array($geminiFs[$t], ['positive', 'negative', 'uncertain'], true)) {
                    $status[$t] = $geminiFs[$t];
                } else {
                    // Asked but not safely determined from this answer.
                    $status[$t] = 'not_assessed';
                }
            }
        } elseif (count($targets) === 1) {
            $t = $targets[0];
            if ($uncertain) {
                $status[$t] = 'uncertain';
            } elseif ($yn === true) {
                $status[$t] = 'positive';
            } elseif ($yn === false) {
                $status[$t] = 'negative';
            } elseif (isset($geminiFs[$t])) {
                $status[$t] = $geminiFs[$t];
            }
            // else keep not_assessed
        } else {
            // Multiple findings in one question.
            if ($uncertain) {
                foreach ($targets as $t) {
                    $status[$t] = 'uncertain';
                }
            } elseif ($yn === false) {
                // Safe: global negation denies all probed findings.
                foreach ($targets as $t) {
                    $status[$t] = 'negative';
                }
            } elseif ($yn === true) {
                // Bare affirmation is ambiguous for multi-finding OR questions.
                foreach ($targets as $t) {
                    $status[$t] = isset($geminiFs[$t]) && in_array($geminiFs[$t], ['positive', 'negative', 'uncertain'], true)
                        ? $geminiFs[$t]
                        : 'uncertain';
                }
            } else {
                foreach ($targets as $t) {
                    if (isset($geminiFs[$t])) {
                        $status[$t] = $geminiFs[$t];
                    } else {
                        $status[$t] = 'not_assessed';
                    }
                }
            }
        }

        $facts['finding_status'] = $status;
        if (in_array('uncertain', $status, true)) {
            $facts['patient_uncertain'] = true;
        }

        return self::syncFindingStatusSideEffects($facts, $status);
    }

    /**
     * Detect which targeted findings are named in the answer, with local polarity when possible.
     *
     * @param list<string> $targets
     * @return array<string, 'positive'|'negative'|'uncertain'>
     */
    private static function detectFindingMentionsInAnswer(string $answer, array $targets): array
    {
        $low = mb_strtolower(trim($answer));
        if ($low === '' || $targets === []) {
            return [];
        }

        $lex = self::findingLexicon();
        $out = [];

        // Split soft clauses on connectors (pero/but/while/and) for mixed polarity.
        $clauses = preg_split('/\b(pero|but|while|however|and|,|;|at|kag)\b/ui', $low) ?: [$low];
        $clauses = array_values(array_filter(array_map('trim', $clauses), static fn (string $c): bool => $c !== ''));
        if ($clauses === []) {
            $clauses = [$low];
        }

        foreach ($targets as $finding) {
            $syns = $lex[$finding] ?? [str_replace('_', ' ', $finding)];
            foreach ($clauses as $clause) {
                $hit = false;
                foreach ($syns as $syn) {
                    $syn = mb_strtolower(trim((string) $syn));
                    if ($syn === '') {
                        continue;
                    }
                    if (str_contains($clause, $syn) || (bool) preg_match('/\b' . preg_quote($syn, '/') . '\b/u', $clause)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                $clauseUncertain = class_exists('ClinicalFeatureExtractors')
                    && ClinicalFeatureExtractors::looksPatientUncertain($clause);
                $clauseYn = class_exists('ClinicalFeatureExtractors')
                    ? ClinicalFeatureExtractors::extractYesNo($clause)
                    : null;
                if ($clauseUncertain) {
                    $out[$finding] = 'uncertain';
                } elseif ($clauseYn === false) {
                    $out[$finding] = 'negative';
                } else {
                    // Named without negation → positive presence.
                    $out[$finding] = 'positive';
                }
                break;
            }
        }

        return $out;
    }

    /**
     * Infer finding keys probed by a free-text clinical question (semantic synonyms).
     *
     * @return list<string>
     */
    private static function inferTargetedFindingsFromQuestion(string $question): array
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return [];
        }
        $found = [];
        foreach (self::findingLexicon() as $key => $syns) {
            foreach ($syns as $syn) {
                $syn = mb_strtolower(trim((string) $syn));
                if ($syn === '') {
                    continue;
                }
                if (str_contains($q, $syn) || (bool) preg_match('/\b' . preg_quote($syn, '/') . '\b/u', $q)) {
                    $found[] = $key;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function findingLexicon(): array
    {
        return [
            'fever_confirmed' => ['fever', 'lagnat', 'hilanat', 'mainit ang lawas', 'mainit lawas', 'temperature', 'gilagnat'],
            'vomiting' => ['vomit', 'vomiting', 'suka', 'nagsuka', 'throw up', 'nausea', 'kasukaon', 'ginsuka'],
            'dizziness' => ['dizzy', 'dizziness', 'hilo', 'nahilo', 'lipong', 'punaw', 'faint', 'nahihilo', 'ginahilo'],
            'breathing_difficulty' => ['breath', 'breathing', 'ginhawa', 'dyspnea', 'shortness of breath', 'huoy', 'ginahapo', 'hapo'],
            'weakness' => ['weak', 'weakness', 'kaluya', 'numb', 'pamujod', 'lumpo', 'panghinay'],
            'sweating' => ['sweat', 'sweating', 'pawis', 'nagapawis', 'diaphoresis'],
            'chest_radiation' => ['radiat', 'radiation', 'spread to arm', 'jaw pain', 'kakagat'],
            'bleeding_continuing' => ['bleed', 'bleeding', 'dugo', 'nagadugo', 'hemorrhage'],
            'diarrhea' => ['diarrhea', 'diarrhoea', 'kalibang', 'tatae', 'loose stool', 'libang'],
            'cough' => ['cough', 'ubo', 'nag-ubo', 'nagaubo'],
            'headache' => ['headache', 'sakit ulo', 'ulo ko', 'head pain', 'masakit ang ulo'],
            'rash' => ['rash', 'katol', 'gakatol', 'itch', 'kati', 'exanthem', 'pantal'],
            'abdominal_pain' => ['stomach', 'tiyan', 'abdomen', 'abdominal', 'sakit tiyan'],
            'chest_pain' => ['chest pain', 'sakit dughan', 'dibdib', 'dughan'],
            'has_other_symptoms' => ['other symptom', 'iban nga sintomas', 'anything else', 'iba pa'],
            'urinary_burning' => ['burning urine', 'arisgado pag-ihi', 'mahapdi pag-ihi', 'dysuria'],
            'vision_change' => ['vision', 'blurry', 'malabo', 'sight', 'mata'],
            'speech_difficulty' => ['speech', 'slurred', 'magpanghambal', 'nagsasalita'],
        ];
    }

    /**
     * @param mixed $value
     */
    private static function normalizeFindingStatusValue(mixed $value): ?string
    {
        if ($value === true || $value === 1) {
            return 'positive';
        }
        if ($value === false || $value === 0) {
            return 'negative';
        }
        $s = strtolower(trim((string) $value));
        return match ($s) {
            'positive', 'pos', 'yes', 'true', 'present', '+' => 'positive',
            'negative', 'neg', 'no', 'false', 'absent', '-' => 'negative',
            'uncertain', 'unknown', 'unsure', 'maybe' => 'uncertain',
            'not_assessed', 'not-assessed', 'na', 'pending', 'unassessed' => 'not_assessed',
            default => null,
        };
    }

    private static function normalizeFindingKey(string $raw): string
    {
        $k = strtolower(trim($raw));
        if ($k === '') {
            return '';
        }
        $k = str_replace([' ', '-', '/'], '_', $k);
        $k = (string) preg_replace('/[^a-z0-9_]/', '', $k);
        $aliases = [
            'fever' => 'fever_confirmed',
            'lagnat' => 'fever_confirmed',
            'hilanat' => 'fever_confirmed',
            'suka' => 'vomiting',
            'vomit' => 'vomiting',
            'hilo' => 'dizziness',
            'dizzy' => 'dizziness',
            'lipong' => 'dizziness',
            'ginhawa' => 'breathing_difficulty',
            'breathing' => 'breathing_difficulty',
            'pawis' => 'sweating',
            'sweat' => 'sweating',
            'katol' => 'rash',
            'itch' => 'rash',
            'itching' => 'rash',
            'ubo' => 'cough',
            'kalibang' => 'diarrhea',
        ];

        return $aliases[$k] ?? $k;
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private static function normalizeFindingKeyList(mixed $list): array
    {
        $out = [];
        foreach (self::stringList(is_array($list) ? $list : []) as $item) {
            $key = self::normalizeFindingKey($item);
            if ($key !== '' && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Mirror finding_status into legacy lists / provenance without inventing new findings.
     *
     * @param array<string, mixed> $facts
     * @param array<string, string> $status
     * @return array<string, mixed>
     */
    private static function syncFindingStatusSideEffects(array $facts, array $status): array
    {
        $assoc = self::stringList($facts['associated_symptoms'] ?? []);
        $neg = self::stringList($facts['relevant_negatives'] ?? []);
        $negSym = self::stringList($facts['negative_symptoms'] ?? []);
        $patient = self::stringList($facts['symptoms_patient'] ?? []);

        foreach ($status as $finding => $polarity) {
            if (self::isInterviewControlLabel((string) $finding)) {
                continue;
            }
            $label = str_replace('_', ' ', (string) $finding);
            if ($polarity === 'positive') {
                if (!in_array($label, $assoc, true)) {
                    $assoc[] = $label;
                }
                if (!in_array($label, $patient, true)) {
                    $patient[] = $label;
                }
                $neg = array_values(array_filter($neg, static fn (string $n): bool => mb_strtolower($n) !== mb_strtolower($label)));
                $negSym = array_values(array_filter($negSym, static fn (string $n): bool => mb_strtolower($n) !== mb_strtolower($label)));
            } elseif ($polarity === 'negative') {
                if (!in_array($label, $neg, true)) {
                    $neg[] = $label;
                }
                if (!in_array($label, $negSym, true)) {
                    $negSym[] = $label;
                }
                $assoc = array_values(array_filter($assoc, static fn (string $n): bool => mb_strtolower($n) !== mb_strtolower($label)));
                $patient = array_values(array_filter($patient, static fn (string $n): bool => mb_strtolower($n) !== mb_strtolower($label)));
            }
        }

        $facts['associated_symptoms'] = $assoc;
        $facts['relevant_negatives'] = $neg;
        $facts['negative_symptoms'] = $negSym;
        $facts['symptoms_patient'] = $patient;

        return $facts;
    }

    /**
     * @param array<string, mixed> $mapped
     * @param array<string, string> $findingStatus
     * @return array<string, mixed>
     */
    private static function applyFindingStatusToEngineBooleans(array $mapped, array $findingStatus): array
    {
        $direct = [
            'fever_confirmed' => 'fever_confirmed',
            'breathing_difficulty' => 'breathing_difficulty',
            'weakness' => 'weakness',
            'sweating' => 'sweating',
            'sweating_with_chest' => 'sweating',
            'chest_radiation' => 'chest_radiation',
            'dizziness' => 'dizziness',
            'vision_change' => 'vision_change',
            'speech_difficulty' => 'speech_difficulty',
            'bleeding_continuing' => 'bleeding_continuing',
            'has_other_symptoms' => 'has_other_symptoms',
        ];
        foreach ($findingStatus as $finding => $polarity) {
            if (!isset($direct[$finding])) {
                continue;
            }
            if ($polarity === 'positive') {
                $mapped[$direct[$finding]] = true;
            } elseif ($polarity === 'negative') {
                $mapped[$direct[$finding]] = false;
            }
            // uncertain / not_assessed → leave boolean unset
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function hasClinicallyUsefulFacts(array $facts): bool
    {
        $sym = trim((string) ($facts['symptom'] ?? ''));

        return $sym !== '' && !self::isNonClinicalDiscourseLabel($sym);
    }

    /**
     * Follow-up questions already shown this interview (retries of the same question do not count).
     *
     * @param array<string, mixed> $context
     */
    private static function followUpQuestionCount(array $context): int
    {
        $n = 0;
        foreach ((array) ($context['conversation'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtolower((string) ($row['kind'] ?? '')) === 'followup') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * PHP-enforced cap shared by Gemini and BITS. Not taken from the model JSON.
     *
     * @param array<string, mixed> $context
     * @param list<string> $missing
     */
    private static function shouldAskAnotherFollowUp(array $context, array $missing, int $alreadyAsked): bool
    {
        if ($alreadyAsked >= self::MAX_FOLLOWUP_QUESTIONS) {
            return false;
        }
        if ($missing === []) {
            return false;
        }
        $facts = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        if ($alreadyAsked >= self::PREFERRED_FOLLOWUP_QUESTIONS) {
            return self::extraFollowUpNeeded($context, $missing);
        }
        if (self::hasClinicallyUsefulFacts($facts) && self::onlyLowPriorityFollowUpGaps($missing)) {
            return false;
        }

        return true;
    }

    /**
     * Location/laterality alone must not pad the interview to 3 questions.
     *
     * @param list<string> $missing
     */
    private static function onlyLowPriorityFollowUpGaps(array $missing): bool
    {
        if ($missing === []) {
            return false;
        }
        foreach ($missing as $entry) {
            $slot = self::canonicalInterviewSlot(self::missingSlotId((string) $entry));
            if (!in_array($slot, ['location', 'laterality'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A 4th question only for still-missing triage-critical slots.
     *
     * @param array<string, mixed> $context
     * @param list<string> $missing
     */
    private static function extraFollowUpNeeded(array $context, array $missing): bool
    {
        $facts = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        if (!self::hasClinicallyUsefulFacts($facts)) {
            return $missing !== [];
        }
        foreach ($missing as $entry) {
            $slot = self::canonicalInterviewSlot(self::missingSlotId((string) $entry));
            if (in_array($slot, ['pain_score', 'trauma', 'associated_symptoms', 'findings'], true)) {
                return true;
            }
            if (str_starts_with($slot, 'finding_') || str_starts_with($slot, 'FINDING_')) {
                return true;
            }
        }

        return false;
    }

    private static function canonicalInterviewSlot(string $slot): string
    {
        $slot = strtolower(trim($slot));
        if ($slot === 'associated_detail' || $slot === 'has_other_symptoms') {
            return 'associated_symptoms';
        }

        return $slot;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function rememberAskedSlot(array &$context, string $slot): void
    {
        $slot = self::canonicalInterviewSlot($slot);
        if ($slot === '' || $slot === 'none') {
            return;
        }
        if (!isset($context['asked_slots']) || !is_array($context['asked_slots'])) {
            $context['asked_slots'] = [];
        }
        if (!in_array($slot, $context['asked_slots'], true)) {
            $context['asked_slots'][] = $slot;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function slotAlreadyAsked(array $context, string $slot): bool
    {
        $slot = self::canonicalInterviewSlot($slot);
        if ($slot === '') {
            return false;
        }
        foreach ((array) ($context['asked_slots'] ?? []) as $item) {
            if (self::canonicalInterviewSlot((string) $item) === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep the next question on a fact that is still missing. One question per turn.
     *
     * @param array<string, mixed> $context
     * @return array{context: array<string, mixed>, sufficient: bool, next_question: string, replaced_slot: string}
     */
    private static function reconcileFollowUp(array $context, string $nextQ, bool $sufficient): array
    {
        $facts = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        $missing = self::genuinelyMissingSlots($facts, $context);
        $context['clinical_facts'] = $facts;
        $context['missing_information'] = $missing;

        if ($missing === [] && self::interviewReadyToClose($facts, $context)) {
            $context['awaiting_complaint_id'] = '';
            $context['awaiting_slot'] = '';

            return [
                'context' => $context,
                'sufficient' => true,
                'next_question' => '',
                'replaced_slot' => '',
            ];
        }

        $asked = self::questionFactSlot($nextQ);
        $replaced = '';
        if ($missing !== []) {
            $sufficient = false;
            $entry = (string) $missing[0];
            $matched = $asked !== '' ? self::firstMissingEntryForSlot($missing, $asked) : null;
            if (is_string($matched) && self::complaintIdFromMissingEntry($facts, $matched) === self::complaintIdFromMissingEntry($facts, $entry)) {
                $entry = $matched;
            }
            $slotToAsk = self::missingSlotId($entry);
            $complaintId = self::complaintIdFromMissingEntry($facts, $entry);
            $context['active_complaint_id'] = $complaintId;
            $context['awaiting_complaint_id'] = in_array($slotToAsk, ['associated_symptoms', 'associated_detail'], true)
                ? ''
                : $complaintId;
            $context['awaiting_slot'] = $slotToAsk;
            // Always compose the patient question. A model phrase that names the right
            // fact can still be a fragment or can omit which complaint it means.
            $nextQ = self::simpleQuestionForSlot($slotToAsk, $context);
            $replaced = $slotToAsk;
        }

        return [
            'context' => $context,
            'sufficient' => $sufficient,
            'next_question' => $nextQ,
            'replaced_slot' => $replaced,
        ];
    }

    /**
     * Slots still empty after every patient answer has been merged.
     * This is the only list allowed into missing_information.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     * @return list<string>
     */
    /**
     * @param array<string, mixed> $facts
     */
    private static function interviewReadyToClose(array $facts, array $context = []): bool
    {
        if (self::hasClinicallyUsefulFacts($facts)) {
            return true;
        }
        foreach (self::complaintRecords($facts) as $complaint) {
            if (trim((string) ($complaint['symptom'] ?? '')) !== '' || trim((string) ($complaint['location'] ?? '')) !== ''
                || self::complaintSlotSettled($complaint, 'symptom')
            ) {
                return true;
            }
        }

        // Every question was asked and answered in the patient's own words.
        return self::contextSlotUnresolved($context, 'symptom');
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<array<string, mixed>>
     */
    private static function complaintRecords(array $facts): array
    {
        $rows = is_array($facts['complaints'] ?? null) ? $facts['complaints'] : [];
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $complaints
     * @return array<string, mixed>|null
     */
    private static function complaintById(array $complaints, string $id): ?array
    {
        foreach ($complaints as $complaint) {
            if ((string) ($complaint['id'] ?? '') === $id) {
                return $complaint;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $complaint
     * @return array<string, mixed>
     */
    private static function complaintAsFacts(array $complaint): array
    {
        $facts = self::blankFacts();
        foreach (['symptom', 'location', 'laterality', 'onset', 'duration', 'frequency'] as $key) {
            $value = trim((string) ($complaint[$key] ?? ''));
            $facts[$key] = $value !== '' ? $value : null;
        }
        if (isset($complaint['pain_score']) && is_numeric($complaint['pain_score'])) {
            $score = (int) $complaint['pain_score'];
            if ($score >= 1 && $score <= 10) {
                $facts['pain_score'] = $score;
            }
        }
        $facts['finding_status'] = is_array($complaint['finding_status'] ?? null) ? $complaint['finding_status'] : [];
        $facts['complaints'] = [];

        return $facts;
    }

    /**
     * @param array<string, mixed> $complaint
     */
    private static function complaintScopeText(array $complaint): string
    {
        $parts = [];
        foreach (['text_span', 'label', 'local_symptom', 'local_location', 'symptom', 'location'] as $key) {
            $value = trim((string) ($complaint[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $complaint
     * @return array<string, mixed>
     */
    private static function contextScopedToComplaint(array $context, array $complaint): array
    {
        $context['clinical_facts'] = self::complaintAsFacts($complaint);
        $span = trim((string) ($complaint['text_span'] ?? ''));
        if ($span !== '') {
            $context['chief_complaint'] = $span;
            $context['patient_turns'] = [];
        }

        return $context;
    }

    private static function missingSlotId(string $entry): string
    {
        $entry = mb_strtolower(trim($entry));
        if (preg_match('/^(symptom|location|laterality|onset|duration|pain_score|trauma|associated_symptoms|associated_detail|frequency)\b/u', $entry, $m)) {
            return (string) $m[1];
        }

        return $entry;
    }

    /**
     * @param list<string> $missing
     */
    private static function missingListContainsSlot(array $missing, string $slot): bool
    {
        foreach ($missing as $entry) {
            if (self::missingSlotId((string) $entry) === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $missing
     */
    private static function firstMissingEntryForSlot(array $missing, string $slot): ?string
    {
        foreach ($missing as $entry) {
            if (self::missingSlotId((string) $entry) === $slot) {
                return (string) $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function complaintIdFromMissingEntry(array $facts, string $entry): string
    {
        $slot = self::missingSlotId($entry);
        if (in_array($slot, ['associated_symptoms', 'associated_detail'], true) && !str_contains($entry, '(')) {
            return '';
        }
        $records = self::complaintRecords($facts);
        if (preg_match('/\((.+)\)\s*$/u', $entry, $m)) {
            $label = mb_strtolower(trim((string) $m[1]));
            foreach ($records as $complaint) {
                if (mb_strtolower(trim((string) ($complaint['label'] ?? ''))) === $label
                    || (string) ($complaint['id'] ?? '') === $label
                ) {
                    return (string) ($complaint['id'] ?? '');
                }
            }
        }

        return (string) ($records[0]['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $complaint
     */
    private static function formatMissingEntry(string $slot, array $complaint, int $total): string
    {
        if ($total <= 1) {
            return $slot;
        }
        $label = trim((string) ($complaint['label'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($complaint['id'] ?? ''));
        }

        return $label !== '' ? $slot . ' (' . $label . ')' : $slot;
    }

    /**
     * @param list<array<string, mixed>> $complaints
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private static function missingSlotsAcrossComplaints(array $complaints, array $parent, array $context): array
    {
        $total = count($complaints);
        $redFlag = [];
        $severity = [];
        $timing = [];
        $place = [];
        foreach ($complaints as $complaint) {
            $scoped = self::complaintAsFacts($complaint);
            $scope = self::complaintScopeText($complaint);
            if (!self::hasClinicallyUsefulFacts($scoped) && (string) ($scoped['finding_status']['symptom'] ?? '') === 'negative') {
                // The patient denied feeling anything there; it is not a complaint to probe.
                continue;
            }
            if (!self::hasClinicallyUsefulFacts($scoped) && !self::complaintSlotSettled($complaint, 'symptom')
                && !self::slotAlreadyAsked($context, 'symptom')
            ) {
                return [self::formatMissingEntry('symptom', $complaint, $total)];
            }
            if (!self::slotAlreadyAsked($context, 'trauma')
                && !self::complaintSlotSettled($complaint, 'trauma') && self::traumaIsUseful($scoped, $context, $scope)
            ) {
                $redFlag[] = self::formatMissingEntry('trauma', $complaint, $total);
            }
            if (!self::slotAlreadyAsked($context, 'pain_score')
                && !self::complaintSlotSettled($complaint, 'pain_score') && self::needsPainScore($scoped, $context, $scope)
            ) {
                $severity[] = self::formatMissingEntry('pain_score', $complaint, $total);
            }
            if (!self::slotAlreadyAsked($context, 'onset') && !self::complaintSlotSettled($complaint, 'onset')) {
                $timing[] = self::formatMissingEntry('onset', $complaint, $total);
            }
            if (!self::slotAlreadyAsked($context, 'duration')
                && !self::complaintSlotSettled($complaint, 'duration') && !self::onsetCoversDuration($scoped)
            ) {
                $timing[] = self::formatMissingEntry('duration', $complaint, $total);
            }
            if (!self::slotAlreadyAsked($context, 'location')
                && !self::complaintSlotSettled($complaint, 'location') && self::locationIsUseful($scoped, $context, $scope)
            ) {
                $place[] = self::formatMissingEntry('location', $complaint, $total);
            }
            if (!self::slotAlreadyAsked($context, 'laterality')
                && !self::complaintSlotSettled($complaint, 'laterality') && self::lateralityIsUseful($scoped)
            ) {
                $place[] = self::formatMissingEntry('laterality', $complaint, $total);
            }
        }
        $associated = self::missingAssociated($parent, $context);

        return array_merge($redFlag, $severity, $associated, $timing, $place);
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private static function missingAssociated(array $facts, array $context): array
    {
        if (self::slotAlreadyAsked($context, 'associated_symptoms')
            || self::contextSlotUnresolved($context, 'associated_symptoms')
        ) {
            return [];
        }
        if (self::associatedNeedsDetail($facts)) {
            return ['associated_detail'];
        }

        return self::associatedAddressed($facts, $context) ? [] : ['associated_symptoms'];
    }

    private static function genuinelyMissingSlots(array $facts, array $context): array
    {
        $complaints = self::complaintRecords($facts);
        if ($complaints !== []) {
            return self::missingSlotsAcrossComplaints($complaints, $facts, $context);
        }
        $open = static fn (string $slot): bool => !self::slotAlreadyAsked($context, $slot)
            && !self::slotAddressed($slot, $facts)
            && !self::contextSlotUnresolved($context, $slot);
        if (!self::hasClinicallyUsefulFacts($facts) && $open('symptom')) {
            return ['symptom'];
        }

        $priority = [];
        if ($open('trauma') && self::traumaIsUseful($facts, $context)) {
            $priority[] = 'trauma';
        }
        if ($open('pain_score') && self::needsPainScore($facts, $context)) {
            $priority[] = 'pain_score';
        }
        $associated = self::missingAssociated($facts, $context);
        $rest = [];
        if ($open('onset')) {
            $rest[] = 'onset';
        }
        if ($open('duration') && !self::onsetCoversDuration($facts)) {
            $rest[] = 'duration';
        }
        if ($open('location') && self::locationIsUseful($facts, $context)) {
            $rest[] = 'location';
        }
        if ($open('laterality') && self::lateralityIsUseful($facts)) {
            $rest[] = 'laterality';
        }

        return array_merge($priority, $associated, $rest);
    }

    /**
     * Separate each complaint the patient named, then attach this utterance's
     * facts only to the complaints it actually refers to.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function bindComplaintFacts(array $facts, array $context, string $utterance, bool $opening): array
    {
        $utterance = trim($utterance);
        if ($utterance === '') {
            return self::projectComplaintLedger($facts);
        }
        if (!$opening && self::isBarePolarityAnswer($utterance)) {
            return self::applyBareAnswerToComplaint($facts, $context, $utterance);
        }

        $complaints = self::complaintRecords($facts);
        $lang = self::detectLanguageHint($context);
        foreach (self::discoverComplaintSeeds($utterance) as $seed) {
            $index = self::findComplaintIndex($complaints, $seed);
            if ($index === null) {
                $seed['id'] = self::nextComplaintId($complaints);
                $seed['label'] = self::labelForComplaint($seed, $utterance, $lang);
                $complaints[] = $seed;
                continue;
            }
            $complaints[$index] = self::mergeComplaintSeed($complaints[$index], $seed, $utterance, $lang);
        }

        $scalars = self::scalarsFromUtterance($utterance, (string) ($context['awaiting_slot'] ?? ''));
        $named = [];
        foreach ($complaints as $complaint) {
            if (self::complaintMentionedIn($complaint, $utterance)) {
                $named[] = (string) ($complaint['id'] ?? '');
            }
        }
        $awaitingId = trim((string) ($context['awaiting_complaint_id'] ?? ''));
        $share = self::utteranceAppliesToEveryComplaint($utterance, $opening ? '' : (string) ($context['awaiting_slot'] ?? ''));
        if ($opening) {
            $targets = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $complaints);
        } elseif ($share) {
            $targets = $named !== [] ? $named : array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $complaints);
        } elseif ($named === []) {
            $targets = $awaitingId !== '' ? [$awaitingId] : [];
        } else {
            $targets = $named;
        }
        $complaints = $share
            ? self::applyScalarsToComplaints(
                $complaints,
                $targets,
                $scalars,
                $utterance,
                true,
                $awaitingId,
                (string) ($context['awaiting_slot'] ?? '')
            )
            : self::applyScalarsByClause(
                $complaints,
                $targets,
                $utterance,
                $awaitingId,
                (string) ($context['awaiting_slot'] ?? '')
            );
        $facts['complaints'] = $complaints;

        return self::projectComplaintLedger($facts);
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function applyBareAnswerToComplaint(array $facts, array $context, string $utterance): array
    {
        $slot = self::canonicalInterviewSlot(trim((string) ($context['awaiting_slot'] ?? '')));
        $id = trim((string) ($context['awaiting_complaint_id'] ?? ''));
        $uncertain = class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksPatientUncertain($utterance);
        $yn = class_exists('ClinicalFeatureExtractors') ? ClinicalFeatureExtractors::extractYesNo($utterance) : null;
        $polarity = '';
        if ($uncertain) {
            $polarity = 'uncertain';
        } elseif ($yn === false) {
            $polarity = 'negative';
        } elseif ($yn === true) {
            $polarity = 'positive';
        }
        $side = $slot === 'laterality' ? self::canonicalLaterality($utterance, true) : '';
        if ($side !== '') {
            $polarity = 'positive';
        } elseif ($polarity === 'positive' && !in_array($slot, ['trauma', 'associated_symptoms'], true)) {
            $polarity = '';
        }

        if ($slot === '' || $slot === 'associated_symptoms') {
            if ($polarity !== '') {
                return self::markSlot($facts, 'associated_symptoms', $polarity);
            }

            return $facts;
        }

        $complaints = self::complaintRecords($facts);
        if ($complaints === []) {
            if ($side !== '') {
                $facts['laterality'] = $side;
            }

            return $polarity !== '' ? self::markSlot($facts, $slot, $polarity) : $facts;
        }
        foreach ($complaints as $i => $complaint) {
            if ($id !== '' && (string) ($complaint['id'] ?? '') !== $id) {
                continue;
            }
            if ($slot === 'laterality' && $side !== '') {
                $complaint['laterality'] = $side;
                $complaint = self::markComplaintSlot($complaint, 'laterality', 'positive');
            } elseif ($polarity !== '') {
                $complaint = self::markComplaintSlot($complaint, $slot, $polarity);
            }
            $complaints[$i] = $complaint;
            if ($id !== '') {
                break;
            }
        }
        $facts['complaints'] = $complaints;
        if ($side !== '') {
            $facts['laterality'] = $side;
        }
        if ($polarity !== '') {
            $facts = self::markSlot($facts, $slot, $polarity);
        }

        return self::projectComplaintLedger($facts);
    }

    /**
     * @param array<string, mixed> $complaint
     * @return array<string, mixed>
     */
    private static function markComplaintSlot(array $complaint, string $slot, string $polarity): array
    {
        $status = is_array($complaint['finding_status'] ?? null) ? $complaint['finding_status'] : [];
        $status[$slot] = $polarity;
        $complaint['finding_status'] = $status;

        return $complaint;
    }

    /**
     * A per-complaint slot needs no further question: it holds a fact, a polarity,
     * or the patient's own unresolved wording.
     *
     * @param array<string, mixed> $complaint
     */
    private static function complaintSlotSettled(array $complaint, string $slot): bool
    {
        $unresolved = is_array($complaint['unresolved'] ?? null) ? $complaint['unresolved'] : [];

        return isset($unresolved[$slot]) || self::slotAddressed($slot, self::complaintAsFacts($complaint));
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function contextSlotUnresolved(array $context, string $slot): bool
    {
        $unresolved = is_array($context['unresolved_answers'] ?? null) ? $context['unresolved_answers'] : [];

        return isset($unresolved[$slot]);
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function ledgerFingerprint(array $facts): string
    {
        $rows = [];
        foreach (self::complaintRecords($facts) as $complaint) {
            $row = [];
            foreach (['id', 'symptom', 'location', 'laterality', 'onset', 'duration', 'frequency', 'pain_score', 'finding_status'] as $key) {
                $row[$key] = $complaint[$key] ?? null;
            }
            $rows[] = $row;
        }
        $flat = [];
        foreach (['symptom', 'location', 'laterality', 'onset', 'duration', 'frequency', 'pain_score'] as $key) {
            $flat[$key] = $facts[$key] ?? null;
        }
        $flat['associated_symptoms'] = self::stringList($facts['associated_symptoms'] ?? []);

        return (string) json_encode([$rows, $flat]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function lastQuestionWasRetry(array $context): bool
    {
        $conversation = is_array($context['conversation'] ?? null) ? $context['conversation'] : [];
        for ($i = count($conversation) - 1; $i >= 0; $i--) {
            if (is_array($conversation[$i]) && ($conversation[$i]['role'] ?? '') === 'gemini') {
                return ($conversation[$i]['kind'] ?? '') === 'retry';
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function sameQuestionAskedTwice(array $context): bool
    {
        $conversation = is_array($context['conversation'] ?? null) ? $context['conversation'] : [];
        $asked = [];
        for ($i = count($conversation) - 1; $i >= 0 && count($asked) < 2; $i--) {
            if (is_array($conversation[$i]) && ($conversation[$i]['role'] ?? '') === 'gemini') {
                $asked[] = mb_strtolower(trim((string) ($conversation[$i]['text'] ?? '')));
            }
        }

        return count($asked) === 2 && $asked[0] !== '' && $asked[0] === $asked[1];
    }

    /**
     * Facts that passed evidence grounding fill empty fields of the complaint they belong to.
     * Patient-stated facts are never overwritten and nothing is copied across complaints
     * unless the patient said it applies to all of them.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $validated grounded model facts for this turn
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function fillComplaintsFromGemini(array $facts, array $validated, array $context, string $utterance, bool $opening): array
    {
        $complaints = self::complaintRecords($facts);
        $utterance = trim($utterance);
        if ($utterance === '' || $validated === []) {
            return $facts;
        }
        if ($complaints === []) {
            $seed = self::complaintFromValidatedFacts($facts, $validated, $context);
            if ($seed === null) {
                return $facts;
            }
            $complaints = [$seed];
        }
        $awaitingId = $opening ? '' : trim((string) ($context['awaiting_complaint_id'] ?? ''));
        $awaitingSlot = $opening ? '' : trim((string) ($context['awaiting_slot'] ?? ''));
        $ids = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $complaints);
        if (!in_array($awaitingId, $ids, true)) {
            $awaitingId = '';
        }
        $share = self::utteranceAppliesToEveryComplaint($utterance, $awaitingSlot);
        $named = [];
        foreach ($complaints as $complaint) {
            if (self::complaintMentionedIn($complaint, $utterance)) {
                $named[] = (string) ($complaint['id'] ?? '');
            }
        }
        if ($opening) {
            $targets = $ids;
        } elseif ($share) {
            $targets = $named !== [] ? $named : $ids;
        } elseif ($named === []) {
            $targets = $awaitingId !== '' ? [$awaitingId] : (count($ids) === 1 ? $ids : []);
        } else {
            $targets = $named;
        }

        $nlp = self::collectLocalNlpEvidence($utterance);
        $gloss = (string) ($nlp['english_gloss'] ?? '');
        $hay = mb_strtolower(trim($utterance . ' ' . $gloss));
        $sideHay = $awaitingSlot === 'laterality' ? $hay : mb_strtolower(self::withoutShareMarkers($utterance . ' ' . $gloss));

        $scalars = ['onset' => '', 'duration' => '', 'laterality' => '', 'pain_score' => null, 'trauma' => '', 'frequency' => ''];
        foreach (['onset', 'duration', 'frequency'] as $key) {
            $value = trim((string) ($validated[$key] ?? ''));
            if ($value !== '' && !self::isInterviewControlLabel($value) && self::scalarSupportedByPatient($value, $hay)) {
                $scalars[$key] = $value;
            }
        }
        $side = trim((string) ($validated['laterality'] ?? ''));
        if ($side !== '' && class_exists('ClinicalFeatureExtractors')) {
            $readsAsDenial = ClinicalFeatureExtractors::extractYesNo($side) === false;
            if ($readsAsDenial
                ? ($awaitingSlot === 'laterality' && ClinicalFeatureExtractors::looksLateralityWala($utterance))
                : self::scalarSupportedByPatient($side, $sideHay)
            ) {
                $scalars['laterality'] = $side;
            }
        }
        if (is_numeric($validated['pain_score'] ?? null)) {
            $score = (int) $validated['pain_score'];
            if ($score >= 1 && $score <= 10 && preg_match('/\b' . $score . '\b/u', $utterance)) {
                $scalars['pain_score'] = $score;
            }
        }
        if ($targets !== []) {
            // Several receivers: only the complaint nearest the fact in the sentence gets it.
            $complaints = self::applyScalarsToComplaints($complaints, $targets, $scalars, $utterance, $share, '', '');
        }

        $location = trim((string) ($validated['location'] ?? ''));
        $symptom = trim((string) ($validated['symptom'] ?? ''));
        if ($symptom !== '' && !self::isNonClinicalDiscourseLabel($symptom) && !self::isInterviewControlLabel($symptom)) {
            $owned = false;
            $empty = [];
            foreach ($complaints as $i => $complaint) {
                $current = trim((string) ($complaint['symptom'] ?? ''));
                if ($current === '') {
                    $empty[] = $i;
                } elseif (self::sameClinicalLabel($current, $symptom)) {
                    $owned = true;
                }
            }
            $pick = null;
            if (!$owned && $empty !== []) {
                $aligned = [];
                foreach ($empty as $i) {
                    foreach (['label', 'local_location', 'location'] as $key) {
                        $term = trim((string) ($complaints[$i][$key] ?? ''));
                        if ($term !== '' && (self::factTextsAlign($term, $symptom) || ($location !== '' && self::factTextsAlign($term, $location)))) {
                            $aligned[] = $i;
                            break;
                        }
                    }
                }
                $awaitingIndex = array_search($awaitingId, $ids, true);
                if (count($aligned) === 1) {
                    $pick = $aligned[0];
                } elseif ($awaitingSlot === 'symptom' && is_int($awaitingIndex) && in_array($awaitingIndex, $empty, true)) {
                    $pick = $awaitingIndex;
                } elseif (count($complaints) === 1) {
                    $pick = 0;
                }
            }
            if ($pick !== null) {
                $complaints[$pick]['symptom'] = $symptom;
            }
        }

        if ($location !== '' && !self::isNonClinicalDiscourseLabel($location) && !self::isInterviewControlLabel($location)) {
            $taken = false;
            foreach ($complaints as $complaint) {
                foreach (['location', 'local_location'] as $key) {
                    $term = trim((string) ($complaint[$key] ?? ''));
                    if ($term !== '' && (self::sameClinicalLabel($term, $location) || self::factTextsAlign($term, $location))) {
                        $taken = true;
                    }
                }
            }
            $pick = null;
            $awaitingIndex = array_search($awaitingId, $ids, true);
            if (!$taken) {
                $allowed = array_map(
                    static fn (string $s): string => mb_strtolower(trim($s)),
                    self::stringList($nlp['locations'] ?? [])
                );
                if ($awaitingSlot === 'location' && is_int($awaitingIndex)
                    && trim((string) ($complaints[$awaitingIndex]['location'] ?? '')) === ''
                    && self::locationSupported($location, $hay, $allowed)
                ) {
                    $pick = $awaitingIndex;
                } elseif (count($complaints) === 1 && trim((string) ($complaints[0]['location'] ?? '')) === '') {
                    $pick = 0;
                }
            }
            if ($pick !== null) {
                $complaints[$pick]['location'] = $location;
                if (trim((string) ($complaints[$pick]['label'] ?? '')) === '') {
                    $complaints[$pick]['label'] = self::labelForComplaint(
                        $complaints[$pick],
                        $utterance,
                        self::detectLanguageHint($context)
                    );
                }
            }
        }
        $facts['complaints'] = $complaints;

        return self::projectComplaintLedger($facts);
    }

    /**
     * The local dictionary found no complaint but a validated model symptom exists:
     * it becomes the first complaint record, carrying the facts already collected for it.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private static function complaintFromValidatedFacts(array $facts, array $validated, array $context): ?array
    {
        $symptom = trim((string) ($validated['symptom'] ?? ''));
        if ($symptom === '' || self::isNonClinicalDiscourseLabel($symptom) || self::isInterviewControlLabel($symptom)) {
            return null;
        }
        $opening = trim((string) ($context['chief_complaint'] ?? ''));
        $corpus = self::patientEvidenceCorpus($context);
        $location = trim((string) ($validated['location'] ?? ''));
        if ($location === '' || self::isNonClinicalDiscourseLabel($location) || self::isInterviewControlLabel($location)) {
            $location = trim((string) ($facts['location'] ?? ''));
        }
        $local = $location !== '' && self::appearsInPatientText($location, $corpus) ? $location : '';
        $pos = $local !== '' ? (self::textPos($opening, $local) ?? 0) : 0;
        $seed = self::seedFromParts($opening !== '' ? $opening : $corpus, $symptom, $local, $location, $pos);
        $seed['id'] = 'c1';
        $seed['anchored'] = true;
        foreach (['laterality', 'onset', 'duration', 'frequency'] as $key) {
            $value = trim((string) ($facts[$key] ?? ''));
            if ($value !== '' && !self::isInterviewControlLabel($value)) {
                $seed[$key] = $value;
            }
        }
        if (is_numeric($facts['pain_score'] ?? null)) {
            $seed['pain_score'] = (int) $facts['pain_score'];
        }
        $status = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        foreach (['symptom', 'location', 'laterality', 'onset', 'duration', 'frequency', 'pain_score', 'trauma'] as $slot) {
            if (in_array((string) ($status[$slot] ?? ''), ['negative', 'uncertain'], true)
                || ($slot === 'trauma' && ($status[$slot] ?? '') === 'positive')
            ) {
                $seed = self::markComplaintSlot($seed, $slot, (string) $status[$slot]);
            }
        }
        $unresolved = is_array($context['unresolved_answers'] ?? null) ? $context['unresolved_answers'] : [];
        unset($unresolved['associated_symptoms']);
        if ($unresolved !== []) {
            $seed['unresolved'] = $unresolved;
        }
        $seed['label'] = self::labelForComplaint($seed, $opening !== '' ? $opening : $corpus, self::detectLanguageHint($context));

        return $seed;
    }

    /**
     * Record how this reply answered the slot that was asked. A reply that states a
     * polarity keeps it; a reply that maps to no fact is kept word-for-word as unresolved
     * evidence so the same question is not asked forever. Unresolved is never a fact.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function resolveAwaitingAnswer(array $context, string $utterance, string $answerStatus, bool $learnedSomething): array
    {
        $utterance = trim($utterance);
        if ($utterance === '') {
            return $context;
        }
        $slot = self::canonicalInterviewSlot(trim((string) ($context['awaiting_slot'] ?? '')));
        if ($slot === '') {
            $slot = self::canonicalInterviewSlot(self::questionFactSlot((string) ($context['awaiting_question'] ?? '')));
        }
        if ($slot === '') {
            return $context;
        }
        $facts = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        $polarity = $answerStatus === 'UNCERTAIN' ? 'uncertain' : self::answerPolarityForSlot($slot, $utterance);
        $complaints = self::complaintRecords($facts);
        $id = trim((string) ($context['awaiting_complaint_id'] ?? ''));
        $targets = [];
        if ($complaints !== []) {
            if ($slot === 'associated_symptoms' || $id === '' || self::complaintById($complaints, $id) === null) {
                foreach ($complaints as $complaint) {
                    $cid = (string) ($complaint['id'] ?? '');
                    if ($cid !== '') {
                        $targets[] = $cid;
                    }
                }
            } else {
                $targets = [$id];
            }
        }
        if ($slot === 'laterality' && $polarity !== 'uncertain') {
            $side = self::canonicalLaterality($utterance, true);
            if ($side !== '') {
                return self::storeLateralityAnswer($context, $facts, $complaints, $targets, $side, $slot);
            }
        }
        if ($polarity === 'positive' && !in_array($slot, ['trauma', 'associated_symptoms'], true)) {
            $polarity = '';
        }

        if ($polarity !== '') {
            $facts = self::markSlot($facts, $slot, $polarity);
            foreach ($complaints as $i => $complaint) {
                $cid = (string) ($complaint['id'] ?? '');
                if ($targets !== [] && !in_array($cid, $targets, true)) {
                    continue;
                }
                $complaints[$i] = self::markComplaintSlot($complaint, $slot, $polarity);
            }
        } else {
            $unresolved = is_array($context['unresolved_answers'] ?? null) ? $context['unresolved_answers'] : [];
            $unresolved[$slot] = $utterance;
            $context['unresolved_answers'] = $unresolved;
            foreach ($complaints as $i => $complaint) {
                $cid = (string) ($complaint['id'] ?? '');
                if ($targets !== [] && !in_array($cid, $targets, true)) {
                    continue;
                }
                $kept = is_array($complaint['unresolved'] ?? null) ? $complaint['unresolved'] : [];
                $kept[$slot] = $utterance;
                $complaints[$i]['unresolved'] = $kept;
            }
        }
        if ($complaints !== []) {
            $facts['complaints'] = $complaints;
        }
        $context['clinical_facts'] = self::projectComplaintLedger($facts);
        self::rememberAskedSlot($context, $slot);

        return $context;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function discoverComplaintSeeds(string $text): array
    {
        $nlp = self::collectLocalNlpEvidence($text);
        $negated = self::negatedSymptomLabels($text, $nlp);
        $symptoms = [];
        $deniedAt = [];
        foreach (is_array($nlp['symptom_mappings'] ?? null) ? $nlp['symptom_mappings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $local = trim((string) ($row['local'] ?? ''));
            $english = trim((string) ($row['english'] ?? ''));
            if ($english === '' || self::isNonClinicalDiscourseLabel($english) || self::isInterviewControlLabel($english)) {
                continue;
            }
            $pos = self::textPos($text, $local);
            if ($pos === null) {
                $pos = self::textPos($text, $english);
            }
            if (isset($negated[mb_strtolower($english)])) {
                if ($pos !== null) {
                    $deniedAt[] = $pos;
                }
                continue;
            }
            $symptoms[] = ['local' => $local, 'english' => $english, 'pos' => $pos, 'generic' => self::isGenericPainLabel($english)];
        }
        $specific = array_values(array_filter($symptoms, static fn (array $row): bool => !$row['generic']));
        if ($specific !== []) {
            $symptoms = $specific;
        } elseif (count($symptoms) > 1) {
            // Several dictionary glosses of one unspecified pain are one mention, not several complaints.
            $keep = $symptoms[0];
            foreach ($symptoms as $row) {
                if (mb_strtolower((string) $row['english']) === 'pain') {
                    $keep['english'] = $row['english'];
                }
                if ($row['pos'] !== null && ($keep['pos'] === null || $row['pos'] < $keep['pos'])) {
                    $keep['pos'] = $row['pos'];
                    $keep['local'] = $row['local'];
                }
            }
            $symptoms = [$keep];
        }
        $anchoredName = [];
        foreach ($symptoms as $row) {
            if ($row['pos'] !== null) {
                $anchoredName[mb_strtolower((string) $row['english'])] = true;
            }
        }
        $symptoms = array_values(array_filter(
            $symptoms,
            static function (array $row) use ($anchoredName): bool {
                if ($row['pos'] !== null) {
                    return true;
                }

                return !isset($anchoredName[mb_strtolower((string) $row['english'])]);
            }
        ));
        $places = [];
        foreach (is_array($nlp['location_mappings'] ?? null) ? $nlp['location_mappings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $local = trim((string) ($row['surface'] ?? '')) ?: trim((string) ($row['local'] ?? ''));
            $english = trim((string) ($row['english'] ?? ''));
            if ($english === '' && $local === '') {
                continue;
            }
            $pos = self::textPos($text, $local !== '' ? $local : $english);
            if ($pos === null) {
                continue;
            }
            if (self::placeOnlyDenied($text, $pos, $deniedAt, $symptoms)) {
                continue;
            }
            $places[] = ['local' => $local, 'english' => $english !== '' ? $english : $local, 'pos' => $pos];
        }
        $seeds = [];
        $used = [];
        if ($specific === [] && count($places) >= 2) {
            $pain = $symptoms[0]['english'] ?? '';
            foreach ($places as $place) {
                $seeds[] = self::seedFromParts($text, is_string($pain) ? $pain : '', (string) $place['local'], (string) $place['english'], (int) $place['pos']);
            }

            return self::sortSeeds($seeds);
        }
        foreach ($symptoms as $symptom) {
            if ($symptom['pos'] === null) {
                $alignedPlace = null;
                foreach ($places as $index => $place) {
                    if (isset($used[$index])) {
                        continue;
                    }
                    if (self::factTextsAlign((string) $place['english'], (string) $symptom['english'])
                        || self::factTextsAlign((string) $place['local'], (string) $symptom['english'])
                    ) {
                        $alignedPlace = $index;
                        break;
                    }
                }
                if ($alignedPlace !== null) {
                    $place = $places[$alignedPlace];
                    $used[$alignedPlace] = true;
                    $seed = self::seedFromParts(
                        $text,
                        (string) $symptom['english'],
                        (string) $place['local'],
                        (string) $place['english'],
                        (int) $place['pos']
                    );
                    $seed['anchored'] = true;
                    $seeds[] = $seed;
                    continue;
                }
                $seed = self::seedFromParts($text, (string) $symptom['english'], '', '', 0);
                $seed['anchored'] = false;
                $seed['text_span'] = '';
                $symLocal = trim((string) $symptom['local']);
                if ($symLocal !== '' && !self::referenceLooksLikeSentence($symLocal)) {
                    $seed['local_symptom'] = $symLocal;
                }
                $seeds[] = $seed;
                continue;
            }
            $best = null;
            $bestDistance = PHP_INT_MAX;
            foreach ($places as $index => $place) {
                if (isset($used[$index])) {
                    continue;
                }
                $distance = abs((int) $symptom['pos'] - (int) $place['pos']);
                $symLocal = mb_strtolower((string) $symptom['local']);
                $placeLocal = mb_strtolower((string) $place['local']);
                $contained = $symLocal !== '' && $placeLocal !== '' && (str_contains($symLocal, $placeLocal) || str_contains($placeLocal, $symLocal));
                $aligned = self::factTextsAlign((string) $place['english'], (string) $symptom['english'])
                    || self::factTextsAlign($placeLocal, $symLocal);
                $sameClause = mb_strtolower(self::clauseAt($text, (int) $symptom['pos'])) === mb_strtolower(self::clauseAt($text, (int) $place['pos']));
                if (!$contained && !$aligned && !($sameClause && $distance <= 48)) {
                    continue;
                }
                if ($distance < $bestDistance) {
                    $best = $index;
                    $bestDistance = $distance;
                }
            }
            $place = $best !== null ? $places[$best] : null;
            if ($best !== null) {
                $used[$best] = true;
            }
            $seed = self::seedFromParts(
                $text,
                (string) $symptom['english'],
                $place !== null ? (string) $place['local'] : '',
                $place !== null ? (string) $place['english'] : '',
                (int) $symptom['pos']
            );
            $symLocal = trim((string) $symptom['local']);
            if ($symLocal !== '' && !self::referenceLooksLikeSentence($symLocal)) {
                $seed['local_symptom'] = $symLocal;
            }
            $seed['anchored'] = true;
            $seeds[] = $seed;
        }
        foreach ($places as $index => $place) {
            if (isset($used[$index])) {
                continue;
            }
            $seed = self::seedFromParts($text, '', (string) $place['local'], (string) $place['english'], (int) $place['pos']);
            $seed['anchored'] = true;
            $seeds[] = $seed;
        }

        return self::sortSeeds(self::assignUnanchoredSpans($seeds, $text));
    }

    /**
     * A symptom the dictionary only knows in another language still belongs to
     * the clause that no other complaint has claimed.
     *
     * @param list<array<string, mixed>> $seeds
     * @return list<array<string, mixed>>
     */
    private static function assignUnanchoredSpans(array $seeds, string $text): array
    {
        $clauses = self::textClauses($text);
        $used = [];
        foreach ($seeds as $seed) {
            if (empty($seed['anchored'])) {
                continue;
            }
            foreach ($clauses as $index => $clause) {
                $end = $clauses[$index + 1]['start'] ?? (mb_strlen($text) + 1);
                $pos = (int) ($seed['pos'] ?? 0);
                if ($pos >= (int) $clause['start'] && $pos < $end) {
                    $used[$index] = true;
                }
            }
        }
        foreach ($seeds as $index => $seed) {
            if (!empty($seed['anchored'])) {
                continue;
            }
            foreach ($clauses as $clauseIndex => $clause) {
                if (isset($used[$clauseIndex]) || self::clauseIsOnlyTime((string) $clause['text'])) {
                    continue;
                }
                $seeds[$index]['text_span'] = (string) $clause['text'];
                $seeds[$index]['pos'] = (int) $clause['start'];
                $seeds[$index]['anchored'] = true;
                $used[$clauseIndex] = true;
                break;
            }
        }

        return $seeds;
    }

    /**
     * @return list<array{start:int,text:string}>
     */
    private static function textClauses(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $delims = 'kag|ug|and';
        if (preg_match('/\b(ang|ko|mo|masakit)\b/ui', $text)) {
            $delims .= '|at';
        }
        $clauses = [];
        $start = 0;
        if (preg_match_all('/\b(?:' . $delims . ')\b|,/ui', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $hit) {
                $char = mb_strlen(substr($text, 0, (int) $hit[1]));
                $clause = trim(mb_substr($text, $start, max(0, $char - $start)));
                if ($clause !== '') {
                    $clauses[] = ['start' => $start, 'text' => $clause];
                }
                $start = $char + mb_strlen((string) $hit[0]);
            }
        }
        $tail = trim(mb_substr($text, $start));
        if ($tail !== '') {
            $clauses[] = ['start' => $start, 'text' => $tail];
        }
        if ($clauses === []) {
            $clauses[] = ['start' => 0, 'text' => $text];
        }

        return $clauses;
    }

    private static function clauseIsOnlyTime(string $clause): bool
    {
        $clause = trim($clause);
        if ($clause === '' || !class_exists('ClinicalFeatureExtractors')) {
            return false;
        }
        $duration = ClinicalFeatureExtractors::extractDuration($clause);
        $raw = trim((string) ($duration['raw'] ?? ''));
        if ($raw === '') {
            return false;
        }
        $rest = trim((string) preg_replace('/' . preg_quote($raw, '/') . '/iu', '', $clause));

        return $rest === '' || self::isNonClinicalDiscourseLabel($rest);
    }

    /**
     * @return array<string, mixed>
     */
    private static function seedFromParts(string $text, string $symptom, string $localLocation, string $location, int $pos): array
    {
        $symptom = trim($symptom);
        $location = trim($location);
        $span = self::clauseAt($text, $pos);

        return [
            'id' => '',
            'symptom' => $symptom !== '' ? $symptom : null,
            'location' => $location !== '' ? $location : null,
            'laterality' => null,
            'pain_score' => null,
            'onset' => null,
            'duration' => null,
            'frequency' => null,
            'finding_status' => [],
            'label' => '',
            'local_symptom' => '',
            'local_location' => trim($localLocation),
            'text_span' => $span !== '' ? $span : $text,
            'pos' => $pos,
        ];
    }

    /**
     * @param list<array<string, mixed>> $seeds
     * @return list<array<string, mixed>>
     */
    private static function sortSeeds(array $seeds): array
    {
        usort($seeds, static fn (array $a, array $b): int => ((int) ($a['pos'] ?? 0)) <=> ((int) ($b['pos'] ?? 0)));

        return $seeds;
    }

    private static function textPos(string $text, string $needle): ?int
    {
        $needle = trim($needle);
        if ($needle === '' || mb_strlen($needle) < 3) {
            return null;
        }
        $pos = mb_stripos($text, $needle);

        return $pos === false ? null : (int) $pos;
    }

    /**
     * @return list<int>
     */
    private static function textPositions(string $text, string $needle): array
    {
        $needle = trim($needle);
        if ($needle === '' || mb_strlen($needle) < 3) {
            return [];
        }
        $out = [];
        $offset = 0;
        while (($pos = mb_stripos($text, $needle, $offset)) !== false) {
            $out[] = (int) $pos;
            $offset = (int) $pos + mb_strlen($needle);
        }

        return $out;
    }

    /**
     * Whether the words just before a mention, inside its own clause, deny it.
     * Polarity comes from the shared language-aware yes/no reader.
     */
    private static function mentionIsNegated(string $text, int $charPos, array $turnStarts = []): bool
    {
        if ($charPos <= 0 || !class_exists('ClinicalFeatureExtractors')) {
            return false;
        }
        $floor = 0;
        foreach ($turnStarts as $start) {
            if ($start <= $charPos && $start > $floor) {
                $floor = (int) $start;
            }
        }
        $prefix = mb_substr($text, $floor, $charPos - $floor);
        $parts = preg_split('/\b(?:pero|but|while|however|and|kag|ug|at)\b|[,;.!?]/ui', $prefix) ?: [$prefix];
        $segment = trim((string) end($parts));
        if ($segment === '') {
            return false;
        }
        $words = preg_split('/\s+/u', $segment) ?: [];
        $window = implode(' ', array_slice($words, -3));
        if (ClinicalFeatureExtractors::looksLateralityWala($window . ' ' . mb_substr($text, $charPos, 24))) {
            // Hiligaynon "wala" naming the left side is not a denial.
            return false;
        }

        return ClinicalFeatureExtractors::extractYesNo($window) === false;
    }

    /**
     * English labels of NLP symptom mappings the patient only mentioned in a denial.
     *
     * @param array<string, mixed> $nlp
     * @param list<int> $turnStarts where each patient turn begins when $text is the joined corpus
     * @return array<string, true>
     */
    private static function negatedSymptomLabels(string $text, array $nlp, array $turnStarts = []): array
    {
        $seen = [];
        foreach (is_array($nlp['symptom_mappings'] ?? null) ? $nlp['symptom_mappings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $english = mb_strtolower(trim((string) ($row['english'] ?? '')));
            $local = trim((string) ($row['local'] ?? ''));
            if ($english === '') {
                continue;
            }
            $positions = self::textPositions($text, $local);
            if ($positions === []) {
                $positions = self::textPositions($text, $english);
            }
            foreach ($positions as $pos) {
                $negated = self::mentionIsNegated($text, $pos, $turnStarts);
                $seen[$english] = ($seen[$english] ?? true) && $negated;
            }
        }

        return array_filter($seen, static fn (bool $v): bool => $v);
    }

    /**
     * "both / pareho / lahat …" either quantifies a body place ("both knees") or says
     * a fact applies to several complaints. Only the second is a sharing marker.
     *
     * @return list<array{start:int,end:int,place:bool}>
     */
    private static function shareMarkers(string $text): array
    {
        if (!preg_match_all(
            '/\b(both|all of them|pareho|parehas|parehong|lahat|silang dalawa|ang duha|sa duha)\b/ui',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }
        $places = [];
        $nlp = self::collectLocalNlpEvidence($text);
        foreach (is_array($nlp['location_mappings'] ?? null) ? $nlp['location_mappings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $term = trim((string) ($row['local'] ?? ''));
            foreach (self::textPositions($text, $term !== '' ? $term : (string) ($row['english'] ?? '')) as $pos) {
                $places[] = $pos;
            }
        }
        $out = [];
        foreach ($matches[0] as $hit) {
            $start = mb_strlen(substr($text, 0, (int) $hit[1]));
            $end = $start + mb_strlen((string) $hit[0]);
            $place = false;
            foreach ($places as $pos) {
                if ($pos < $end) {
                    continue;
                }
                $between = trim(mb_substr($text, $end, $pos - $end));
                if (preg_match('/[,;.!?]/u', $between)) {
                    continue;
                }
                $gap = $between === '' ? 0 : count(preg_split('/\s+/u', $between) ?: []);
                if ($gap <= 2) {
                    $place = true;
                    break;
                }
            }
            $out[] = ['start' => $start, 'end' => $end, 'place' => $place];
        }

        return $out;
    }

    private static function negationSegmentStart(string $text, int $charPos): int
    {
        $start = 0;
        if (preg_match_all('/\b(?:pero|but|while|however|and|kag|ug|at)\b|[,;.!?]/ui', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $char = mb_strlen(substr($text, 0, (int) $hit[1]));
                if ($char >= $charPos) {
                    break;
                }
                $start = $char + mb_strlen((string) $hit[0]);
            }
        }

        return $start;
    }

    /**
     * A body place named only inside a denial ("no pain in my X") is not a complaint.
     *
     * @param list<int> $deniedAt
     * @param list<array<string, mixed>> $keptSymptoms
     */
    private static function placeOnlyDenied(string $text, int $pos, array $deniedAt, array $keptSymptoms): bool
    {
        if (self::mentionIsNegated($text, $pos)) {
            return true;
        }
        if ($deniedAt === []) {
            return false;
        }
        $segment = self::negationSegmentStart($text, $pos);
        $denied = false;
        foreach ($deniedAt as $at) {
            if (self::negationSegmentStart($text, $at) === $segment) {
                $denied = true;
                break;
            }
        }
        if (!$denied) {
            return false;
        }
        foreach ($keptSymptoms as $row) {
            if ($row['pos'] !== null && self::negationSegmentStart($text, (int) $row['pos']) === $segment) {
                return false;
            }
        }

        return true;
    }

    private static function withoutShareMarkers(string $text): string
    {
        $markers = array_reverse(array_filter(self::shareMarkers($text), static fn (array $m): bool => !$m['place']));
        foreach ($markers as $m) {
            $text = mb_substr($text, 0, $m['start']) . ' ' . mb_substr($text, $m['end']);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function clauseAt(string $text, int $charPos): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $delims = 'kag|ug|and';
        if (preg_match('/\b(ang|ko|mo|masakit)\b/ui', $text)) {
            $delims .= '|at';
        }
        $start = 0;
        $chosen = $text;
        if (preg_match_all('/\b(?:' . $delims . ')\b|,/ui', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $hit) {
                $char = mb_strlen(substr($text, 0, (int) $hit[1]));
                if ($charPos < $char) {
                    return trim(mb_substr($text, $start, $char - $start));
                }
                $start = $char + mb_strlen((string) $hit[0]);
            }
            $chosen = trim(mb_substr($text, $start));
        }

        return $chosen !== '' ? $chosen : $text;
    }

    /**
     * @param list<array<string, mixed>> $complaints
     * @param array<string, mixed> $seed
     */
    private static function findComplaintIndex(array $complaints, array $seed): ?int
    {
        foreach ($complaints as $index => $complaint) {
            if (self::complaintMatchesSeed($complaint, $seed)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $complaint
     * @param array<string, mixed> $seed
     */
    private static function complaintMatchesSeed(array $complaint, array $seed): bool
    {
        $cLoc = mb_strtolower(trim((string) ($complaint['location'] ?? '')));
        $sLoc = mb_strtolower(trim((string) ($seed['location'] ?? '')));
        $cLocal = mb_strtolower(trim((string) ($complaint['local_location'] ?? '')));
        $sLocal = mb_strtolower(trim((string) ($seed['local_location'] ?? '')));
        $sameLocal = $cLocal !== '' && $sLocal !== '' && (self::sameClinicalLabel($cLocal, $sLocal) || self::factTextsAlign($cLocal, $sLocal));
        $locConflict = !$sameLocal && $cLoc !== '' && $sLoc !== ''
            && !self::sameClinicalLabel($cLoc, $sLoc) && !self::factTextsAlign($cLoc, $sLoc);
        if ($locConflict) {
            return false;
        }
        $cSym = trim((string) ($complaint['symptom'] ?? ''));
        $sSym = trim((string) ($seed['symptom'] ?? ''));
        $cGeneric = $cSym === '' || self::isGenericPainLabel($cSym);
        $sGeneric = $sSym === '' || self::isGenericPainLabel($sSym);
        if (!$cGeneric && !$sGeneric && !self::sameClinicalLabel($cSym, $sSym) && !self::factTextsAlign($cSym, $sSym)) {
            return false;
        }
        if (!$sGeneric && $cLoc !== '' && $sLoc === '' && !self::factTextsAlign($cLoc, $sSym) && !self::sameClinicalLabel($cLoc, $sSym)) {
            return false;
        }
        if (!$cGeneric && $sGeneric && $sLoc !== '' && $cLoc === '' && !self::factTextsAlign($sLoc, $cSym) && !self::sameClinicalLabel($sLoc, $cSym)) {
            return false;
        }
        if ($cLoc === '' && $sLoc === '' && $cLocal === '' && $sLocal === '' && $cGeneric && $sGeneric) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $complaint
     * @param array<string, mixed> $seed
     * @return array<string, mixed>
     */
    private static function mergeComplaintSeed(array $complaint, array $seed, string $text, string $lang): array
    {
        $symptom = trim((string) ($seed['symptom'] ?? ''));
        $current = trim((string) ($complaint['symptom'] ?? ''));
        if ($symptom !== '' && ($current === '' || (self::isGenericPainLabel($current) && !self::isGenericPainLabel($symptom)))) {
            $complaint['symptom'] = $symptom;
        }
        foreach (['location', 'local_location'] as $key) {
            if (trim((string) ($complaint[$key] ?? '')) === '' && trim((string) ($seed[$key] ?? '')) !== '') {
                $complaint[$key] = $seed[$key];
            }
        }
        $incomingSpan = trim((string) ($seed['text_span'] ?? ''));
        $currentSpan = trim((string) ($complaint['text_span'] ?? ''));
        $own = trim((string) ($complaint['local_location'] ?? ''));
        if ($own === '') {
            $own = trim((string) ($complaint['location'] ?? ''));
        }
        $keepCurrent = $currentSpan !== '' && $own !== '' && self::appearsInPatientText($own, $currentSpan);
        $local = trim((string) (($seed['local_location'] ?? '') !== '' ? $seed['local_location'] : ($complaint['local_location'] ?? '')));
        if (!$keepCurrent && $incomingSpan !== '' && ($currentSpan === ''
            || ($local !== '' && self::appearsInPatientText($local, $incomingSpan) && !self::appearsInPatientText($local, $currentSpan))
        )) {
            $complaint['text_span'] = $incomingSpan;
        }
        $label = self::labelForComplaint($complaint, $text, $lang);
        if ($label !== '') {
            $complaint['label'] = $label;
        }

        return $complaint;
    }

    /**
     * @param array<string, mixed> $complaint
     */
    private static function labelForComplaint(array $complaint, string $text, string $lang): string
    {
        $span = trim((string) ($complaint['text_span'] ?? ''));
        $hay = $span !== '' ? $span : $text;
        $facts = self::complaintAsFacts($complaint);
        $stored = trim((string) ($complaint['location'] ?? ''));
        if ($stored === '') {
            $stored = trim((string) ($complaint['local_location'] ?? ''));
        }
        if ($stored !== '') {
            $place = self::phraseForStoredLocation($stored, $hay, $lang, $facts);
            if ($place === '') {
                $place = self::phraseForStoredLocation($stored, $text, $lang, $facts);
            }
            if ($place !== '') {
                return $place;
            }
        }
        $symptom = self::phraseForStoredSymptom($facts, $hay, $lang);
        if ($symptom === '') {
            $symptom = self::phraseForStoredSymptom($facts, $text, $lang);
        }
        if ($symptom !== '') {
            return $symptom;
        }
        $clause = self::asNounPhrase($span);
        if ($clause !== '' && !self::referenceLooksLikeSentence($clause)) {
            $usable = self::usableReference($clause, $lang, $text);
            if ($usable !== '') {
                return $usable;
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $complaints
     */
    private static function nextComplaintId(array $complaints): string
    {
        $max = 0;
        foreach ($complaints as $complaint) {
            if (preg_match('/^c(\d+)$/', (string) ($complaint['id'] ?? ''), $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'c' . ($max + 1);
    }

    /**
     * @return array{onset:string,duration:string,laterality:string,pain_score:?int,trauma:string,frequency:string}
     */
    private static function scalarsFromUtterance(string $text, string $awaitingSlot): array
    {
        if ($awaitingSlot !== 'laterality') {
            $text = self::withoutShareMarkers($text);
        }
        $scratch = self::harvestStatedFacts(self::blankFacts(), $text);
        $pain = null;
        if (is_numeric($scratch['pain_score'] ?? null)) {
            $pain = (int) $scratch['pain_score'];
        } elseif (class_exists('ClinicalFeatureExtractors')) {
            $standalone = ClinicalFeatureExtractors::extractStandalonePainScore($text, $awaitingSlot === 'pain_score');
            if ($standalone !== null) {
                $pain = $standalone;
            }
        }
        $status = is_array($scratch['finding_status'] ?? null) ? $scratch['finding_status'] : [];

        return [
            'onset' => trim((string) ($scratch['onset'] ?? '')),
            'duration' => trim((string) ($scratch['duration'] ?? '')),
            'laterality' => self::lateralityScalarFromUtterance($text, $awaitingSlot, trim((string) ($scratch['laterality'] ?? ''))),
            'pain_score' => $pain,
            'trauma' => trim((string) ($status['trauma'] ?? '')),
            'frequency' => trim((string) ($scratch['frequency'] ?? '')),
        ];
    }

    private static function utteranceAppliesToEveryComplaint(string $text, string $awaitingSlot = ''): bool
    {
        if ($awaitingSlot === 'laterality') {
            // Here the marker answers "which side", it does not share a fact.
            return false;
        }
        foreach (self::shareMarkers($text) as $marker) {
            if (!$marker['place']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $complaint
     */
    private static function complaintMentionedIn(array $complaint, string $text): bool
    {
        $terms = [
            (string) ($complaint['label'] ?? ''),
            (string) ($complaint['local_location'] ?? ''),
            (string) ($complaint['location'] ?? ''),
            (string) ($complaint['local_symptom'] ?? ''),
        ];
        $symptom = trim((string) ($complaint['symptom'] ?? ''));
        if ($symptom !== '' && !self::isGenericPainLabel($symptom)) {
            $terms[] = $symptom;
        }
        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '' || self::isGenericPainLabel($term)) {
                continue;
            }
            if (self::appearsInPatientText($term, $text) || (mb_strlen($term) >= 4 && mb_stripos($text, $term) !== false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $complaints
     * @param list<string> $targets
     * @param array{onset:string,duration:string,laterality:string,pain_score:?int,trauma:string,frequency:string} $scalars
     * @return list<array<string, mixed>>
     */
    /**
     * Facts in one clause stay on the complaint named in that clause.
     * A clause that names nobody answers the complaint the question already asked.
     *
     * @param list<array<string, mixed>> $complaints
     * @param list<string> $targets
     * @return list<array<string, mixed>>
     */
    private static function applyScalarsByClause(
        array $complaints,
        array $targets,
        string $utterance,
        string $awaitingId,
        string $awaitingSlot
    ): array {
        $targetSet = array_fill_keys(array_filter($targets), true);
        $clauses = self::textClauses($utterance);
        if ($clauses === []) {
            return $complaints;
        }
        $namedAnchors = [];
        foreach ($complaints as $complaint) {
            $id = (string) ($complaint['id'] ?? '');
            if ($id === '' || !isset($targetSet[$id]) || $id === $awaitingId) {
                continue;
            }
            $anchor = self::complaintAnchor($complaint, $utterance);
            if ($anchor !== null) {
                $namedAnchors[$id] = $anchor;
            }
        }
        foreach ($clauses as $index => $clause) {
            $start = (int) $clause['start'];
            $end = (int) ($clauses[$index + 1]['start'] ?? (mb_strlen($utterance) + 1));
            $clauseText = (string) $clause['text'];
            $inClause = [];
            foreach ($complaints as $complaint) {
                $id = (string) ($complaint['id'] ?? '');
                if ($id === '' || !isset($targetSet[$id])) {
                    continue;
                }
                $anchor = self::complaintAnchor($complaint, $utterance);
                if ($anchor !== null && $anchor >= $start && $anchor < $end) {
                    $inClause[] = $id;
                }
            }
            $scalars = self::scalarsFromUtterance($clauseText, $inClause === [] ? $awaitingSlot : '');
            if ($inClause !== []) {
                $complaints = self::applyScalarsToComplaints(
                    $complaints,
                    $inClause,
                    $scalars,
                    $clauseText,
                    false,
                    '',
                    ''
                );
                continue;
            }
            $beforeNamed = $namedAnchors === [] || $start < min($namedAnchors);
            if ($awaitingId !== '' && $beforeNamed) {
                $complaints = self::applyScalarsToComplaints(
                    $complaints,
                    [$awaitingId],
                    $scalars,
                    $clauseText,
                    false,
                    '',
                    ''
                );
                continue;
            }
            $previous = null;
            $previousAt = null;
            foreach ($namedAnchors as $id => $anchor) {
                if ($anchor < $end && ($previousAt === null || $anchor > $previousAt)) {
                    $previous = (string) $id;
                    $previousAt = $anchor;
                }
            }
            if ($previous !== null) {
                $complaints = self::applyScalarsToComplaints(
                    $complaints,
                    [$previous],
                    $scalars,
                    $clauseText,
                    false,
                    '',
                    ''
                );
            }
        }

        return $complaints;
    }

    private static function applyScalarsToComplaints(
        array $complaints,
        array $targets,
        array $scalars,
        string $text,
        bool $shareAcrossTargets,
        string $awaitingId,
        string $awaitingSlot
    ): array {
        $targetSet = array_fill_keys(array_filter($targets), true);
        foreach (['onset', 'duration', 'laterality', 'pain_score', 'trauma', 'frequency'] as $key) {
            $value = $scalars[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $pos = self::scalarPos($text, $key, is_scalar($value) ? (string) $value : '');
            $receivers = array_keys($targetSet);
            if ($shareAcrossTargets) {
                // The patient said this fact applies to every complaint they named.
            } elseif ($awaitingId !== '' && $pos !== null) {
                $other = null;
                foreach ($complaints as $complaint) {
                    $id = (string) ($complaint['id'] ?? '');
                    if ($id === '' || $id === $awaitingId || !isset($targetSet[$id])) {
                        continue;
                    }
                    $anchor = self::complaintAnchor($complaint, $text);
                    if ($anchor !== null && ($other === null || $anchor < $other)) {
                        $other = $anchor;
                    }
                }
                if ($other !== null && $pos < $other) {
                    $receivers = [$awaitingId];
                }
            } elseif (count($receivers) > 1) {
                $anchors = [];
                foreach ($complaints as $complaint) {
                    $id = (string) ($complaint['id'] ?? '');
                    if (!isset($targetSet[$id])) {
                        continue;
                    }
                    $anchor = self::complaintAnchor($complaint, $text);
                    if ($anchor !== null) {
                        $anchors[$id] = $anchor;
                    }
                }
                if ($pos === null || $anchors === []) {
                    // A fact with no place in the sentence is not copied onto every complaint.
                    $receivers = [];
                } else {
                    $nearest = null;
                    $best = PHP_INT_MAX;
                    foreach ($anchors as $id => $anchor) {
                        $distance = abs($pos - $anchor);
                        if ($distance < $best) {
                            $best = $distance;
                            $nearest = (string) $id;
                        }
                    }
                    $receivers = $nearest !== null ? [$nearest] : [];
                }
            }
            foreach ($complaints as $i => $complaint) {
                if (!in_array((string) ($complaint['id'] ?? ''), $receivers, true)) {
                    continue;
                }
                $complaints[$i] = self::fillComplaintField($complaint, $key, $value);
            }
        }

        return $complaints;
    }

    /**
     * @param array<string, mixed> $complaint
     * @return array<string, mixed>
     */
    private static function fillComplaintField(array $complaint, string $key, mixed $value): array
    {
        if ($key === 'pain_score') {
            if (!is_numeric($complaint['pain_score'] ?? null) && is_numeric($value)) {
                $score = (int) $value;
                if ($score >= 1 && $score <= 10) {
                    $complaint['pain_score'] = $score;
                    $complaint = self::markComplaintSlot($complaint, 'pain_score', 'positive');
                }
            }

            return $complaint;
        }
        if ($key === 'trauma') {
            $status = is_array($complaint['finding_status'] ?? null) ? $complaint['finding_status'] : [];
            $current = (string) ($status['trauma'] ?? '');
            if ($current === '' || $current === 'not_assessed') {
                $complaint = self::markComplaintSlot($complaint, 'trauma', (string) $value);
            }

            return $complaint;
        }
        if (trim((string) ($complaint[$key] ?? '')) === '') {
            $complaint[$key] = $value;
        }

        return $complaint;
    }

    private static function scalarPos(string $text, string $key, string $value): ?int
    {
        if ($key === 'laterality') {
            return self::textPos($text, $value);
        }
        if ($key === 'pain_score') {
            $pos = mb_stripos($text, $value);

            return $pos === false ? null : (int) $pos;
        }
        if ($key === 'trauma') {
            if (preg_match('/\b(nahulog|nabunggo|nabungguan|naaksidente|naigo|pilas|samad|nabalian|accident|injury|injured|fell|fall)\b/ui', $text, $m, PREG_OFFSET_CAPTURE)) {
                return mb_strlen(substr($text, 0, (int) $m[0][1]));
            }

            return null;
        }

        return self::textPos($text, $value);
    }

    /**
     * @param array<string, mixed> $complaint
     */
    private static function complaintAnchor(array $complaint, string $text): ?int
    {
        $terms = [
            (string) ($complaint['local_location'] ?? ''),
            (string) ($complaint['label'] ?? ''),
            (string) ($complaint['location'] ?? ''),
        ];
        $symptom = trim((string) ($complaint['symptom'] ?? ''));
        if ($symptom !== '' && !self::isGenericPainLabel($symptom)) {
            $terms[] = $symptom;
        }
        $best = null;
        foreach ($terms as $term) {
            $pos = self::textPos($text, $term);
            if ($pos !== null && ($best === null || $pos < $best)) {
                $best = $pos;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function projectComplaintLedger(array $facts): array
    {
        $complaints = self::complaintRecords($facts);
        if ($complaints === []) {
            $facts['complaints'] = [];

            return $facts;
        }
        $primary = $complaints[0];
        foreach (['symptom', 'location', 'laterality', 'onset', 'duration', 'frequency'] as $key) {
            $value = trim((string) ($primary[$key] ?? ''));
            $facts[$key] = $value !== '' ? $value : null;
        }
        $facts['pain_score'] = is_numeric($primary['pain_score'] ?? null) ? (int) $primary['pain_score'] : null;
        $parentStatus = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        $primaryStatus = is_array($primary['finding_status'] ?? null) ? $primary['finding_status'] : [];
        foreach ($primaryStatus as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;
            if ($key === '' || !in_array($value, ['positive', 'negative', 'uncertain'], true)) {
                continue;
            }
            $existing = (string) ($parentStatus[$key] ?? '');
            if (!in_array($existing, ['positive', 'negative', 'uncertain'], true)) {
                $parentStatus[$key] = $value;
            }
        }
        $facts['finding_status'] = $parentStatus;
        $owned = [];
        foreach ($complaints as $index => $complaint) {
            if ($index === 0) {
                continue;
            }
            $symptom = trim((string) ($complaint['symptom'] ?? ''));
            if ($symptom !== '') {
                $owned[] = $symptom;
            }
        }
        if ($owned !== []) {
            $assoc = [];
            foreach (self::stringList($facts['associated_symptoms'] ?? []) as $item) {
                if (!self::listHasLabel($owned, $item)) {
                    $assoc[] = $item;
                }
            }
            $facts['associated_symptoms'] = $assoc;
        }
        $notes = [];
        foreach (self::stringList($facts['notes'] ?? []) as $note) {
            if (preg_match('/^also (?:symptom|location|onset|duration|laterality|frequency):/ui', $note)) {
                continue;
            }
            $notes[] = $note;
        }
        $facts['notes'] = $notes;
        $facts['complaints'] = $complaints;

        return $facts;
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private static function interviewFactsForEngine(array $facts, array $mapped, string $patientEvidence, string $activeId): array
    {
        $complaints = self::complaintRecords($facts);
        if (count($complaints) < 2) {
            return [
                'active_complaint_id' => 'gemini_demo_c1',
                'active_facts' => $mapped,
                'tracks' => [],
                'patient_evidence_text' => $patientEvidence,
            ];
        }
        $tracks = [];
        foreach ($complaints as $complaint) {
            $tracks[] = [
                'complaint_id' => (string) ($complaint['id'] ?? ''),
                'text_span' => (string) ($complaint['text_span'] ?? ''),
                'family_keys' => [],
                'facts' => self::mapFactsForEngine(self::complaintAsFacts($complaint)),
            ];
        }
        $active = self::complaintById($complaints, $activeId) ?? $complaints[0];

        return [
            'active_complaint_id' => (string) ($active['id'] ?? ''),
            'active_facts' => self::mapFactsForEngine(self::complaintAsFacts($active)),
            'tracks' => $tracks,
            'patient_evidence_text' => $patientEvidence,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function slotAddressed(string $slot, array $facts): bool
    {
        if ($slot === 'pain_score') {
            $score = $facts['pain_score'] ?? null;
            if ($score !== null && $score !== '' && is_numeric($score)) {
                $n = (int) $score;
                if ($n >= 1 && $n <= 10) {
                    return true;
                }
            }
        } elseif (in_array($slot, ['symptom', 'location', 'laterality', 'onset', 'duration', 'frequency'], true)) {
            if (trim((string) ($facts[$slot] ?? '')) !== '') {
                return true;
            }
        }

        $status = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        foreach ([$slot, $slot === 'associated_symptoms' ? 'has_other_symptoms' : $slot] as $key) {
            $value = (string) ($status[$key] ?? '');
            if (in_array($value, ['positive', 'negative', 'uncertain'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     */
    private static function locationIsUseful(array $facts, array $context, string $scope = ''): bool
    {
        if (self::needsPainScore($facts, $context, $scope)) {
            return true;
        }
        $hay = $scope !== '' ? mb_strtolower($scope) : mb_strtolower(self::patientEvidenceCorpus($context));

        return (bool) preg_match(
            '/\b(tiil|siki|kamot|ulo|dughan|tiyan|mata|likod|binti|paa|dibdib|chest|head|stomach|arm|leg|foot)\b/u',
            $hay
        );
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function lateralityIsUseful(array $facts): bool
    {
        $hay = mb_strtolower(trim(
            (string) ($facts['location'] ?? '') . ' '
            . (string) ($facts['symptom'] ?? '') . ' '
            . implode(' ', self::stringList($facts['associated_symptoms'] ?? []))
        ));

        return (bool) preg_match(
            '/\b(eye|ear|arm|hand|leg|foot|knee|ankle|shoulder|hip|breast|chest|'
            . 'mata|dulunggan|tenga|kamot|kamay|tiil|siki|batiis|paa|binti|tuhod|abaga|balikat|dughan|dibdib)\b/u',
            $hay
        );
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     */
    private static function traumaIsUseful(array $facts, array $context, string $scope = ''): bool
    {
        return self::needsPainScore($facts, $context, $scope) || self::slotAddressed('location', $facts);
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function onsetCoversDuration(array $facts): bool
    {
        $onset = trim((string) ($facts['onset'] ?? ''));
        if ($onset === '' || !class_exists('ClinicalFeatureExtractors')) {
            return false;
        }
        $duration = ClinicalFeatureExtractors::extractDuration($onset);

        return trim((string) ($duration['raw'] ?? '')) !== '' || trim((string) ($duration['label'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function associatedNeedsDetail(array $facts): bool
    {
        $status = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        $flag = (string) (($status['associated_symptoms'] ?? '') ?: ($status['has_other_symptoms'] ?? ''));

        return $flag === 'positive' && self::stringList($facts['associated_symptoms'] ?? []) === [];
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     */
    private static function associatedAddressed(array $facts, array $context): bool
    {
        if (self::associatedNeedsDetail($facts)) {
            return false;
        }
        if (self::slotAddressed('associated_symptoms', $facts) || self::slotAddressed('has_other_symptoms', $facts)) {
            return true;
        }
        $hay = self::patientEvidenceCorpus($context);

        return class_exists('ClinicalFeatureExtractors')
            && ClinicalFeatureExtractors::deniedAssociatedSymptoms($hay);
    }

    /**
     * Which stored fact a follow-up is asking for. Empty when it is not a fact question.
     */
    private static function questionFactSlot(string $question): string
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return '';
        }
        if (preg_match('/\b(ano pa|what else)\b/u', $q)) {
            return 'associated_detail';
        }
        if (preg_match('/\b(1\s*(to|tubtob|hanggang|-|–)\s*10|pain\s*score|gaano\s*kasakit|pila\s*ka\s*grabe|how\s+(bad|severe))\b/u', $q)) {
            return 'pain_score';
        }
        if (preg_match('/\b(left|wala|kaliwa)\b.{0,40}\b(right|tuo|kanan)\b/u', $q)
            || preg_match('/\b(which\s+side|laterality|nga\s+kilid)\b/u', $q)
        ) {
            return 'laterality';
        }
        if (preg_match('/\b(fall|fell|injury|injured|accident|trauma|nahulog|nabunggo|nabungguan|naaksidente|pilas|samad)\b/u', $q)) {
            return 'trauma';
        }
        if (preg_match('/\b(anything else|other symptom|iban|iba pa|iba pang|bukod|maliban|besides|associated)\b/u', $q)) {
            return 'associated_symptoms';
        }
        if (preg_match('/\b(how\s+often|frequency|pirmi|palagi|paminsan)\b/u', $q)) {
            return 'frequency';
        }
        if (preg_match('/\b(how\s+long|gaano\s+(na\s+)?katagal|pila\s+na\s+ka|duration|tagal|dugay)\b/u', $q)
            && !preg_match('/\b(nagsugod|nagsimula|start)\b/u', $q)
        ) {
            return 'duration';
        }
        if (preg_match('/\b(when|san-o|kailan|nagsugod|nagsimula|start|onset|began)\b/u', $q)) {
            return 'onset';
        }
        if (preg_match('/\b(where|diin|saan|which\s+part|ano\s+nga\s+parte|location|asa)\b/u', $q)) {
            return 'location';
        }
        if (preg_match('/\b(what are you feeling|what do you feel|ano ang imo nabatyagan|ano po ang nararamdaman|nararamdaman)\b/u', $q)) {
            return 'symptom';
        }

        return '';
    }

    /**
     * One complete question for the missing fact, in the patient's language,
     * naming the complaint they already used when we have their words.
     *
     * @param array<string, mixed> $context
     */
    private static function simpleQuestionForSlot(string $slot, array $context): string
    {
        $lang = self::detectLanguageHint($context);
        $facts = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        $records = self::complaintRecords($facts);
        $namesPain = self::needsPainScore($facts, $context);
        if (count($records) > 1 && in_array($slot, ['associated_symptoms', 'associated_detail'], true)) {
            return self::composePatientQuestion($lang, $slot, '', 'complaint', $namesPain);
        }
        $activeId = trim((string) ($context['active_complaint_id'] ?? ''));
        $complaint = $activeId !== '' ? self::complaintById($records, $activeId) : null;
        if ($complaint !== null) {
            $context = self::contextScopedToComplaint($context, $complaint);
        }
        $focus = self::followUpSubject($context, $slot, $lang);
        if ($focus['phrase'] === '' && $complaint !== null) {
            $label = trim((string) ($complaint['label'] ?? ''));
            if ($label !== '') {
                $focus = [
                    'phrase' => $label,
                    'kind' => trim((string) ($complaint['location'] ?? '')) !== '' ? 'place' : 'complaint',
                ];
            }
        }

        return self::composePatientQuestion($lang, $slot, $focus['phrase'], $focus['kind'], $namesPain);
    }

    /**
     * One reference for this question, taken from structured facts.
     * The same complaint is used for every missing fact so the target does not switch.
     * The raw complaint sentence is never inserted.
     *
     * @param array<string, mixed> $context
     * @return array{phrase:string, kind:string}
     */
    private static function followUpSubject(array $context, string $slot, string $lang): array
    {
        $empty = ['phrase' => '', 'kind' => 'complaint'];
        $facts = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        $text = self::patientEvidenceCorpus($context);
        $place = self::phraseForStoredLocation(self::activeFactLocation($facts, $text), $text, $lang, $facts);
        $symptom = self::phraseForStoredSymptom($facts, $text, $lang);

        if ($slot === 'location') {
            return $symptom !== '' ? ['phrase' => $symptom, 'kind' => 'complaint'] : $empty;
        }
        if ($place !== '') {
            if ($slot !== 'laterality') {
                $place = self::placeWithKnownSide($place, (string) ($facts['laterality'] ?? ''), $lang);
            }

            return ['phrase' => $place, 'kind' => 'place'];
        }
        if ($symptom !== '') {
            return ['phrase' => $symptom, 'kind' => 'complaint'];
        }

        return $empty;
    }

    /**
     * Body part this interview is asking about. Several sites stay on the one
     * that belongs to the primary symptom; otherwise the stored location is used.
     *
     * @param array<string, mixed> $facts
     */
    private static function activeFactLocation(array $facts, string $text): string
    {
        $locs = [];
        $primary = trim((string) ($facts['location'] ?? ''));
        if ($primary !== '') {
            $locs[] = $primary;
        }
        foreach (self::stringList($facts['notes'] ?? []) as $note) {
            if (preg_match('/^also location:\s*(.+)$/ui', $note, $m)) {
                $extra = trim((string) $m[1]);
                if ($extra !== '' && !in_array($extra, $locs, true)) {
                    $locs[] = $extra;
                }
            }
        }
        $symptom = trim((string) ($facts['symptom'] ?? ''));
        if (count($locs) > 1 && $symptom !== '' && !self::isGenericPainLabel($symptom)) {
            $places = self::patientSpokenPlaces($text);
            foreach ($locs as $loc) {
                if (self::factTextsAlign($loc, $symptom)) {
                    return $loc;
                }
                foreach ($places as $row) {
                    if (self::locationRowMatches($row, $loc) && self::factTextsAlign((string) $row['english'], $symptom)) {
                        return $loc;
                    }
                }
            }
        }

        return $locs[0] ?? '';
    }

    /**
     * @param array{phrase:string, english:string} $row
     */
    private static function locationRowMatches(array $row, string $stored): bool
    {
        $stored = mb_strtolower(trim($stored));

        return $stored !== '' && (
            $stored === $row['english']
            || $stored === mb_strtolower($row['phrase'])
            || self::sameClinicalLabel($stored, (string) $row['english'])
            || self::sameClinicalLabel($stored, (string) $row['phrase'])
        );
    }

    private static function factTextsAlign(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '' || self::isGenericPainLabel($a) || self::isGenericPainLabel($b)) {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        $na = preg_replace('/[^a-z]/', '', mb_strtolower(self::spokenFocus($a))) ?? '';
        $nb = preg_replace('/[^a-z]/', '', mb_strtolower(self::spokenFocus($b))) ?? '';
        if (strlen($na) >= 4 && strlen($nb) >= 4) {
            return str_starts_with($na, substr($nb, 0, 4)) || str_starts_with($nb, substr($na, 0, 4));
        }

        return false;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function phraseForStoredLocation(string $stored, string $text, string $lang, array $facts): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }
        $symptom = mb_strtolower(trim((string) ($facts['symptom'] ?? '')));
        foreach (self::patientSpokenPlaces($text) as $row) {
            if (!self::locationRowMatches($row, $stored)) {
                continue;
            }
            $local = self::usableReference((string) $row['phrase'], $lang, $text);
            if ($local === '' || ($symptom !== '' && self::sameClinicalLabel($local, $symptom))) {
                continue;
            }

            return $local;
        }
        $token = self::usableReference($stored, $lang, $text);
        if ($token !== '') {
            return $token;
        }
        if ($lang === 'english') {
            $english = self::asNounPhrase($stored);

            return self::referenceLooksLikeSentence($english) ? '' : $english;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function phraseForStoredSymptom(array $facts, string $text, string $lang): string
    {
        $stored = trim((string) ($facts['symptom'] ?? ''));
        if ($stored === '' || self::isGenericPainLabel($stored)) {
            return '';
        }
        foreach (self::patientSpokenComplaints($text) as $row) {
            if (!self::factTextsAlign((string) $row['english'], $stored) && !self::sameClinicalLabel((string) $row['phrase'], $stored)) {
                continue;
            }
            $local = self::usableReference((string) $row['phrase'], $lang, $text);
            if ($local !== '') {
                return $local;
            }
        }
        if ($lang !== 'english') {
            return '';
        }
        $english = self::asNounPhrase(mb_strtolower($stored));

        return self::referenceLooksLikeSentence($english) ? '' : $english;
    }

    private static function usableReference(string $phrase, string $lang, string $text): string
    {
        $phrase = self::asNounPhrase($phrase);
        if ($phrase === '' || self::referenceLooksLikeSentence($phrase) || !self::subjectFitsQuestion($phrase, $lang, $text)) {
            return '';
        }

        return $phrase;
    }

    private static function referenceLooksLikeSentence(string $phrase): bool
    {
        $phrase = mb_strtolower(trim($phrase));
        if ($phrase === '' || mb_strlen($phrase) > 32) {
            return true;
        }
        $words = preg_split('/\s+/u', $phrase) ?: [];
        if (count($words) > 3) {
            return true;
        }

        return (bool) preg_match(
            '/\b(gasakit|masakit|akon|ako|gid|hurts|hurt|i|have|my|the|ang|nga|na)\b/u',
            $phrase
        );
    }

    private static function placeWithKnownSide(string $place, string $laterality, string $lang): string
    {
        $side = mb_strtolower(trim((string) preg_replace('/[.!?…]+$/u', '', trim($laterality))));
        if (!self::isLateralityToken($side)) {
            return $place;
        }
        if ($lang === 'english' && in_array($side, ['left', 'right'], true)) {
            return $side . ' ' . $place;
        }
        if ($lang === 'hiligaynon' && in_array($side, ['wala', 'left'], true)) {
            return 'wala nga ' . $place;
        }
        if ($lang === 'hiligaynon' && in_array($side, ['tuo', 'right'], true)) {
            return 'tuo nga ' . $place;
        }
        if ($lang === 'tagalog' && in_array($side, ['kaliwa', 'left'], true)) {
            return 'kaliwang ' . $place;
        }
        if ($lang === 'tagalog' && in_array($side, ['kanan', 'right'], true)) {
            return 'kanang ' . $place;
        }

        return $place;
    }

    /**
     * A short noun the question can name. Full clauses are not used as the subject.
     */
    private static function asNounPhrase(string $phrase): string
    {
        $phrase = self::spokenFocus($phrase);
        $phrase = trim((string) preg_replace('/\s+(ko|mo|ka|akon|ako|ninyo|niyo|po)$/ui', '', $phrase));
        if ($phrase === '' || self::isLateralityToken($phrase) || self::isNonClinicalDiscourseLabel($phrase)) {
            return '';
        }
        if (preg_match('/\b(ang|ng|na|nga|sang|the|my|your|yung|ung)\b/ui', $phrase)) {
            return '';
        }
        $words = preg_split('/\s+/u', $phrase) ?: [];
        if (count($words) > 4) {
            return '';
        }

        return $phrase;
    }

    /**
     * @return list<array{phrase:string, english:string}>
     */
    private static function patientSpokenPlaces(string $text): array
    {
        $nlp = self::collectLocalNlpEvidence($text);
        $out = [];
        foreach (is_array($nlp['location_mappings'] ?? null) ? $nlp['location_mappings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $phrase = self::spokenFocus((string) (($row['surface'] ?? '') !== '' ? $row['surface'] : ($row['local'] ?? '')));
            $english = mb_strtolower(trim((string) ($row['english'] ?? '')));
            if ($phrase === '' || self::isLateralityToken($phrase) || !self::appearsInPatientText($phrase, $text)) {
                continue;
            }
            $key = mb_strtolower($phrase);
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = ['phrase' => $phrase, 'english' => $english];
        }

        return array_values($out);
    }

    /**
     * @return list<array{phrase:string, english:string}>
     */
    private static function patientSpokenComplaints(string $text): array
    {
        $nlp = self::collectLocalNlpEvidence($text);
        $out = [];
        foreach (is_array($nlp['symptom_mappings'] ?? null) ? $nlp['symptom_mappings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $english = trim((string) ($row['english'] ?? ''));
            if ($english === '' || self::isGenericPainLabel($english)) {
                continue;
            }
            $phrase = self::spokenFocus((string) ($row['local'] ?? ''));
            if ($phrase === '' || self::isLateralityToken($phrase) || self::referenceLooksLikeSentence($phrase) || !self::appearsInPatientText($phrase, $text)) {
                continue;
            }
            $key = mb_strtolower($phrase);
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = ['phrase' => $phrase, 'english' => mb_strtolower($english)];
        }

        return array_values($out);
    }

    private static function spokenFocus(string $phrase): string
    {
        $phrase = trim($phrase);
        $phrase = trim((string) preg_replace('/[.!?,;:]+$/u', '', $phrase));
        $phrase = trim((string) preg_replace(
            '/^(akon|ako|ko|imo|mo|ang|sang|sa|nga|gid|the|my|your|yung|ung)\s+/ui',
            '',
            $phrase
        ));

        return trim($phrase);
    }

    private static function appearsInPatientText(string $term, string $text): bool
    {
        $term = mb_strtolower(trim($term));
        $text = mb_strtolower($text);
        if ($term === '' || $text === '' || mb_strlen($term) < 3) {
            return false;
        }

        return preg_match('/(?<!\p{L})' . preg_quote($term, '/') . '(?!\p{L})/u', $text) === 1;
    }

    private static function subjectFitsQuestion(string $subject, string $lang, string $patientText): bool
    {
        if (!self::appearsInPatientText($subject, $patientText)) {
            return false;
        }
        if ($lang === 'english') {
            return self::resolveQuestionLanguage($subject) === 'english';
        }
        $subjectLang = self::resolveQuestionLanguage($subject);
        if ($subjectLang === 'english' && self::englishLexiconOnly($subject)) {
            return false;
        }

        return true;
    }

    /**
     * Build one grammatical question. Pain wording is used only when the patient
     * already stated pain. A complaint subject stays the patient's own short phrase.
     */
    private static function composePatientQuestion(string $lang, string $slot, string $subject, string $kind, bool $namesPain = true): string
    {
        $subject = trim($subject);
        $hasSubject = $subject !== '';
        $place = $hasSubject && $kind === 'place';
        $painPlace = $place && $namesPain;

        if ($lang === 'hiligaynon') {
            if (!$hasSubject) {
                return match ($slot) {
                    'location' => 'Diin mo ini nabatyagan?',
                    'laterality' => $namesPain
                        ? 'Diin nga kilid ang masakit, sa wala ukon sa tuo?'
                        : 'Diin nga kilid, sa wala ukon sa tuo?',
                    'onset' => 'San-o ini nagsugod?',
                    'duration' => 'Pila na ka adlaw ukon oras ini nga ara?',
                    'pain_score' => 'Pila ka grabe ang kasakit, halin 1 tubtob 10?',
                    'trauma' => 'May nabungguan ka ukon nahulog ka antes ini nagsugod?',
                    'associated_symptoms' => 'May ara pa iban nga nabatyagan mo?',
                    'associated_detail' => 'Ano pa ang imo nabatyagan?',
                    'frequency' => 'Pirme ini, ukon kung kaisa lang?',
                    default => 'Ano ang imo nabatyagan?',
                };
            }
            $about = $painPlace ? "sakit sa imo {$subject}" : ($place ? "imo {$subject}" : "{$subject} mo");

            return match ($slot) {
                'location' => "Diin mo nabatyagan ang {$subject}?",
                'laterality' => $namesPain
                    ? "Sa imo {$subject}, wala ukon tuo ang masakit?"
                    : "Sa imo {$subject}, wala ukon tuo?",
                'onset' => "San-o nagsugod ang {$about}?",
                'duration' => "Pila na ka adlaw ukon oras ang {$about}?",
                'pain_score' => $place
                    ? "Pila ka grabe ang kasakit sa imo {$subject}, halin 1 tubtob 10?"
                    : "Pila ka grabe ang {$subject} mo, halin 1 tubtob 10?",
                'trauma' => "May nabungguan ka ukon nahulog ka bago nagsugod ang {$about}?",
                'associated_symptoms' => "Bukod sa {$about}, may ara pa iban nga nabatyagan mo?",
                'associated_detail' => "Ano pa ang imo nabatyagan bukod sa {$about}?",
                'frequency' => "Pirme bala ang {$about}, ukon kung kaisa lang?",
                default => "Ano ang imo nabatyagan sa {$subject}?",
            };
        }

        if ($lang === 'tagalog') {
            if (!$hasSubject) {
                return match ($slot) {
                    'location' => 'Saan mo ito nararamdaman?',
                    'laterality' => $namesPain
                        ? 'Alin ang masakit, kaliwa o kanan?'
                        : 'Alin ang kilid, kaliwa o kanan?',
                    'onset' => 'Kailan ito nagsimula?',
                    'duration' => 'Gaano na katagal ito?',
                    'pain_score' => 'Gaano kasakit ito, mula 1 hanggang 10?',
                    'trauma' => 'May nabunggo ka ba o nahulog bago ito nagsimula?',
                    'associated_symptoms' => 'May iba pa bang nararamdaman mo?',
                    'associated_detail' => 'Ano pa ang nararamdaman mo?',
                    'frequency' => 'Palagi ba ito, o paminsan-minsan lang?',
                    default => 'Ano ang nararamdaman mo?',
                };
            }
            $about = $painPlace ? "sakit sa {$subject} mo" : "{$subject} mo";

            return match ($slot) {
                'location' => "Saan mo nararamdaman ang {$subject}?",
                'laterality' => $namesPain
                    ? "Sa {$subject} mo, kaliwa o kanan ang masakit?"
                    : "Sa {$subject} mo, kaliwa o kanan?",
                'onset' => "Kailan nagsimula ang {$about}?",
                'duration' => "Gaano na katagal ang {$about}?",
                'pain_score' => $place
                    ? "Gaano kasakit ang sakit sa {$subject} mo, mula 1 hanggang 10?"
                    : "Gaano kasakit ang {$subject} mo, mula 1 hanggang 10?",
                'trauma' => "May nabunggo ka ba o nahulog bago nagsimula ang {$about}?",
                'associated_symptoms' => "Maliban sa {$about}, may iba ka pa bang nararamdaman?",
                'associated_detail' => "Ano pa ang nararamdaman mo maliban sa {$about}?",
                'frequency' => "Palagi ba ang {$about}, o paminsan-minsan lang?",
                default => "Ano ang nararamdaman mo sa {$subject}?",
            };
        }

        if (!$hasSubject) {
            return match ($slot) {
                'location' => 'Where do you feel it?',
                'laterality' => 'Is it on the left side or the right side?',
                'onset' => 'When did it start?',
                'duration' => 'How long has this been going on?',
                'pain_score' => 'How bad is the pain, from 1 to 10?',
                'trauma' => 'Did you get hit or fall before this started?',
                'associated_symptoms' => 'Do you feel anything else?',
                'associated_detail' => 'What else do you feel?',
                'frequency' => 'Does this happen all the time, or only sometimes?',
                default => 'What are you feeling?',
            };
        }
        $about = $painPlace ? "the pain in your {$subject}" : "your {$subject}";

        return match ($slot) {
            'location' => "Where do you feel your {$subject}?",
            'laterality' => $namesPain
                ? "Is the pain in your {$subject} on the left side or the right side?"
                : "Is your {$subject} on the left side or the right side?",
            'onset' => "When did {$about} start?",
            'duration' => $painPlace
                ? "How long have you had the pain in your {$subject}?"
                : "How long have you had your {$subject}?",
            'pain_score' => $place
                ? "How bad is the pain in your {$subject}, from 1 to 10?"
                : "How bad is your {$subject}, from 1 to 10?",
            'trauma' => "Did you get hit or fall before {$about} started?",
            'associated_symptoms' => "Besides {$about}, do you feel anything else?",
            'associated_detail' => "What else do you feel besides {$about}?",
            'frequency' => $painPlace
                ? "Does the pain in your {$subject} happen all the time, or only sometimes?"
                : "Does your {$subject} happen all the time, or only sometimes?",
            default => "What are you feeling in your {$subject}?",
        };
    }

    /**
     * Store the patient's own answer on the fact the question asked. Do not invent a value.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function absorbDirectAnswer(array $facts, string $question, string $answer): array
    {
        $answer = trim($answer);
        if ($answer === '') {
            return $facts;
        }
        $facts = self::harvestStatedFacts($facts, $answer);
        $slot = self::questionFactSlot($question);
        if ($slot === '' || $slot === 'associated_detail') {
            $slot = $slot === 'associated_detail' ? 'associated_symptoms' : $slot;
        }
        if ($slot === '') {
            return $facts;
        }

        if ($slot === 'laterality') {
            if (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksPatientUncertain($answer)) {
                return self::markSlot($facts, 'laterality', 'uncertain');
            }
            $side = self::canonicalLaterality($answer, true);
            if ($side !== '') {
                if (trim((string) ($facts['laterality'] ?? '')) === '') {
                    $facts['laterality'] = $side;
                }

                return self::markSlot($facts, 'laterality', 'positive');
            }
        }

        if ($slot === 'pain_score') {
            $score = null;
            if (class_exists('ClinicalFeatureExtractors')) {
                $score = ClinicalFeatureExtractors::extractStandalonePainScore($answer, true);
                if ($score === null) {
                    $parsed = ClinicalFeatureExtractors::extractPainScale($answer);
                    $score = $parsed['score'] ?? null;
                }
            }
            if ($score !== null && (int) $score >= 1 && (int) $score <= 10) {
                $facts['pain_score'] = (int) $score;

                return self::markSlot($facts, 'pain_score', 'positive');
            }
        }

        $polarity = self::answerPolarityForSlot($slot, $answer);
        if ($polarity !== '') {
            return self::markSlot($facts, $slot, $polarity);
        }
        $reported = self::answerReportedSymptoms($answer);
        if ($slot === 'associated_symptoms' && $reported !== []) {
            $assoc = self::stringList($facts['associated_symptoms'] ?? []);
            foreach ($reported as $label) {
                if (!self::listHasLabel($assoc, $label)) {
                    $assoc[] = $label;
                }
            }
            $facts['associated_symptoms'] = $assoc;

            return self::markSlot($facts, 'associated_symptoms', 'positive');
        }

        // Wording that maps to no field stays patient evidence; it is never stored as a fact.
        return $facts;
    }

    /**
     * How a reply answers the asked slot: uncertain, negative, positive (yes/no slots only),
     * or '' when the wording states no polarity for it.
     */
    private static function answerPolarityForSlot(string $slot, string $answer): string
    {
        if (!class_exists('ClinicalFeatureExtractors') || trim($answer) === '') {
            return '';
        }
        if (ClinicalFeatureExtractors::looksPatientUncertain($answer)) {
            return 'uncertain';
        }
        // The active slot decides what "wala" means. On a left/right question it is a side.
        if (self::canonicalInterviewSlot($slot) === 'laterality' && self::canonicalLaterality($answer, true) !== '') {
            return '';
        }
        $yn = ClinicalFeatureExtractors::extractYesNo($answer);
        if ($yn === null) {
            return '';
        }
        $reported = self::answerReportedSymptoms($answer);
        $yesNoSlot = in_array($slot, ['associated_symptoms', 'trauma'], true);
        if ($yn === false) {
            // A denial that also reports a new symptom is not a plain "no".
            if ($reported !== []) {
                return '';
            }
            if ($yesNoSlot || $slot === 'pain_score' || self::isBarePolarityAnswer($answer) || self::isNonClinicalDiscourseLabel($answer)) {
                return 'negative';
            }

            return '';
        }
        if ($yesNoSlot && ($slot === 'trauma' || $reported === [])) {
            return 'positive';
        }

        return '';
    }

    /**
     * Specific symptoms this reply reports (not denied, not generic pain, not filler or slot words).
     *
     * @return list<string>
     */
    private static function answerReportedSymptoms(string $answer): array
    {
        $nlp = self::collectLocalNlpEvidence($answer);
        $negated = self::negatedSymptomLabels($answer, $nlp);
        $out = [];
        foreach (self::stringList($nlp['symptoms'] ?? []) as $symptom) {
            if (isset($negated[mb_strtolower(trim($symptom))])
                || self::isGenericPainLabel($symptom)
                || self::isNonClinicalDiscourseLabel($symptom)
                || self::isInterviewControlLabel($symptom)
            ) {
                continue;
            }
            if (!in_array($symptom, $out, true)) {
                $out[] = $symptom;
            }
        }

        return $out;
    }

    /**
     * Pull only facts the patient actually said. Never overwrite a stored fact.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function harvestStatedFacts(array $facts, string $text, array $turnStarts = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return $facts;
        }

        if (class_exists('ClinicalFeatureExtractors')) {
            $duration = ClinicalFeatureExtractors::extractDuration($text);
            $raw = trim((string) ($duration['raw'] ?? ''));
            if ($raw !== '') {
                if (trim((string) ($facts['onset'] ?? '')) === '') {
                    $facts['onset'] = $raw;
                }
                if (trim((string) ($facts['duration'] ?? '')) === '') {
                    $facts['duration'] = $raw;
                }
            }
            $character = ClinicalFeatureExtractors::extractOnset($text);
            if ($character !== '' && trim((string) ($facts['onset'] ?? '')) === '') {
                $facts['onset'] = $character;
            }
            $pain = ClinicalFeatureExtractors::extractPainScale($text);
            if (($facts['pain_score'] ?? null) === null && isset($pain['score']) && is_numeric($pain['score'])) {
                $score = (int) $pain['score'];
                if ($score >= 1 && $score <= 10) {
                    $facts['pain_score'] = $score;
                }
            }
        }

        $side = self::statedLaterality($text);
        if ($side !== '' && trim((string) ($facts['laterality'] ?? '')) === '') {
            $facts['laterality'] = self::canonicalLaterality($side, false) ?: $side;
        }

        $facts = self::harvestTraumaMention($facts, $text);
        $facts = self::harvestSymptomsFromText($facts, $text, $turnStarts);

        if (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::deniedAssociatedSymptoms($text)) {
            $facts = self::markSlot($facts, 'associated_symptoms', 'negative');
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function harvestSymptomsFromText(array $facts, string $text, array $turnStarts = []): array
    {
        $nlp = self::collectLocalNlpEvidence($text);
        $negated = self::negatedSymptomLabels($text, $nlp, $turnStarts);
        $symptoms = self::preferSpecificSymptoms(array_values(array_filter(
            self::stringList($nlp['symptoms'] ?? []),
            static fn (string $s): bool => !isset($negated[mb_strtolower(trim($s))]) && !self::isInterviewControlLabel($s)
        )));
        $symptoms = array_values(array_filter(
            $symptoms,
            static function (string $symptom) use ($text): bool {
                if (!self::isGenericPainLabel($symptom)) {
                    return true;
                }

                return (bool) preg_match(
                    '/\b(sakit|masakit|pain|hapdi|kasakit|gasakit|hurts?|sumasakit)\b/ui',
                    $text
                );
            }
        ));
        foreach ($symptoms as $symptom) {
            $facts = self::keepSymptom($facts, $symptom);
        }
        $locals = [];
        foreach (is_array($nlp['location_mappings'] ?? null) ? $nlp['location_mappings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $alias = trim((string) ($row['local'] ?? ''));
            $english = trim((string) ($row['english'] ?? ''));
            $label = $english !== '' ? $english : $alias;
            if ($alias !== '' && !str_contains($alias, ' ') && !self::listHasLabel($symptoms, $alias)) {
                $label = $alias;
            }
            if ($label !== '' && !self::listHasLabel($locals, $label)) {
                $locals[] = $label;
            }
        }
        if ($locals === []) {
            $locals = self::stringList($nlp['locations'] ?? []);
        }
        if ($locals === []) {
            return $facts;
        }
        if (trim((string) ($facts['location'] ?? '')) === '') {
            $facts['location'] = $locals[0];
            $locals = array_slice($locals, 1);
        }
        $notes = self::stringList($facts['notes'] ?? []);
        foreach ($locals as $extra) {
            if (self::sameClinicalLabel((string) ($facts['location'] ?? ''), $extra)) {
                continue;
            }
            $note = 'also location: ' . $extra;
            if (!in_array($note, $notes, true)) {
                $notes[] = $note;
            }
        }
        $facts['notes'] = $notes;

        return $facts;
    }

    /**
     * Drop generic pain labels when the patient named a specific complaint.
     * "Chronic Pain" is not stored unless the patient said it was chronic.
     *
     * @param list<string> $symptoms
     * @return list<string>
     */
    private static function preferSpecificSymptoms(array $symptoms): array
    {
        $specific = [];
        $generic = [];
        foreach ($symptoms as $symptom) {
            if (self::isGenericPainLabel($symptom)) {
                if (mb_strtolower(trim($symptom)) === 'pain' && !in_array('Pain', $generic, true)) {
                    $generic[] = 'Pain';
                }
                continue;
            }
            if (!in_array($symptom, $specific, true)) {
                $specific[] = $symptom;
            }
        }

        return $specific !== [] ? $specific : $generic;
    }

    private static function isGenericPainLabel(string $symptom): bool
    {
        return in_array(mb_strtolower(trim($symptom)), ['pain', 'chronic pain', 'sakit'], true);
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function keepSymptom(array $facts, string $symptom): array
    {
        $symptom = trim($symptom);
        if ($symptom === '' || self::isNonClinicalDiscourseLabel($symptom)) {
            return $facts;
        }
        $current = trim((string) ($facts['symptom'] ?? ''));
        if ($current === '' || (self::isGenericPainLabel($current) && !self::isGenericPainLabel($symptom))) {
            $facts['symptom'] = $symptom;
            if ($current !== '' && !self::sameClinicalLabel($current, $symptom)) {
                $assoc = self::stringList($facts['associated_symptoms'] ?? []);
                $assoc = array_values(array_filter(
                    $assoc,
                    static fn (string $item): bool => !self::isGenericPainLabel($item)
                ));
                $facts['associated_symptoms'] = $assoc;
            }

            return $facts;
        }
        if (self::sameClinicalLabel($current, $symptom) || self::isGenericPainLabel($symptom)) {
            return $facts;
        }
        $assoc = self::stringList($facts['associated_symptoms'] ?? []);
        if (!self::listHasLabel($assoc, $symptom)) {
            $assoc[] = $symptom;
            $facts['associated_symptoms'] = $assoc;
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function harvestTraumaMention(array $facts, string $text): array
    {
        if (self::slotAddressed('trauma', $facts)) {
            return $facts;
        }
        $low = mb_strtolower($text);
        $injury = 'nahulog|nabunggo|nabungguan|naaksidente|naigo|pilas|samad|nabalian|accident|injury|injured|fell|fall';
        $neg = 'no|wala|walang|indi|hindi|dili';
        if (preg_match('/\b(?:' . $neg . ')\b.{0,24}\b(?:' . $injury . ')\b/u', $low)
            || preg_match('/\b(?:' . $injury . ')\b.{0,16}\b(?:' . $neg . ')\b/u', $low)
        ) {
            return self::markSlot($facts, 'trauma', 'negative');
        }
        if (preg_match('/\b(' . $injury . ')\b/u', $low, $m)) {
            $notes = self::stringList($facts['notes'] ?? []);
            $note = 'trauma: ' . $m[0];
            if (!in_array($note, $notes, true)) {
                $notes[] = $note;
            }
            $facts['notes'] = $notes;

            return self::markSlot($facts, 'trauma', 'positive');
        }

        return $facts;
    }

    private static function statedLaterality(string $text): string
    {
        return self::canonicalLaterality($text, false);
    }

    /**
     * Read a side from the answer using the active slot.
     * "sa wala" / "left" / "kaliwa" are left even outside a laterality question.
     * Bare "wala" is left only when this question asked which side; on a yes/no
     * question it stays a negation.
     *
     * @return 'left'|'right'|'both'|''
     */
    private static function canonicalLaterality(string $text, bool $askedLaterality): string
    {
        $low = mb_strtolower(trim($text));
        $low = trim((string) preg_replace('/[.!?…]+$/u', '', $low));
        if ($low === '') {
            return '';
        }
        if (in_array($low, ['left', 'right', 'both'], true)) {
            return $low;
        }
        if (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksPatientUncertain($low)) {
            return '';
        }

        $denialWala = (bool) preg_match('/\b(wala\s+ko|wala\s+sang|wala\s+gid|walang)\b/u', $low);
        $hasRight = (bool) preg_match('/\b(?:sa\s+)?(?:tuo|right|kanan)\b/u', $low);
        $hasLeftPhrase = (bool) preg_match('/\b(?:sa\s+wala|left|kaliwa)\b/u', $low)
            || (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksLateralityWala($low));
        $bareWala = !$denialWala && (bool) preg_match('/\bwala\b/u', $low);
        $hasLeft = $hasLeftPhrase || ($askedLaterality && $bareWala && !$hasRight);

        if (preg_match('/\b(both\s+sides|both|pareho|parehas|duha\s+ka\s+bahin|bilateral)\b/u', $low)) {
            return 'both';
        }
        if ($hasLeft && $hasRight) {
            return 'both';
        }
        if ($hasRight) {
            return 'right';
        }
        if ($hasLeft) {
            return 'left';
        }

        return '';
    }

    private static function lateralityScalarFromUtterance(string $text, string $awaitingSlot, string $harvested): string
    {
        $harvested = trim($harvested);
        if ($harvested !== '') {
            return self::canonicalLaterality($harvested, $awaitingSlot === 'laterality') ?: $harvested;
        }
        if ($awaitingSlot !== 'laterality') {
            return '';
        }

        return self::canonicalLaterality($text, true);
    }

    /**
     * Store a side on the complaint the question asked about and mark that slot resolved.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $facts
     * @param list<array<string, mixed>> $complaints
     * @param list<string> $targets
     * @return array<string, mixed>
     */
    private static function storeLateralityAnswer(
        array $context,
        array $facts,
        array $complaints,
        array $targets,
        string $side,
        string $slot
    ): array {
        $facts['laterality'] = $side;
        $facts = self::markSlot($facts, 'laterality', 'positive');
        foreach ($complaints as $i => $complaint) {
            $cid = (string) ($complaint['id'] ?? '');
            if ($targets !== [] && !in_array($cid, $targets, true)) {
                continue;
            }
            $complaint['laterality'] = $side;
            $complaints[$i] = self::markComplaintSlot($complaint, 'laterality', 'positive');
        }
        if ($complaints !== []) {
            $facts['complaints'] = $complaints;
        }
        $context['clinical_facts'] = self::projectComplaintLedger($facts);
        self::rememberAskedSlot($context, $slot);

        return $context;
    }

    private static function isLateralityToken(string $value): bool
    {
        $value = mb_strtolower(trim($value));
        $value = trim((string) preg_replace('/[.!?…]+$/u', '', $value));

        return in_array($value, ['wala', 'tuo', 'left', 'right', 'both', 'kaliwa', 'kanan', 'pareho'], true);
    }

    private static function isBarePolarityAnswer(string $answer): bool
    {
        $a = mb_strtolower(trim($answer));
        $a = trim((string) preg_replace('/[.!?…]+$/u', '', $a));

        return (bool) preg_match(
            '/^(oo|opo|o+|yes|yeah|yep|no|nope|indi|di|hindi|wala(\s+man)?|walang)(\s+(gid|lang|man|po))?$/ui',
            $a
        );
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function markSlot(array $facts, string $slot, string $polarity): array
    {
        if (!in_array($polarity, ['positive', 'negative', 'uncertain'], true)) {
            return $facts;
        }
        $status = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        $status[$slot] = $polarity;
        if ($slot === 'associated_symptoms') {
            $status['has_other_symptoms'] = $polarity;
        }
        $facts['finding_status'] = $status;
        if ($polarity === 'uncertain') {
            $facts['patient_uncertain'] = true;
        }
        if ($polarity === 'negative' && !self::isInterviewControlLabel($slot)) {
            $label = str_replace('_', ' ', $slot);
            $neg = self::stringList($facts['relevant_negatives'] ?? []);
            if (!self::listHasLabel($neg, $label)) {
                $neg[] = $label;
            }
            $facts['relevant_negatives'] = $neg;
            $negSym = self::stringList($facts['negative_symptoms'] ?? []);
            if (!self::listHasLabel($negSym, $label)) {
                $negSym[] = $label;
            }
            $facts['negative_symptoms'] = $negSym;
        }

        return $facts;
    }

    private static function sameClinicalLabel(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || str_contains($a, $b) || str_contains($b, $a);
    }

    /**
     * @param list<string> $list
     */
    private static function listHasLabel(array $list, string $label): bool
    {
        foreach ($list as $item) {
            if (self::sameClinicalLabel((string) $item, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Short yes/no/negation/particle replies that answer the current question conversationally.
     */
    private static function isContextualShortReply(string $answer): bool
    {
        $a = mb_strtolower(trim($answer));
        if ($a === '') {
            return false;
        }
        $a = trim((string) preg_replace('/[.!?…]+$/u', '', $a));
        if (self::isNonClinicalDiscourseLabel($a)) {
            return true;
        }

        return (bool) preg_match(
            '/^(oo|opo|o+|yes|yeah|yep|no|nope|indi|di|hindi|wala(\s+man)?|walang|syempre|sige|ok(ay)?|gid|lang|man)(\s+(gid|lang|man|po))?$/ui',
            $a
        );
    }

    /**
     * True when the proposed follow-up asks for a fact the patient already gave.
     * Onset and duration are separate. A time span such as "kahapon" fills both.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     */
    private static function questionTargetsAlreadyKnownFact(string $question, array $facts, array $context): bool
    {
        $slot = self::questionFactSlot($question);
        if ($slot === '') {
            return false;
        }

        return !in_array($slot, self::genuinelyMissingSlots($facts, $context), true);
    }

    /**
     * NLP/domain precheck before Gemini (no hard-coded complaint phrases).
     *
     * @return array<string, mixed>
     */
    private static function runNlpDomainPrecheck(string $patientText): array
    {
        $evidence = self::collectLocalNlpEvidence($patientText);
        $domain = [
            'domain' => 'UNCLEAR',
            'health_related' => false,
            'confidence' => 'LOW',
            'score' => 0.0,
            'reason' => 'domain_detector_unavailable',
        ];
        if (class_exists('HealthComplaintDomainDetector')) {
            try {
                $detected = HealthComplaintDomainDetector::detect($patientText);
                if (is_array($detected)) {
                    $domain = [
                        'domain' => (string) ($detected['domain'] ?? 'UNCLEAR'),
                        'health_related' => !empty($detected['health_related']),
                        'confidence' => (string) ($detected['confidence'] ?? 'LOW'),
                        'score' => (float) ($detected['score'] ?? 0),
                        'reason' => (string) ($detected['reason'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
                $domain['reason'] = 'domain_detector_error:' . $e->getMessage();
            }
        }

        $hasMappings = self::stringList($evidence['symptoms'] ?? []) !== []
            || self::stringList($evidence['locations'] ?? []) !== [];
        $domainLabel = strtoupper(str_replace([' ', '-'], '_', (string) ($domain['domain'] ?? 'UNCLEAR')));
        $nlpSaysHealth = $hasMappings
            || !empty($domain['health_related'])
            || in_array($domainLabel, ['HEALTH', 'HEALTH_RELATED', 'MEDICAL'], true);

        return [
            'evidence' => $evidence,
            'domain' => $domain,
            'nlp_says_health' => $nlpSaysHealth,
            'has_validated_mappings' => $hasMappings,
            'explicit_non_human_subject' => self::explicitNonHumanSubject($patientText),
        ];
    }

    /**
     * Local fail-safe for the gate: the complaint is explicitly about an animal (owner/subject of the
     * complaint), with no first-person patient and no exposure wording (bite, scratch, allergy).
     */
    private static function explicitNonHumanSubject(string $patientText): bool
    {
        $text = mb_strtolower(trim($patientText));
        if ($text === '') {
            return false;
        }
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }
        $animals = ['dog', 'dogs', 'puppy', 'cat', 'cats', 'kitten', 'pet', 'pets',
            'ido', 'iro', 'aso', 'tuta', 'pusa', 'kuting', 'iring'];
        $owners = ['my', 'our', 'the', 'ang', 'yung', 'ung', 'akon', 'amon', 'aton', 'aming', 'ating'];
        $ownerEnclitics = ['ko', 'nako', 'namon', 'namin', 'natin', 'naton'];
        $firstPerson = ['i', 'me', 'my', 'ako', 'ko', 'nako', 'akon', 'ak', 'kami', 'kita', 'tayo'];
        $exposure = '/^(bite|bites|biting|bit|bitten|kagat|kinagat|ginkagat|nakagat|kagatan|scratch|scratched|scratches|kalmot|kinalmot|ginkalmot|nakalmot|lick|licked|allergy|allergic|allergies|alerdyi|allergi[ck]o?)$/u';

        $subjectAt = [];
        foreach ($tokens as $i => $tok) {
            if (!in_array($tok, $animals, true)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? '';
            if (in_array($tok, ['pet', 'cat'], true) && in_array($next, ['scan', 'ct'], true)) {
                continue;
            }
            $prev = $tokens[$i - 1] ?? '';
            if ($i === 0 || in_array($prev, $owners, true) || in_array($next, $ownerEnclitics, true)) {
                $subjectAt[$i] = true;
                if (in_array($prev, $owners, true)) {
                    $subjectAt[$i - 1] = true;
                }
                if (in_array($next, $ownerEnclitics, true)) {
                    $subjectAt[$i + 1] = true;
                }
            }
        }
        if ($subjectAt === []) {
            return false;
        }
        foreach ($tokens as $i => $tok) {
            if (isset($subjectAt[$i])) {
                continue;
            }
            if (preg_match($exposure, $tok)) {
                return false;
            }
            if (in_array($tok, $firstPerson, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $evidence collectLocalNlpEvidence()
     * @return array<string, mixed>
     */
    private static function factsFromNlpEvidence(array $evidence): array
    {
        $facts = self::blankFacts();
        $symptoms = self::preferSpecificSymptoms(self::stringList($evidence['symptoms'] ?? []));
        if ($symptoms !== []) {
            $facts['symptom'] = $symptoms[0];
            if (count($symptoms) > 1) {
                $facts['associated_symptoms'] = array_values(array_slice($symptoms, 1));
            }
        }
        $locations = self::stringList($evidence['locations'] ?? []);
        if (count($locations) === 1) {
            $facts['location'] = $locations[0];
        }

        return $facts;
    }

    /**
     * @param array{
     *   classification:string,
     *   confidence:float|null,
     *   normalized_health_concern:string,
     *   passed:bool,
     *   patient_subject?:string,
     *   out_of_scope_non_human?:bool
     * } $gate
     * @param array<string, mixed> $nlpPrecheck
     * @param array<string, mixed> $gemini
     * @return array{
     *   classification:string,
     *   confidence:float|null,
     *   normalized_health_concern:string,
     *   passed:bool,
     *   patient_subject:string,
     *   out_of_scope_non_human:bool,
     *   nlp_override?:bool
     * }
     */
    private static function applyNlpHealthGateOverride(array $gate, array $nlpPrecheck, array $gemini = []): array
    {
        $subject = strtoupper((string) ($gate['patient_subject'] ?? self::normalizePatientSubject($gemini)));
        if (!empty($nlpPrecheck['explicit_non_human_subject'])) {
            $subject = 'NON_HUMAN';
        }
        $gate['patient_subject'] = $subject !== '' ? $subject : 'UNCLEAR';
        $gate['out_of_scope_non_human'] = !empty($gate['out_of_scope_non_human']) || $subject === 'NON_HUMAN';

        // Never promote animal/non-human complaints to HEALTH via NLP symptom matches.
        if (!empty($gate['out_of_scope_non_human']) || $subject === 'NON_HUMAN') {
            $gate['classification'] = self::CLASS_NON_HEALTH;
            $gate['passed'] = false;
            $gate['out_of_scope_non_human'] = true;
            $gate['patient_subject'] = 'NON_HUMAN';
            $gate['normalized_health_concern'] = '';
            unset($gate['nlp_override']);

            return $gate;
        }

        if (empty($nlpPrecheck['nlp_says_health'])) {
            return $gate;
        }
        if (($gate['classification'] ?? '') === self::CLASS_HEALTH) {
            return $gate;
        }
        // NLP may upgrade mistaken NON_HEALTH/UNCLEAR → HEALTH only when Gemini
        // explicitly affirms a HUMAN patient subject (never for animals / unclear subject).
        if ($subject !== 'HUMAN') {
            return $gate;
        }

        $gate['classification'] = self::CLASS_HEALTH;
        $gate['passed'] = true;
        $gate['nlp_override'] = true;
        $gate['patient_subject'] = 'HUMAN';
        $gate['out_of_scope_non_human'] = false;
        $evidence = is_array($nlpPrecheck['evidence'] ?? null) ? $nlpPrecheck['evidence'] : [];
        $symptoms = self::stringList($evidence['symptoms'] ?? []);
        if (($gate['normalized_health_concern'] ?? '') === '' && $symptoms !== []) {
            $gate['normalized_health_concern'] = $symptoms[0];
        }

        return $gate;
    }

    /**
     * @param array<string, mixed> $gemini
     */
    private static function geminiExtractionConfidence(array $gemini): float
    {
        foreach (['extraction_confidence', 'confidence'] as $key) {
            if (!isset($gemini[$key]) || !is_numeric($gemini[$key])) {
                continue;
            }
            $c = (float) $gemini[$key];
            if ($c > 1.0 && $c <= 100.0) {
                $c /= 100.0;
            }

            return max(0.0, min(1.0, $c));
        }

        return 0.0;
    }

    private static function stripAcuityLanguage(string $text): string
    {
        $clean = preg_replace(
            '/\b(EMERGENCY|URGENT|NON[\s_-]?URGENT|NON_URGENT)\b/iu',
            '',
            $text
        );

        return trim((string) preg_replace('/\s{2,}/u', ' ', (string) $clean));
    }

    /**
     * NLP-first merge + Gemini Flash semantic fallback. Strips inventions; never sets acuity.
     *
     * @param array<string, mixed> $gemini
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function groundGeminiAgainstEvidence(array $gemini, array $context, bool $isStart): array
    {
        $incoming = is_array($gemini['clinical_facts'] ?? null) ? $gemini['clinical_facts'] : self::blankFacts();
        unset($incoming['complaints']);
        $patientText = self::patientEvidenceCorpus($context);
        $nlp = self::collectLocalNlpEvidence($patientText);
        $deniedLabels = self::negatedSymptomLabels($patientText, $nlp, self::corpusTurnStarts($context));
        if ($deniedLabels !== []) {
            $nlp['symptoms'] = array_values(array_filter(
                self::stringList($nlp['symptoms'] ?? []),
                static fn (string $s): bool => !isset($deniedLabels[mb_strtolower(trim($s))])
            ));
        }
        $hay = mb_strtolower(trim($patientText . ' ' . (string) ($nlp['english_gloss'] ?? '')));
        $dropped = [];
        $seeded = [];
        $geminiAccepted = [];
        $allowedSymptoms = array_values(array_unique(array_map(
            static fn (string $s): string => mb_strtolower(trim($s)),
            self::stringList($nlp['symptoms'] ?? [])
        )));
        $allowedLocations = array_values(array_unique(array_map(
            static fn (string $s): string => mb_strtolower(trim($s)),
            self::stringList($nlp['locations'] ?? [])
        )));

        $answerStatus = strtoupper((string) ($gemini['answer_status'] ?? 'VALID'));
        if (!in_array($answerStatus, ['VALID', 'UNCERTAIN', 'UNCLEAR', 'UNRELATED'], true)) {
            $answerStatus = 'VALID';
        }
        $extractionConfidence = self::geminiExtractionConfidence($gemini);
        $geminiClaimsSupport = array_key_exists('facts_supported_by_patient_wording', $gemini)
            ? !empty($gemini['facts_supported_by_patient_wording'])
            : ($answerStatus === 'VALID' && $extractionConfidence >= self::GEMINI_SEMANTIC_MIN_CONFIDENCE);
        $allowGeminiSemantic = $answerStatus === 'VALID'
            && $geminiClaimsSupport
            && $extractionConfidence >= self::GEMINI_SEMANTIC_MIN_CONFIDENCE
            && !in_array($answerStatus, ['UNCLEAR', 'UNCERTAIN', 'UNRELATED'], true);

        // Start from NLP-confirmed facts (authoritative layer).
        $facts = self::factsFromNlpEvidence($nlp);
        foreach (self::stringList($facts['associated_symptoms'] ?? []) as $s) {
            $seeded[] = 'associated:' . $s;
        }
        if (($facts['symptom'] ?? null) !== null && $facts['symptom'] !== '') {
            $seeded[] = 'symptom:' . $facts['symptom'];
        }
        if (($facts['location'] ?? null) !== null && $facts['location'] !== '') {
            $seeded[] = 'location:' . $facts['location'];
        }

        // Detect Gemini inventing unsupported anatomy — distrust Gemini-only symptom package.
        $geminiLocRaw = trim((string) ($incoming['location'] ?? ''));
        $geminiInventedLocation = $geminiLocRaw !== ''
            && !self::locationSupported($geminiLocRaw, $hay, $allowedLocations);

        // --- symptom: NLP wins; else a Gemini label grounded in the patient's own wording ---
        $geminiSymptom = trim((string) ($incoming['symptom'] ?? ''));
        if ($allowedSymptoms !== []) {
            // Prefer NLP; ignore conflicting Gemini symptom.
            if ($geminiSymptom !== ''
                && !self::clinicalLabelSupported($geminiSymptom, $hay, $allowedSymptoms, $allowedLocations)
            ) {
                $dropped[] = 'symptom:' . $geminiSymptom;
            }
        } elseif ($geminiSymptom !== '') {
            if (self::isNonClinicalDiscourseLabel($geminiSymptom)) {
                $dropped[] = 'symptom_discourse:' . $geminiSymptom;
            } elseif (isset($deniedLabels[mb_strtolower($geminiSymptom)])) {
                $dropped[] = 'symptom:' . $geminiSymptom;
            } else {
                $expressions = null;
                $nlpOrHayOk = self::clinicalLabelSupported($geminiSymptom, $hay, $allowedSymptoms, $allowedLocations);
                if (!$nlpOrHayOk) {
                    $expressions = self::localSymptomExpressions($patientText);
                    $nlpOrHayOk = in_array(mb_strtolower($geminiSymptom), $expressions, true);
                }
                if ($nlpOrHayOk) {
                    $facts['symptom'] = $geminiSymptom;
                    $geminiAccepted[] = 'symptom:' . $geminiSymptom;
                } elseif (!$geminiInventedLocation
                    && $expressions !== []
                    && self::symptomSiteSupported($geminiSymptom, $hay, $allowedLocations)
                ) {
                    // Gemini only named what the patient said: a symptom word plus the body site it belongs to.
                    $facts['symptom'] = $geminiSymptom;
                    $geminiAccepted[] = 'symptom_site_anchored:' . $geminiSymptom;
                } else {
                    $dropped[] = 'symptom:' . $geminiSymptom;
                }
            }
        }

        // --- location: NLP/lexicon/patient wording only — never pure Gemini invention ---
        if ($geminiLocRaw !== '') {
            if (self::locationSupported($geminiLocRaw, $hay, $allowedLocations)) {
                if (($facts['location'] ?? null) === null || $facts['location'] === '') {
                    $facts['location'] = $geminiLocRaw;
                    $geminiAccepted[] = 'location:' . $geminiLocRaw;
                }
            } else {
                $dropped[] = 'location:' . $geminiLocRaw;
            }
        }

        // --- laterality ---
        $side = trim((string) ($incoming['laterality'] ?? ''));
        if ($side !== '') {
            $lowSide = mb_strtolower($side);
            $bareNegation = in_array($lowSide, ['no', 'none', 'indi', 'hindi', 'wala', 'walang'], true);
            $statedLeft = $bareNegation
                && $lowSide === 'wala'
                && class_exists('ClinicalFeatureExtractors')
                && ClinicalFeatureExtractors::looksLateralityWala($hay);
            if ($statedLeft || (!$bareNegation && self::scalarSupportedByPatient($side, $hay))) {
                $facts['laterality'] = $statedLeft ? 'wala' : $side;
            } else {
                $dropped[] = 'laterality:' . $side;
            }
        }

        // --- pain_score: only if patient stated a 1–10 number ---
        if (array_key_exists('pain_score', $incoming) && $incoming['pain_score'] !== null && $incoming['pain_score'] !== '') {
            $score = is_numeric($incoming['pain_score']) ? (int) $incoming['pain_score'] : 0;
            $numberInPatient = (bool) preg_match('/\b([1-9]|10)\b/u', $hay);
            if ($score >= 1 && $score <= 10 && $numberInPatient) {
                $facts['pain_score'] = $score;
            } else {
                $dropped[] = 'pain_score:' . (string) $incoming['pain_score'];
            }
        }

        foreach (['onset', 'duration', 'frequency'] as $scalarKey) {
            $val = trim((string) ($incoming[$scalarKey] ?? ''));
            if ($val === '') {
                continue;
            }
            if (self::scalarSupportedByPatient($val, $hay)
                || ($allowGeminiSemantic && !$geminiInventedLocation && $answerStatus === 'VALID')
            ) {
                // Scalars still require patient-token support; do not invent durations from nothing.
                if (self::scalarSupportedByPatient($val, $hay)) {
                    $facts[$scalarKey] = $val;
                } else {
                    $dropped[] = $scalarKey . ':' . $val;
                }
            } else {
                $dropped[] = $scalarKey . ':' . $val;
            }
        }

        foreach (['associated_symptoms', 'warning_signs'] as $listKey) {
            $kept = self::stringList($facts[$listKey] ?? []);
            foreach (self::stringList($incoming[$listKey] ?? []) as $item) {
                if (self::isNonClinicalDiscourseLabel($item)) {
                    $dropped[] = $listKey . '_discourse:' . $item;
                    continue;
                }
                $ok = self::clinicalLabelSupported($item, $hay, $allowedSymptoms, $allowedLocations);
                if ($ok) {
                    if (!in_array($item, $kept, true)) {
                        $kept[] = $item;
                    }
                } else {
                    $dropped[] = $listKey . ':' . $item;
                }
            }
            $facts[$listKey] = $kept;
        }

        // Negatives: keep only when patient used negation language.
        $negKept = [];
        $hasNegation = (bool) preg_match(
            '/\b(no|wala(\s+ko)?|walang|without|denies|indi|wala\s+man|none|nothing)\b/ui',
            $hay
        );
        foreach (self::stringList($incoming['relevant_negatives'] ?? []) as $neg) {
            if (!$hasNegation) {
                $dropped[] = 'relevant_negatives:' . $neg;
                continue;
            }
            $bare = trim((string) preg_replace(
                '/^(no|wala(\s+ko)?|walang|without|denies)\s+/ui',
                '',
                mb_strtolower($neg)
            ));
            if ($bare === '' || $bare === 'other symptoms' || $bare === 'additional symptoms'
                || self::clinicalLabelSupported($bare, $hay, $allowedSymptoms, $allowedLocations)
                || self::scalarSupportedByPatient($bare, $hay)
            ) {
                $negKept[] = $neg;
            } else {
                $dropped[] = 'relevant_negatives:' . $neg;
            }
        }
        $facts['relevant_negatives'] = $negKept;
        $facts['notes'] = []; // never keep speculative Gemini notes

        foreach (self::stringList($incoming['notes'] ?? []) as $note) {
            $dropped[] = 'notes:' . $note;
        }

        $gemini['clinical_facts'] = self::sanitizeClinicalFacts($facts);
        $facts = $gemini['clinical_facts'];
        if (isset($gemini['next_question'])) {
            $gemini['next_question'] = self::stripAcuityLanguage((string) $gemini['next_question']);
        }

        $known = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        $needsClarify = !self::hasClinicallyUsefulFacts($facts)
            && $allowedSymptoms === []
            && trim((string) ($context['chief_complaint'] ?? '')) !== ''
            // Once a complaint is known, an answer about its details needs no symptom re-description.
            && !self::interviewReadyToClose($known, $context);

        // Ambiguous meaning → UNCLEAR + neutral clarification (do not guess).
        // Skip when the complaint is already clinically obvious from validated facts.
        if ($needsClarify && (($gemini['classification'] ?? self::CLASS_HEALTH) === self::CLASS_HEALTH
            || !array_key_exists('classification', $gemini)
            || ($gemini['classification'] ?? '') === '')
        ) {
            if (!in_array($answerStatus, ['UNCLEAR', 'UNCERTAIN', 'UNRELATED'], true)) {
                $gemini['answer_status'] = 'UNCLEAR';
            }
            $gemini['question_needed'] = true;
            $gemini['interview_sufficient'] = false;
            $missing = self::stringList($gemini['missing_information'] ?? []);
            if (!in_array('symptom_clarification', $missing, true)) {
                $missing[] = 'symptom_clarification';
            }
            $gemini['missing_information'] = $missing;
            $lang = self::detectLanguageHint($context);
            $gemini['next_question'] = match ($lang) {
                'hiligaynon' => 'Pwede mo mas klaro nga isaysay kung ano ang imo nabatyagan?',
                'tagalog' => 'Pwede mo bang ilarawan nang mas malinaw ang nararamdaman mo?',
                default => 'Can you describe more clearly what you are feeling?',
            };
        }

        // Follow-ups must not assume dropped invented facts.
        if ($dropped !== [] && !$needsClarify) {
            $nextQ = trim((string) ($gemini['next_question'] ?? ''));
            if ($nextQ !== '' && self::questionAssumesInventedFacts($nextQ, $dropped)) {
                $gemini['next_question'] = self::neutralMissingFactQuestion($context, $facts);
                $gemini['question_needed'] = true;
                $gemini['interview_sufficient'] = false;
            }
        }

        if (isset($gemini['normalized_health_concern'])) {
            $gloss = trim((string) $gemini['normalized_health_concern']);
            if ($gloss !== '' && !self::glossSupported($gloss, $hay, $allowedSymptoms, $allowedLocations, $facts)) {
                if (($facts['symptom'] ?? null) !== null && $facts['symptom'] !== '') {
                    $gemini['normalized_health_concern'] = (string) $facts['symptom'];
                } else {
                    $gemini['normalized_health_concern'] = '';
                }
                $dropped[] = 'normalized_health_concern:' . $gloss;
            }
        }

        $gemini['_nlp_grounding'] = [
            'patient_text' => $patientText,
            'nlp_symptoms' => $nlp['symptoms'] ?? [],
            'nlp_locations' => $nlp['locations'] ?? [],
            'symptom_mappings' => $nlp['symptom_mappings'] ?? [],
            'location_mappings' => $nlp['location_mappings'] ?? [],
            'dropped_unsupported' => $dropped,
            'seeded_from_nlp' => $seeded,
            'accepted_gemini_semantic' => $geminiAccepted,
            'extraction_confidence' => $extractionConfidence,
            'allow_gemini_semantic' => $allowGeminiSemantic,
            'gemini_invented_location' => $geminiInventedLocation,
            'needs_clarification' => $needsClarify,
            'is_start' => $isStart,
        ];

        return $gemini;
    }

    /**
     * @param list<string> $allowedEnglish
     * @param list<string> $allowedLocations
     */
    private static function clinicalLabelSupported(string $label, string $hay, array $allowedEnglish, array $allowedLocations = []): bool
    {
        $label = trim($label);
        if ($label === '' || self::isNonClinicalDiscourseLabel($label)) {
            return false;
        }
        $low = mb_strtolower($label);
        foreach ($allowedEnglish as $allowed) {
            if ($allowed === '') {
                continue;
            }
            if ($low === $allowed || str_contains($allowed, $low)) {
                return true;
            }
            // A generic NLP label ("pain") only vouches for a more specific one ("chest pain")
            // when the extra wording or its body site also comes from the patient.
            if (str_contains($low, $allowed)) {
                $rest = trim((string) preg_replace('/\s+/u', ' ', str_replace($allowed, ' ', $low)));
                if ($rest === '' || self::scalarSupportedByPatient($rest, $hay)
                    || self::symptomSiteSupported($label, $hay, $allowedLocations)
                ) {
                    return true;
                }
            }
        }
        if (class_exists('SymptomEvidenceGate')) {
            try {
                $filtered = SymptomEvidenceGate::filterSymptomNames([$label], $hay, $hay, $hay);

                return $filtered !== [];
            } catch (Throwable) {
                // fall through
            }
        }

        return self::scalarSupportedByPatient($label, $hay);
    }

    /**
     * @param list<string> $allowedLocations
     */
    private static function locationSupported(string $location, string $hay, array $allowedLocations): bool
    {
        $location = trim($location);
        if ($location === '') {
            return false;
        }
        $low = mb_strtolower($location);
        foreach ($allowedLocations as $allowed) {
            if ($allowed !== '' && ($low === $allowed || str_contains($low, $allowed) || str_contains($allowed, $low))) {
                return true;
            }
        }
        if (self::scalarSupportedByPatient($location, $hay)) {
            return true;
        }
        if (class_exists('BodyLocationLexicon')) {
            try {
                foreach (BodyLocationLexicon::extractCanonical($location) as $canonical) {
                    $c = mb_strtolower(trim((string) $canonical));
                    if ($c !== '' && (str_contains($hay, $c) || in_array($c, $allowedLocations, true))) {
                        return true;
                    }
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return false;
    }

    /**
     * Symptom words the local domain detector found in the patient's own text (incl. its spelling repair).
     *
     * @return list<string>
     */
    private static function localSymptomExpressions(string $patientText): array
    {
        if (trim($patientText) === '' || !class_exists('HealthComplaintDomainDetector')) {
            return [];
        }
        try {
            $detected = HealthComplaintDomainDetector::detect($patientText);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($detected['signals'] ?? null) ? $detected['signals'] : [] as $signal) {
            if (!is_array($signal) || !in_array((string) ($signal['type'] ?? ''), ['symptom', 'dataset_symptom'], true)) {
                continue;
            }
            foreach (explode('→', (string) ($signal['value'] ?? '')) as $part) {
                $part = mb_strtolower(trim($part));
                if ($part !== '' && !in_array($part, $out, true)) {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    /**
     * True when the body site named inside a symptom label is one the patient actually mentioned.
     *
     * @param list<string> $allowedLocations
     */
    private static function symptomSiteSupported(string $label, string $hay, array $allowedLocations): bool
    {
        if (!class_exists('BodyLocationLexicon')) {
            return false;
        }
        try {
            foreach (BodyLocationLexicon::extractCanonical($label) as $site) {
                if (self::locationSupported((string) $site, $hay, $allowedLocations)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function scalarSupportedByPatient(string $value, string $hay): bool
    {
        $value = mb_strtolower(trim($value));
        if ($value === '' || $hay === '') {
            return false;
        }
        if (str_contains($hay, $value)) {
            return true;
        }
        $words = preg_split('/[\s,.;:\/\-]+/u', $value) ?: [];
        $significant = [];
        foreach ($words as $w) {
            $w = trim($w);
            if ($w === '' || mb_strlen($w) < 3) {
                continue;
            }
            if (in_array($w, ['the', 'and', 'for', 'with', 'from', 'this', 'that', 'have', 'has', 'had', 'was', 'were', 'are', 'not'], true)) {
                continue;
            }
            $significant[] = $w;
        }
        if ($significant === []) {
            return false;
        }
        foreach ($significant as $w) {
            if (!preg_match('/(?<!\w)' . preg_quote($w, '/') . '(?!\w)/u', $hay)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $dropped
     */
    private static function questionAssumesInventedFacts(string $question, array $dropped): bool
    {
        $q = mb_strtolower($question);
        foreach ($dropped as $item) {
            $parts = explode(':', $item, 2);
            $val = mb_strtolower(trim($parts[1] ?? ''));
            if ($val === '' || mb_strlen($val) < 3) {
                continue;
            }
            // Avoid tiny fragments; require word-ish presence of invented value.
            $tokens = preg_split('/\s+/u', $val) ?: [];
            foreach ($tokens as $tok) {
                $tok = trim($tok);
                if (mb_strlen($tok) < 4) {
                    continue;
                }
                if (preg_match('/(?<!\w)' . preg_quote($tok, '/') . '(?!\w)/u', $q)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $facts
     */
    private static function neutralMissingFactQuestion(array $context, array $facts): string
    {
        $missing = self::genuinelyMissingSlots($facts, $context);
        if ($missing === []) {
            return '';
        }

        return self::simpleQuestionForSlot($missing[0], $context);
    }

    /**
     * @param list<string> $allowedSymptoms
     * @param list<string> $allowedLocations
     * @param array<string, mixed> $facts
     */
    private static function glossSupported(
        string $gloss,
        string $hay,
        array $allowedSymptoms,
        array $allowedLocations,
        array $facts
    ): bool {
        $gloss = mb_strtolower(trim($gloss));
        if ($gloss === '') {
            return true;
        }
        if (self::scalarSupportedByPatient($gloss, $hay)) {
            return true;
        }
        $sym = mb_strtolower(trim((string) ($facts['symptom'] ?? '')));
        if ($sym !== '' && (str_contains($gloss, $sym) || $gloss === $sym)) {
            return true;
        }
        foreach ($allowedSymptoms as $s) {
            if ($s !== '' && str_contains($gloss, $s)) {
                // Still reject if gloss also asserts an unsupported body site.
                if (class_exists('BodyLocationLexicon')) {
                    try {
                        foreach (BodyLocationLexicon::extractCanonical($gloss) as $site) {
                            if (!self::locationSupported((string) $site, $hay, $allowedLocations)) {
                                return false;
                            }
                        }
                    } catch (Throwable) {
                        // ignore lexicon errors
                    }
                }

                return true;
            }
        }
        if (class_exists('BodyLocationLexicon')) {
            try {
                foreach (BodyLocationLexicon::extractCanonical($gloss) as $site) {
                    if (!self::locationSupported((string) $site, $hay, $allowedLocations)) {
                        return false;
                    }
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return self::clinicalLabelSupported($gloss, $hay, $allowedSymptoms);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private static function callGemini(string $mode, array $context, string $latestAnswer): ?array
    {
        self::$lastError = '';
        try {
            $raw = self::complete(self::buildUserPrompt($mode, $context, $latestAnswer));
            $parsed = self::parseJson($raw, $mode);
            if ($parsed === null) {
                self::$lastError = 'unparseable: ' . mb_substr($raw, 0, 200);

                return null;
            }
            // Never trust Gemini triage/diagnosis/acuity fields.
            unset(
                $parsed['triage'],
                $parsed['triage_display'],
                $parsed['triage_level'],
                $parsed['urgency'],
                $parsed['acuity'],
                $parsed['diagnosis'],
                $parsed['prescription'],
                $parsed['treatment'],
                $parsed['recommended_action']
            );
            if (isset($parsed['next_question'])) {
                $parsed['next_question'] = self::stripAcuityLanguage((string) $parsed['next_question']);
            }
            foreach (['normalized_health_concern'] as $glossKey) {
                if (isset($parsed[$glossKey]) && is_string($parsed[$glossKey])) {
                    $parsed[$glossKey] = self::stripAcuityLanguage($parsed[$glossKey]);
                }
            }

            return $parsed;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('GeminiClinicalInterviewDemo: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function buildUserPrompt(string $mode, array $context, string $latestAnswer): string
    {
        $factsJson = json_encode($context['clinical_facts'] ?? self::blankFacts(), JSON_UNESCAPED_UNICODE);
        $missingJson = json_encode($context['missing_information'] ?? [], JSON_UNESCAPED_UNICODE);
        $convLines = [];
        foreach ((array) ($context['conversation'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = strtoupper((string) ($row['role'] ?? ''));
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $convLines[] = $role . ': ' . $text;
        }
        $conversation = $convLines !== [] ? implode("\n", $convLines) : '(none yet)';

        $evidenceText = $mode === 'start'
            ? trim((string) ($context['chief_complaint'] ?? ''))
            : trim(self::patientEvidenceCorpus($context, $latestAnswer));
        $nlpBlock = self::formatNlpEvidenceForPrompt(self::collectLocalNlpEvidence($evidenceText));

        $languageBlock = self::questionLanguagePromptBlock($context);

        if ($mode === 'start') {
            return $languageBlock
                . "MODE: START_INTERVIEW\n"
                . "Use COMMON-SENSE clinical conversation. Understand the COMPLETE patient meaning — do not blindly fill schema fields.\n"
                . "Pipeline: NLP/domain mappings above are checked first; you are Gemini Flash semantic interpretation/fallback.\n"
                . "Decide whether the opening text is a genuine health/medical concern by MEANING.\n"
                . "Support English, Hiligaynon/Ilonggo, Tagalog, mixed language, slang, informal wording, misspellings, and local expressions.\n\n"
                . "Original patient opening (preserve exactly; do not rewrite as the stored complaint):\n"
                . (string) ($context['chief_complaint'] ?? '') . "\n\n"
                . $nlpBlock . "\n"
                . "Already seeded clinical_facts from NLP (JSON; do not contradict; ignore any particle/filler noise):\n" . $factsJson . "\n\n"
                . "Return JSON with gate fields ALWAYS.\n"
                . "If NON_HEALTH_RELATED or UNCLEAR: question_needed=false, interview_sufficient=false, next_question=\"\", empty clinical_facts.\n"
                . "HUMAN-PATIENT SCOPE (required): set patient_subject to HUMAN, NON_HUMAN, or UNCLEAR by MEANING. "
                . "If the complaint is about an animal, pet, livestock, wildlife, or any non-human subject — even when symptoms sound medical — "
                . "classification=NON_HEALTH_RELATED, patient_subject=NON_HUMAN, empty clinical_facts, do not start interview. "
                . "Do not reinterpret an animal complaint as a human complaint. "
                . "If HEALTH_RELATED: patient_subject must be HUMAN; extract ONLY facts the patient actually said. "
                . "clinical_facts must include every fact in this message (all complaints, not just the first). "
                . "missing_information lists only facts that are still absent. "
                . "Ask exactly ONE simple next_question for one still-missing fact, in QUESTION LANGUAGE. "
                . "If onset, location, laterality, pain score, duration, trauma, or any other fact is already present, do not ask for it. "
                . "Never output EMERGENCY, URGENT, or NON-URGENT. Do not diagnose.";
        }

        return $languageBlock
            . "MODE: INTERPRET_ANSWER\n"
            . "Read the FULL conversation. The accumulated clinical_facts are the source of truth for this turn.\n"
            . "Merge every new fact from the latest answer into clinical_facts. Do not drop facts from earlier turns.\n"
            . "Keep every complaint the patient named, with the facts that belong to it. Do not mix facts between complaints.\n"
            . "no, wala, indi, and hindi are valid answers. Store them. Do not ask that fact again.\n"
            . "Do not assume, infer, or invent any fact the patient did not say.\n"
            . "missing_information must list only facts that are still empty in clinical_facts.\n"
            . "Ask exactly ONE simple next_question for one item in missing_information, in QUESTION LANGUAGE.\n"
            . "If nothing important is missing, set interview_sufficient=true and next_question=\"\".\n"
            . "Do not diagnose. Final acuity is NOT your job.\n\n"
            . "Original patient complaint (preserve exactly):\n"
            . (string) ($context['chief_complaint'] ?? '') . "\n\n"
            . "Current question the patient was answering:\n"
            . (string) ($context['awaiting_question'] ?? '') . "\n\n"
            . "Latest patient answer (preserve exactly):\n"
            . $latestAnswer . "\n\n"
            . "Full conversation so far:\n" . $conversation . "\n\n"
            . $nlpBlock . "\n"
            . "Already collected clinical_facts (JSON):\n" . $factsJson . "\n\n"
            . "Previously listed missing_information (JSON):\n" . $missingJson . "\n\n"
            . "Return the full accumulated clinical_facts, not only the new answer. "
            . "Apply a short yes/no to the CURRENT question only. "
            . "next_question must follow the patient's latest language and ask one missing fact in everyday words. "
            . "Do not translate the question word-for-word. If ambiguous, answer_status=UNCLEAR and do not guess. "
            . "Never output EMERGENCY, URGENT, or NON-URGENT.";
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are Gemini Flash conducting a natural clinical interview for a MedConnect DEMO page.

Authoritative pipeline (you are only the semantic interview layer):
Patient input → existing NLP/domain validation → Gemini Flash semantic interpretation when needed → confirmed clinical facts → ClinicalTriageEngine (WHO IITT + clinical rules) → final acuity.

You MUST NEVER determine clinical acuity. You MUST NEVER output, assign, recommend, or infer EMERGENCY, URGENT, or NON-URGENT. You do not diagnose. You do not prescribe. ClinicalTriageEngine alone decides final triage later.

CUMULATIVE FACTS (critical):
- clinical_facts is the running record of the whole interview. Every turn must keep prior facts and add only what this answer newly states.
- Preserve every complaint, not only the first. Do not delete a symptom, place, time, score, or denial from an earlier turn.
- When the patient names more than one complaint, keep each complaint's facts separate. Do not copy pain, time, side, or place from one complaint onto another unless the patient says that fact applies to both.
- Patient answers are the source of truth. Do not assume, infer, or invent symptom, location, laterality, onset, duration, frequency, pain score, trauma, or associated symptoms.
- "no", "wala", "indi", and "hindi" are real answers. Store the denial. Never ask that same fact again.
- missing_information may contain only facts that are still empty. If onset is filled, onset must not be listed. Same for location, laterality, pain score, duration, trauma, and every other stored fact.
- next_question must be chosen from the current clinical_facts. Ask exactly one missing fact. If nothing important is missing, stop.

FOLLOW-UP LANGUAGE (next_question only):
- Detect the language of the patient's latest message. The user message names that language. Write next_question only in it.
- Hiligaynon/Ilonggo in, simple Hiligaynon/Ilonggo out. Tagalog in, simple Tagalog out. English in, simple English out.
- Mixed language: follow the patient's dominant language. Do not switch language unless the patient switches.
- Use everyday words a patient can answer. Do not translate word-for-word from English. Do not use technical or formal wording.
- Keep the patient's own words in clinical_facts. Do not rewrite the complaint.

Short answers & discourse particles:
- Interpret short replies such as affirmatives, negatives, and brief particles according to the CURRENT question's conversational context (they can be VALID).
- Never treat normal language particles/fillers/intensifiers as medical symptoms, and never convert ordinary words into symptoms via filler glosses.
- Extract only genuine clinical information.

Health gate (START_INTERVIEW only):
medConnect accepts HUMAN / PATIENT health complaints only.

Return exactly one classification:
- HEALTH_RELATED — the meaning is a health problem/symptom/injury/medical concern about a HUMAN patient (the speaker or another person), any language/style.
- NON_HEALTH_RELATED — not an in-scope human health concern (greetings, chat, jokes/pranks, spam, unrelated topics, OR any complaint whose subject is an animal/pet/livestock/wildlife/non-human).
- UNCLEAR — no clear health meaning, or too ambiguous to extract without guessing.

Also set patient_subject by MEANING (not a fixed animal-word list):
- HUMAN — complaint is about a person
- NON_HUMAN — complaint is about an animal or other non-human subject (veterinary / pet / livestock / wildlife / etc.)
- UNCLEAR — cannot tell who/what the subject is

Rules:
- Judge MEANING across English, Hiligaynon/Ilonggo, Tagalog, mixed language, slang, informal wording, and misspellings.
- Do not rely only on exact keywords or a hardcoded animal phrase list.
- If the subject is NON_HUMAN: classification MUST be NON_HEALTH_RELATED, clinical_facts empty, question_needed=false — do not start the interview or invent human symptoms.
- Never reinterpret an animal/non-human complaint as a human complaint.
- Support valid human health complaints normally.
- Do not invent special-case word lists for languages.

Clinical jobs (only when HEALTH_RELATED):
1) Understand complaint/answers semantically using original wording + conversation context.
2) Prefer validated NLP mappings when provided; use Gemini as semantic fallback only when meaning is reliable.
3) Extract structured clinical facts ONLY when patient-supported (and/or validated NLP). Preserve original wording in conversation.
4) Ask exactly ONE simple follow-up for one genuinely missing fact — or stop when those facts are present. Do not decide EMERGENCY, URGENT, or NON-URGENT.
5) Stop when the accumulated facts are enough for ClinicalTriageEngine (interview_sufficient=true, question_needed=false). You do not choose acuity.
6) Write next_question only in the QUESTION LANGUAGE from the user prompt. Use simple spoken language, not a word-for-word translation. Do not switch language unless the patient did.

STRICT anti-hallucination:
- NEVER invent symptom, location, severity, duration, frequency, laterality, associated symptom, warning sign, or diagnosis.
- Do NOT manufacture facts to complete JSON — leave unsupported fields null.
- If genuinely ambiguous: answer_status=UNCLEAR, leave fields null, ask neutral clarification.
- Set facts_supported_by_patient_wording and extraction_confidence honestly.

Pain severity: only when the patient clearly indicated pain (not assumed); collect 1–10 once; never ask pain score when pain was never stated.

Multi-finding questions (critical):
- When next_question asks about more than one clinical finding, set targeted_findings to the canonical keys for ONLY those findings (e.g. ["fever_confirmed","vomiting","dizziness"]).
- On INTERPRET_ANSWER, update clinical_facts.finding_status ONLY for findings in the previous question's targeted_findings.
- finding_status values: positive | negative | uncertain | not_assessed
- Do not apply one yes/no blindly to unrelated findings that were not asked.
- If the answer is ambiguous across multiple findings, mark only what is safely determined; leave the rest not_assessed or uncertain.
- Preserve the patient's original wording in conversation; never invent findings.

answer_status:
- VALID — useful answer including clear yes/no/negative/short contextual replies
- UNCERTAIN — patient unsure
- UNCLEAR — unreadable/nonsense/truly ambiguous (not merely a short yes/no)
- UNRELATED — does not address the clinical question

Respond with JSON ONLY:
{
  "classification": "HEALTH_RELATED|NON_HEALTH_RELATED|UNCLEAR",
  "patient_subject": "HUMAN|NON_HUMAN|UNCLEAR",
  "is_human_patient_complaint": true,
  "confidence": 0.0,
  "extraction_confidence": 0.0,
  "facts_supported_by_patient_wording": true,
  "normalized_health_concern": "",
  "answer_status": "VALID|UNCERTAIN|UNCLEAR|UNRELATED",
  "targeted_findings": string[],
  "clinical_facts": {
    "symptom": string|null,
    "location": string|null,
    "laterality": string|null,
    "pain_score": number|null,
    "onset": string|null,
    "duration": string|null,
    "frequency": string|null,
    "associated_symptoms": string[],
    "relevant_negatives": string[],
    "warning_signs": string[],
    "notes": string[],
    "finding_status": { "<finding_key>": "positive|negative|uncertain|not_assessed" }
  },
  "missing_information": string[],
  "question_needed": boolean,
  "next_question": string,
  "interview_sufficient": boolean
}

For START_INTERVIEW: always include classification, patient_subject, is_human_patient_complaint, and confidence; interview fields only when HEALTH_RELATED with patient_subject=HUMAN.
When patient_subject=NON_HUMAN: classification=NON_HEALTH_RELATED, empty clinical_facts, question_needed=false, interview_sufficient=false, next_question="".
For INTERPRET_ANSWER: focus on answer_status, clinical_facts (including finding_status for prior targeted_findings), extraction_confidence, facts_supported_by_patient_wording.
When asking next_question, always populate targeted_findings for the findings that question probes.
PROMPT;
    }

    private static function complete(string $userPrompt): string
    {
        self::ensureAiProviders();
        // BITS/Ollama ignores Gemini thinkingConfig. The [true, false] retry below would
        // send the same huge prompt twice (chat + generate each time) and exceed the
        // patient-portal 120s abort before NLP fallback can run.
        if (self::bitsInterviewProviderSelected()) {
            $bitsText = self::generate(self::requestPayload($userPrompt, false));
            if ($bitsText !== '') {
                return $bitsText;
            }
            throw new RuntimeException('BITS Ollama unavailable or empty reply');
        }
        $last = null;
        // Gemini 3.5 Flash thinks by default. Thought tokens share maxOutputTokens, so a
        // short budget returns finishReason=MAX_TOKENS and an empty candidate. The demo
        // then reports that empty body as invalid JSON. Ask for minimal thinking first.
        // If the model rejects thinkingConfig, retry once with a larger output budget.
        foreach ([true, false] as $i => $withThinkingConfig) {
            try {
                $res = self::generate(self::requestPayload($userPrompt, $withThinkingConfig));
                if ($res !== '') {
                    return $res;
                }
            } catch (RuntimeException $e) {
                $last = $e;
                if (self::isGeminiQuotaError($e->getMessage())) {
                    throw $e;
                }
                $msg = strtolower($e->getMessage());
                $retryable = str_contains($msg, '503')
                    || str_contains($msg, 'high demand')
                    || str_contains($msg, 'unavailable')
                    || str_contains($msg, 'empty railway')
                    || str_contains($msg, 'railway gemini failed')
                    || str_contains($msg, 'timeout')
                    || str_contains($msg, 'timed out');
                if ($i < 1 && $retryable) {
                    usleep(1500000);
                    continue;
                }
                if ($i < 1) {
                    usleep(500000);
                    continue;
                }
                throw $e;
            }
        }
        if ($last instanceof RuntimeException) {
            throw $last;
        }

        throw new RuntimeException('empty Gemini response');
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestPayload(string $userPrompt, bool $withThinkingConfig): array
    {
        $config = [
            'temperature' => 0.1,
            // Room for the interview schema after any residual thoughts.
            'maxOutputTokens' => $withThinkingConfig ? 4096 : 8192,
            'responseMimeType' => 'application/json',
        ];
        if ($withThinkingConfig) {
            // Gemini 3.x: thinkingBudget 0 can hang. thinkingLevel MINIMAL keeps thoughts at 0
            // so the candidate is the JSON object instead of an empty MAX_TOKENS body.
            $config['thinkingConfig'] = ['thinkingLevel' => 'MINIMAL'];
        }

        return [
            'systemInstruction' => [
                'parts' => [['text' => self::systemPrompt()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $userPrompt]],
            ]],
            'generationConfig' => $config,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function generate(array $payload): string
    {
        self::$aiProviderUsed = '';
        $model = 'gemini-3.5-flash';
        self::ensureGeminiConfig();
        if (function_exists('medconnect_gemini_model')) {
            $model = medconnect_gemini_model();
        } elseif (function_exists('ai_providers_gemini_model')) {
            self::ensureAiProviders();
            $model = ai_providers_gemini_model();
        } else {
            $envModel = trim((string) (getenv('AI_MODEL') ?: ($_ENV['AI_MODEL'] ?? '')));
            if ($envModel !== '' && str_starts_with(strtolower($envModel), 'gemini')) {
                $model = $envModel;
            }
        }

        if (self::bitsInterviewProviderSelected()) {
            require_once dirname(__DIR__) . '/includes/openrouter_demo_fallback.php';
            $bitsText = medconnect_demo_bits_text_from_gemini($payload, self::$bitsTransportForTest);
            if (is_string($bitsText) && trim($bitsText) !== '') {
                self::$aiProviderUsed = 'bits';

                return trim($bitsText);
            }
            throw new RuntimeException('BITS Ollama unavailable or empty reply');
        }

        if (self::$openRouterQuotaProbe) {
            $probeError = new RuntimeException('Gemini HTTP 429: You exceeded your current quota');
            $recovered = self::recoverDemoQuotaWithOpenRouter($probeError, $payload);
            if (is_string($recovered)) {
                return $recovered;
            }
            throw $probeError;
        }

        if (self::shouldUseRailway()) {
            try {
                return self::generateViaRailway($payload, $model);
            } catch (RuntimeException $e) {
                $recovered = self::recoverDemoQuotaWithOpenRouter($e, $payload);
                if (is_string($recovered)) {
                    return $recovered;
                }
                $msg = strtolower($e->getMessage());
                $thinkingReject = !empty($payload['generationConfig']['thinkingConfig'])
                    && (str_contains($msg, 'gemini http 400') || str_contains($msg, 'thinking'));
                if (self::apiKey() === '') {
                    // Soft-fail so complete() can retry without thinkingConfig / after backoff.
                    if ($thinkingReject || str_contains($msg, 'empty railway') || str_contains($msg, '503')) {
                        return '';
                    }
                    throw $e;
                }
                // Fall through to direct Gemini when a local key exists.
            }
        }

        $key = self::apiKey();
        if ($key === '') {
            throw new RuntimeException('Gemini API key missing');
        }
        $url = sprintf(self::ENDPOINT, rawurlencode($model));
        try {
            $data = self::httpPostJson($url, $payload, [
                'x-goog-api-key: ' . $key,
            ]);
        } catch (RuntimeException $e) {
            $fallbackText = self::tryGeminiFallbackModelOnce($e, $payload, $model, $key);
            if (is_string($fallbackText) && $fallbackText !== '') {
                return $fallbackText;
            }
            $recovered = self::recoverDemoQuotaWithOpenRouter($e, $payload);
            if (is_string($recovered)) {
                return $recovered;
            }
            // Soft-fail thinkingConfig 400 by returning empty for retry path.
            if (str_contains($e->getMessage(), 'Gemini HTTP 400')
                && !empty($payload['generationConfig']['thinkingConfig'])
            ) {
                return '';
            }
            throw $e;
        }

        $text = self::extractCandidateText($data);
        self::$aiProviderUsed = 'gemini';

        return $text;
    }

    /**
     * Local/direct Google path. After primary Gemini HTTP 429/quota, try gemini-3.8-flash once.
     * Success returns its text. Failure returns null so the existing OpenRouter fallback can run.
     *
     * @param array<string, mixed> $payload
     */
    private static function tryGeminiFallbackModelOnce(
        RuntimeException $e,
        array $payload,
        string $primaryModel,
        string $key
    ): ?string {
        if (!self::isGeminiQuotaError($e->getMessage())) {
            return null;
        }
        $fallback = self::GEMINI_FALLBACK_MODEL;
        if ($fallback === '' || $primaryModel === $fallback) {
            return null;
        }
        $url = sprintf(self::ENDPOINT, rawurlencode($fallback));
        $body = $payload;
        if (isset($body['generationConfig']) && is_array($body['generationConfig'])
            && array_key_exists('thinkingConfig', $body['generationConfig'])
        ) {
            $gen = $body['generationConfig'];
            unset($gen['thinkingConfig']);
            $body['generationConfig'] = $gen;
        }
        try {
            $data = self::httpPostJson($url, $body, [
                'x-goog-api-key: ' . $key,
            ]);
        } catch (RuntimeException) {
            return null;
        }
        try {
            $text = self::extractCandidateText($data);
        } catch (RuntimeException) {
            return null;
        }
        if ($text === '') {
            return null;
        }
        self::$aiProviderUsed = 'gemini';

        return $text;
    }

    /**
     * Demo-only. On Gemini HTTP 429 / quota, try OpenRouter once with the same payload.
     * A non-quota error returns null so the existing Gemini path continues.
     * A failed or unconfigured OpenRouter call rethrows the original Gemini error.
     *
     * @param array<string, mixed> $payload
     */
    private static function recoverDemoQuotaWithOpenRouter(RuntimeException $e, array $payload): ?string
    {
        if (self::$skipPhpOpenRouterQuotaFallback) {
            return null;
        }
        if (!self::isGeminiQuotaError($e->getMessage())) {
            return null;
        }
        self::$phpOpenRouterRecoverCallsForTest++;
        require_once dirname(__DIR__) . '/includes/openrouter_demo_fallback.php';
        $text = medconnect_demo_openrouter_quota_text(
            $e->getMessage(),
            $payload,
            self::$openRouterTransportForTest
        );
        if (is_string($text) && trim($text) !== '') {
            self::$aiProviderUsed = 'openrouter';
            return trim($text);
        }
        if (!self::$geminiQuotaProbe && !self::$openRouterQuotaProbe) {
            $groqText = medconnect_demo_groq_quota_text($e->getMessage(), $payload, null);
            if (is_string($groqText) && trim($groqText) !== '') {
                self::$aiProviderUsed = 'groq';
                return trim($groqText);
            }
            $bitsText = medconnect_demo_bits_quota_text($e->getMessage(), $payload, null);
            if (is_string($bitsText) && trim($bitsText) !== '') {
                self::$aiProviderUsed = 'bits';
                return trim($bitsText);
            }
        }
        throw $e;
    }

    /**
     * Production Hostinger should call Railway so Gemini keys stay on the Python service.
     */
    private static function shouldUseRailway(): bool
    {
        if (!defined('AI_SERVICE_ENABLED') || !AI_SERVICE_ENABLED) {
            return false;
        }
        if (!defined('AI_SERVICE_BASE_URL') || !is_string(AI_SERVICE_BASE_URL) || AI_SERVICE_BASE_URL === '') {
            return false;
        }
        $url = strtolower(AI_SERVICE_BASE_URL);
        if (str_contains($url, 'railway.app')) {
            return true;
        }

        return function_exists('medconnect_is_production_host') && medconnect_is_production_host();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function generateViaRailway(array $payload, string $model): string
    {
        if (!class_exists('AiServiceClient')) {
            throw new RuntimeException('ai client missing');
        }
        $data = AiServiceClient::geminiGenerateContent($payload, $model, 45);
        if (!is_array($data)) {
            $hint = '';
            if (method_exists('AiServiceClient', 'lastHttpError')) {
                $hint = trim((string) AiServiceClient::lastHttpError());
            }
            throw new RuntimeException(
                $hint !== ''
                    ? ('railway gemini failed: ' . mb_substr($hint, 0, 220))
                    : 'empty railway gemini reply'
            );
        }
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '' && isset($data['response']) && is_array($data['response'])) {
            $text = self::extractCandidateText($data['response']);
        }
        if ($text === '') {
            throw new RuntimeException('empty Gemini response');
        }

        self::noteAiProviderFromRailwayModel((string) ($data['model'] ?? ''));

        return $text;
    }

    /**
     * Railway already returns the model that produced the text.
     * A gemini* id is Gemini. Any other id is the quota fallback.
     */
    private static function noteAiProviderFromRailwayModel(string $model): void
    {
        $model = strtolower(trim($model));
        if ($model === '') {
            self::$aiProviderUsed = '';
            return;
        }
        if (str_starts_with($model, 'gemini')) {
            self::$aiProviderUsed = 'gemini';
            return;
        }
        if (
            str_contains($model, 'groq')
            || str_contains($model, 'gpt-oss')
            || str_starts_with($model, 'llama-')
        ) {
            self::$aiProviderUsed = 'groq';
            return;
        }
        if (
            str_contains($model, 'phi3')
            || str_contains($model, 'qwen')
            || str_contains($model, 'llava')
            || str_contains($model, 'bits')
        ) {
            self::$aiProviderUsed = 'bits';
            return;
        }
        self::$aiProviderUsed = 'openrouter';
    }

    private static function aiProviderUsedLabel(): string
    {
        return match (self::$aiProviderUsed) {
            'gemini' => 'Gemini Flash',
            'openrouter' => 'OpenRouter (Gemini quota fallback)',
            'groq' => 'Groq (Gemini quota fallback)',
            'bits' => 'BITS Ollama (Gemini quota fallback)',
            'nlp' => 'Question bank (Gemini quota fallback)',
            default => 'Unknown',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractCandidateText(array $data): string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $out = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (!is_array($part) || !isset($part['text'])) {
                    continue;
                }
                // Thought text is not the JSON schema. Concatenating it makes json_decode fail.
                if (!empty($part['thought'])) {
                    continue;
                }
                $out .= (string) $part['text'];
            }
        }
        $out = trim($out);
        if ($out === '') {
            $finish = strtoupper((string) ($data['candidates'][0]['finishReason'] ?? ''));
            throw new RuntimeException(
                $finish === 'MAX_TOKENS'
                    ? 'empty Gemini response (MAX_TOKENS)'
                    : 'empty Gemini response'
            );
        }

        return $out;
    }

    /**
     * Same SSL / CA handling as GeminiComplaintInputValidator (reuse AI_SSL_VERIFY).
     *
     * @param array<string, mixed> $payload
     * @param list<string> $extraHeaders
     * @return array<string, mixed>
     */
    private static function httpPostJson(string $url, array $payload, array $extraHeaders): array
    {
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        $verifySsl = true;
        $rawSsl = getenv('AI_SSL_VERIFY');
        if ($rawSsl === false || $rawSsl === '') {
            $rawSsl = $_ENV['AI_SSL_VERIFY'] ?? null;
        }
        if ($rawSsl !== null && $rawSsl !== '') {
            $verifySsl = !in_array(strtolower(trim((string) $rawSsl)), ['0', 'false', 'no', 'off'], true);
        }
        $timeout = self::TIMEOUT;
        $envTimeout = (int) (getenv('AI_TIMEOUT') ?: ($_ENV['AI_TIMEOUT'] ?? 0));
        if ($envTimeout > 0) {
            // AI_TIMEOUT is shared with short FAQ calls (often 20s). This interview
            // schema needs the longer demo budget; do not shrink below TIMEOUT.
            $timeout = max(self::TIMEOUT, min(60, $envTimeout));
        }
        if (self::$geminiQuotaProbe && str_contains($url, ':generateContent')) {
            self::$directGeminiAttempts++;
            if (
                self::$geminiFallbackSuccessTextForTest !== null
                && str_contains($url, self::GEMINI_FALLBACK_MODEL)
            ) {
                return [
                    'candidates' => [
                        ['content' => ['parts' => [['text' => self::$geminiFallbackSuccessTextForTest]]]],
                    ],
                ];
            }
            throw new RuntimeException('Gemini HTTP 429: You exceeded your current quota');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (!is_readable($ca)) {
            $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        }
        if ($ca !== '' && is_readable($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            throw new RuntimeException('Gemini HTTP error: ' . ($error !== '' ? $error : 'errno ' . $errno));
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || $code >= 400) {
            $detail = '';
            if (is_array($data)) {
                $detail = trim((string) ($data['error']['status'] ?? $data['error']['message'] ?? ''));
                $detail = preg_replace('/AQ\.[A-Za-z0-9_-]+/', '[KEY]', $detail) ?? $detail;
                $detail = preg_replace('/AIza[A-Za-z0-9_-]+/', '[KEY]', $detail) ?? $detail;
                $detail = mb_substr($detail, 0, 180);
            }
            throw new RuntimeException('Gemini HTTP ' . $code . ($detail !== '' ? ': ' . $detail : ''));
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseJson(string $raw, string $mode = 'answer'): ?array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $raw = trim($raw);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            return null;
        }

        if ($mode === 'start') {
            // Health gate is mandatory on start — missing/invalid classification fails closed.
            if (!array_key_exists('classification', $decoded)) {
                return null;
            }
            $gate = self::normalizeHealthGate($decoded);
            $decoded['classification'] = $gate['classification'];
            $decoded['confidence'] = $gate['confidence'];
            $decoded['normalized_health_concern'] = $gate['normalized_health_concern'];
            $decoded['patient_subject'] = $gate['patient_subject'];
            $decoded['is_human_patient_complaint'] = ($gate['patient_subject'] === 'HUMAN');
            $decoded['out_of_scope_non_human'] = !empty($gate['out_of_scope_non_human']);
            unset($decoded['reason']);

            if ($gate['classification'] !== self::CLASS_HEALTH || !empty($gate['out_of_scope_non_human'])) {
                // Non-health / unclear / non-human: interview fields optional; force no question.
                $decoded['clinical_facts'] = self::blankFacts();
                $decoded['question_needed'] = false;
                $decoded['interview_sufficient'] = false;
                $decoded['next_question'] = '';
                $decoded['missing_information'] = [];
                $decoded['answer_status'] = 'UNCLEAR';
                if (!empty($gate['out_of_scope_non_human'])) {
                    $decoded['classification'] = self::CLASS_NON_HEALTH;
                    $decoded['patient_subject'] = 'NON_HUMAN';
                    $decoded['is_human_patient_complaint'] = false;
                }

                return $decoded;
            }
        }

        // Interview schema (required for answer mode, and for HEALTH_RELATED start).
        if (!array_key_exists('question_needed', $decoded) && !array_key_exists('interview_sufficient', $decoded)) {
            return null;
        }
        if (!isset($decoded['clinical_facts']) || !is_array($decoded['clinical_facts'])) {
            $decoded['clinical_facts'] = self::blankFacts();
        }
        if (!isset($decoded['answer_status'])) {
            $decoded['answer_status'] = 'VALID';
        }
        if (!isset($decoded['next_question'])) {
            $decoded['next_question'] = '';
        }
        if (!isset($decoded['missing_information']) || !is_array($decoded['missing_information'])) {
            $decoded['missing_information'] = [];
        }
        $decoded['question_needed'] = !empty($decoded['question_needed']);
        $decoded['interview_sufficient'] = !empty($decoded['interview_sufficient']);
        if ($decoded['interview_sufficient']) {
            $decoded['question_needed'] = false;
        }

        return $decoded;
    }

    private static function geminiReady(): bool
    {
        self::ensureAiProviders();
        $enabled = true;
        if (function_exists('ai_providers_bool_env')) {
            $enabled = ai_providers_bool_env('AI_ENABLED', true);
        } else {
            $raw = getenv('AI_ENABLED');
            if ($raw !== false && $raw !== '') {
                $enabled = !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
            }
        }
        if (!$enabled) {
            return false;
        }
        $phpOnly = filter_var(getenv('MEDCONNECT_PHP_NLP_ONLY') ?: '0', FILTER_VALIDATE_BOOLEAN);
        if ($phpOnly) {
            return false;
        }
        if (self::bitsInterviewProviderSelected()) {
            if (!function_exists('medconnect_bits_service_enabled')) {
                require_once dirname(__DIR__) . '/includes/openrouter_demo_fallback.php';
            }

            return function_exists('medconnect_bits_service_enabled') && medconnect_bits_service_enabled();
        }
        $provider = strtolower(trim((string) (getenv('AI_PROVIDER') ?: ($_ENV['AI_PROVIDER'] ?? 'gemini'))));
        if ($provider !== '' && $provider !== 'gemini') {
            return false;
        }

        return self::apiKey() !== '' || self::shouldUseRailway();
    }

    /**
     * When AI_PROVIDER=bits, generate() uses campus Ollama with the Gemini interview payload.
     */
    private static function bitsInterviewProviderSelected(): bool
    {
        $provider = strtolower(trim((string) (getenv('AI_PROVIDER') ?: ($_ENV['AI_PROVIDER'] ?? 'gemini'))));

        return in_array($provider, ['bits', 'bits_ollama', 'ollama'], true);
    }

    private static function apiKey(): string
    {
        self::ensureGeminiConfig();

        return medconnect_gemini_api_key();
    }

    private static function ensureGeminiConfig(): void
    {
        if (function_exists('medconnect_gemini_api_key')) {
            return;
        }
        $path = dirname(__DIR__) . '/includes/gemini_config.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    private static function ensureAiProviders(): void
    {
        if (function_exists('ai_providers_http_post_json')) {
            return;
        }
        $path = dirname(__DIR__) . '/includes/ai_providers.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }
}
