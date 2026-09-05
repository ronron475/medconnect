<?php
/**
 * TRIAL ONLY — Step 3 demo adaptive chief-complaint interview.
 *
 * Does not write to triage_results or change production chatbot / registration flows.
 * Reuses ClinicalInterviewEngine + ChiefComplaintNlpService::assessInterview.
 *
 * Demo-only pain follow-up order for vague pain:
 *   1) PAIN_SEVERITY (1–10) → 2) PAIN_LOCATION → 3) ONSET → existing bank
 * Incomplete pain never finalizes as NON-URGENT; clinical_status = NEEDS_FOLLOW_UP.
 */
final class NlpStep3DemoTrial
{
    public const MODE = 'nlp_step3_demo_trial';

    /**
     * @param array<string, mixed> $priorContext
     * @return array<string, mixed>
     */
    public static function assess(string $utterance, array $priorContext = []): array
    {
        $turn = trim($utterance);
        $prior = ClinicalInterviewEngine::normalizeContext($priorContext);
        // normalizeContext defaults assessment_status to IN_PROGRESS — use turns/complaint only.
        $hadTurns = ($prior['patient_turns'] ?? []) !== []
            || trim((string) ($prior['chief_complaint'] ?? '')) !== '';

        if ($turn === '') {
            return self::packGate('UNCLEAR', $turn, $prior, "Please enter a complaint to analyze.", false, false);
        }

        // Opening gates only on a fresh conversation (demo-side; do not change production NLP).
        if (!$hadTurns) {
            if (FaqChatbotDomainScope::isAllowedOpening($turn)
                && !FaqChatbotDomainScope::isHealthcareRelated($turn)
            ) {
                return self::packGate(
                    'NON_HEALTH_RELATED',
                    $turn,
                    $prior,
                    "I'm here to help with health concerns. This does not trigger medical assessment.",
                    false,
                    false
                );
            }

            if (self::isMalformedOrUnclear($turn)) {
                return self::packGate(
                    'UNCLEAR',
                    $turn,
                    $prior,
                    "I'm not sure I understood your message. Could you please rephrase it?",
                    false,
                    false
                );
            }
        }

        $normalizedTurn = self::normalizeDemoTurn($turn, $prior);

        // Universal typo / misspelling tolerance (demo only) before NLP + Gemini.
        $fuzzyPrep = NlpStep3DemoAnswerFuzzy::prepare(
            $normalizedTurn,
            strtoupper((string) ($prior['awaiting_question_id'] ?? ''))
        );
        $fuzzyMeta = [
            'status' => (string) ($fuzzyPrep['fuzzy_status'] ?? 'NONE'),
            'confidence' => (float) ($fuzzyPrep['confidence'] ?? 1),
            'corrections' => is_array($fuzzyPrep['corrections'] ?? null) ? $fuzzyPrep['corrections'] : [],
            'original' => (string) ($fuzzyPrep['original'] ?? $normalizedTurn),
            'corrected' => (string) ($fuzzyPrep['corrected'] ?? $normalizedTurn),
        ];
        if (!empty($fuzzyPrep['changed']) && ($fuzzyPrep['corrected'] ?? '') !== '') {
            $normalizedTurn = (string) $fuzzyPrep['corrected'];
        }

        $geminiMeta = [
            'called' => false,
            'available' => NlpStep3DemoGeminiAnswerInterpreter::enabled(),
            'status' => 'NOT_CALLED',
            'reason' => 'Existing NLP primary — Gemini not needed yet',
            'interpretation' => null,
            'min_confidence' => NlpStep3DemoGeminiAnswerInterpreter::minConfidence(),
            'fuzzy' => $fuzzyMeta,
        ];

        $awaiting = strtoupper((string) ($prior['awaiting_question_id'] ?? ''));
        if ($hadTurns && $awaiting !== '') {
            // Pass original patient text for Gemini context; corrected text for NLP retry.
            $geminiPack = self::maybeInterpretFollowUpWithGemini(
                (string) ($fuzzyPrep['original'] ?? $turn),
                $prior,
                $awaiting,
                $fuzzyPrep
            );
            $geminiMeta = array_merge($geminiMeta, $geminiPack['meta'] ?? []);
            $geminiMeta['fuzzy'] = $fuzzyMeta;
            if (!empty($geminiPack['block_advance'])) {
                return self::packClarificationHold(
                    $turn,
                    $prior,
                    (string) $geminiPack['message'],
                    $geminiMeta,
                    is_array($geminiPack['question'] ?? null) ? $geminiPack['question'] : null
                );
            }
            $normalizedTurn = (string) ($geminiPack['turn'] ?? $normalizedTurn);
            $prior = is_array($geminiPack['prior'] ?? null) ? $geminiPack['prior'] : $prior;
        }

        $assessment = ChiefComplaintNlpService::assessInterview($normalizedTurn, $prior);
        $assessment = self::enrichDemoAssessmentFacts($assessment);
        $assessment = self::applyDemoAdaptiveFollowUp($assessment, $prior);
        $assessment = self::maybeFinalizeDemoWhenSufficient($assessment);

        $status = strtoupper((string) ($assessment['assessment_status'] ?? ''));
        $inProgress = $status === ClinicalInterviewEngine::STATUS_IN_PROGRESS;
        $display = strtoupper(str_replace('_', '-', (string) ($assessment['triage']['triage_display'] ?? '')));
        if ($display === 'NON URGENT') {
            $display = 'NON-URGENT';
        }
        if ($inProgress) {
            $display = '';
            // Never leak a premature engine class into the demo response.
            if (isset($assessment['triage']) && is_array($assessment['triage'])) {
                $assessment['triage']['triage_display'] = '';
                $assessment['triage']['triage_classification'] = '';
                $assessment['triage']['assessment_status'] = ClinicalInterviewEngine::STATUS_IN_PROGRESS;
            }
        }

        $facts = is_array($assessment['interview']['facts'] ?? null)
            ? $assessment['interview']['facts']
            : [];
        $question = is_array($assessment['followup_question'] ?? null)
            ? $assessment['followup_question']
            : null;

        $completeness = is_array($assessment['demo_completeness'] ?? null)
            ? $assessment['demo_completeness']
            : NlpStep3DemoClinicalState::evaluateCompleteness(
                $facts,
                (string) ($assessment['clinical_transcript'] ?? $turn),
                $assessment
            );

        $healthRelated = true;
        $information = (string) ($completeness['status'] ?? ($inProgress ? 'INSUFFICIENT' : 'SUFFICIENT'));
        if ($information === NlpStep3DemoClinicalState::STATUS_SUFFICIENT && $inProgress) {
            $information = 'INSUFFICIENT';
        }
        $clinicalStatus = $inProgress ? 'NEEDS_FOLLOW_UP' : 'COMPLETED';
        $clinicalReasoning = trim((string) (
            $assessment['triage']['clinical_reasoning']
            ?? $assessment['clinical_reasoning']
            ?? $assessment['reason']
            ?? ''
        ));
        if ($inProgress && $clinicalReasoning === '') {
            $clinicalReasoning = 'Insufficient information for a reliable triage classification.';
        }
        $diagnosis = 'NOT determined';
        $geminiUsed = !empty($geminiMeta['called']);
        $geminiWhy = (string) ($geminiMeta['reason'] ?? 'not called');
        if (!$geminiUsed && is_array($question) && (($question['source'] ?? '') === 'gemini')) {
            $geminiUsed = true;
            $geminiWhy = 'Gemini phrased a follow-up question only; it did not set triage class';
            $geminiMeta['called'] = true;
            $geminiMeta['status'] = 'QUESTION_PHRASE_ONLY';
            $geminiMeta['reason'] = $geminiWhy;
        }
        if (!isset($geminiMeta['primary_nlp'])) {
            $geminiMeta['primary_nlp'] = $hadTurns && $awaiting !== '' ? 'LOW_CONFIDENCE' : 'N/A';
        }
        if (!isset($geminiMeta['fallback'])) {
            $geminiMeta['fallback'] = 'none';
        }

        $summary = NlpStep3DemoClinicalState::toDisplaySummary(
            $facts,
            (string) ($assessment['clinical_transcript'] ?? $turn)
        );

        return [
            'demo_mode' => self::MODE,
            'trial_only' => true,
            'production_untouched' => true,
            'input' => $turn,
            'normalized_input' => $normalizedTurn,
            'health_related' => $healthRelated,
            'domain_class' => 'HEALTH_RELATED',
            'information' => $information,
            'information_status' => $information,
            'clinical_status' => $clinicalStatus,
            'clinical_reasoning' => $clinicalReasoning,
            'diagnosis' => $diagnosis,
            'assessment_status' => $status !== '' ? $status : ($inProgress ? 'IN_PROGRESS' : 'COMPLETED'),
            'triage_display' => $display,
            'triage_final' => $inProgress ? null : ($display !== '' ? $display : null),
            'followup_required' => $inProgress,
            'followup_question' => $question,
            'followup_skipped' => !empty($assessment['interview']['demo_followup_skipped'])
                || !empty($assessment['triage']['demo_followup_skipped']),
            'followup_skip_reason' => (string) (
                $assessment['triage']['demo_followup_skip_reason']
                ?? $assessment['interview']['demo_followup_skip_reason']
                ?? ''
            ),
            'completeness' => $completeness,
            'missing_fields' => is_array($completeness['missing'] ?? null) ? $completeness['missing'] : [],
            'patient_message' => (string) ($assessment['patient_message'] ?? ''),
            'clinical_transcript' => (string) ($assessment['clinical_transcript'] ?? ''),
            'facts' => $facts,
            'clinical_state' => is_array($facts['clinical_state'] ?? null) ? $facts['clinical_state'] : [],
            'complaint_summary' => $summary,
            'detected_symptoms' => is_array($assessment['detected_symptoms'] ?? null) ? $assessment['detected_symptoms'] : [],
            'possible_conditions' => is_array($assessment['possible_conditions'] ?? null)
                ? $assessment['possible_conditions']
                : (is_array($assessment['detected_conditions'] ?? null) ? $assessment['detected_conditions'] : []),
            'english_translation' => (string) ($assessment['english_translation'] ?? ''),
            'detected_language' => (string) ($assessment['detected_language'] ?? ($assessment['interview']['detected_language'] ?? '')),
            'interview_context' => $assessment['interview'] ?? ClinicalInterviewEngine::normalizeContext($assessment),
            'engine' => (string) ($assessment['engine'] ?? 'clinical-interview'),
            'engine_chain' => ChiefComplaintNlpService::ENGINE_CHAIN . ' + ClinicalInterviewEngine (demo adaptive completeness)',
            'gemini_called' => $geminiUsed,
            'gemini_why' => $geminiWhy,
            'gemini' => $geminiMeta,
            'nlp_primary' => true,
            'assessment' => $assessment,
            'next_action' => $inProgress
                ? 'Answer the follow-up question to continue accumulating context.'
                : (
                    !empty($assessment['triage']['demo_followup_skipped'])
                        ? 'Follow-up skipped — sufficient information; final triage ready.'
                        : 'Final triage ready (EMERGENCY / URGENT / NON-URGENT only).'
                ),
        ];
    }

