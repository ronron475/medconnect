<?php
/**
 * Maps GeminiClinicalInterviewDemo packs onto the existing patient API/UI
 * payload. Does not run a separate interview: start/answer live in
 * GeminiClinicalInterviewDemo (same methods as the demo page).
 * Persist interview_context inside assessment_payload as gemini_led_context.
 * ClinicalTriageEngine remains the only acuity authority.
 */
final class GeminiPatientInterview
{
    public const CONTEXT_KEY = 'gemini_led_context';

    /** Persisted only for the galupot static fallback. Not a Gemini interview context. */
    public const GALUPOT_STATIC_KEY = 'galupot_static_fallback';

    /** @var array<string, mixed>|null */
    public static ?array $packOverrideForTest = null;

    /** @var list<string> */
    public static array $engineAssessCallsForTest = [];

    public static function resetTestHooks(): void
    {
        self::$packOverrideForTest = null;
        self::$engineAssessCallsForTest = [];
        if (class_exists('GeminiClinicalInterviewDemo')) {
            GeminiClinicalInterviewDemo::endSkipPhpOpenRouterQuotaFallback();
            GeminiClinicalInterviewDemo::endBitsProviderTransportForTest();
            GeminiClinicalInterviewDemo::$phpOpenRouterRecoverCallsForTest = 0;
        }
    }

    /**
     * Patient API/UI mapping only. Interview itself is GeminiClinicalInterviewDemo::start/answer.
     * $checkboxSymptoms are for last-resort PHP ClinicalInterviewEngine only — never Gemini input.
     *
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $demoPrior
     * @param list<string> $checkboxSymptoms
     * @return array<string, mixed>
     */
    public static function mapPack(array $pack, string $utterance, array $demoPrior = [], array $checkboxSymptoms = []): array
    {
        if (self::shouldStartGalupotStatic($utterance, $demoPrior, $pack)) {
            return self::startGalupotStatic(trim($utterance));
        }
        if (self::isTransportFailure($pack)) {
            return self::phpFallback(
                $utterance,
                self::enginePriorFromGeminiContext($demoPrior),
                $checkboxSymptoms
            );
        }
        if (self::isHealthReject($pack)) {
            return self::toNeedsValidComplaint($pack, $utterance);
        }

        return self::toPatientAssessment($pack);
    }

    /**
     * Continue the galupot static script after it has already been activated.
     * Returns null when this interview is not in that fallback, so the normal
     * Gemini / PHP path is unchanged.
     *
     * @param array<string, mixed> $priorContext
     * @return array<string, mixed>|null
     */
    public static function continueGalupotStaticIfActive(string $utterance, array $priorContext): ?array
    {
        $state = self::galupotStaticState($priorContext);
        if ($state === null || empty($state['active'])) {
            return null;
        }

        return self::advanceGalupotStatic($utterance, $state);
    }

