<?php
/**
 * Logged-in patient Gemini-led interview adapter.
 *
 * Uses GeminiClinicalInterviewDemo::start/answer (Railway 3.5 → 3.8 → nemotron).
 * Does not use the demo browser/API or the Hostinger PHP gemma OpenRouter hop.
 * Persist gemini_led_context inside assessment_payload.
 * AI transport failure → ClinicalInterviewEngine::assess($currentUtterance, mappedPrior).
 * ClinicalTriageEngine remains the only acuity authority.
 */
final class GeminiPatientInterview
{
    public const CONTEXT_KEY = 'gemini_led_context';

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
            GeminiClinicalInterviewDemo::$phpOpenRouterRecoverCallsForTest = 0;
        }
    }

    /**
     * @param list<string> $checkboxSymptoms
     * @param array<string, mixed> $priorContext
     * @return array<string, mixed>|null Null = caller should use ClinicalInterviewEngine unchanged.
     */
    public static function assess(string $utterance, array $priorContext = [], array $checkboxSymptoms = []): ?array
    {
        if (self::shouldUsePhpEngine($priorContext)) {
            return null;
        }
        if (!class_exists('GeminiClinicalInterviewDemo')) {
            return null;
        }

        $priorGemini = self::extractGeminiContext($priorContext);
        $isContinue = $priorGemini !== [];
        $pack = self::$packOverrideForTest;
        if (!is_array($pack)) {
            GeminiClinicalInterviewDemo::beginSkipPhpOpenRouterQuotaFallback();
            try {
                $pack = $isContinue
                    ? GeminiClinicalInterviewDemo::answer($utterance, $priorGemini)
                    : GeminiClinicalInterviewDemo::start($utterance);
            } finally {
                GeminiClinicalInterviewDemo::endSkipPhpOpenRouterQuotaFallback();
            }
        }

        if (self::isTransportFailure($pack)) {
            $mapped = self::enginePriorFromGeminiContext($isContinue ? $priorGemini : []);

            return self::phpFallback($utterance, $mapped, $checkboxSymptoms);
        }
        if (self::isHealthReject($pack)) {
            return self::toNeedsValidComplaint($pack, $utterance);
        }

        return self::toPatientAssessment($pack);
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
     * @param array<string, mixed> $priorContext
     */
    private static function shouldUsePhpEngine(array $priorContext): bool
    {
        $raw = getenv('MEDCONNECT_PHP_NLP_ONLY');
        if ($raw !== false && in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        $interview = is_array($priorContext['interview'] ?? null) ? $priorContext['interview'] : [];
        if (!empty($priorContext['gemini_led_abandoned']) || !empty($interview['gemini_led_abandoned'])) {
            return true;
        }
        if (self::extractGeminiContext($priorContext) !== []) {
            return false;
        }
        $asked = $interview['questions_asked'] ?? $priorContext['questions_asked'] ?? [];
        if (is_array($asked) && $asked !== []) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $priorContext
     * @return array<string, mixed>
     */
    private static function extractGeminiContext(array $priorContext): array
    {
        foreach ([
            $priorContext[self::CONTEXT_KEY] ?? null,
            is_array($priorContext['interview'] ?? null) ? ($priorContext['interview'][self::CONTEXT_KEY] ?? null) : null,
            $priorContext['interview_context'] ?? null,
        ] as $raw) {
            if (is_array($raw) && trim((string) ($raw['chief_complaint'] ?? '')) !== '') {
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
        $question = [
            'question_id' => '',
            'text' => $q,
            'language' => $lang,
        ];
        $facts = GeminiClinicalInterviewDemo::mapFactsForEngine(
            is_array($pack['clinical_facts'] ?? null) ? $pack['clinical_facts'] : []
        );
        $complaint = (string) ($pack['chief_complaint'] ?? $context['chief_complaint'] ?? '');

        $assessment = [
            'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
            'followup_required' => true,
            'followup_question' => $question,
            'patient_message' => $q,
            'chief_complaint' => $complaint,
            'original_chief_complaint' => $complaint,
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
        $assessment = [
            'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
            'followup_required' => false,
            'followup_question' => null,
            'patient_message' => class_exists('ClinicalInterviewEngine')
                ? ClinicalInterviewEngine::patientMessage($display)
                : $display,
            'chief_complaint' => $complaint,
            'original_chief_complaint' => $complaint,
            'detected_symptoms' => is_array($facts['symptoms'] ?? null) ? $facts['symptoms'] : [],
            'triage' => [
                'triage_display' => $display,
                'triage_classification' => (string) ($final['triage_classification'] ?? $classification),
                'triage_level' => (string) ($final['triage_level'] ?? ''),
                'gis_triage_level' => $gis,
                'db_level' => $db,
                'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
                'final_authority' => 'ClinicalTriageEngine',
                'reason' => (string) ($final['reason'] ?? ''),
                'recommended_action' => (string) ($final['recommended_action'] ?? ''),
                'red_flags' => is_array($final['red_flags'] ?? null) ? $final['red_flags'] : [],
            ],
            'db_level' => $db,
            'urgency_label' => $display,
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
        $interview[self::CONTEXT_KEY] = $context;
        $assessment['interview'] = $interview;
        $assessment[self::CONTEXT_KEY] = $context;

        return $assessment;
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