    /**
     * Demo-only: primary NLP → fuzzy → Gemini fallback for follow-up answers.
     *
     * @param array<string, mixed> $prior
     * @param array<string, mixed> $fuzzyPrep
     * @return array<string, mixed>
     */
    private static function maybeInterpretFollowUpWithGemini(
        string $turn,
        array $prior,
        string $awaiting,
        array $fuzzyPrep = []
    ): array {
        $meta = [
            'called' => false,
            'available' => NlpStep3DemoGeminiAnswerInterpreter::enabled(),
            'status' => 'NOT_CALLED',
            'reason' => 'Existing NLP understood the follow-up answer',
            'interpretation' => null,
            'min_confidence' => NlpStep3DemoGeminiAnswerInterpreter::minConfidence(),
            'primary_nlp' => 'PENDING',
            'fallback' => 'none',
            'fuzzy_status' => (string) ($fuzzyPrep['fuzzy_status'] ?? 'NONE'),
        ];

        $corrected = trim((string) ($fuzzyPrep['corrected'] ?? $turn));
        if ($corrected === '') {
            $corrected = $turn;
        }
        $synonym = trim((string) ($fuzzyPrep['synonym'] ?? NlpStep3DemoAnswerFuzzy::synonymNormalize($turn)));
        if ($synonym === '') {
            $synonym = $turn;
        }

        // 1) Existing NLP on original answer
        if (NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer($turn, $awaiting, $prior)) {
            $meta['primary_nlp'] = 'SUCCESS';
            $meta['fallback'] = 'none';
            $meta['reason'] = 'NOT CALLED — PRIMARY NLP SUFFICIENT';
            $meta['status'] = 'NOT_CALLED';

            return [
                'meta' => $meta,
                'block_advance' => false,
                'turn' => $turn,
                'prior' => $prior,
            ];
        }

        // 1b) Exact synonym variant (kagapon→gahapon) — still primary NLP success, not a typo path
        if ($synonym !== $turn
            && NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer($synonym, $awaiting, $prior)
        ) {
            $meta['primary_nlp'] = 'SUCCESS';
            $meta['fallback'] = 'none';
            $meta['fuzzy_status'] = 'SYNONYM';
            $meta['reason'] = 'NOT CALLED — PRIMARY NLP SUFFICIENT';
            $meta['status'] = 'NOT_CALLED';

            return [
                'meta' => $meta,
                'block_advance' => false,
                'turn' => $synonym,
                'prior' => $prior,
            ];
        }

        // 2) Existing NLP on fuzzy-corrected answer (true typo distance)
        $isTrueFuzzy = $corrected !== $turn && $corrected !== $synonym;
        if (($isTrueFuzzy || ($corrected !== $turn && $synonym === $turn))
            && NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer($corrected, $awaiting, $prior)
        ) {
            $meta['primary_nlp'] = 'LOW_CONFIDENCE';
            $meta['fallback'] = 'demo_fuzzy';
            $meta['fuzzy_status'] = 'SUCCESS';
            $meta['reason'] = 'NOT CALLED — FUZZY MATCH RESOLVED (Gemini not required)';
            $meta['status'] = 'DEMO_FUZZY';
            $meta['interpretation'] = [
                'relevant' => true,
                'understood' => true,
                'answer_type' => self::expectedAnswerType($awaiting),
                'normalized_value' => $corrected,
                'confidence' => (float) ($fuzzyPrep['confidence'] ?? 0.85),
                'needs_clarification' => false,
                'clarification_reason' => null,
                'source' => 'demo_fuzzy',
                'corrections' => $fuzzyPrep['corrections'] ?? [],
            ];

            return [
                'meta' => $meta,
                'block_advance' => false,
                'turn' => $corrected,
                'prior' => $prior,
            ];
        }

        // 3) Primary NLP did not confidently understand → Gemini fallback (then lexicon/fuzzy inside interpret)
        $meta['primary_nlp'] = 'FAILED';
        $expected = self::expectedAnswerType($awaiting);
        $questionText = self::lastAskedQuestionText($prior, $awaiting);
        $result = NlpStep3DemoGeminiAnswerInterpreter::interpret(
            $turn,
            $questionText,
            $awaiting,
            $expected,
            $prior,
            $corrected
        );

        $meta = array_merge($meta, [
            'called' => !empty($result['called']),
            'available' => !empty($result['available']),
            'status' => (string) ($result['status'] ?? 'ERROR'),
            'reason' => (string) ($result['reason'] ?? ''),
            'interpretation' => is_array($result['interpretation'] ?? null) ? $result['interpretation'] : null,
            'primary_nlp' => (string) ($result['primary_nlp'] ?? 'FAILED'),
            'fallback' => (string) ($result['fallback'] ?? 'none'),
            'fuzzy_status' => (string) ($result['fuzzy_status'] ?? $meta['fuzzy_status']),
        ]);

        // Accurate reason when Gemini was required
        if (!empty($result['called'])) {
            $meta['reason'] = 'CALLED — PRIMARY NLP INSUFFICIENT';
            if (($result['status'] ?? '') === 'OK' && is_array($result['interpretation'] ?? null)) {
                $nv = (string) ($result['interpretation']['normalized_value'] ?? '');
                if ($nv !== '') {
                    $meta['reason'] = 'CALLED — PRIMARY NLP INSUFFICIENT; understood: ' . $nv;
                }
            }
        } elseif (($result['fallback'] ?? '') === 'demo_fuzzy') {
            $meta['reason'] = 'NOT CALLED — DEMO FUZZY FALLBACK (Gemini unavailable or unused)';
        } elseif (($result['fallback'] ?? '') === 'demo_semantic_lexicon') {
            $meta['reason'] = 'NOT CALLED — DEMO SEMANTIC LEXICON (after primary NLP failure)';
        } elseif (($result['status'] ?? '') === 'UNAVAILABLE') {
            $meta['reason'] = 'PRIMARY NLP FAILED; Gemini unavailable — continuing cautiously';
        }

        $interp = is_array($meta['interpretation']) ? $meta['interpretation'] : null;

        if (is_array($interp)) {
            $needsClarification = !empty($interp['needs_clarification'])
                || empty($interp['relevant'])
                || (isset($interp['understood']) && $interp['understood'] === false)
                || in_array($meta['status'], ['UNRELATED', 'NEEDS_CLARIFICATION', 'LOW_CONFIDENCE'], true);

            if ($needsClarification) {
                $msg = self::clarificationMessage($awaiting, $interp, $questionText);

                return [
                    'meta' => $meta,
                    'block_advance' => true,
                    'message' => $msg,
                    'question' => self::heldQuestion($awaiting, $questionText, $msg),
                    'turn' => $turn,
                    'prior' => $prior,
                ];
            }

            $applied = NlpStep3DemoGeminiAnswerInterpreter::applyToPrior(
                $prior,
                $corrected !== '' ? $corrected : $turn,
                $interp,
                $awaiting
            );

            return [
                'meta' => $meta,
                'block_advance' => false,
                'turn' => (string) ($applied['turn'] ?? $corrected),
                'prior' => is_array($applied['prior'] ?? null) ? $applied['prior'] : $prior,
            ];
        }

        // Last resort: continue with fuzzy-corrected text if any, else original.
        return [
            'meta' => $meta,
            'block_advance' => false,
            'turn' => $corrected !== '' ? $corrected : $turn,
            'prior' => $prior,
        ];
    }

