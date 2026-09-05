<?php
/**
 * TRIAL ONLY — Step 3 demo adaptive chief-complaint interview.
 *
 * Does not write to triage_results or change production chatbot / registration flows.
 * Reuses ClinicalInterviewEngine + ChiefComplaintNlpService::assessInterview.
 *
 * Demo-only pain follow-up order for vague pain:
 *   1) PAIN_SEVERITY (0–10) → 2) PAIN_LOCATION → 3) ONSET → existing bank
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
        }

        $facts = is_array($assessment['interview']['facts'] ?? null)
            ? $assessment['interview']['facts']
            : [];
        $question = is_array($assessment['followup_question'] ?? null)
            ? $assessment['followup_question']
            : null;

        $healthRelated = true;
        $information = $inProgress ? 'INCOMPLETE' : 'SUFFICIENT';
        $diagnosis = 'NOT determined';
        $geminiUsed = false;
        $geminiWhy = 'not called (demo reuses existing ClinicalInterviewEngine / question bank)';
        if (is_array($question) && (($question['source'] ?? '') === 'gemini')) {
            $geminiUsed = true;
            $geminiWhy = 'Gemini phrased a follow-up question only; it did not set triage class';
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
            'english_translation' => (string) ($assessment['english_translation'] ?? ''),
            'detected_language' => (string) ($assessment['detected_language'] ?? ($assessment['interview']['detected_language'] ?? '')),
            'interview_context' => $assessment['interview'] ?? ClinicalInterviewEngine::normalizeContext($assessment),
            'engine' => (string) ($assessment['engine'] ?? 'clinical-interview'),
            'engine_chain' => ChiefComplaintNlpService::ENGINE_CHAIN . ' + ClinicalInterviewEngine (demo trial pain order)',
            'gemini_called' => $geminiUsed,
            'gemini_why' => $geminiWhy,
            'assessment' => $assessment,
            'next_action' => $inProgress
                ? 'Answer the follow-up question to continue accumulating context.'
                : 'Final triage ready (EMERGENCY / URGENT / NON-URGENT only).',
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
        $status = strtoupper((string) ($assessment['assessment_status'] ?? ''));
        if ($status === ClinicalInterviewEngine::STATUS_COMPLETED) {
            return $assessment;
        }

        $facts = is_array($assessment['interview']['facts'] ?? null)
            ? $assessment['interview']['facts']
            : [];
        $transcript = (string) ($assessment['clinical_transcript'] ?? $assessment['chief_complaint'] ?? '');
        if (!self::isPainComplaint($assessment, $transcript, $facts)) {
            return self::applyDemoQuestionWording($assessment);
        }

        $hasSeverity = ($facts['pain_score'] ?? null) !== null;
        $hasLocation = ($facts['body_locations'] ?? []) !== [];
        $hasOnset = trim((string) ($facts['onset'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== '';
        $lang = self::questionLanguage($assessment);

        if (!$hasSeverity) {
            return self::forceDemoQuestion($assessment, 'PAIN_SEVERITY', $lang);
        }
        if (!$hasLocation) {
            return self::forceDemoQuestion($assessment, 'PAIN_LOCATION', $lang);
        }
        if (!$hasOnset) {
            return self::forceDemoQuestion($assessment, 'ONSET', $lang);
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
                    'text' => 'How would you rate your pain right now from 0–10?',
                    'helper' => '0 = no pain, 10 = worst pain imaginable.',
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
                'TAGALOG' => [
                    'text' => 'Kung 0–10 ang pain scale, gaano kasakit ngayon?',
                    'helper' => '0 = walang sakit, 10 = pinakamalalang sakit na maisip.',
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
                default => [
                    'text' => 'Kung 0–10 ang pain scale, pila ang imo kasakit subong?',
                    'helper' => '0 = wala sang kasakit, 10 = pinakagrabe nga kasakit nga ma-imagine.',
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
            };
        }

        if ($qid === 'PAIN_LOCATION') {
            return match ($lang) {
                'ENGLISH' => [
                    'text' => 'Where does it hurt?',
                    'helper' => '',
                    'purpose' => 'Locate unspecified pain or discomfort',
                    'priority' => 10,
                ],
                'TAGALOG' => [
                    'text' => 'Saan ang masakit sa iyo?',
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

        return [
            'complaint' => $hasPain ? 'pain' : '',
            'location' => $locations !== [] ? implode(', ', $locations) : '',
            'pain_severity' => $score !== null ? ((int) $score) . '/10' : '',
            'onset' => $onset,
            'duration' => $duration,
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
            ],
            'detected_symptoms' => [],
            'english_translation' => '',
            'detected_language' => '',
            'interview_context' => null,
            'engine' => 'nlp-step3-demo-trial-gate',
            'engine_chain' => 'demo gate only (no triage)',
            'gemini_called' => $geminiCalled,
            'gemini_why' => $geminiCalled ? 'domain fallback' : 'not called',
            'assessment' => null,
            'next_action' => $message,
        ];
    }
}
