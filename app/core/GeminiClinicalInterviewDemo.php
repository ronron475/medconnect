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
    private const MAX_TURNS = 12;
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
            $detail = self::$lastError !== '' ? (' (' . self::$lastError . ')') : '';

            // NLP already confirmed health: still cannot interview without Gemini questions — fail closed.
            return self::rejectNonHealth(
                $context,
                self::CLASS_UNCLEAR,
                'Gemini unavailable or returned invalid JSON. Interview not started.' . $detail,
                [
                    'raw_error' => self::$lastError,
                    'nlp_precheck' => $nlpPrecheck,
                    'health_gate' => [
                        'classification' => self::CLASS_UNCLEAR,
                        'confidence' => null,
                        'normalized_health_concern' => '',
                        'passed' => false,
                        'error' => 'gemini_unavailable_or_invalid_json',
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
        // NLP-first grounding: drop invented / non-clinical labels before merge.
        $gemini = self::groundGeminiAgainstEvidence($gemini, $context, $isStart);
        $context['nlp_grounding'] = is_array($gemini['_nlp_grounding'] ?? null) ? $gemini['_nlp_grounding'] : null;
        unset($gemini['_nlp_grounding']);

        $status = strtoupper((string) ($gemini['answer_status'] ?? 'VALID'));
        if (!in_array($status, ['VALID', 'UNCERTAIN', 'UNCLEAR', 'UNRELATED'], true)) {
            $status = 'VALID';
        }

        // Short contextual replies (yes/no/negation/particles) are valid answers — do not retry.
        $latestPatient = '';
        $turns = is_array($context['patient_turns'] ?? null) ? $context['patient_turns'] : [];
        if ($turns !== []) {
            $latestPatient = trim((string) end($turns));
        }
        if (!$isStart && in_array($status, ['UNCLEAR', 'UNRELATED'], true)
            && self::isContextualShortReply($latestPatient)
        ) {
            $status = 'VALID';
            $gemini['answer_status'] = 'VALID';
        }

        // Merge clinical facts (never replace original wording); scrub discourse particles.
        $incoming = is_array($gemini['clinical_facts'] ?? null) ? $gemini['clinical_facts'] : [];
        $context['clinical_facts'] = self::sanitizeClinicalFacts(self::mergeFacts(
            is_array($context['clinical_facts'] ?? null) ? $context['clinical_facts'] : self::blankFacts(),
            $incoming
        ));
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
        $nextQ = self::stripAcuityLanguage(trim((string) ($gemini['next_question'] ?? '')));
        $gemini['next_question'] = $nextQ;

        // Do not re-ask what the conversation / facts already answered.
        if (!$sufficient && $nextQ !== '' && self::questionTargetsAlreadyKnownFact($nextQ, $context['clinical_facts'], $context)) {
            if (self::hasClinicallyUsefulFacts($context['clinical_facts'])) {
                $sufficient = true;
                $nextQ = '';
                $gemini['question_needed'] = false;
                $gemini['interview_sufficient'] = true;
                $gemini['next_question'] = '';
            } else {
                $nextQ = self::neutralMissingFactQuestion($context, $context['clinical_facts']);
                $gemini['next_question'] = $nextQ;
            }
        }

        // Pain score only when pain is patient-stated and still missing — not a blind checklist.
        if (!$sufficient && self::needsPainScore($context['clinical_facts'], $context)) {
            $hasPainQ = $nextQ !== '' && (bool) preg_match('/\b(1\s*(to|tubtob|-|–)\s*10|1\-10|pain\s*score|gaano\s*kasakit|pila\s*ka\s*grabe)\b/ui', $nextQ);
            if (!$hasPainQ && ($nextQ === '' || self::questionTargetsAlreadyKnownFact($nextQ, $context['clinical_facts'], $context))) {
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
        // Only patient-stated wording counts — never Gemini-invented fact labels.
        $hay = mb_strtolower(self::patientEvidenceCorpus($context));

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
     * Validated local NLP mappings for the current patient text (dictionary / KB / body lexicon).
     * No hard-coded example phrases — uses existing MedConnect NLP datasets only.
     *
     * @return array{
     *   patient_text:string,
     *   english_gloss:string,
     *   symptoms:list<string>,
     *   symptom_mappings:list<array{local:string,english:string}>,
     *   locations:list<string>,
     *   location_mappings:list<array{local:string,english:string}>
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
            if ($val === '' || self::isNonClinicalDiscourseLabel($val)) {
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
        foreach (['associated_symptoms', 'relevant_negatives', 'warning_signs', 'notes'] as $listKey) {
            $kept = [];
            foreach (self::stringList($facts[$listKey] ?? []) as $item) {
                if (self::isNonClinicalDiscourseLabel($item)) {
                    continue;
                }
                $kept[] = $item;
            }
            $out[$listKey] = $kept;
        }

        return $out;
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
     * True when the proposed follow-up asks for something already known from facts/conversation.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     */
    private static function questionTargetsAlreadyKnownFact(string $question, array $facts, array $context): bool
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return false;
        }

        if (preg_match('/\b(when|san-o|kailan|nagsugod|nagsimula|start|onset|how\s+long|duration|gaano\s+katagal|pila\s+ka\s+(adlaw|oras))\b/u', $q)) {
            if (trim((string) ($facts['onset'] ?? '')) !== '' || trim((string) ($facts['duration'] ?? '')) !== '') {
                return true;
            }
        }
        if (preg_match('/\b(where|diin|saan|which\s+part|ano\s+nga\s+parte|location|asa)\b/u', $q)) {
            if (trim((string) ($facts['location'] ?? '')) !== '') {
                return true;
            }
        }
        if (preg_match('/\b(1\s*(to|tubtob|-|–)\s*10|pain\s*score|gaano\s*kasakit|pila\s*ka\s*grabe|severity)\b/u', $q)) {
            if (($facts['pain_score'] ?? null) !== null) {
                return true;
            }
        }
        if (preg_match('/\b(what\s+(is|are)\s+(your|the)\s+(symptom|complaint)|ano\s+ang\s+(imong\s+)?(sakit|sintomas)|describe\s+(your\s+)?(symptom|pain))\b/u', $q)) {
            if (self::hasClinicallyUsefulFacts($facts)) {
                return true;
            }
        }
        // Asking to clarify a particle/filler already in the transcript is never useful.
        if (preg_match('/\b(really|only|gid|lang)\b/u', $q) && self::hasClinicallyUsefulFacts($facts)) {
            return true;
        }

        return false;
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
        ];
    }

    /**
     * @param array<string, mixed> $evidence collectLocalNlpEvidence()
     * @return array<string, mixed>
     */
    private static function factsFromNlpEvidence(array $evidence): array
    {
        $facts = self::blankFacts();
        $symptoms = self::stringList($evidence['symptoms'] ?? []);
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
        $patientText = self::patientEvidenceCorpus($context);
        $nlp = self::collectLocalNlpEvidence($patientText);
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

        // --- symptom: NLP wins; else confident Gemini semantic fallback ---
        $geminiSymptom = trim((string) ($incoming['symptom'] ?? ''));
        if ($allowedSymptoms !== []) {
            // Prefer NLP; ignore conflicting Gemini symptom.
            if ($geminiSymptom !== ''
                && !self::clinicalLabelSupported($geminiSymptom, $hay, $allowedSymptoms)
            ) {
                $dropped[] = 'symptom:' . $geminiSymptom;
            }
        } elseif ($geminiSymptom !== '') {
            if (self::isNonClinicalDiscourseLabel($geminiSymptom)) {
                $dropped[] = 'symptom_discourse:' . $geminiSymptom;
            } else {
                $nlpOrHayOk = self::clinicalLabelSupported($geminiSymptom, $hay, $allowedSymptoms);
                if ($nlpOrHayOk) {
                    $facts['symptom'] = $geminiSymptom;
                    $geminiAccepted[] = 'symptom:' . $geminiSymptom;
                } elseif ($allowGeminiSemantic && !$geminiInventedLocation) {
                    // Semantic fallback: reliable meaning, no invented body site attached.
                    $facts['symptom'] = $geminiSymptom;
                    $geminiAccepted[] = 'symptom_semantic:' . $geminiSymptom;
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
            if (self::scalarSupportedByPatient($side, $hay)) {
                $facts['laterality'] = $side;
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
                $ok = self::clinicalLabelSupported($item, $hay, $allowedSymptoms);
                if (!$ok && $allowGeminiSemantic && !$geminiInventedLocation) {
                    $ok = true;
                    $geminiAccepted[] = $listKey . '_semantic:' . $item;
                }
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
                || self::clinicalLabelSupported($bare, $hay, $allowedSymptoms)
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

        $needsClarify = !self::hasClinicallyUsefulFacts($facts)
            && $allowedSymptoms === []
            && trim((string) ($context['chief_complaint'] ?? '')) !== '';

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
     */
    private static function clinicalLabelSupported(string $label, string $hay, array $allowedEnglish): bool
    {
        $label = trim($label);
        if ($label === '' || self::isNonClinicalDiscourseLabel($label)) {
            return false;
        }
        $low = mb_strtolower($label);
        foreach ($allowedEnglish as $allowed) {
            if ($allowed !== '' && ($low === $allowed || str_contains($low, $allowed) || str_contains($allowed, $low))) {
                return true;
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
        $lang = self::detectLanguageHint($context);
        $symptom = trim((string) ($facts['symptom'] ?? ''));
        if ($symptom === '') {
            return match ($lang) {
                'hiligaynon' => 'Pwede mo mas klaro nga isaysay kung ano ang imo nabatyagan?',
                'tagalog' => 'Pwede mo bang ilarawan nang mas malinaw ang nararamdaman mo?',
                default => 'Can you describe more clearly what you are feeling?',
            };
        }

        return match ($lang) {
            'hiligaynon' => 'San-o ini nagsugod?',
            'tagalog' => 'Kailan ito nagsimula?',
            default => 'When did this start?',
        };
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

        if ($mode === 'start') {
            return "MODE: START_INTERVIEW\n"
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
                . "If HEALTH_RELATED: patient_subject must be HUMAN; extract ONLY genuine clinical facts for the human patient. "
                . "Ask at most ONE natural, context-aware next_question for the single most clinically relevant missing detail — not a checklist. "
                . "If the complaint is already clinically obvious, do not ask unnecessary clarification. "
                . "Never output EMERGENCY, URGENT, or NON-URGENT.";
        }

        return "MODE: INTERPRET_ANSWER\n"
            . "Use COMMON-SENSE clinical conversation over the FULL conversation + current answer.\n"
            . "Do NOT ask a question whose answer is already clearly implied or known.\n"
            . "Do NOT ask repetitive, unnatural, or irrelevant questions.\n"
            . "Interpret short replies (yes/no/negation/particles) by conversational context — they are often VALID answers, not new symptoms.\n"
            . "Never treat discourse particles/fillers/intensifiers (or English glosses like intensifier words) as medical symptoms.\n"
            . "Final acuity is NOT your job.\n\n"
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
            . "Update clinical_facts ONLY with newly supported genuine clinical information. "
            . "Apply short-answer meaning to the CURRENT question (e.g. negation → relevant_negatives or confirmed absence). "
            . "Ask at most ONE natural next_question for the most important remaining clinical gap, or set interview_sufficient=true if enough is known. "
            . "If ambiguous, answer_status=UNCLEAR and clarify — never guess. Never output EMERGENCY, URGENT, or NON-URGENT.";
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are Gemini Flash conducting a natural clinical interview for a MedConnect DEMO page.

Authoritative pipeline (you are only the semantic interview layer):
Patient input → existing NLP/domain validation → Gemini Flash semantic interpretation when needed → confirmed clinical facts → ClinicalTriageEngine (WHO IITT + clinical rules) → final acuity.

You MUST NEVER determine clinical acuity. You MUST NEVER output, assign, recommend, or infer EMERGENCY, URGENT, or NON-URGENT. You do not diagnose. You do not prescribe. ClinicalTriageEngine alone decides final triage later.

COMMON-SENSE CONVERSATION (critical):
- Read the COMPLETE conversation and the current answer before choosing the next question.
- Do not blindly fill schema fields one by one like a checklist.
- Do not ask a question when the answer is already clearly implied or already known.
- Do not ask repetitive, unnatural, or irrelevant questions.
- Ask only the single most clinically relevant missing question needed to understand the complaint.
- Questions must be natural and context-aware.
- If the complaint is already clinically obvious, do not ask unnecessary clarification — set interview_sufficient=true when appropriate.

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
4) Ask exactly ONE natural follow-up from confirmed facts + genuinely missing information — or stop when sufficient.
5) Stop when clinically sufficient (interview_sufficient=true, question_needed=false).

STRICT anti-hallucination:
- NEVER invent symptom, location, severity, duration, frequency, laterality, associated symptom, warning sign, or diagnosis.
- Do NOT manufacture facts to complete JSON — leave unsupported fields null.
- If genuinely ambiguous: answer_status=UNCLEAR, leave fields null, ask neutral clarification.
- Set facts_supported_by_patient_wording and extraction_confidence honestly.

Pain severity: only when the patient clearly indicated pain (not assumed); collect 1–10 once; never ask pain score when pain was never stated.

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

For START_INTERVIEW: always include classification, patient_subject, is_human_patient_complaint, and confidence; interview fields only when HEALTH_RELATED with patient_subject=HUMAN.
When patient_subject=NON_HUMAN: classification=NON_HEALTH_RELATED, empty clinical_facts, question_needed=false, interview_sufficient=false, next_question="".
For INTERPRET_ANSWER: focus on answer_status, clinical_facts, extraction_confidence, facts_supported_by_patient_wording.
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
            'temperature' => 0.1,
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

        if (self::shouldUseRailway()) {
            try {
                return self::generateViaRailway($payload, $model);
            } catch (RuntimeException $e) {
                if (self::apiKey() === '') {
                    // Soft-fail thinkingConfig 400 so complete() can retry without it.
                    if (str_contains($e->getMessage(), 'Gemini HTTP 400')
                        && !empty($payload['generationConfig']['thinkingConfig'])
                    ) {
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
            // Soft-fail thinkingConfig 400 by returning empty for retry path.
            if (str_contains($e->getMessage(), 'Gemini HTTP 400')
                && !empty($payload['generationConfig']['thinkingConfig'])
            ) {
                return '';
            }
            throw $e;
        }

        return self::extractCandidateText($data);
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
        $data = AiServiceClient::geminiGenerateContent($payload, $model, 25);
        if (!is_array($data)) {
            throw new RuntimeException('empty railway gemini reply');
        }
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '' && isset($data['response']) && is_array($data['response'])) {
            $text = self::extractCandidateText($data['response']);
        }
        if ($text === '') {
            throw new RuntimeException('empty Gemini response');
        }

        return $text;
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
        $provider = strtolower(trim((string) (getenv('AI_PROVIDER') ?: ($_ENV['AI_PROVIDER'] ?? 'gemini'))));
        if ($provider !== '' && $provider !== 'gemini') {
            return false;
        }

        return self::apiKey() !== '' || self::shouldUseRailway();
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