    private static function expectedAnswerType(string $awaiting): string
    {
        return match (strtoupper($awaiting)) {
            'PAIN_SEVERITY' => 'PAIN_SEVERITY',
            'PAIN_LOCATION' => 'PAIN_LOCATION',
            'ONSET', 'DURATION' => 'ONSET_DURATION',
            'ASSOCIATED_SYMPTOMS', 'ABDOMINAL_ASSOCIATED' => 'ASSOCIATED_SYMPTOMS',
            default => 'OTHER_CLINICAL',
        };
    }

    /**
     * @param array<string, mixed> $prior
     */
    private static function lastAskedQuestionText(array $prior, string $awaiting): string
    {
        $pack = self::demoQuestionPack($awaiting, 'HILIGAYNON');
        if (($pack['text'] ?? '') !== '' && in_array($awaiting, ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true)) {
            return (string) $pack['text'];
        }

        return match ($awaiting) {
            'DURATION' => 'San-o pa / pila ka adlaw na ang kasakit?',
            'ASSOCIATED_SYMPTOMS', 'ABDOMINAL_ASSOCIATED' => 'May iban pa bala nga sintomas?',
            default => 'Palihog klaroha ang imo sabat sa clinical question.',
        };
    }

    /**
     * @param array<string, mixed> $interp
     */
    private static function clarificationMessage(string $awaiting, array $interp, string $questionText): string
    {
        $reason = trim((string) ($interp['clarification_reason'] ?? ''));
        $awaiting = strtoupper($awaiting);

        if (empty($interp['relevant']) || strtoupper((string) ($interp['answer_type'] ?? '')) === 'UNRELATED') {
            return match ($awaiting) {
                'ONSET', 'DURATION' => 'Para ma-assess ang imo kasakit, gusto ko lang mahibaluan kung san-o ini nagsugod. Halimbawa: subong lang, gahapon, halin gahapon, ukon dugay na.',
                'PAIN_LOCATION' => 'Diin ang masakit sa imo? Halimbawa: ulo, tiyan, dughan, likod.',
                'PAIN_SEVERITY' => 'Palihog i-rate ang imo kasakit sa scale nga 1–10.',
                default => 'Palihog sabta ang clinical question: ' . $questionText,
            };
        }

        if ($awaiting === 'ONSET' || $awaiting === 'DURATION') {
            return 'Mga san-o ini nagsugod? Halimbawa, subong lang, gahapon, pila ka adlaw na, ukon dugay na?'
                . ($reason !== '' ? "\n\n(" . $reason . ')' : '');
        }

        return ($reason !== '' ? $reason . "\n\n" : '') . 'Palihog i-klaro ang imo sabat: ' . $questionText;
    }

