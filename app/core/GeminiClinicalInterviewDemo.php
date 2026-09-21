<?php
/**
 * DEMO ONLY — Gemini-driven clinical interview experiment.
 *
 * Gemini: understands complaint/answers, extracts structured facts, asks ONE next
 * follow-up until clinically sufficient.
 * Final acuity: ClinicalTriageEngine (+ WHO IITT / existing CDS rules) ONLY.
 *
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
    private const MAX_TURNS = 12;
    private const TIMEOUT = 45;

    /**
     * Start interview from a primary complaint.
     *
     * Gate: Gemini must classify HEALTH_RELATED before any clinical interview begins.
     * NON_HEALTH_RELATED / UNCLEAR / unavailable Gemini → reject (fail closed).
     *
     * @return array<string, mixed>
     */
    public static function start(string $complaint): array
    {
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
        $context['conversation'][] = [
            'role' => 'patient',
            'text' => $complaint,
            'kind' => 'complaint',
        ];

        // One Gemini start call: health gate + first interview question (only if HEALTH_RELATED).
        $gemini = self::callGemini('start', $context, '');
        if ($gemini === null) {
            $detail = self::$lastError !== '' ? (' (' . self::$lastError . ')') : '';

            // Fail closed: never start interview when gate/parser fails.
            return self::rejectNonHealth(
                $context,
                self::CLASS_UNCLEAR,
                'Gemini unavailable or returned invalid JSON. Interview not started.' . $detail,
                [
                    'raw_error' => self::$lastError,
                    'health_gate' => [
                        'classification' => self::CLASS_UNCLEAR,
                        'confidence' => null,
                        'reason' => 'gemini_unavailable_or_invalid_json',
                        'normalized_health_concern' => '',
                        'passed' => false,
                    ],
                ]
            );
        }

        $gate = self::normalizeHealthGate($gemini);
        $context['health_gate'] = $gate;
        $context['gemini_called'] = true;

        if (($gate['classification'] ?? '') !== self::CLASS_HEALTH) {
            $class = (string) ($gate['classification'] ?? self::CLASS_UNCLEAR);
            $msg = $class === self::CLASS_NON_HEALTH
                ? 'That does not look like a health concern. Please describe a symptom or medical problem you are experiencing (any language is OK).'
                : 'Please describe your health concern more clearly so we can continue (symptoms, pain, fever, etc.).';

            return self::rejectNonHealth($context, $class, $msg, [
                'health_gate' => $gate,
                'gemini_raw_structured' => $gemini,
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

        $gemini = self::callGemini('answer', $context, $answer);
        if ($gemini === null) {
            // Do not invent facts; keep prior facts and surface error.
            array_pop($context['patient_turns']);
            array_pop($context['conversation']);
            $detail = self::$lastError !== '' ? (' (' . self::$lastError . ')') : '';

            return self::errorResult($context, 'Gemini unavailable or returned invalid JSON for this answer. No facts were updated.' . $detail, [
                'raw_error' => self::$lastError,
            ]);
        }

        return self::applyGeminiTurn($context, $gemini, false);
    }

    private static string $lastError = '';

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
        $status = strtoupper((string) ($gemini['answer_status'] ?? 'VALID'));
        if (!in_array($status, ['VALID', 'UNCERTAIN', 'UNCLEAR', 'UNRELATED'], true)) {
            $status = 'VALID';
        }

        // Merge clinical facts (never replace original wording).
        $incoming = is_array($gemini['clinical_facts'] ?? null) ? $gemini['clinical_facts'] : [];
        $context['clinical_facts'] = self::mergeFacts(
            is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts(),
            $incoming
        );
        $context['missing_information'] = self::stringList($gemini['missing_information'] ?? []);
        $context['last_gemini'] = $gemini;
        $context['last_answer_status'] = $status;
        $context['gemini_called'] = true;
        $context['turn_count'] = (int) ($context['turn_count'] ?? 0) + ($isStart ? 0 : 1);

        // Unrelated / unclear: retry same question; do not advance.
        if (!$isStart && in_array($status, ['UNCLEAR', 'UNRELATED'], true)) {
            $q = trim((string) ($context['awaiting_question'] ?? ''));
            $retry = trim((string) ($gemini['next_question'] ?? ''));
            if ($retry !== '') {
                $q = $retry;
            }
            if ($q === '') {
                $q = 'Please answer the previous clinical question in your own words.';
            }
            $context['awaiting_question'] = $q;
            $context['status'] = self::STATUS_INTERVIEWING;
            $context['conversation'][] = [
                'role' => 'gemini',
                'text' => $q,
                'kind' => 'retry',
            ];
            $context['status_note'] = 'Answer was ' . $status . '; asking again. Negative/uncertain replies can still be valid.';

            return self::pack($context, $context['status_note'], $gemini);
        }

        $sufficient = !empty($gemini['interview_sufficient']) || empty($gemini['question_needed']);
        $nextQ = trim((string) ($gemini['next_question'] ?? ''));

        // Pain rule: if pain indicated and no valid score yet, force severity question locally
        // when Gemini forgot (still one question; does not invent score).
        if (!$sufficient && self::needsPainScore($context['clinical_facts'], $context)) {
            $hasPainQ = $nextQ !== '' && (bool) preg_match('/\b(1\s*(to|tubtob|-|–)\s*10|1\-10|pain\s*score|gaano\s*kasakit|pila\s*ka\s*grabe)\b/ui', $nextQ);
            if (!$hasPainQ) {
                $lang = self::detectLanguageHint($context);
                $nextQ = match ($lang) {
                    'hiligaynon' => 'Pila ka grabe ang imo kasakit, halin 1 tubtob 10?',
                    'tagalog' => 'Gaano kasakit ito, mula 1 hanggang 10?',
                    default => 'On a scale of 1 to 10, how severe is your pain?',
                };
                $gemini['next_question'] = $nextQ;
                $gemini['question_needed'] = true;
                $gemini['interview_sufficient'] = false;
                $sufficient = false;
                if (!in_array('pain_score', $context['missing_information'], true)) {
                    $context['missing_information'][] = 'pain_score';
                }
            }
        }

        if ($sufficient || $nextQ === '') {
            $context['awaiting_question'] = '';
            $context['status'] = self::STATUS_SUFFICIENT;

            return self::finalizeWithClinicalEngine($context, $gemini);
        }

        $context['awaiting_question'] = $nextQ;
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
        $facts = is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts();
        $mapped = self::mapFactsForEngine($facts);
        $patientEvidence = self::patientEvidenceText($context);
        $factsText = class_exists('ClinicalInterviewEngine')
            ? ClinicalInterviewEngine::factsHaystack($mapped)
            : self::simpleFactsHaystack($mapped);

        $caseParts = array_filter([
            trim((string) ($context['chief_complaint'] ?? '')),
            $patientEvidence,
            $factsText,
        ], static fn (string $p): bool => $p !== '');
        $caseText = trim(implode('. ', $caseParts));

        $interviewFacts = [
            'active_complaint_id' => 'gemini_demo_c1',
            'active_facts' => $mapped,
            'tracks' => [],
            'patient_evidence_text' => $patientEvidence !== '' ? $patientEvidence : (string) ($context['chief_complaint'] ?? ''),
        ];

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
            unset($gemini['triage'], $gemini['urgency'], $gemini['triage_level'], $gemini['diagnosis']);
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
            'conversation' => is_array($context['conversation'] ?? null) ? $context['conversation'] : [],
            'clinical_facts' => is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts(),
            'missing_information' => is_array($context['missing_information'] ?? null) ? $context['missing_information'] : [],
            'last_answer_status' => (string) ($context['last_answer_status'] ?? ''),
            'final_triage' => is_array($context['final_triage'] ?? null) ? $context['final_triage'] : null,
            'interview_context' => $context,
            'gemini_called' => !empty($context['gemini_called']),
            'health_gate' => is_array($context['health_gate'] ?? null) ? $context['health_gate'] : null,
            'debug' => [
                'gemini_raw_structured' => $debugGemini,
                'health_gate' => is_array($context['health_gate'] ?? null) ? $context['health_gate'] : null,
                'engine_case_text' => (string) ($context['engine_case_text'] ?? ''),
                'engine_mapped_facts' => $context['engine_mapped_facts'] ?? null,
                'engine_result' => $context['engine_result'] ?? null,
                'pipeline' => 'Gemini health gate → Gemini interview → collected facts → ClinicalTriageEngine → final triage',
                'gemini_must_not' => [
                    'set_final_triage',
                    'diagnose',
                    'prescribe',
                    'bypass_who_iitt',
                    'start_interview_on_non_health',
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
                'reason' => '',
                'normalized_health_concern' => '',
                'passed' => false,
            ];
        $gate['passed'] = false;
        $context['health_gate'] = $gate;
        $context['conversation'][] = [
            'role' => 'system',
            'text' => $message,
            'kind' => 'health_gate_reject',
        ];

        $pack = self::pack($context, $message, is_array($extra['gemini_raw_structured'] ?? null) ? $extra['gemini_raw_structured'] : null);
        $pack['error'] = true;
        $pack['rejected'] = true;
        $pack['needs_health_concern'] = true;
        $pack['health_classification'] = $classification;
        // Do not leave a half-started interview context for the client.
        $pack['interview_context'] = [];
        $pack['awaiting_question'] = '';
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
     * @param array<string, mixed> $gemini
     * @return array{classification:string,confidence:float|null,reason:string,normalized_health_concern:string,passed:bool}
     */
    private static function normalizeHealthGate(array $gemini): array
    {
        $class = strtoupper(trim((string) ($gemini['classification'] ?? '')));
        $class = str_replace([' ', '-'], '_', $class);
        if (in_array($class, ['HEALTH', 'HEALTH_RELATED', 'MEDICAL', 'VALID_MEDICAL', 'VALID_MEDICAL_COMPLAINT'], true)) {
            $class = self::CLASS_HEALTH;
        } elseif (in_array($class, ['NON_HEALTH', 'NON_HEALTH_RELATED', 'NOT_HEALTH', 'OUT_OF_SCOPE', 'GREETING', 'PRANK', 'NONSENSE'], true)) {
            $class = self::CLASS_NON_HEALTH;
        } elseif (in_array($class, ['UNCLEAR', 'AMBIGUOUS', 'UNKNOWN'], true)) {
            $class = self::CLASS_UNCLEAR;
        } else {
            // Unknown label → fail closed (do not start interview).
            $class = self::CLASS_UNCLEAR;
        }

        $confidence = null;
        if (isset($gemini['confidence']) && is_numeric($gemini['confidence'])) {
            $confidence = (float) $gemini['confidence'];
            if ($confidence > 1.0 && $confidence <= 100.0) {
                $confidence /= 100.0;
            }
            $confidence = max(0.0, min(1.0, $confidence));
        }

        return [
            'classification' => $class,
            'confidence' => $confidence,
            'reason' => trim((string) ($gemini['reason'] ?? '')),
            'normalized_health_concern' => trim((string) ($gemini['normalized_health_concern'] ?? '')),
            'passed' => $class === self::CLASS_HEALTH,
        ];
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
            'clinical_facts' => self::blankFacts(),
            'missing_information' => [],
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
            if ($val === null || $val === '') {
                continue;
            }
            $base[$key] = is_scalar($val) ? trim((string) $val) : $base[$key];
        }

        if (array_key_exists('pain_score', $incoming) && $incoming['pain_score'] !== null && $incoming['pain_score'] !== '') {
            if (is_numeric($incoming['pain_score'])) {
                $score = (int) $incoming['pain_score'];
                if ($score >= 1 && $score <= 10) {
                    $base['pain_score'] = $score;
                }
            }
        }

        foreach (['associated_symptoms', 'relevant_negatives', 'warning_signs', 'notes'] as $listKey) {
            $cur = self::stringList($base[$listKey] ?? []);
            $add = self::stringList($incoming[$listKey] ?? []);
            foreach ($add as $item) {
                if ($item !== '' && !in_array($item, $cur, true)) {
                    $cur[] = $item;
                }
            }
            $base[$listKey] = $cur;
        }

        return $base;
    }

    /**
     * Map demo facts → ClinicalInterviewEngine / ClinicalTriageEngine fact shape.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function mapFactsForEngine(array $facts): array
    {
        $mapped = class_exists('ClinicalInterviewEngine')
            ? ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts']
            : [];

        $symptoms = [];
        $symptom = trim((string) ($facts['symptom'] ?? ''));
        if ($symptom !== '') {
            $symptoms[] = $symptom;
        }
        foreach (self::stringList($facts['associated_symptoms'] ?? []) as $s) {
            if ($s !== '' && !in_array($s, $symptoms, true)) {
                $symptoms[] = $s;
            }
        }
        $mapped['symptoms'] = $symptoms;
        $mapped['associated_symptoms'] = self::stringList($facts['associated_symptoms'] ?? []);

        $locs = [];
        $loc = trim((string) ($facts['location'] ?? ''));
        if ($loc !== '') {
            $locs[] = $loc;
        }
        $side = trim((string) ($facts['laterality'] ?? ''));
        if ($side !== '') {
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
            if ($bare !== '' && !in_array($bare, $negatives, true)) {
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
    private static function needsPainScore(array $facts, array $context): bool
    {
        if (($facts['pain_score'] ?? null) !== null && (int) $facts['pain_score'] >= 1 && (int) $facts['pain_score'] <= 10) {
            return false;
        }
        $hay = mb_strtolower(
            trim((string) ($context['chief_complaint'] ?? '')) . ' '
            . trim((string) ($facts['symptom'] ?? '')) . ' '
            . implode(' ', self::stringList($context['patient_turns'] ?? []))
        );

        return (bool) preg_match(
            '/\b(sakit|masakit|pain|hapdi|kasakit|gasakit|hurts?|sumasakit)\b/u',
            $hay
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function detectLanguageHint(array $context): string
    {
        $text = trim((string) ($context['chief_complaint'] ?? ''));
        $last = '';
        $turns = is_array($context['patient_turns'] ?? null) ? $context['patient_turns'] : [];
        if ($turns !== []) {
            $last = trim((string) end($turns));
        }
        $probe = trim($last . ' ' . $text);
        if (class_exists('HiligaynonLanguageDetector')) {
            try {
                $primary = strtolower((string) (HiligaynonLanguageDetector::detect($probe)['primary'] ?? 'english'));
                if (in_array($primary, ['hiligaynon', 'ilonggo'], true)) {
                    return 'hiligaynon';
                }
                if (in_array($primary, ['tagalog', 'filipino'], true)) {
                    return 'tagalog';
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return 'english';
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
            // Never trust Gemini triage/diagnosis fields.
            unset(
                $parsed['triage'],
                $parsed['triage_display'],
                $parsed['triage_level'],
                $parsed['urgency'],
                $parsed['diagnosis'],
                $parsed['prescription'],
                $parsed['treatment']
            );

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

        if ($mode === 'start') {
            return "MODE: START_INTERVIEW\n"
                . "First classify whether the patient's opening text is a genuine health/medical concern.\n"
                . "Supported languages: English, Hiligaynon/Ilonggo, Tagalog, mixed, informal, misspelled, abbreviated, local expressions.\n"
                . "Latin/ASCII characters do NOT mean the text is English — local languages often use Latin script.\n"
                . "Short but medical phrases (pain, fever, cough, diarrhea, dizziness, difficulty breathing, sakit, hilanat, galupot, etc.) can be HEALTH_RELATED.\n"
                . "Greetings, weather, sports, jokes, politics, tech questions, spam, nonsense → NON_HEALTH_RELATED or UNCLEAR.\n\n"
                . "Original patient opening (preserve exactly; do not rewrite as the complaint):\n"
                . (string) ($context['chief_complaint'] ?? '') . "\n\n"
                . "Return JSON with classification fields ALWAYS, plus interview fields ONLY when classification is HEALTH_RELATED.\n"
                . "If NON_HEALTH_RELATED or UNCLEAR: set question_needed=false, interview_sufficient=false, next_question=\"\", clinical_facts empty.\n"
                . "If HEALTH_RELATED: ask at most ONE next_question in the patient's language.";
        }

        return "MODE: INTERPRET_ANSWER\n"
            . "Original patient complaint (preserve exactly):\n"
            . (string) ($context['chief_complaint'] ?? '') . "\n\n"
            . "Current question the patient was answering:\n"
            . (string) ($context['awaiting_question'] ?? '') . "\n\n"
            . "Latest patient answer (preserve exactly):\n"
            . $latestAnswer . "\n\n"
            . "Full conversation so far:\n" . $conversation . "\n\n"
            . "Already collected clinical_facts (JSON):\n" . $factsJson . "\n\n"
            . "Previously listed missing_information (JSON):\n" . $missingJson . "\n\n"
            . "Return the required JSON. Update clinical_facts with anything newly learned "
            . "(including negatives like wala/indi). Ask at most ONE next_question if still needed.";
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a clinical interview assistant for a MedConnect DEMO page.

Health gate (START_INTERVIEW only):
Classify the opening patient text as exactly one of:
- HEALTH_RELATED — genuine symptom/medical/health concern in ANY language (English, Hiligaynon/Ilonggo, Tagalog, mixed). Informal, misspelled, abbreviated, or local expressions count when reasonably medical.
- NON_HEALTH_RELATED — greetings, casual chat, weather, sports, school, tech, politics, entertainment, jokes, spam, profanity without a health concern, unrelated questions.
- UNCLEAR — empty of meaning, random/nonsense, or too ambiguous to treat as a health concern.

Do NOT reject Hiligaynon/Tagalog/local medical phrases merely because they use Latin characters.
Do NOT rely on English keyword lists only — decide semantically.

Your clinical interview jobs (only after HEALTH_RELATED):
1) Understand the patient's complaint and answers (English, Hiligaynon/Ilonggo, Tagalog, or mixed).
2) Extract structured clinical facts.
3) Ask exactly ONE relevant follow-up question when information is still missing for safe triage.
4) Stop when clinically sufficient for triage (interview_sufficient=true, question_needed=false).

You MUST NOT:
- Assign EMERGENCY, URGENT, or NON-URGENT
- Diagnose disease
- Prescribe treatment
- Invent facts the patient did not say
- Replace the patient's original wording
- Start a clinical interview for NON_HEALTH_RELATED or UNCLEAR

Pain rule: if pain/sakit/kasakit/hapdi is present, collect pain_score 1–10 before finishing, and do not ask for pain score again after a valid score.

Short answers such as yes, no, oo, indi, wala, wala man, not sure, indi ko sure, depende can be VALID, UNCERTAIN, or carry negative findings — do not mark them UNRELATED just because they are short.

answer_status values (interview turns):
- VALID: answers the question usefully (including clear yes/no/negative)
- UNCERTAIN: patient unsure
- UNCLEAR: unreadable / nonsense
- UNRELATED: clearly does not address the clinical question

Respond with JSON ONLY matching this schema:
{
  "classification": "HEALTH_RELATED|NON_HEALTH_RELATED|UNCLEAR",
  "confidence": 0.0,
  "reason": "short explanation",
  "normalized_health_concern": "short semantic representation or empty",
  "answer_status": "VALID|UNCERTAIN|UNCLEAR|UNRELATED",
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
    "notes": string[]
  },
  "missing_information": string[],
  "question_needed": boolean,
  "next_question": string,
  "interview_sufficient": boolean
}

For START_INTERVIEW:
- Always include classification/confidence/reason/normalized_health_concern.
- Only when classification is HEALTH_RELATED: set question_needed true (unless already sufficient) and provide next_question in the patient's language.
- When NON_HEALTH_RELATED or UNCLEAR: question_needed=false, interview_sufficient=false, next_question="", empty clinical_facts.

For INTERPRET_ANSWER: classification may be omitted or HEALTH_RELATED; focus on answer_status and clinical_facts.
PROMPT;
    }

    private static function complete(string $userPrompt): string
    {
        self::ensureAiProviders();
        $payload = self::requestPayload($userPrompt, true);
        $res = self::generate($payload);
        if ($res !== '') {
            return $res;
        }
        // Retry without thinkingConfig (some models reject it).
        return self::generate(self::requestPayload($userPrompt, false));
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestPayload(string $userPrompt, bool $withThinkingConfig): array
    {
        $config = [
            'temperature' => 0.2,
            'maxOutputTokens' => 1024,
            'responseMimeType' => 'application/json',
        ];
        if ($withThinkingConfig) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
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
        $model = 'gemini-3.5-flash';
        if (function_exists('ai_providers_gemini_model')) {
            self::ensureAiProviders();
            $model = ai_providers_gemini_model();
        } else {
            $envModel = trim((string) (getenv('AI_MODEL') ?: ($_ENV['AI_MODEL'] ?? '')));
            if ($envModel !== '' && str_starts_with(strtolower($envModel), 'gemini')) {
                $model = $envModel;
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
            // Soft-fail thinkingConfig 400 by returning empty for retry path.
            if (str_contains($e->getMessage(), 'Gemini HTTP 400')
                && !empty($payload['generationConfig']['thinkingConfig'])
            ) {
                return '';
            }
            throw $e;
        }
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $out = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $out .= (string) $part['text'];
                }
            }
        }
        $out = trim($out);
        if ($out === '') {
            throw new RuntimeException('empty Gemini response');
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
            $timeout = max(5, min(60, $envTimeout));
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
            throw new RuntimeException('Gemini HTTP ' . $code);
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
            $decoded['reason'] = $gate['reason'];
            $decoded['normalized_health_concern'] = $gate['normalized_health_concern'];

            if ($gate['classification'] !== self::CLASS_HEALTH) {
                // Non-health / unclear: interview fields optional; force no question.
                if (!isset($decoded['clinical_facts']) || !is_array($decoded['clinical_facts'])) {
                    $decoded['clinical_facts'] = self::blankFacts();
                }
                $decoded['question_needed'] = false;
                $decoded['interview_sufficient'] = false;
                $decoded['next_question'] = '';
                $decoded['missing_information'] = [];
                $decoded['answer_status'] = 'UNCLEAR';

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
        $provider = strtolower(trim((string) (getenv('AI_PROVIDER') ?: ($_ENV['AI_PROVIDER'] ?? 'gemini'))));
        if ($provider !== '' && $provider !== 'gemini') {
            return false;
        }

        return self::apiKey() !== '';
    }

    private static function apiKey(): string
    {
        foreach (['AI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $envKey) {
            $val = trim((string) (getenv($envKey) ?: ($_ENV[$envKey] ?? '')));
            if ($val !== '') {
                return $val;
            }
        }

        return '';
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
