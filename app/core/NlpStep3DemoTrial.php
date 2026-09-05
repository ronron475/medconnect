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

        $geminiMeta = [
            'called' => false,
            'available' => NlpStep3DemoGeminiAnswerInterpreter::enabled(),
            'status' => 'NOT_CALLED',
            'reason' => 'Existing NLP primary — Gemini not needed yet',
            'interpretation' => null,
            'min_confidence' => NlpStep3DemoGeminiAnswerInterpreter::minConfidence(),
        ];

        $awaiting = strtoupper((string) ($prior['awaiting_question_id'] ?? ''));
        if ($hadTurns && $awaiting !== '') {
            $geminiPack = self::maybeInterpretFollowUpWithGemini($normalizedTurn, $prior, $awaiting);
            $geminiMeta = $geminiPack['meta'];
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
        $assessment = self::applyDemoPainQuestionOrder($assessment);

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

        $healthRelated = true;
        $information = $inProgress ? 'INCOMPLETE' : 'SUFFICIENT';
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

        return [
            'demo_mode' => self::MODE,
            'trial_only' => true,
            'production_untouched' => true,
            'input' => $turn,
            'normalized_input' => $normalizedTurn,
            'health_related' => $healthRelated,
            'domain_class' => 'HEALTH_RELATED',
            'information' => $information,
            'clinical_status' => $clinicalStatus,
            'clinical_reasoning' => $clinicalReasoning,
            'diagnosis' => $diagnosis,
            'assessment_status' => $status !== '' ? $status : ($inProgress ? 'IN_PROGRESS' : 'COMPLETED'),
            'triage_display' => $display,
            'triage_final' => $inProgress ? null : ($display !== '' ? $display : null),
            'followup_required' => $inProgress,
            'followup_question' => $question,
            'patient_message' => (string) ($assessment['patient_message'] ?? ''),
            'clinical_transcript' => (string) ($assessment['clinical_transcript'] ?? ''),
            'facts' => $facts,
            'complaint_summary' => self::complaintSummary($facts, (string) ($assessment['clinical_transcript'] ?? $turn)),
            'detected_symptoms' => is_array($assessment['detected_symptoms'] ?? null) ? $assessment['detected_symptoms'] : [],
            'possible_conditions' => is_array($assessment['possible_conditions'] ?? null)
                ? $assessment['possible_conditions']
                : (is_array($assessment['detected_conditions'] ?? null) ? $assessment['detected_conditions'] : []),
            'english_translation' => (string) ($assessment['english_translation'] ?? ''),
            'detected_language' => (string) ($assessment['detected_language'] ?? ($assessment['interview']['detected_language'] ?? '')),
            'interview_context' => $assessment['interview'] ?? ClinicalInterviewEngine::normalizeContext($assessment),
            'engine' => (string) ($assessment['engine'] ?? 'clinical-interview'),
            'engine_chain' => ChiefComplaintNlpService::ENGINE_CHAIN . ' + ClinicalInterviewEngine (demo trial pain order)',
            'gemini_called' => $geminiUsed,
            'gemini_why' => $geminiWhy,
            'gemini' => $geminiMeta,
            'nlp_primary' => true,
            'assessment' => $assessment,
            'next_action' => $inProgress
                ? 'Answer the follow-up question to continue accumulating context.'
                : 'Final triage ready (EMERGENCY / URGENT / NON-URGENT only).',
        ];
    }

    /**
     * Demo-only: call Gemini when existing NLP cannot confidently understand a follow-up answer.
     *
     * @param array<string, mixed> $prior
     * @return array<string, mixed>
     */
    private static function maybeInterpretFollowUpWithGemini(string $turn, array $prior, string $awaiting): array
    {
        $meta = [
            'called' => false,
            'available' => NlpStep3DemoGeminiAnswerInterpreter::enabled(),
            'status' => 'NOT_CALLED',
            'reason' => 'Existing NLP understood the follow-up answer',
            'interpretation' => null,
            'min_confidence' => NlpStep3DemoGeminiAnswerInterpreter::minConfidence(),
        ];

        if (NlpStep3DemoGeminiAnswerInterpreter::nlpUnderstandsAnswer($turn, $awaiting, $prior)) {
            return [
                'meta' => $meta,
                'block_advance' => false,
                'turn' => $turn,
                'prior' => $prior,
            ];
        }

        $expected = self::expectedAnswerType($awaiting);
        $questionText = self::lastAskedQuestionText($prior, $awaiting);
        $result = NlpStep3DemoGeminiAnswerInterpreter::interpret(
            $turn,
            $questionText,
            $awaiting,
            $expected,
            $prior
        );

        $meta = array_merge($meta, [
            'called' => !empty($result['called']),
            'available' => !empty($result['available']),
            'status' => (string) ($result['status'] ?? 'ERROR'),
            'reason' => (string) ($result['reason'] ?? ''),
            'interpretation' => is_array($result['interpretation'] ?? null) ? $result['interpretation'] : null,
        ]);

        // Gemini unavailable / failed → continue with existing NLP + clarification path (no crash).
        if (in_array($meta['status'], ['UNAVAILABLE', 'ERROR', 'INVALID_JSON'], true) || empty($meta['called'])) {
            if ($meta['status'] === 'UNAVAILABLE') {
                $meta['reason'] = 'Gemini fallback unavailable — continuing with existing NLP';
            }

            return [
                'meta' => $meta,
                'block_advance' => false,
                'turn' => $turn,
                'prior' => $prior,
            ];
        }

        $interp = is_array($meta['interpretation']) ? $meta['interpretation'] : [];
        $needsClarification = !empty($interp['needs_clarification'])
            || empty($interp['relevant'])
            || empty($interp['understood'])
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

        $applied = NlpStep3DemoGeminiAnswerInterpreter::applyToPrior($prior, $turn, $interp, $awaiting);

        return [
            'meta' => $meta,
            'block_advance' => false,
            'turn' => (string) ($applied['turn'] ?? $turn),
            'prior' => is_array($applied['prior'] ?? null) ? $applied['prior'] : $prior,
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
            'information' => 'INCOMPLETE',
            'clinical_status' => 'NEEDS_FOLLOW_UP',
            'clinical_reasoning' => 'Follow-up answer needs clarification before the interview can advance.',
            'diagnosis' => 'NOT determined',
            'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
            'triage_display' => '',
            'triage_final' => null,
            'followup_required' => true,
            'followup_question' => $question,
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
     * Demo-only preferred pain order: severity → location → onset.
     * Does not change shared ClinicalFollowUpQuestionBank priorities.
     *
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private static function applyDemoPainQuestionOrder(array $assessment): array
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

        // Red-flag emergencies stay final — do not force the generic pain quiz.
        if ($status === ClinicalInterviewEngine::STATUS_COMPLETED && $display === 'EMERGENCY') {
            return $assessment;
        }

        if (!self::isPainComplaint($assessment, $transcript, $facts)) {
            if ($status === ClinicalInterviewEngine::STATUS_COMPLETED) {
                return $assessment;
            }

            return self::applyDemoQuestionWording($assessment);
        }

        $hasSeverity = ($facts['pain_score'] ?? null) !== null;
        $hasLocation = ($facts['body_locations'] ?? []) !== [];
        $hasOnset = trim((string) ($facts['onset'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== '';
        $lang = self::questionLanguage($assessment);

        // Incomplete pain must stay in follow-up even if the engine prematurely finalized NON-URGENT.
        if (!$hasSeverity) {
            return self::forceDemoQuestion($assessment, 'PAIN_SEVERITY', $lang);
        }
        if (!$hasLocation) {
            return self::forceDemoQuestion($assessment, 'PAIN_LOCATION', $lang);
        }
        if (!$hasOnset) {
            return self::forceDemoQuestion($assessment, 'ONSET', $lang);
        }

        if ($status === ClinicalInterviewEngine::STATUS_COMPLETED) {
            return $assessment;
        }

        return self::applyDemoQuestionWording($assessment);
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
            'red_flag_related' => false,
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
            'PAIN_SEVERITY' => ['PAIN_LOCATION', 'ONSET', 'DURATION', 'ABDOMINAL_ASSOCIATED'],
            'PAIN_LOCATION' => ['ONSET', 'DURATION', 'ABDOMINAL_ASSOCIATED'],
            'ONSET' => ['ABDOMINAL_ASSOCIATED', 'DURATION'],
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
        // Keep engine reasoning for UI, but never treat provisional class as final.
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
        if ($qid === 'PAIN_SEVERITY') {
            return match ($lang) {
                'ENGLISH' => [
                    'text' => 'I understand that you are experiencing pain. How would you rate your pain right now on a scale of 1–10?',
                    'helper' => "1 = mildest pain\n10 = worst pain imaginable",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
                'TAGALOG' => [
                    'text' => 'Naiintindihan ko na may sakit ka. Para ma-assess nang mas mabuti, gaano kasakit ngayon sa scale na 1–10?',
                    'helper' => "1 = pinakamahinang sakit\n10 = pinakamalalang sakit na maisip",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
                default => [
                    'text' => 'Naintindihan ko nga may kasakit ka. Para ma-assess ini sing mas maayo, pila ang imo pain level subong sa scale nga 1–10?',
                    'helper' => "1 = pinakamahinay nga kasakit\n10 = pinakagrabe nga kasakit nga ma-imagine",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
            };
        }

        if ($qid === 'PAIN_LOCATION') {
            return match ($lang) {
                'ENGLISH' => [
                    'text' => 'Where exactly is the pain?',
                    'helper' => '',
                    'purpose' => 'Locate unspecified pain or discomfort',
                    'priority' => 10,
                ],
                'TAGALOG' => [
                    'text' => 'Saan exactamente ang masakit sa iyo?',
                    'helper' => '',
                    'purpose' => 'Locate unspecified pain or discomfort',
                    'priority' => 10,
                ],
                default => [
                    'text' => 'Diin ang masakit sa imo?',
                    'helper' => '',
                    'purpose' => 'Locate unspecified pain or discomfort',
                    'priority' => 10,
                ],
            };
        }

        // ONSET
        return match ($lang) {
            'ENGLISH' => [
                'text' => 'When did the pain start?',
                'helper' => '',
                'purpose' => 'Determine onset / how long ago pain began',
                'priority' => 50,
            ],
            'TAGALOG' => [
                'text' => 'Kailan pa nagsimula ang sakit?',
                'helper' => '',
                'purpose' => 'Determine onset / how long ago pain began',
                'priority' => 50,
            ],
            default => [
                'text' => 'San-o pa nagsugod ang kasakit?',
                'helper' => '',
                'purpose' => 'Determine onset / how long ago pain began',
                'priority' => 50,
            ],
        };
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
        if (!in_array($qid, ['PAIN_LOCATION', 'PAIN_SEVERITY', 'ONSET'], true)) {
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
        $locations = is_array($facts['body_locations'] ?? null) ? $facts['body_locations'] : [];
        $score = $facts['pain_score'] ?? null;
        $hasPain = (bool) preg_match('/\b(sakit|masakit|pain|hurts?|hapdi)\b/ui', $transcript)
            || $locations !== []
            || $score !== null;

        $duration = trim((string) ($facts['duration_label'] ?? ''));
        $onset = trim((string) ($facts['onset'] ?? ''));
        if ($duration === '' && $onset !== '') {
            $duration = $onset;
        }

        $character = trim((string) ($facts['pain_qualifier'] ?? ''));
        $associated = [];
        $hay = mb_strtolower($transcript);
        $assocMap = [
            'vomiting' => '/\b(suka|nagasuka|ginasuka|vomit|vomiting|nahilo)\b/u',
            'fever' => '/\b(hilanat|ginahilanat|lagnat|fever)\b/u',
            'diarrhea' => '/\b(diarrhea|kalibang|libang|tae)\b/u',
            'dizziness' => '/\b(dizzy|malipong|nalipong|nahihilo)\b/u',
            'breathing difficulty' => '/\b(budlay|ginhawa|breath|hinga|dyspnea)\b/u',
            'bleeding' => '/\b(dugo|bleeding|nagdugo)\b/u',
        ];
        foreach ($assocMap as $label => $pattern) {
            if (preg_match($pattern, $hay)) {
                $associated[] = $label;
            }
        }
        if (($facts['abdominal_associated'] ?? null) === true && $associated === []) {
            $associated[] = 'abdominal associated symptoms';
        }
        if (($facts['has_other_symptoms'] ?? null) === true && $associated === []) {
            $associated[] = 'other symptoms present';
        }
        if (($facts['denied_associated'] ?? false) === true) {
            $associated = ['none reported'];
        }

        return [
            'complaint' => $hasPain ? 'pain' : '',
            'location' => $locations !== [] ? implode(', ', $locations) : '',
            'pain_severity' => $score !== null ? ((int) $score) . '/10' : '',
            'onset' => $onset,
            'duration' => $duration,
            'character' => $character,
            'associated_symptoms' => $associated !== [] ? implode(', ', $associated) : '',
        ];
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
                'associated_symptoms' => '',
            ],
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