    /**
     * @return array<string, mixed>
     */
    private static function heldQuestion(string $awaiting, string $questionText, string $clarification): array
    {
        $lang = 'HILIGAYNON';
        $pack = in_array($awaiting, ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true)
            ? self::demoQuestionPack($awaiting, $lang)
            : ['text' => $questionText, 'helper' => '', 'purpose' => 'clarification', 'priority' => 50];

        return [
            'question_id' => $awaiting !== '' ? $awaiting : 'CLARIFY',
            'clinical_purpose' => (string) ($pack['purpose'] ?? 'Clarify patient answer'),
            'red_flag_related' => false,
            'priority' => (int) ($pack['priority'] ?? 50),
            'text' => $clarification,
            'helper_text' => (string) ($pack['helper'] ?? ''),
            'language' => $lang,
            'source' => 'demo_gemini_clarification',
            'demo_wording' => true,
            'demo_order' => true,
        ];
    }

    /**
     * @param array<string, mixed> $prior
     * @param array<string, mixed> $geminiMeta
     * @param array<string, mixed>|null $question
     * @return array<string, mixed>
     */
    private static function packClarificationHold(
        string $turn,
        array $prior,
        string $message,
        array $geminiMeta,
        ?array $question
    ): array {
        $awaiting = strtoupper((string) ($prior['awaiting_question_id'] ?? ''));
        $facts = is_array($prior['facts'] ?? null) ? $prior['facts'] : [];
        if ($question === null) {
            $question = self::heldQuestion($awaiting, self::lastAskedQuestionText($prior, $awaiting), $message);
        }

        $counts = is_array($prior['clarification_counts'] ?? null) ? $prior['clarification_counts'] : [];
        if ($awaiting !== '') {
            $counts[$awaiting] = (int) ($counts[$awaiting] ?? 0) + 1;
        }
        $prior['clarification_counts'] = $counts;
        $prior['assessment_status'] = ClinicalInterviewEngine::STATUS_IN_PROGRESS;
        $prior['awaiting_question_id'] = $awaiting;

        return [
            'demo_mode' => self::MODE,
            'trial_only' => true,
            'production_untouched' => true,
            'input' => $turn,
            'normalized_input' => $turn,
            'health_related' => true,
            'domain_class' => 'HEALTH_RELATED',
            'information' => 'INSUFFICIENT',
            'information_status' => 'INSUFFICIENT',
            'clinical_status' => 'NEEDS_FOLLOW_UP',
            'clinical_reasoning' => 'Follow-up answer needs clarification before the interview can advance.',
            'diagnosis' => 'NOT determined',
            'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
            'triage_display' => '',
            'triage_final' => null,
            'followup_required' => true,
            'followup_question' => $question,
            'followup_skipped' => false,
            'followup_skip_reason' => '',
            'patient_message' => $message,
            'clinical_transcript' => trim(implode('. ', array_map('strval', (array) ($prior['patient_turns'] ?? [])))),
            'facts' => $facts,
            'complaint_summary' => self::complaintSummary($facts, (string) ($prior['chief_complaint'] ?? $turn)),
            'detected_symptoms' => [],
            'possible_conditions' => [],
            'english_translation' => '',
            'detected_language' => (string) ($prior['detected_language'] ?? ''),
            'interview_context' => $prior,
            'engine' => 'nlp-step3-demo-gemini-clarify',
            'engine_chain' => 'Existing NLP primary + Gemini answer fallback (clarification hold)',
            'gemini_called' => !empty($geminiMeta['called']),
            'gemini_why' => (string) ($geminiMeta['reason'] ?? ''),
            'gemini' => $geminiMeta,
            'nlp_primary' => true,
            'assessment' => [
                'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
                'triage' => ['triage_display' => '', 'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS],
                'interview' => $prior,
                'followup_question' => $question,
            ],
            'next_action' => 'Answer the clarification — interview has not advanced.',
        ];
    }

    private static function isMalformedOrUnclear(string $turn): bool
    {
        if (FaqChatbotDomainScope::looksUnclear($turn)) {
            return true;
        }

        $low = mb_strtolower(trim($turn));
        $low = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $low));
        if ($low === '') {
            return true;
        }