    /**
     * Map persisted Gemini interview_context → ClinicalInterviewEngine priorContext.
     * Does not invent bank question IDs. Does not include the current failed POST utterance.
     *
     * @param array<string, mixed> $geminiContext
     * @return array<string, mixed>
     */
    public static function enginePriorFromGeminiContext(array $geminiContext): array
    {
        if ($geminiContext === []) {
            return [];
        }
        $facts = is_array($geminiContext['clinical_facts'] ?? null) ? $geminiContext['clinical_facts'] : [];
        $mappedFacts = GeminiClinicalInterviewDemo::mapFactsForEngine($facts);
        $turns = self::stringList($geminiContext['patient_turns'] ?? []);
        if ($turns === []) {
            foreach ((array) ($geminiContext['conversation'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strtolower((string) ($row['role'] ?? '')) !== 'patient') {
                    continue;
                }
                $text = trim((string) ($row['text'] ?? ''));
                if ($text !== '') {
                    $turns[] = $text;
                }
            }
        }
        $lang = strtolower(trim((string) ($geminiContext['question_language'] ?? $geminiContext['detected_language'] ?? 'english')));
        if ($lang === '') {
            $lang = 'english';
        }
        $awaitingText = trim((string) ($geminiContext['awaiting_question'] ?? ''));

        return [
            'chief_complaint' => (string) ($geminiContext['chief_complaint'] ?? ''),
            'detected_language' => (string) ($geminiContext['detected_language'] ?? $lang),
            'question_language' => $lang,
            'patient_turns' => $turns,
            'questions_asked' => [],
            'questions_answered' => [],
            'awaiting_question_id' => '',
            'last_followup_question' => $awaitingText === '' ? null : [
                'question_id' => '',
                'text' => $awaitingText,
                'language' => $lang,
            ],
            'chief_complaints' => is_array($facts['complaints'] ?? null) ? $facts['complaints'] : [],
            'facts' => $mappedFacts,
            'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
            'findings_asked' => [],
            'awaiting_target_finding' => '',
        ];
    }

    /**
     * @param array<string, mixed> $priorContext
     */
    public static function isTransportFailure(array $pack): bool
    {
        $code = (string) ($pack['code'] ?? '');
        if ($code === '' && is_array($pack['debug'] ?? null)) {
            $code = (string) ($pack['debug']['code'] ?? '');
        }
        if (in_array($code, ['gemini_quota_exceeded', 'gemini_unavailable_or_invalid_json'], true)) {
            return true;
        }
        $status = (string) ($pack['status'] ?? '');
        if ($status === GeminiClinicalInterviewDemo::STATUS_ERROR || !empty($pack['error'])) {
            return $code !== '' || empty($pack['needs_health_concern']);
        }

        return false;
    }

    /**
     * PHP ClinicalInterviewEngine only for PHP-only mode or an already-abandoned
     * PHP session. New consultations with empty prior use the demo start() path.
     *
     * @param array<string, mixed> $priorContext
     */
    public static function shouldUsePhpEngine(array $priorContext): bool
    {
        $raw = getenv('MEDCONNECT_PHP_NLP_ONLY');
        if ($raw !== false && in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        $interview = is_array($priorContext['interview'] ?? null) ? $priorContext['interview'] : [];
        if (!empty($priorContext['gemini_led_abandoned']) || !empty($interview['gemini_led_abandoned'])) {
            return true;
        }
        if (self::demoInterviewContext($priorContext) !== []) {
            return false;
        }
        $asked = $interview['questions_asked'] ?? $priorContext['questions_asked'] ?? [];
        if (is_array($asked) && $asked !== []) {
            return true;
        }

        return false;
    }

    /**
     * Exact demo interview_context the demo page posts as interview_context.
     *
     * @param array<string, mixed> $priorContext
     * @return array<string, mixed>
     */
    public static function demoInterviewContext(array $priorContext): array
    {
        $nestedInterview = is_array($priorContext['interview'] ?? null) ? $priorContext['interview'] : [];
        foreach ([
            $priorContext['interview_context'] ?? null,
            $priorContext[self::CONTEXT_KEY] ?? null,
            $nestedInterview['interview_context'] ?? null,
            $nestedInterview[self::CONTEXT_KEY] ?? null,
        ] as $raw) {
            if (!is_array($raw) || $raw === []) {
                continue;
            }
            if (trim((string) ($raw['chief_complaint'] ?? '')) !== ''
                || trim((string) ($raw['awaiting_question'] ?? '')) !== ''
                || self::stringList($raw['patient_turns'] ?? []) !== []
            ) {
                return $raw;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $pack
     */
    private static function isHealthReject(array $pack): bool
    {
        if (self::isTransportFailure($pack)) {
            return false;
        }

        return !empty($pack['needs_health_concern'])
            || (string) ($pack['status'] ?? '') === GeminiClinicalInterviewDemo::STATUS_NEEDS_HEALTH
            || !empty($pack['rejected']);
    }

    /**
     * @param list<string> $checkboxSymptoms
     * @param array<string, mixed> $mappedPrior
     * @return array<string, mixed>
     */
    private static function phpFallback(string $utterance, array $mappedPrior, array $checkboxSymptoms): array
    {
        self::$engineAssessCallsForTest[] = $utterance;
        $assessment = ClinicalInterviewEngine::assess($utterance, $mappedPrior, $checkboxSymptoms);
        if (!isset($assessment['interview']) || !is_array($assessment['interview'])) {
            $assessment['interview'] = [];
        }
        $assessment['interview']['gemini_led_abandoned'] = true;
        $assessment['gemini_led_abandoned'] = true;

        return $assessment;
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, mixed>
     */
    private static function toNeedsValidComplaint(array $pack, string $utterance): array
    {
        $gate = is_array($pack['health_gate'] ?? null) ? $pack['health_gate'] : [];
        $class = strtoupper((string) ($gate['classification'] ?? $pack['health_classification'] ?? ''));
        $isUnclear = $class === GeminiClinicalInterviewDemo::CLASS_UNCLEAR;
        $message = trim((string) ($pack['message'] ?? ''));
        if ($message === '') {
            $message = 'Please describe a health concern or symptom you are experiencing so we can continue.';
        }
        $domain = $isUnclear
            ? ComplaintSemanticValidator::DOMAIN_UNCLEAR
            : ComplaintSemanticValidator::DOMAIN_NON_HEALTH;
        $status = ClinicalInterviewEngine::STATUS_NEEDS_VALID_COMPLAINT;

        return [
            'assessment_status' => $status,
            'followup_required' => false,
            'followup_question' => null,
            'patient_message' => $message,
            'needs_valid_complaint' => !$isUnclear,
            'needs_clarification' => $isUnclear,
            'domain_label' => $domain,
            'domain_skipped' => true,
            'chief_complaint' => $utterance,
            'original_chief_complaint' => $utterance,
            'detected_symptoms' => [],
            'triage' => [
                'triage_classification' => '',
                'triage_display' => '',
                'triage_status' => 'NOT_READY',
                'assessment_status' => $status,
                'needs_valid_complaint' => !$isUnclear,
                'needs_clarification' => $isUnclear,
                'domain_label' => $domain,
                'domain_skipped' => true,
                'final_authority' => 'ClinicalTriageEngine',
            ],
            'interview' => [
                'assessment_status' => $status,
                'needs_valid_complaint' => !$isUnclear,
                'needs_clarification' => $isUnclear,
                'domain_label' => $domain,
                'domain_skipped' => true,
            ],
            'engine' => 'gemini-patient-interview-health-gate',
            'engine_version' => class_exists('MedicalAssessmentEngine') ? MedicalAssessmentEngine::VERSION : '',
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, mixed>
     */
    private static function toPatientAssessment(array $pack): array
    {
        $context = is_array($pack['interview_context'] ?? null) ? $pack['interview_context'] : [];
        $status = (string) ($pack['status'] ?? '');
        if ($status === GeminiClinicalInterviewDemo::STATUS_FINAL
            || is_array($pack['final_triage'] ?? null)
        ) {
            return self::toCompletedAssessment($pack, $context);
        }

        $q = trim((string) ($pack['awaiting_question'] ?? ''));
        $lang = strtolower(trim((string) ($context['question_language'] ?? $pack['question_language'] ?? 'english')));
        if ($lang === '') {
            $lang = 'english';
        }
        $retry = self::isRetryTurn($pack, $context);
        $question = [
            'question_id' => self::questionIdForUi($pack, $context, $q),
            'text' => $q,
            'language' => $lang,
        ];
        $facts = GeminiClinicalInterviewDemo::mapFactsForEngine(
            is_array($pack['clinical_facts'] ?? null) ? $pack['clinical_facts'] : []
        );
        $complaint = (string) ($pack['chief_complaint'] ?? $context['chief_complaint'] ?? '');
        $notice = trim((string) ($pack['message'] ?? ''));

        $assessment = [
            'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
            'followup_required' => true,
            'followup_question' => $question,
            'patient_message' => ($retry && $notice !== '') ? $notice : $q,
            'retry_current_question' => $retry,
            'answer_rejected' => $retry,
            'chief_complaint' => $complaint,
            'original_chief_complaint' => $complaint,
            'detected_language' => (string) ($context['detected_language'] ?? $lang),
            'question_language' => $lang,
            'detected_symptoms' => is_array($facts['symptoms'] ?? null) ? $facts['symptoms'] : [],
            'clinical_transcript' => implode('. ', self::stringList($context['patient_turns'] ?? [])),
            'triage' => [
                'triage_classification' => '',
                'triage_display' => '',
                'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
                'db_level' => 'pending',
                'urgency_label' => 'Assessment in progress',
                'final_authority' => 'ClinicalTriageEngine',
            ],
            'db_level' => 'pending',
            'urgency_label' => 'Assessment in progress',
            'engine' => 'gemini-patient-interview',
            'engine_version' => class_exists('MedicalAssessmentEngine') ? MedicalAssessmentEngine::VERSION : '',
        ];

        return self::attachGeminiContext($assessment, $context, $facts, $question, $lang);
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function toCompletedAssessment(array $pack, array $context): array
    {
        $final = is_array($pack['final_triage'] ?? null) ? $pack['final_triage'] : [];
        $display = strtoupper(str_replace('_', '-', (string) ($final['triage_display'] ?? 'NON-URGENT')));
        if (!in_array($display, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
            $display = 'NON-URGENT';
        }
        $classification = $display === 'NON-URGENT' ? 'NON_URGENT' : $display;
        $gis = match ($display) {
            'EMERGENCY' => 'emergency',
            'URGENT' => 'urgent',
            default => 'non_urgent',
        };
        $db = match ($display) {
            'EMERGENCY' => '1',
            'URGENT' => '2',
            default => '3',
        };
        $facts = GeminiClinicalInterviewDemo::mapFactsForEngine(
            is_array($pack['clinical_facts'] ?? null) ? $pack['clinical_facts'] : []
        );
        $complaint = (string) ($pack['chief_complaint'] ?? $context['chief_complaint'] ?? '');
        $transcript = implode('. ', self::stringList($context['patient_turns'] ?? []));
        if ($transcript === '') {
            $transcript = $complaint;
        }
        $action = self::recommendedActionForDisplay($display, (string) ($final['recommended_action'] ?? ''));
        $urgency = match ($display) {
            'EMERGENCY' => 'Emergency (Immediate)',
            'URGENT' => 'Urgent (Priority)',
            default => 'Non-Urgent (Routine)',
        };
        $confidenceScore = (int) ($final['confidence_score'] ?? 0);
        $assessment = [
            'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
            'followup_required' => false,
            'followup_question' => null,
            'patient_message' => class_exists('ClinicalInterviewEngine')
                ? ClinicalInterviewEngine::patientMessage($display)
                : $display,
            'chief_complaint' => $complaint,
            'original_chief_complaint' => $complaint,
            'english_translation' => $transcript,
            'detected_language' => (string) ($context['detected_language'] ?? ''),
            'detected_symptoms' => is_array($facts['symptoms'] ?? null) ? $facts['symptoms'] : [],
            'clinical_transcript' => $transcript,
            'recommended_action' => $action,
            'recommendations' => array_values(array_filter([$action])),
            'confidence' => [
                'score' => $confidenceScore,
                'level' => $confidenceScore >= 75 ? 'high' : ($confidenceScore >= 50 ? 'moderate' : 'review_needed'),
            ],
            'triage' => [
                'triage_display' => $display,
                'triage_classification' => (string) ($final['triage_classification'] ?? $classification),
                'triage_level' => (string) ($final['triage_level'] ?? ''),
                'gis_triage_level' => $gis,
                'db_level' => $db,
                'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
                'final_authority' => 'ClinicalTriageEngine',
                'reason' => (string) ($final['reason'] ?? ''),
                'recommended_action' => $action,
                'red_flags' => is_array($final['red_flags'] ?? null) ? $final['red_flags'] : [],
                'confidence_score' => $confidenceScore,
            ],
            'db_level' => $db,
            'urgency_label' => $urgency,
            'engine' => 'gemini-patient-interview',
            'engine_version' => class_exists('MedicalAssessmentEngine') ? MedicalAssessmentEngine::VERSION : '',
        ];

        return self::attachGeminiContext($assessment, $context, $facts, null, '');
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $context
     * @param array<string, mixed> $facts
     * @param array<string, mixed>|null $question
     * @return array<string, mixed>
     */
    private static function attachGeminiContext(
        array $assessment,
        array $context,
        array $facts,
        ?array $question,
        string $lang
    ): array {
        $interview = is_array($assessment['interview'] ?? null) ? $assessment['interview'] : [];
        $interview['assessment_status'] = $assessment['assessment_status'];
        $interview['chief_complaint'] = (string) ($assessment['chief_complaint'] ?? '');
        $interview['patient_turns'] = self::stringList($context['patient_turns'] ?? []);
        $interview['facts'] = $facts;
        $interview['question_language'] = $lang !== '' ? $lang : (string) ($context['question_language'] ?? '');
        $interview['detected_language'] = (string) ($context['detected_language'] ?? '');
        $interview['awaiting_question_id'] = '';
        $interview['last_followup_question'] = $question;
        $interview['next_question'] = $question;
        $interview['retry_current_question'] = !empty($assessment['retry_current_question']);
        $interview['answer_rejected'] = !empty($assessment['answer_rejected']);
        $interview[self::CONTEXT_KEY] = $context;
        $interview['interview_context'] = $context;
        $assessment['interview'] = $interview;
        $assessment[self::CONTEXT_KEY] = $context;
        $assessment['interview_context'] = $context;

        return $assessment;
    }

    /**
     * Existing patient UI chips key off question_id. Map Gemini slots onto those
     * ids only for display — do not treat them as PHP question-bank state.
     *
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $context
     */
    private static function questionIdForUi(array $pack, array $context, string $questionText): string
    {
        $targets = is_array($pack['awaiting_target_findings'] ?? null)
            ? $pack['awaiting_target_findings']
            : (is_array($context['awaiting_target_findings'] ?? null) ? $context['awaiting_target_findings'] : []);
        $slot = strtolower(trim((string) ($targets[0] ?? '')));
        if ($slot === '' && $questionText !== '') {
            $low = mb_strtolower($questionText);
            if (preg_match('/\b(1\s*(to|tubtob|hanggang|-|–)\s*10|pain\s*score|gaano\s*kasakit|how\s+(bad|severe))\b/u', $low)) {
                $slot = 'pain_score';
            } elseif (preg_match('/\b(fall|fell|injury|injured|accident|trauma|nahulog|nabunggo|nabungguan|naaksidente|pilas|samad)\b/u', $low)) {
                $slot = 'trauma';
            } elseif (preg_match('/\b(how\s+long|gaano\s+(na\s+)?katagal|duration|tagal)\b/u', $low)) {
                $slot = 'duration';
            } elseif (preg_match('/\b(when|san-o|kailan|nagsugod|nagsimula|onset)\b/u', $low)) {
                $slot = 'onset';
            } elseif (preg_match('/\b(where|diin|saan|location|which\s+part)\b/u', $low)) {
                $slot = 'location';
            }
        }

        return match ($slot) {
            'pain_score' => 'PAIN_SEVERITY',
            'duration' => 'DURATION',
            'onset' => 'ONSET',
            'location' => 'LOCATION',
            'laterality' => 'LATERALITY',
            'trauma' => 'TRAUMA',
            'frequency' => 'FREQUENCY',
            'associated_symptoms' => 'ASSOCIATED_SYMPTOMS',
            'associated_detail' => 'ASSOCIATED_DETAIL',
            default => (str_starts_with($slot, 'finding_') || str_starts_with($slot, 'FINDING_'))
                ? strtoupper($slot)
                : '',
        };
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $context
     */
    private static function isRetryTurn(array $pack, array $context): bool
    {
        $status = strtoupper((string) ($pack['last_answer_status'] ?? $context['last_answer_status'] ?? ''));
        if (in_array($status, ['UNCLEAR', 'UNRELATED'], true)) {
            return true;
        }
        $conv = is_array($pack['conversation'] ?? null)
            ? $pack['conversation']
            : (is_array($context['conversation'] ?? null) ? $context['conversation'] : []);
        if ($conv === []) {
            return false;
        }
        $last = end($conv);

        return is_array($last) && strtolower((string) ($last['kind'] ?? '')) === 'retry';
    }

    private static function recommendedActionForDisplay(string $display, string $fromEngine): string
    {
        $fromEngine = trim($fromEngine);
        if ($fromEngine !== '') {
            return $fromEngine;
        }

        return match ($display) {
            'EMERGENCY' => 'Seek emergency medical care immediately.',
            'URGENT' => 'Consult a healthcare provider within 24 hours.',
            default => 'Monitor symptoms and schedule a routine consultation if symptoms persist.',
        };
    }

    /**
     * Opening turn only, and only when Gemini Flash did not produce this pack.
     * Quota recoveries (OpenRouter, Groq, BITS, NLP question bank) and transport
     * errors are Gemini-unavailable for this one complaint. An interview Gemini
     * already started is never replaced.
     *
     * @param array<string, mixed> $demoPrior
     * @param array<string, mixed> $pack
     */
    private static function shouldStartGalupotStatic(string $utterance, array $demoPrior, array $pack): bool
    {
        if (!self::isExactGalupot($utterance) || self::geminiInterviewAlreadyStarted($demoPrior)) {
            return false;
        }
        if ((string) ($pack['ai_provider_used'] ?? '') === 'Gemini Flash' && !self::isTransportFailure($pack)) {
            return false;
        }

        return self::isTransportFailure($pack)
            || self::isUnassistedQuestionBank($pack)
            || self::isNonGeminiRecovery($pack);
    }

    /**
     * @param array<string, mixed> $pack
     */
    private static function isNonGeminiRecovery(array $pack): bool
    {
        return in_array((string) ($pack['ai_provider_used'] ?? ''), [
            'OpenRouter (Gemini quota fallback)',
            'Groq (Gemini quota fallback)',
            'BITS Ollama (Gemini quota fallback)',
        ], true);
    }

    /**
     * NLP question bank inside the demo class runs only after Gemini returns nothing.
     * A real Gemini / OpenRouter / Groq / BITS question is not this fallback.
     *
     * @param array<string, mixed> $pack
     */
    private static function isUnassistedQuestionBank(array $pack): bool
    {
        $used = (string) ($pack['ai_provider_used'] ?? '');
        if (in_array($used, [
            'Gemini Flash',
            'OpenRouter (Gemini quota fallback)',
            'Groq (Gemini quota fallback)',
            'BITS Ollama (Gemini quota fallback)',
        ], true)) {
            return false;
        }
        if ($used === 'Question bank (Gemini quota fallback)') {
            return true;
        }
        $context = is_array($pack['interview_context'] ?? null) ? $pack['interview_context'] : [];
        $note = (string) ($context['status_note'] ?? $pack['message'] ?? '');

        return str_contains($note, 'continuing with the NLP question bank');
    }

    /**
     * @param array<string, mixed> $demoPrior
     */
    private static function geminiInterviewAlreadyStarted(array $demoPrior): bool
    {
        if ($demoPrior === [] || self::galupotStaticState($demoPrior) !== null) {
            return false;
        }

        return trim((string) ($demoPrior['chief_complaint'] ?? '')) !== ''
            || trim((string) ($demoPrior['awaiting_question'] ?? '')) !== '';
    }

    private static function isExactGalupot(string $text): bool
    {
        return strcasecmp(trim($text), 'galupot') === 0;
    }

    /**
     * @param array<string, mixed> $priorContext
     * @return array<string, mixed>|null
     */
    private static function galupotStaticState(array $priorContext): ?array
    {
        $candidates = [
            $priorContext[self::GALUPOT_STATIC_KEY] ?? null,
            is_array($priorContext['interview'] ?? null) ? ($priorContext['interview'][self::GALUPOT_STATIC_KEY] ?? null) : null,
        ];
        foreach ($candidates as $raw) {
            if (!is_array($raw) || $raw === []) {
                continue;
            }
            if (!self::isExactGalupot((string) ($raw['original_complaint'] ?? ''))) {
                continue;
            }

            return $raw;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function startGalupotStatic(string $originalComplaint): array
    {
        $state = [
            'active' => true,
            'step' => 1,
            'original_complaint' => $originalComplaint,
            'interpretation' => self::galupotInterpretation($originalComplaint),
            'reason' => 'Gemini unavailable',
            'answers' => [],
        ];

        return self::galupotQuestionAssessment($state, 'Sano nagsugod?', false);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function advanceGalupotStatic(string $utterance, array $state): array
    {
        $answer = trim($utterance);
        $step = (int) ($state['step'] ?? 1);
        if ($step < 1) {
            $step = 1;
        }
        $question = $step >= 2 ? 'May iban ka pa nga ginabatyan?' : 'Sano nagsugod?';
        if ($answer === '') {
            return self::galupotQuestionAssessment($state, $question, true);
        }

        $answers = self::stringList($state['answers'] ?? []);
        $answers[] = $answer;
        $state['answers'] = $answers;
        if ($step < 2) {
            $state['step'] = 2;
            $state['active'] = true;

            return self::galupotQuestionAssessment($state, 'May iban ka pa nga ginabatyan?', false);
        }

        $state['step'] = 3;
        $state['active'] = false;

        return self::galupotCompletedAssessment($state);
    }

    private static function galupotInterpretation(string $complaint): string
    {
        if (class_exists('HiligaynonMedicalKnowledgeBase')) {
            try {
                $entry = HiligaynonMedicalKnowledgeBase::lookup($complaint);
                if (is_array($entry)) {
                    foreach (['normalized_symptom', 'english'] as $key) {
                        $value = trim((string) ($entry[$key] ?? ''));
                        if (strcasecmp($value, 'diarrhea') === 0) {
                            return 'diarrhea';
                        }
                    }
                }
            } catch (Throwable) {
                // The controlled fallback interpretation is still diarrhea.
            }
        }

        return 'diarrhea';
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function galupotQuestionAssessment(array $state, string $questionText, bool $retry): array
    {
        $complaint = (string) ($state['original_complaint'] ?? 'galupot');
        $interpretation = (string) ($state['interpretation'] ?? 'diarrhea');
        $question = [
            'question_id' => '',
            'text' => $questionText,
            'language' => 'hiligaynon',
        ];
        $assessment = [
            'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
            'followup_required' => true,
            'followup_question' => $question,
            'patient_message' => $retry
                ? 'Please answer the question above.'
                : $questionText,
            'retry_current_question' => $retry,
            'answer_rejected' => $retry,
            'chief_complaint' => $complaint,
            'original_chief_complaint' => $complaint,
            'english_translation' => $interpretation,
            'detected_language' => 'hiligaynon',
            'question_language' => 'hiligaynon',
            'detected_symptoms' => [$interpretation],
            'clinical_transcript' => self::galupotTranscript($complaint, $state),
            'triage' => [
                'triage_classification' => '',
                'triage_display' => '',
                'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
                'db_level' => 'pending',
                'urgency_label' => 'Assessment in progress',
                'final_authority' => 'galupot_static_fallback',
            ],
            'db_level' => 'pending',
            'urgency_label' => 'Assessment in progress',
            'engine' => 'galupot-static-fallback',
            'gemini_generated' => false,
            'static_fallback_used' => true,
            'static_fallback_reason' => (string) ($state['reason'] ?? 'Gemini unavailable'),
            self::GALUPOT_STATIC_KEY => $state,
        ];
        $assessment['interview'] = self::galupotInterviewBlock($assessment, $state, $question);

        return $assessment;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function galupotCompletedAssessment(array $state): array
    {
        $complaint = (string) ($state['original_complaint'] ?? 'galupot');
        $interpretation = (string) ($state['interpretation'] ?? 'diarrhea');
        $display = 'NON-URGENT';
        $action = class_exists('ClinicalInterviewEngine')
            ? ClinicalInterviewEngine::patientMessage($display)
            : $display;
        $reason = 'Static fallback used because Gemini was unavailable. Original complaint "'
            . $complaint
            . '" is interpreted as diarrhea. This is not a Gemini-generated result.';
        $assessment = [
            'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
            'followup_required' => false,
            'followup_question' => null,
            'patient_message' => $action,
            'chief_complaint' => $complaint,
            'original_chief_complaint' => $complaint,
            'english_translation' => $interpretation,
            'detected_language' => 'hiligaynon',
            'detected_symptoms' => [$interpretation],
            'clinical_transcript' => self::galupotTranscript($complaint, $state),
            'recommended_action' => $action,
            'recommendations' => [$action],
            'confidence' => [
                'score' => 0,
                'level' => 'review_needed',
            ],
            'triage' => [
                'triage_display' => $display,
                'triage_classification' => 'NON_URGENT',
                'triage_level' => 'LOW',
                'gis_triage_level' => 'non_urgent',
                'db_level' => '3',
                'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
                'final_authority' => 'galupot_static_fallback',
                'reason' => $reason,
                'recommended_action' => $action,
                'red_flags' => [],
                'confidence_score' => 0,
            ],
            'db_level' => '3',
            'urgency_label' => 'Non-Urgent (Routine)',
            'engine' => 'galupot-static-fallback',
            'gemini_generated' => false,
            'static_fallback_used' => true,
            'static_fallback_reason' => (string) ($state['reason'] ?? 'Gemini unavailable'),
            self::GALUPOT_STATIC_KEY => $state,
        ];
        $assessment['interview'] = self::galupotInterviewBlock($assessment, $state, null);

        return $assessment;
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $question
     * @return array<string, mixed>
     */
    private static function galupotInterviewBlock(array $assessment, array $state, ?array $question): array
    {
        $interpretation = (string) ($state['interpretation'] ?? 'diarrhea');

        return [
            'assessment_status' => $assessment['assessment_status'],
            'chief_complaint' => (string) ($assessment['chief_complaint'] ?? ''),
            'detected_language' => 'hiligaynon',
            'question_language' => 'hiligaynon',
            'normalized_complaints' => [[
                'id' => 'DIARRHEA',
                'name' => $interpretation,
                'family_key' => 'gastrointestinal',
            ]],
            'patient_turns' => self::stringList($state['answers'] ?? []),
            'last_followup_question' => $question,
            'next_question' => $question,
            'retry_current_question' => !empty($assessment['retry_current_question']),
            'answer_rejected' => !empty($assessment['answer_rejected']),
            self::GALUPOT_STATIC_KEY => $state,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function galupotTranscript(string $complaint, array $state): string
    {
        $parts = [$complaint];
        foreach (self::stringList($state['answers'] ?? []) as $answer) {
            $parts[] = $answer;
        }

        return implode('. ', $parts);
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
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }
}
