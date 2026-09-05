<?php
/**
 * TRIAL ONLY — Step 3 demo adaptive chief-complaint interview.
 *
 * Does not write to triage_results or change production chatbot / registration flows.
 * Reuses ClinicalInterviewEngine + ChiefComplaintNlpService::assessInterview.
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
        $assessment = self::applyDemoQuestionWording($assessment);

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
            'engine_chain' => ChiefComplaintNlpService::ENGINE_CHAIN . ' + ClinicalInterviewEngine (demo trial)',
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
        if (!preg_match('/^\s*([1-9]|10)\s*$/u', $turn, $m)) {
            return $turn;
        }

        $score = (int) $m[1];
        $facts = is_array($prior['facts'] ?? null) ? $prior['facts'] : [];
        $hasLocation = ($facts['body_locations'] ?? []) !== [];
        $noScore = ($facts['pain_score'] ?? null) === null;
        $awaiting = strtoupper((string) ($prior['awaiting_question_id'] ?? ''));

        if ($awaiting === 'PAIN_SEVERITY' || ($hasLocation && $noScore)) {
            $prior['awaiting_question_id'] = 'PAIN_SEVERITY';
            return $score . '/10';
        }

        return $turn;
    }

    /**
     * Trial wording preferred by product owner; does not change shared question bank.
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
        $lang = strtoupper((string) ($q['language'] ?? ''));
        if ($qid !== 'PAIN_LOCATION') {
            return $assessment;
        }

        $text = match ($lang) {
            'ENGLISH' => 'Where does it hurt?',
            'TAGALOG' => 'Saan ang masakit sa iyo?',
            default => 'Diin ang masakit sa imo?',
        };
        $q['text'] = $text;
        $q['demo_wording'] = true;
        $assessment['followup_question'] = $q;
        $assessment['patient_message'] = $text;

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

        return [
            'complaint' => $hasPain ? 'pain' : '',
            'location' => $locations !== [] ? implode(', ', $locations) : '',
            'pain_severity' => $score !== null ? ((int) $score) . '/10' : '',
            'onset' => (string) ($facts['onset'] ?? ''),
            'duration' => (string) ($facts['duration_label'] ?? ''),
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