        // Single token that embeds a medical root inside gibberish (e.g. sakitgbgjgbvd).
        if (!preg_match('/\s/u', $low)
            && preg_match('/(sakit|masakit|pain|fever|ulo|tiyan|dughan)/u', $low)
            && !preg_match('/^(sakit|masakit|pain|fever|ulo|tiyan|dughan|sipon|ubo|hilanat)(ko|gid|lang)?$/u', $low)
            && (bool) preg_match('/[bcdfghjklmnpqrstvwxyz]{4,}/i', $low)
        ) {
            return true;
        }

        return ClinicalFeatureExtractors::isUnintelligibleComplaint($turn)
            && !FaqChatbotDomainScope::isHealthcareRelated($turn);
    }

    /**
     * @param array<string, mixed> $prior
     */
    private static function normalizeDemoTurn(string $turn, array &$prior): string
    {
        // Bare 0–10 numeric reply while pain severity is still missing.
        if (preg_match('/^\s*(10|[0-9])\s*$/u', $turn, $m)) {
            $score = (int) $m[1];
            $facts = is_array($prior['facts'] ?? null) ? $prior['facts'] : [];
            $noScore = ($facts['pain_score'] ?? null) === null;
            $awaiting = strtoupper((string) ($prior['awaiting_question_id'] ?? ''));

            if ($noScore && (
                $awaiting === 'PAIN_SEVERITY'
                || $awaiting === 'PAIN_LOCATION'
                || $awaiting === ''
                || self::priorLooksLikePain($prior)
            )) {
                $prior['awaiting_question_id'] = 'PAIN_SEVERITY';
                return $score . '/10';
            }
        }

        return $turn;
    }

    /**
     * @param array<string, mixed> $prior
     */
    private static function priorLooksLikePain(array $prior): bool
    {
        $transcript = trim(implode('. ', array_map('strval', (array) ($prior['patient_turns'] ?? []))));
        if ($transcript === '') {
            $transcript = (string) ($prior['chief_complaint'] ?? '');
        }

        return (bool) preg_match('/\b(sakit|masakit|pain|hurts?|hapdi)\b/ui', $transcript)
            || (($prior['facts']['body_locations'] ?? []) !== []);
    }

    /**
     * Demo-only: enrich facts from the full transcript so already-answered
     * clinical details are visible before deciding whether another question is necessary.
     *
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private static function enrichDemoAssessmentFacts(array $assessment): array
    {
        $transcript = (string) ($assessment['clinical_transcript'] ?? $assessment['chief_complaint'] ?? '');
        if ($transcript === '') {
            return $assessment;
        }
        if (!isset($assessment['interview']) || !is_array($assessment['interview'])) {
            $assessment['interview'] = [];
        }
        $facts = is_array($assessment['interview']['facts'] ?? null)
            ? $assessment['interview']['facts']
            : [];
        $facts = NlpStep3DemoClinicalState::enrichInterviewFacts($facts, $transcript);
        $assessment['interview']['facts'] = $facts;
        $assessment['demo_completeness'] = NlpStep3DemoClinicalState::evaluateCompleteness(
            $facts,
            $transcript,
            $assessment
        );

        return $assessment;
    }

    /**
     * Adaptive follow-up: ask only the single highest-priority missing clinically necessary field.
     * Question bank is a resource, not a mandatory sequence.
     *
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $prior
     * @return array<string, mixed>
     */
    private static function applyDemoAdaptiveFollowUp(array $assessment, array $prior = []): array
    {
        $facts = is_array($assessment['interview']['facts'] ?? null)
            ? $assessment['interview']['facts']
            : [];
        $transcript = (string) ($assessment['clinical_transcript'] ?? $assessment['chief_complaint'] ?? '');
        $status = strtoupper((string) ($assessment['assessment_status'] ?? ''));
        $display = strtoupper(str_replace('_', '-', (string) ($assessment['triage']['triage_display'] ?? '')));
        if ($display === 'NON URGENT') {
            $display = 'NON-URGENT';
        }

        if ($status === ClinicalInterviewEngine::STATUS_COMPLETED && $display === 'EMERGENCY') {
            return $assessment;
        }

        $completeness = is_array($assessment['demo_completeness'] ?? null)
            ? $assessment['demo_completeness']
            : NlpStep3DemoClinicalState::evaluateCompleteness($facts, $transcript, $assessment);
        $assessment['demo_completeness'] = $completeness;

        // Prevent infinite clarification loops on the same slot.
        $awaiting = strtoupper((string) ($prior['awaiting_question_id'] ?? $assessment['interview']['awaiting_question_id'] ?? ''));
        $clarifyCounts = is_array($prior['clarification_counts'] ?? null) ? $prior['clarification_counts'] : [];
        if ($awaiting !== '' && isset($clarifyCounts[$awaiting]) && (int) $clarifyCounts[$awaiting] >= 2) {
            $unknown = is_array($facts['unknown_fields'] ?? null) ? $facts['unknown_fields'] : [];
            $slotKey = match ($awaiting) {
                'PAIN_SEVERITY' => 'severity',
                'PAIN_LOCATION' => 'location',
                'ONSET', 'DURATION' => 'onset',
                'EYE_VISION' => 'eye_red_flags',
                'ASSOCIATED_SYMPTOMS', 'ABDOMINAL_ASSOCIATED' => 'associated_symptoms',
                default => strtolower($awaiting),
            };
            if (!in_array($slotKey, $unknown, true)) {
                $unknown[] = $slotKey;
            }
            $facts['unknown_fields'] = $unknown;
            $facts = NlpStep3DemoClinicalState::enrichInterviewFacts($facts, $transcript);
            $assessment['interview']['facts'] = $facts;
            $completeness = NlpStep3DemoClinicalState::evaluateCompleteness($facts, $transcript, $assessment);
            $assessment['demo_completeness'] = $completeness;
        }

        if (($completeness['status'] ?? '') === NlpStep3DemoClinicalState::STATUS_SUFFICIENT
            || !empty($completeness['red_flag_priority'])
        ) {
            $assessment['followup_required'] = false;
            $assessment['followup_question'] = null;
            if (isset($assessment['interview']) && is_array($assessment['interview'])) {
                $assessment['interview']['awaiting_question_id'] = '';
            }

            return $assessment;
        }

        $nextId = strtoupper((string) ($completeness['next_question_id'] ?? ''));
        $lang = self::questionLanguage($assessment);
        if ($nextId === '') {
            return self::applyDemoQuestionWording($assessment);
        }

        // Prefer demo wording for the single missing clinically necessary question.
        return self::forceDemoQuestion($assessment, $nextId, $lang);
    }

    /**
     * If the patient already supplied enough clinically relevant information, skip remaining
     * bank questions and finalize via the existing ClinicalTriageEngine classification.
     *
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private static function maybeFinalizeDemoWhenSufficient(array $assessment): array
    {
        $status = strtoupper((string) ($assessment['assessment_status'] ?? ''));
        if ($status === ClinicalInterviewEngine::STATUS_COMPLETED) {
            $facts = is_array($assessment['interview']['facts'] ?? null) ? $assessment['interview']['facts'] : [];
            $transcript = (string) ($assessment['clinical_transcript'] ?? '');
            $display = (string) ($assessment['triage']['triage_display'] ?? '');
            $soft = NlpStep3DemoClinicalState::softTriageDisplay($display, $facts, $transcript);
            if ($soft !== $display && isset($assessment['triage']) && is_array($assessment['triage'])) {
                return self::finalizeDemoWithTriageEngine(
                    array_merge($assessment, ['triage' => array_merge($assessment['triage'], [
                        'provisional_engine_classification' => $soft,
                    ])]),
                    $transcript,
                    $facts
                );
            }

            return $assessment;
        }

        $facts = is_array($assessment['interview']['facts'] ?? null)
            ? $assessment['interview']['facts']
            : [];
        $transcript = (string) ($assessment['clinical_transcript'] ?? $assessment['chief_complaint'] ?? '');
        $completeness = is_array($assessment['demo_completeness'] ?? null)
            ? $assessment['demo_completeness']
            : NlpStep3DemoClinicalState::evaluateCompleteness($facts, $transcript, $assessment);

        if (($completeness['status'] ?? '') !== NlpStep3DemoClinicalState::STATUS_SUFFICIENT
            && empty($completeness['red_flag_priority'])
        ) {
            return $assessment;
        }

        return self::finalizeDemoWithTriageEngine($assessment, $transcript, $facts);
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function finalizeDemoWithTriageEngine(array $assessment, string $transcript, array $facts): array
    {
        $display = strtoupper(str_replace(
            '_',
            '-',
            (string) ($assessment['triage']['provisional_engine_classification'] ?? '')
        ));
        if ($display === 'NON URGENT') {
            $display = 'NON-URGENT';
        }
        if (!in_array($display, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
            $raw = ClinicalTriageEngine::assess($transcript, $transcript);
            $display = strtoupper(str_replace('_', '-', (string) ($raw['triage_display'] ?? 'NON-URGENT')));
            if ($display === 'NON URGENT') {
                $display = 'NON-URGENT';
            }
            if (!in_array($display, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
                $display = 'NON-URGENT';
            }
            if (is_array($raw) && isset($assessment['triage']) && is_array($assessment['triage'])) {
                foreach (['clinical_reasoning', 'confidence', 'confidence_score', 'detected_symptoms', 'assessment_factors'] as $key) {
                    if (isset($raw[$key]) && empty($assessment['triage'][$key])) {
                        $assessment['triage'][$key] = $raw[$key];
                    }
                }
            }
        }

        $display = NlpStep3DemoClinicalState::softTriageDisplay($display, $facts, $transcript);

        $classification = $display === 'NON-URGENT' ? 'NON_URGENT' : $display;
        $gis = match ($display) {
            'EMERGENCY' => 'emergency',
            'URGENT' => 'urgent',
            default => 'non_urgent',
        };

        if (!isset($assessment['triage']) || !is_array($assessment['triage'])) {
            $assessment['triage'] = [];
        }
        $assessment['assessment_status'] = ClinicalInterviewEngine::STATUS_COMPLETED;
        $assessment['followup_required'] = false;
        $assessment['followup_question'] = null;
        $assessment['patient_message'] = match ($display) {
            'EMERGENCY' => 'Based on the information you shared, this may need emergency care. Please seek urgent medical help.',
            'URGENT' => 'Based on the information you shared, this may need priority medical attention.',
            default => 'Based on the information you shared, this appears non-urgent. Still consult a clinician if symptoms change.',
        };
        $assessment['triage']['assessment_status'] = ClinicalInterviewEngine::STATUS_COMPLETED;
        $assessment['triage']['triage_display'] = $display;
        $assessment['triage']['triage_classification'] = $classification;
        $assessment['triage']['gis_triage_level'] = $gis;
        $assessment['triage']['db_level'] = match ($display) {
            'EMERGENCY' => '1',
            'URGENT' => '2',
            default => '3',
        };
        $assessment['triage']['urgency_label'] = match ($display) {
            'EMERGENCY' => 'Emergency (Immediate)',
            'URGENT' => 'Urgent (Priority)',
            default => 'Non-Urgent (Routine)',
        };
        $assessment['triage']['demo_followup_skipped'] = true;
        $assessment['triage']['demo_followup_skip_reason'] =
            'Patient message already contained sufficient clinically relevant information; remaining question-bank items were skipped.';

        if (!isset($assessment['interview']) || !is_array($assessment['interview'])) {
            $assessment['interview'] = [];
        }
        $assessment['interview']['facts'] = $facts;
        $assessment['interview']['assessment_status'] = ClinicalInterviewEngine::STATUS_COMPLETED;
        $assessment['interview']['awaiting_question_id'] = '';
        $assessment['interview']['demo_followup_skipped'] = true;
        $assessment['db_level'] = $assessment['triage']['db_level'];
        $assessment['urgency_label'] = $assessment['triage']['urgency_label'];

        return $assessment;
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $facts
     */
    private static function isPainComplaint(array $assessment, string $transcript, array $facts): bool
    {
        if (($facts['pain_score'] ?? null) !== null || ($facts['body_locations'] ?? []) !== []) {
            if (preg_match('/\b(sakit|masakit|pain|hurts?|hapdi)\b/ui', $transcript)) {
                return true;
            }
        }
        foreach ((array) ($assessment['interview']['chief_complaints'] ?? []) as $row) {
            $family = strtolower((string) (is_array($row) ? ($row['family_key'] ?? $row['id'] ?? '') : $row));
            if (str_contains($family, 'pain') || $family === 'headache' || $family === 'abdominal_pain' || $family === 'chest_pain') {
                return true;
            }
        }

        return ClinicalFeatureExtractors::isVagueComplaint($transcript)
            || (bool) preg_match('/\b(sakit|masakit|pain|hurts?|hapdi)\b/ui', $transcript);
    }

    /**
     * @param array<string, mixed> $assessment
     */
    private static function questionLanguage(array $assessment): string
    {
        $fromQ = strtoupper((string) ($assessment['followup_question']['language'] ?? ''));
        if (in_array($fromQ, ['HILIGAYNON', 'TAGALOG', 'ENGLISH'], true)) {
            return $fromQ;
        }
        $ql = strtolower((string) ($assessment['interview']['question_language'] ?? 'hiligaynon'));

        return match ($ql) {
            'english', 'en' => 'ENGLISH',
            'tagalog', 'filipino', 'fil' => 'TAGALOG',
            default => 'HILIGAYNON',
        };
    }

    /**
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private static function forceDemoQuestion(array $assessment, string $qid, string $lang): array
    {
        $pack = self::demoQuestionPack($qid, $lang);
        $question = [
            'question_id' => $qid,
            'clinical_purpose' => $pack['purpose'],
            'red_flag_related' => in_array(strtoupper($qid), ['BREATHING_SEVERITY', 'NEURO_WEAKNESS', 'EYE_VISION', 'ABDOMINAL_ASSOCIATED'], true),
            'priority' => $pack['priority'],
            'text' => $pack['text'],
            'helper_text' => $pack['helper'],
            'language' => $lang,
            'source' => 'question_bank',
            'demo_wording' => true,
            'demo_order' => true,
        ];

        $assessment['assessment_status'] = ClinicalInterviewEngine::STATUS_IN_PROGRESS;
        $assessment['followup_required'] = true;
        $assessment['followup_question'] = $question;
        $assessment['patient_message'] = $pack['text'] . ($pack['helper'] !== '' ? "\n\n" . $pack['helper'] : '');

        if (!isset($assessment['interview']) || !is_array($assessment['interview'])) {
            $assessment['interview'] = [];
        }
        $asked = array_values(array_filter(array_map('strval', (array) ($assessment['interview']['questions_asked'] ?? []))));
        // Keep later slots available: if we skip ahead to severity, do not permanently consume location/onset.
        $deferred = match ($qid) {
            'PAIN_SEVERITY' => ['PAIN_LOCATION', 'ONSET', 'DURATION', 'ABDOMINAL_ASSOCIATED', 'ASSOCIATED_SYMPTOMS', 'EYE_VISION'],
            'PAIN_LOCATION' => ['ONSET', 'DURATION', 'ABDOMINAL_ASSOCIATED', 'ASSOCIATED_SYMPTOMS'],
            'ONSET' => ['ABDOMINAL_ASSOCIATED', 'DURATION', 'ASSOCIATED_SYMPTOMS', 'EYE_VISION'],
            default => [],
        };
        $asked = array_values(array_filter($asked, static function (string $id) use ($deferred): bool {
            return !in_array(strtoupper($id), $deferred, true);
        }));
        if (!in_array($qid, array_map('strtoupper', $asked), true)) {
            $asked[] = $qid;
        }
        $assessment['interview']['questions_asked'] = $asked;
        $assessment['interview']['questions_already_asked'] = $asked;
        $assessment['interview']['awaiting_question_id'] = $qid;
        $assessment['interview']['assessment_status'] = ClinicalInterviewEngine::STATUS_IN_PROGRESS;

        if (!isset($assessment['triage']) || !is_array($assessment['triage'])) {
            $assessment['triage'] = [];
        }
        $assessment['triage']['assessment_status'] = ClinicalInterviewEngine::STATUS_IN_PROGRESS;
        $assessment['triage']['triage_classification'] = '';
        $assessment['triage']['triage_display'] = '';
        $assessment['triage']['db_level'] = 'pending';
        $assessment['triage']['urgency_label'] = 'Needs follow-up';
        if (empty($assessment['triage']['clinical_reasoning'])) {
            $assessment['triage']['clinical_reasoning'] =
                'Insufficient information for a reliable triage classification.';
        }

        return $assessment;
    }

    /**
     * @return array{text:string,helper:string,purpose:string,priority:int}
     */
    private static function demoQuestionPack(string $qid, string $lang): array
    {
        return NlpStep3DemoClinicalState::questionPack($qid, $lang);
    }

    /**
     * Fallback wording when demo order does not override.
     *
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private static function applyDemoQuestionWording(array $assessment): array
    {
        $q = $assessment['followup_question'] ?? null;
        if (!is_array($q)) {
            return $assessment;
        }
        $qid = strtoupper((string) ($q['question_id'] ?? ''));
        $lang = strtoupper((string) ($q['language'] ?? 'HILIGAYNON'));
        if ($qid === '') {
            return $assessment;
        }

        $pack = self::demoQuestionPack($qid, $lang);
        $q['text'] = $pack['text'];
        $q['helper_text'] = $pack['helper'];
        $q['demo_wording'] = true;
        $assessment['followup_question'] = $q;
        $assessment['patient_message'] = $pack['text'] . ($pack['helper'] !== '' ? "\n\n" . $pack['helper'] : '');

        return $assessment;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function complaintSummary(array $facts, string $transcript): array
    {
        return NlpStep3DemoClinicalState::toDisplaySummary($facts, $transcript);
    }

    /**
     * @param array<string, mixed> $prior
     * @return array<string, mixed>
     */
    private static function packGate(
        string $domainClass,
        string $turn,
        array $prior,
        string $message,
        bool $healthRelated,
        bool $geminiCalled
    ): array {
        return [
            'demo_mode' => self::MODE,
            'trial_only' => true,
            'production_untouched' => true,
            'input' => $turn,
            'normalized_input' => $turn,
            'health_related' => $healthRelated,
            'domain_class' => $domainClass,
            'information' => 'N/A',
            'clinical_status' => 'SKIPPED',
            'clinical_reasoning' => $message,
            'diagnosis' => 'NOT determined',
            'assessment_status' => 'SKIPPED',
            'triage_display' => '',
            'triage_final' => null,
            'followup_required' => false,
            'followup_question' => null,
            'patient_message' => $message,
            'clinical_transcript' => '',
            'facts' => $prior['facts'] ?? [],
            'complaint_summary' => [
                'complaint' => '',
                'location' => '',
                'pain_severity' => '',
                'onset' => '',
                'duration' => '',
                'character' => '',
                'aggravating_factor' => '',
                'associated_symptoms' => '',
            ],
            'followup_skipped' => false,
            'followup_skip_reason' => '',
            'detected_symptoms' => [],
            'english_translation' => '',
            'detected_language' => '',
            'interview_context' => null,
            'engine' => 'nlp-step3-demo-trial-gate',
            'engine_chain' => 'demo gate only (no triage)',
            'gemini_called' => $geminiCalled,
            'gemini_why' => $geminiCalled ? 'domain fallback' : 'not called',
            'gemini' => [
                'called' => $geminiCalled,
                'available' => NlpStep3DemoGeminiAnswerInterpreter::enabled(),
                'status' => 'NOT_CALLED',
                'reason' => $geminiCalled ? 'domain fallback' : 'gate — Gemini answer interpreter not used',
                'interpretation' => null,
            ],
            'nlp_primary' => true,
            'assessment' => null,
            'next_action' => $message,
        ];
    }
}
