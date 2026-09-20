<?php
/**
 * Adaptive preliminary-triage interview on top of the existing NLP engine.
 *
 * Does not replace HiligaynonMedicalNlpPipeline / ClinicalTriageEngine.
 * Uses those engines on the accumulated patient transcript, then decides
 * whether enough clinical information exists to finalize NON-URGENT / URGENT /
 * EMERGENCY. Existing NLP (adaptive policy + question bank) selects the next
 * clinical purpose; Gemini only phrases that one question (with bank fallback).
 * Optional Ollama meaning support enriches understanding for Hiligaynon/local
 * text but never replaces the original complaint or sets triage class.
 * Optional Python ai_service enrichment (datasets / symptom matchers) may add
 * English glosses and symptoms when the service is healthy; fail-open if offline.
 * Python never sets triage class.
 *
 * Multi-complaint: facts/Q&A stay per track; final urgency always comes from
 * ClinicalTriageEngine on the COMPLETE accumulated case (never MAX of tracks).
 */

final class ClinicalInterviewEngine
{
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_NEEDS_VALID_COMPLAINT = 'NEEDS_VALID_COMPLAINT';
    public const MAX_QUESTIONS = 6;

    private const FINAL_CLASSES = ['NON-URGENT', 'URGENT', 'EMERGENCY'];

    /**
     * @param list<string> $checkboxSymptoms
     * @param array<string, mixed> $priorContext
     * @return array<string, mixed> MedicalAssessmentEngine payload plus interview fields
     */
    public static function assess(string $utterance, array $priorContext = [], array $checkboxSymptoms = []): array
    {
        $context = self::normalizeContext($priorContext);
        $turn = trim($utterance);
        $originalTurnForLanguage = $turn;
        $isOpeningTurn = ($context['patient_turns'] ?? []) === [] && ($context['questions_asked'] ?? []) === [];

        // Opening-turn semantic gate: PHP domain NLP + optional Gemini validation.
        // Invalid / non-medical input must not enter clinical follow-ups or triage.
        try {
            if ($turn !== ''
                && ($context['patient_turns'] ?? []) === []
                && ($context['questions_asked'] ?? []) === []
                && class_exists('ComplaintSemanticValidator')
            ) {
                $semantic = ComplaintSemanticValidator::validateOpeningComplaint($turn);
                // Block only after validation: confirmed non-health → NEEDS_VALID;
                // UNCLEAR → clarification path (not treated as non-health proof).
                if (!empty($semantic['needs_valid_complaint']) || !empty($semantic['needs_clarification'])) {
                    return self::wrapNeedsValidComplaint($semantic, $turn);
                }
                $nlpText = trim((string) ($semantic['nlp_text'] ?? ''));
                $originalForDisplay = trim((string) ($semantic['original_patient_input'] ?? $originalTurnForLanguage));
                if ($originalForDisplay === '') {
                    $originalForDisplay = $originalTurnForLanguage;
                }

                // Primary AI meaning (Groq → OpenAI → local via MedicalAiInterpreter).
                // Never replaces original patient wording; never sets triage class.
                $ollamaBridge = self::maybeAiMeaningBridge($originalForDisplay, (string) ($semantic['detected_language'] ?? ''));
                if ($ollamaBridge === [] && is_array($semantic['ollama_bridge'] ?? null)) {
                    $ollamaBridge = $semantic['ollama_bridge'];
                }
                if ($ollamaBridge !== []) {
                    $bridgeMeaning = trim((string) ($ollamaBridge['english_interpretation'] ?? ''));
                    $geminiConcept = trim((string) ($semantic['gemini_medical_concept'] ?? ''));
                    if ($bridgeMeaning !== '' && $geminiConcept === '' && $nlpText !== '') {
                        // Enrich NLP copy only; patient-facing chief complaint stays original.
                        if (mb_stripos($nlpText, $bridgeMeaning) === false) {
                            $nlpText = trim($nlpText . '. ' . $bridgeMeaning);
                        }
                    } elseif ($bridgeMeaning !== '' && $nlpText === $originalForDisplay) {
                        $nlpText = trim($originalForDisplay . '. ' . $bridgeMeaning);
                    }
                    $semantic['ollama_bridge'] = $ollamaBridge;
                }

                if ($nlpText !== '') {
                    // Working copy for extractors / enrichment only — never stored as patient_turns.
                    $turn = $nlpText;
                    $context['complaint_text_cleaner'] = [
                        'original' => $originalForDisplay,
                        'cleaned' => $nlpText,
                        'discarded' => is_array($semantic['discarded_tokens'] ?? null) ? $semantic['discarded_tokens'] : [],
                    ];
                    // Additive bridge metadata only — triage still owned by ClinicalTriageEngine.
                    $aiConcepts = [];
                    foreach ((array) (($ollamaBridge['concepts'] ?? []) ?: []) as $c) {
                        if (!is_array($c)) {
                            continue;
                        }
                        $term = trim((string) ($c['term'] ?? ''));
                        if ($term !== '') {
                            $aiConcepts[] = $term;
                        }
                    }
                    $context['semantic_bridge'] = [
                        'source' => (string) ($semantic['combine_reason'] ?? ''),
                        'gemini_concept' => (string) ($semantic['gemini_medical_concept'] ?? ''),
                        'ollama_meaning' => (string) (($ollamaBridge['english_interpretation'] ?? '') ?: ''),
                        'ai_provider' => (string) (($ollamaBridge['provider'] ?? '') ?: ''),
                        'nlp_text' => $nlpText,
                        'original' => $originalForDisplay,
                        'domain_label' => (string) ($semantic['domain_label'] ?? ''),
                        'clinically_vague' => !empty($semantic['clinically_vague']),
                        'evidence_source' => 'ai_bridge',
                    ];
                    if ($aiConcepts !== []) {
                        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : self::blankFacts([]);
                        $aiBag = self::stringList($facts['symptoms_ai'] ?? []);
                        foreach ($aiConcepts as $term) {
                            $term = trim((string) $term);
                            if ($term !== '' && !in_array($term, $aiBag, true)) {
                                $aiBag[] = $term;
                            }
                        }
                        $facts['symptoms_ai'] = $aiBag;
                        $context['facts'] = $facts;
                    }
                    if ($context['chief_complaint'] === '' && $originalForDisplay !== '') {
                        $context['chief_complaint'] = $originalForDisplay;
                    }
                }
            } elseif ($turn !== ''
                && ($context['patient_turns'] ?? []) === []
                && ($context['questions_asked'] ?? []) === []
                && class_exists('HealthComplaintDomainDetector')
            ) {
                // Fallback if semantic validator class is missing.
                $domain = HealthComplaintDomainDetector::detect($turn);
                $routing = (string) ($domain['routing'] ?? '');
                $health = !empty($domain['health_related']);
                $conf = (string) ($domain['confidence'] ?? '');
                if (!$health
                    && in_array($routing, [
                        HealthComplaintDomainDetector::ROUTE_OOS,
                        HealthComplaintDomainDetector::ROUTE_GREETING,
                    ], true)
                    && $conf === HealthComplaintDomainDetector::CONF_HIGH
                ) {
                    return self::wrapDomainSkip($domain, $turn);
                }
            }
        } catch (Throwable $e) {
            error_log('Complaint semantic / domain interview gate fallback: ' . $e->getMessage());
        }

        $awaiting = (string) ($context['awaiting_question_id'] ?? '');

        // Follow-up answers must be relevant to the current question before they become clinical facts.
        if (!$isOpeningTurn && $awaiting !== '' && class_exists('ClinicalFollowUpAnswerValidator')) {
            try {
                $validation = ClinicalFollowUpAnswerValidator::validate($originalTurnForLanguage, $awaiting, $context);
                self::logFollowUpValidation($context, $awaiting, $originalTurnForLanguage, $validation);
                if (empty($validation['accept'])) {
                    // Keep clinically useful free-text (e.g. "Ga suka ko" / breathing red flags)
                    // even when the answer does not satisfy the current question slot.
                    $context = self::absorbOffSlotClinicalFacts($context, $originalTurnForLanguage, $awaiting);
                    return self::wrapRetryCurrentQuestion($context, $validation);
                }
                $context['last_followup_validation'] = $validation;
                $context = self::applyFollowUpValidationFacts($context, $validation, $awaiting);
                $correctedFromValidation = trim((string) ($validation['corrected_answer'] ?? ''));
                if (self::shouldRetryUncertainYesNo($context, $validation, $awaiting)) {
                    // Provenance: store the patient's own wording, not corrected/enriched gloss.
                    $context = self::appendPatientTurn($context, $originalTurnForLanguage);

                    return self::wrapRetryCurrentQuestion($context, $validation);
                }
                if ($correctedFromValidation !== '') {
                    // Working copy for extractors only — patient_turns keep the original answer.
                    $turn = $correctedFromValidation;
                }
            } catch (Throwable $e) {
                error_log('ClinicalFollowUpAnswerValidator fallback: ' . $e->getMessage());
            }
        }

        // Opening turn already dropped non-essential tokens; follow-up answers still get cleaned.
        // Cleaned/normalized text is for extractors only — patient_turns stay patient-authored.
        if (!$isOpeningTurn && $turn !== '' && class_exists('ComplaintTriageTextCleaner')) {
            try {
                $clean = ComplaintTriageTextCleaner::prepare($turn);
                $nlpText = trim((string) ($clean['cleaned'] ?? ''));
                if ($nlpText !== '') {
                    $turn = $nlpText;
                }
            } catch (Throwable $e) {
                error_log('ComplaintTriageTextCleaner follow-up fallback: ' . $e->getMessage());
            }
        }

        // Accuracy: normalize misspellings / slang / mixed tokens before extraction.
        // Opening and follow-up working text is case-folded via centralized forMatch().
        $turnPrep = null;
        try {
            if ($turn !== '' && class_exists('ClinicalAnswerNormalizer')) {
                $turnPrep = ClinicalAnswerNormalizer::prepare($turn, $awaiting);
                $correctedTurn = trim((string) ($turnPrep['corrected'] ?? ''));
                if ($correctedTurn !== '') {
                    $turn = $correctedTurn;
                }
            }
        } catch (Throwable $e) {
            error_log('ClinicalAnswerNormalizer interview fallback: ' . $e->getMessage());
            $turnPrep = null;
        }

        if ($turn !== '' && class_exists('HiligaynonTextNormalizer')) {
            if ($isOpeningTurn) {
                $matchTurn = HiligaynonTextNormalizer::forMatch($turn);
                if ($matchTurn !== '') {
                    $turn = $matchTurn;
                }
            } else {
                // Follow-ups: case-fold only so values like 7/10 stay extractor-safe.
                $folded = HiligaynonTextNormalizer::caseFold($turn);
                if ($folded !== '') {
                    $turn = $folded;
                }
            }
        }

        if (is_array($turnPrep)) {
            $context['last_answer_normalization'] = $turnPrep;
        }

        // Patient-text channel: original utterance only (not nlpText / corrected / AI gloss).
        $patientAuthoredTurn = trim($originalTurnForLanguage);
        if ($patientAuthoredTurn !== '') {
            $context = self::appendPatientTurn($context, $patientAuthoredTurn);
        }

        $transcript = self::transcript($context);
        if ($transcript === '') {
            return self::wrapEmpty();
        }

        if ($context['question_language'] === '') {
            // Detect from the patient's original wording — not post-normalization text —
            // so dataset/synonym rewrites cannot force Hiligaynon/Tagalog replies.
            $langSource = $originalTurnForLanguage !== '' ? $originalTurnForLanguage : $transcript;
            $detected = HiligaynonLanguageDetector::detect($langSource);
            $context['detected_language'] = strtoupper(self::languageLabel((string) ($detected['primary'] ?? 'english')));
            $context['question_language'] = self::questionLanguageFromDetection($detected, $langSource);
        } else {
            $latest = HiligaynonLanguageDetector::detect($originalTurnForLanguage !== '' ? $originalTurnForLanguage : $turn);
            if (($latest['primary'] ?? '') !== '' && ($latest['primary'] ?? 'unknown') !== 'unknown') {
                $context['detected_language'] = strtoupper(self::languageLabel((string) $latest['primary']));
            }
        }

        $awaiting = (string) ($context['awaiting_question_id'] ?? '');
        $context = self::mergeExtractedFacts($context, $turn, $awaiting);

        // Optional Python ai_service strengthen (symptoms/gloss only). PHP triage stays authority.
        $context = self::maybePythonEnrichment($context, $turn, $isOpeningTurn);

        // Recalculate triage from the complete accumulated case (complaint + turns + structured facts).
        // Bare yes/no turns are excluded from the haystack; polarity lives in structured facts.
        // Stage 2A: still pass clinicalContextText for parity; also pass structured interviewFacts (unused for decisions yet).
        $clinicalText = self::clinicalContextText($context, self::clinicalTranscript($context));
        $interviewFacts = self::interviewFactsForTriage($context);
        $raw = ClinicalTriageEngine::assess($clinicalText, $clinicalText, [], [], 0, true, $interviewFacts);
        $assessment = self::assessmentFromEngine($raw, $transcript, (string) ($raw['english_translation'] ?? $transcript), $checkboxSymptoms);
        $nlpFacts = self::factsFromAssessment($assessment, $clinicalText);
        $context['facts'] = self::mergeFacts($context['facts'], $nlpFacts);
        $context = self::absorbAssessmentIntoCase($context, $assessment, $raw);
        $context['chief_complaints'] = ClinicalInterviewContextResolver::deriveComplaints($assessment, $clinicalText, $context['facts']);
        if (class_exists('ClinicalInterviewMultiComplaint')) {
            $context = ClinicalInterviewMultiComplaint::syncTracks($context, $assessment, $clinicalText);
            $context = ClinicalInterviewMultiComplaint::persistActiveFacts($context);
        }
        $context['matched_dataset_entries'] = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(
                is_array($context['matched_dataset_entries'] ?? null) ? $context['matched_dataset_entries'] : [],
                is_array($assessment['detected_symptoms'] ?? null) ? $assessment['detected_symptoms'] : [],
                self::stringList($context['facts']['symptoms'] ?? [])
            )
        ))));

        $expandedText = self::clinicalContextText($context, self::clinicalTranscript($context));
        if ($expandedText !== $clinicalText && $expandedText !== '') {
            $clinicalText = $expandedText;
            $interviewFacts = self::interviewFactsForTriage($context);
            $raw = ClinicalTriageEngine::assess($clinicalText, $clinicalText, [], [], 0, true, $interviewFacts);
            $assessment = self::assessmentFromEngine(
                $raw,
                $transcript,
                (string) ($raw['english_translation'] ?? $transcript),
                $checkboxSymptoms
            );
            $context['facts'] = self::mergeFacts($context['facts'], self::factsFromAssessment($assessment, $clinicalText));
            $context = self::absorbAssessmentIntoCase($context, $assessment, $raw);
            if (class_exists('ClinicalInterviewMultiComplaint')) {
                $context = ClinicalInterviewMultiComplaint::persistActiveFacts($context);
            }
        }

        $redFlags = self::redFlagNames($assessment);
        $trueEmergency = $redFlags !== [];

        if ($trueEmergency) {
            // Emergency finalize from COMPLETE case through ClinicalTriageEngine (not MAX of tracks).
            if (class_exists('ClinicalInterviewMultiComplaint')) {
                $context = ClinicalInterviewMultiComplaint::persistActiveFacts($context);
                $caseText = ClinicalInterviewMultiComplaint::completeCaseHaystack(
                    $context,
                    self::clinicalTranscript($context)
                );
                if ($caseText !== '') {
                    $interviewFacts = self::interviewFactsForTriage($context);
                    $raw = ClinicalTriageEngine::assess($caseText, $caseText, [], [], 0, true, $interviewFacts);
                    $assessment = self::assessmentFromEngine(
                        $raw,
                        $transcript,
                        (string) ($raw['english_translation'] ?? $transcript),
                        $checkboxSymptoms
                    );
                }
                $context['complaint_provisionals'] = ClinicalInterviewMultiComplaint::provisionalTrackAssessments($context);
                $context = ClinicalInterviewMultiComplaint::markTracksCompleted($context);
            }

            return self::finalize($assessment, $context, 'EMERGENCY', $transcript);
        }

        $missing = null;
        if (self::needsFollowUpQuestion($assessment, $context, $clinicalText, $raw)) {
            if (class_exists('ClinicalInterviewMultiComplaint')) {
                $context = ClinicalInterviewMultiComplaint::prepareForNextQuestion($context, $assessment, $clinicalText);
            }
            $missing = self::nextQuestion($context, $clinicalText, $assessment);
        }
        $askedCount = count((array) ($context['_questions_asked_union'] ?? $context['questions_asked'] ?? []));
        if ($askedCount === 0) {
            $askedCount = count($context['questions_asked']);
        }
        $trackCount = count((array) ($context['complaints'] ?? []));
        $maxQuestions = $trackCount > 1
            ? min(12, self::MAX_QUESTIONS * $trackCount)
            : self::MAX_QUESTIONS;
        $factsNow = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $mustKeepInterviewing = !empty($factsNow['needs_associated_detail'])
            || (($factsNow['has_other_symptoms'] ?? null) === true
                && self::stringList($factsNow['associated_symptoms'] ?? []) === []
                && empty($factsNow['denied_associated']));
        // Adaptive policy still insufficient → do not treat "no next question" as complete
        // (candidates may be empty while WHO-critical facts remain null).
        $adaptiveReady = true;
        if (class_exists('ClinicalInterviewAdaptivePolicy')) {
            try {
                $adaptiveReady = ClinicalInterviewAdaptivePolicy::isTriageSufficient(
                    $context,
                    $clinicalText,
                    $assessment
                );
            } catch (Throwable $e) {
                error_log('isTriageSufficient during sufficient gate: ' . $e->getMessage());
                $adaptiveReady = true;
            }
        }
        if ($missing === null && !$adaptiveReady && $askedCount < $maxQuestions && !$mustKeepInterviewing) {
            if (class_exists('ClinicalInterviewMultiComplaint')) {
                $context = ClinicalInterviewMultiComplaint::prepareForNextQuestion($context, $assessment, $clinicalText);
            }
            $missing = self::nextQuestion($context, $clinicalText, $assessment);
        }
        // Cap question count, but never finalize solely because $missing === null when
        // adaptive policy is not ready — and never let MAX_QUESTIONS alone force
        // NON-URGENT while WHO-critical gaps remain.
        $hitQuestionCap = $askedCount >= $maxQuestions;
        $sufficient = ($missing === null && $adaptiveReady) && !$mustKeepInterviewing;
        if (!$sufficient && $hitQuestionCap) {
            if ($missing === null && !$adaptiveReady) {
                if (class_exists('ClinicalInterviewMultiComplaint')) {
                    $context = ClinicalInterviewMultiComplaint::prepareForNextQuestion(
                        $context,
                        $assessment,
                        $clinicalText
                    );
                }
                $missing = self::nextQuestion($context, $clinicalText, $assessment);
            }
            if ($missing !== null && !$adaptiveReady) {
                // Soft over-cap: keep asking open WHO/adaptive slots.
                $sufficient = false;
            } elseif (!$adaptiveReady && $missing === null && !$mustKeepInterviewing) {
                // No further askable slot — finalize on known facts only; flag incomplete
                // (do not invent negatives for unanswered WHO criteria).
                $sufficient = true;
                $context['who_interview_incomplete'] = true;
            } elseif ($adaptiveReady && !$mustKeepInterviewing) {
                $sufficient = true;
            }
        }
        if (class_exists('ClinicalInterviewMultiComplaint')
            && count((array) ($context['complaints'] ?? [])) > 1
            && $sufficient
            && !ClinicalInterviewMultiComplaint::allTracksSufficient($context, $clinicalText, $assessment)
        ) {
            $sufficient = false;
            if ($missing === null) {
                $context = ClinicalInterviewMultiComplaint::prepareForNextQuestion($context, $assessment, $clinicalText);
                $missing = self::nextQuestion($context, $clinicalText, $assessment);
            }
        }
        if ($mustKeepInterviewing && $missing === null) {
            $missing = self::nextQuestion($context, $clinicalText, $assessment);
            if ($missing === null && class_exists('ClinicalFollowUpQuestionBank')) {
                $detail = ClinicalFollowUpQuestionBank::byId('ASSOCIATED_DETAIL');
                if (is_array($detail)) {
                    $lang = self::normalizeQuestionLanguage((string) ($context['question_language'] ?? 'english'));
                    $missing = [
                        'question_id' => 'ASSOCIATED_DETAIL',
                        'clinical_purpose' => (string) ($detail['clinical_purpose'] ?? 'Name the additional symptom'),
                        'red_flag_related' => false,
                        'priority' => 4,
                        'text' => (string) ($detail[$lang] ?? $detail['english'] ?? ''),
                        'language' => $lang,
                    ];
                }
            }
            $sufficient = $missing === null;
        }

        // Not ready and still no question: hold the last follow-up instead of false NON-URGENT.
        if (!$sufficient && $missing === null && $askedCount < $maxQuestions) {
            $held = self::questionSnapshot($context['last_followup_question'] ?? null);
            if (($held['question_id'] ?? '') !== '') {
                $missing = $held;
            }
        }

        if (!$sufficient && $missing !== null) {
            $qid = (string) ($missing['question_id'] ?? '');
            if ($qid !== '' && !in_array($qid, $context['questions_asked'], true)) {
                $context['questions_asked'][] = $qid;
            }
            $finding = strtolower(trim((string) ($missing['target_finding'] ?? '')));
            if ($finding !== '') {
                $askedFindings = array_map('strtolower', array_map('strval', (array) ($context['findings_asked'] ?? [])));
                if (!in_array($finding, $askedFindings, true)) {
                    $askedFindings[] = $finding;
                }
                $context['findings_asked'] = array_values($askedFindings);
            }
            $context['awaiting_target_finding'] = $finding;
            $context['awaiting_parent_question_id'] = strtoupper(trim((string) ($missing['parent_question_id'] ?? '')));
            // Keep a union for the global question cap across multi-complaint tracks.
            $union = array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge(
                    (array) ($context['_questions_asked_union'] ?? []),
                    (array) ($context['questions_asked'] ?? [])
                )
            ))));
            $context['_questions_asked_union'] = $union;
            $context['awaiting_question_id'] = $qid;
            $context['questions_already_asked'] = $union;
            $context['assessment_status'] = self::STATUS_IN_PROGRESS;
            if (class_exists('ClinicalInterviewMultiComplaint')) {
                $context = ClinicalInterviewMultiComplaint::persistActiveFacts($context);
                // Public interview should expose the union of asked slots.
                $context['questions_asked'] = $union;
            }

            return self::wrapInProgress($assessment, $context, $missing, $transcript);
        }

        // Sufficient: final class from COMPLETE-CASE ClinicalTriageEngine only (never MAX of tracks).
        $whoInterviewIncomplete = !empty($context['who_interview_incomplete']);
        if (class_exists('ClinicalInterviewMultiComplaint')) {
            $context = ClinicalInterviewMultiComplaint::persistActiveFacts($context);
            $caseText = ClinicalInterviewMultiComplaint::completeCaseHaystack(
                $context,
                self::clinicalTranscript($context)
            );
            if ($caseText === '') {
                $caseText = self::clinicalContextText($context, self::clinicalTranscript($context));
            }
            $interviewFacts = self::interviewFactsForTriage($context);
            $raw = ClinicalTriageEngine::assess($caseText, $caseText, [], [], 0, true, $interviewFacts);
            $assessment = self::assessmentFromEngine(
                $raw,
                $transcript,
                (string) ($raw['english_translation'] ?? $transcript),
                $checkboxSymptoms
            );
            $context['complaint_provisionals'] = ClinicalInterviewMultiComplaint::provisionalTrackAssessments($context);
            $context = ClinicalInterviewMultiComplaint::markTracksCompleted($context);
        }

        $display = self::finalDisplayFromAssessment($assessment);
        if ($whoInterviewIncomplete) {
            if (!isset($assessment['triage']) || !is_array($assessment['triage'])) {
                $assessment['triage'] = [];
            }
            $assessment['triage']['needs_provider_review'] = true;
            $assessment['triage']['interview_incomplete'] = true;
            $assessment['triage']['confidence_accepted'] = false;
            $assessment['triage']['who_interview_incomplete'] = true;
            // Missing WHO slots are unknown — never treat them as negative findings.
            $assessment['needs_provider_review'] = true;
        }
        $display = self::applyInterviewSafetyOverride($display, $context, $assessment);

        return self::finalize($assessment, $context, $display, $transcript);
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string> $checkboxSymptoms
     * @return array<string, mixed>
     */
    private static function assessmentFromEngine(array $raw, string $transcript, string $english, array $checkboxSymptoms): array
    {
        $display = (string) ($raw['triage_display'] ?? 'NON-URGENT');
        if (!in_array($display, self::FINAL_CLASSES, true)) {
            $display = 'NON-URGENT';
        }

        return [
            'engine_version' => (string) ($raw['engine_version'] ?? MedicalAssessmentEngine::VERSION),
            'engine' => (string) ($raw['source'] ?? 'clinical-triage-engine-interview'),
            'chief_complaint' => $transcript,
            'original_chief_complaint' => $transcript,
            'english_translation' => $english,
            'detected_language' => (string) ($raw['detected_language'] ?? ''),
            'detected_symptoms' => is_array($raw['detected_symptoms'] ?? null) ? $raw['detected_symptoms'] : [],
            'possible_conditions' => [],
            'checkbox_symptoms' => $checkboxSymptoms,
            'confidence' => [
                'score' => (int) ($raw['confidence_score'] ?? 0),
                'level' => (string) ($raw['confidence_level'] ?? ''),
                'level_label' => (string) ($raw['confidence_level_label'] ?? ''),
            ],
            'severity' => [
                'severity' => (string) ($raw['severity'] ?? 'mild'),
                'severity_score' => (int) ($raw['severity_score'] ?? 0),
            ],
            'triage' => $raw,
            'recommendations' => array_values(array_filter([
                (string) ($raw['recommended_action'] ?? $raw['recommendation'] ?? ''),
            ])),
            'recommended_action' => (string) ($raw['recommended_action'] ?? ''),
        ];
    }

    public static function normalizeQuestionLanguage(string $language): string
    {
        $raw = strtolower(trim($language));
        if (in_array($raw, ['tagalog', 'filipino', 'tl', 'fil'], true)) {
            return 'tagalog';
        }
        if (in_array($raw, ['english', 'en'], true)) {
            return 'english';
        }

        return 'hiligaynon';
    }

    /**
     * @param array<string, mixed> $detection
     */
    public static function questionLanguageFromDetection(array $detection, string $text): string
    {
        $low = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text) ?? $text));
        if ($low === 'sakit') {
            return 'hiligaynon';
        }
        if ($low === 'masakit') {
            return 'tagalog';
        }
        if (in_array($low, ['it hurts', 'it hurt', 'hurts', 'hurt', 'pain', 'something hurts'], true)) {
            return 'english';
        }

        $primary = strtolower((string) ($detection['dominant'] ?? $detection['primary'] ?? 'hiligaynon'));
        if ($primary === 'mixed') {
            $tags = is_array($detection['tags'] ?? null) ? $detection['tags'] : [];
            if (in_array('english', $tags, true) && !in_array('hiligaynon', $tags, true) && !in_array('tagalog', $tags, true)) {
                return 'english';
            }
            if (in_array('tagalog', $tags, true) && !in_array('hiligaynon', $tags, true)) {
                return 'tagalog';
            }
            if (in_array('hiligaynon', $tags, true) && !in_array('tagalog', $tags, true)) {
                return 'hiligaynon';
            }
            if (in_array('tagalog', $tags, true)) {
                return 'tagalog';
            }
            if (in_array('hiligaynon', $tags, true)) {
                return 'hiligaynon';
            }
            if (in_array('english', $tags, true)) {
                return 'english';
            }
        }

        return self::normalizeQuestionLanguage($primary);
    }

    public static function languageLabel(string $primary): string
    {
        return match (strtolower(trim($primary))) {
            'tagalog', 'filipino' => 'TAGALOG',
            'english' => 'ENGLISH',
            'mixed' => 'MIXED',
            default => 'HILIGAYNON',
        };
    }

    /**
     * @param array<string, mixed> $assessment
     */
    public static function isInProgress(array $assessment): bool
    {
        $status = strtoupper((string) ($assessment['assessment_status'] ?? ($assessment['interview']['assessment_status'] ?? '')));

        return $status === self::STATUS_IN_PROGRESS;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalizeContext(array $raw): array
    {
        if (isset($raw['interview']) && is_array($raw['interview'])) {
            $raw = $raw['interview'];
        }
        $facts = is_array($raw['facts'] ?? null) ? $raw['facts'] : [];

        return [
            'chief_complaint' => (string) ($raw['chief_complaint'] ?? ''),
            'detected_language' => (string) ($raw['detected_language'] ?? ''),
            'question_language' => (($ql = trim((string) ($raw['question_language'] ?? ''))) === ''
                ? ''
                : self::normalizeQuestionLanguage($ql)),
            'patient_turns' => self::stringList($raw['patient_turns'] ?? []),
            'questions_asked' => self::stringList($raw['questions_asked'] ?? $raw['questions_already_asked'] ?? []),
            'questions_answered' => is_array($raw['questions_answered'] ?? null) ? $raw['questions_answered'] : [],
            'awaiting_question_id' => strtoupper((string) ($raw['awaiting_question_id'] ?? '')),
            'last_followup_question' => self::questionSnapshot($raw['last_followup_question'] ?? $raw['next_question'] ?? null),
            'chief_complaints' => is_array($raw['chief_complaints'] ?? null) ? $raw['chief_complaints'] : [],
            'matched_dataset_entries' => self::stringList($raw['matched_dataset_entries'] ?? []),
            'facts' => self::blankFacts($facts),
            'assessment_status' => strtoupper((string) ($raw['assessment_status'] ?? self::STATUS_IN_PROGRESS)),
            'semantic_bridge' => is_array($raw['semantic_bridge'] ?? null) ? $raw['semantic_bridge'] : [],
            'complaint_text_cleaner' => is_array($raw['complaint_text_cleaner'] ?? null) ? $raw['complaint_text_cleaner'] : [],
            'complaints' => is_array($raw['complaints'] ?? null) ? $raw['complaints'] : [],
            'active_complaint_id' => (string) ($raw['active_complaint_id'] ?? ''),
            'python_enrichment' => is_array($raw['python_enrichment'] ?? null) ? $raw['python_enrichment'] : [],
            'findings_asked' => self::stringList($raw['findings_asked'] ?? []),
            'awaiting_target_finding' => strtolower(trim((string) ($raw['awaiting_target_finding'] ?? ''))),
            'awaiting_parent_question_id' => strtoupper(trim((string) ($raw['awaiting_parent_question_id'] ?? ''))),
        ];
    }

    /**
     * Append one patient-authored turn. Callers must pass the original utterance —
     * never Gemini/Ollama/Python gloss, corrected_answer, or cleaned nlp_text.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function appendPatientTurn(array $context, string $patientAuthoredTurn): array
    {
        $patientAuthoredTurn = trim($patientAuthoredTurn);
        if ($patientAuthoredTurn === '') {
            return $context;
        }
        if ($context['chief_complaint'] === '') {
            $original = trim((string) (($context['complaint_text_cleaner']['original'] ?? '') ?: $patientAuthoredTurn));
            $context['chief_complaint'] = $original !== '' ? $original : $patientAuthoredTurn;
        }
        $context['patient_turns'][] = $patientAuthoredTurn;
        $awaiting = (string) ($context['awaiting_question_id'] ?? '');
        if ($awaiting !== '') {
            $context['questions_answered'][] = [
                'question_id' => $awaiting,
                'answer' => $patientAuthoredTurn,
            ];
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function transcript(array $context): string
    {
        $turns = array_values(array_filter(array_map('trim', $context['patient_turns'])));

        return trim(implode('. ', $turns));
    }

    /**
     * Patient-authored turns for clinical text (skip bare yes/no; polarity lives in facts).
     * patient_turns must already be original patient wording only.
     *
     * @param array<string, mixed> $context
     */
    private static function clinicalTranscript(array $context): string
    {
        $turns = [];
        foreach ((array) ($context['patient_turns'] ?? []) as $turn) {
            if (!is_string($turn)) {
                continue;
            }
            $turn = trim($turn);
            if ($turn === '') {
                continue;
            }
            $low = mb_strtolower($turn);
            $yn = ClinicalFeatureExtractors::extractYesNo($turn);
            $bareYesNo = $yn !== null
                && !preg_match(
                    '/\b(may|mayroon|naa|gina|naga|ga|kasakit|sakit|pain|suka|ubo|hilo|hubag|ginhawa|breath|makaginhawa)\b/u',
                    $low
                )
                && mb_strlen(preg_replace('/\s+/u', ' ', $low)) <= 12;
            if ($bareYesNo) {
                continue;
            }
            $turns[] = $turn;
        }

        return trim(implode('. ', $turns));
    }

    /**
     * Patient-authored evidence only: chief complaint + original patient turns.
     * Excludes factsHaystack(), semantic_bridge.*, Python gloss, and KB-derived labels.
     * Stable across AI/KB enrichment (Stage 1 patient-text channel).
     *
     * @param array<string, mixed> $context
     */
    public static function patientEvidenceText(array $context): string
    {
        $parts = [];
        $complaint = trim((string) ($context['chief_complaint'] ?? ''));
        if ($complaint !== '') {
            $parts[] = $complaint;
        }
        $originalCleaner = trim((string) (($context['complaint_text_cleaner']['original'] ?? '') ?: ''));
        if ($originalCleaner !== '' && mb_strtolower($originalCleaner) !== mb_strtolower($complaint)) {
            $parts[] = $originalCleaner;
        }
        $turns = self::clinicalTranscript($context);
        if ($turns !== '') {
            $parts[] = $turns;
        }

        return trim(implode('. ', array_values(array_unique($parts))));
    }

    /**
     * Full clinical context for Stage-1 triage input (patient text + structured/AI enrichment).
     * Enrichment stays here — not in patientEvidenceText / patient_turns.
     * Cleaned NLP turns alone can drop soft timing phrases; original complaint must remain.
     *
     * @param array<string, mixed> $context
     */
    private static function clinicalContextText(array $context, string $transcript = ''): string
    {
        $parts = [];
        // Patient-authored base (stable when enrichment changes).
        $patientOnly = self::patientEvidenceText($context);
        if ($patientOnly !== '') {
            $parts[] = $patientOnly;
        } else {
            $turns = trim($transcript !== '' ? $transcript : self::clinicalTranscript($context));
            if ($turns === '' && self::transcript($context) !== '') {
                $turns = self::clinicalTranscript($context);
            }
            if ($turns !== '') {
                $parts[] = $turns;
            }
        }
        // System / structured / AI enrichment — interpretation aids for triage, not patient text.
        $factsHaystack = self::factsHaystack(is_array($context['facts'] ?? null) ? $context['facts'] : []);
        if ($factsHaystack !== '') {
            $parts[] = $factsHaystack;
        }
        if (class_exists('ClinicalInterviewMultiComplaint')) {
            foreach (ClinicalInterviewMultiComplaint::multiFactsHaystackParts($context) as $chunk) {
                $chunk = trim((string) $chunk);
                if ($chunk !== '') {
                    $parts[] = $chunk;
                }
            }
        }
        // Include Gemini lexical bridge concept so existing dataset NLP can match
        // without replacing the patient's original complaint wording.
        $bridgeConcept = trim((string) (($context['semantic_bridge']['gemini_concept'] ?? '') ?: ''));
        if ($bridgeConcept !== '') {
            $parts[] = $bridgeConcept;
        }
        $ollamaMeaning = trim((string) (($context['semantic_bridge']['ollama_meaning'] ?? '') ?: ''));
        if ($ollamaMeaning !== '' && mb_stripos(implode(' ', $parts), $ollamaMeaning) === false) {
            $parts[] = $ollamaMeaning;
        }
        $pythonGloss = trim((string) (($context['semantic_bridge']['python_gloss'] ?? '') ?: ''));
        if ($pythonGloss !== '' && mb_stripos(implode(' ', $parts), $pythonGloss) === false) {
            $parts[] = $pythonGloss;
        }
        foreach (self::stringList($context['semantic_bridge']['python_symptoms'] ?? []) as $pySymptom) {
            if ($pySymptom !== '' && mb_stripos(implode(' ', $parts), $pySymptom) === false) {
                $parts[] = $pySymptom;
            }
        }

        return trim(implode('. ', array_values(array_unique($parts))));
    }

    /**
     * @param array<string, mixed> $seed
     * @return array<string, mixed>
     */
    private static function blankFacts(array $seed): array
    {
        $yesNo = static function (mixed $v): ?bool {
            if ($v === true || $v === false) {
                return $v;
            }
            if ($v === null || $v === '') {
                return null;
            }
            $s = strtolower(trim((string) $v));
            if (in_array($s, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($s, ['0', 'false', 'no'], true)) {
                return false;
            }

            return null;
        };

        return [
            'body_locations' => self::stringList($seed['body_locations'] ?? []),
            'symptoms' => self::stringList($seed['symptoms'] ?? []),
            'associated_symptoms' => self::stringList($seed['associated_symptoms'] ?? []),
            'negative_symptoms' => self::stringList($seed['negative_symptoms'] ?? []),
            // Stage 2 Batch 1: provenance buckets (legacy lists above stay for AdaptivePolicy/UI).
            'symptoms_patient' => self::stringList($seed['symptoms_patient'] ?? []),
            'symptoms_kb' => self::stringList($seed['symptoms_kb'] ?? []),
            'symptoms_ai' => self::stringList($seed['symptoms_ai'] ?? []),
            'vital_signs' => self::stringList($seed['vital_signs'] ?? []),
            'risk_factors' => self::stringList($seed['risk_factors'] ?? []),
            'red_flags' => self::stringList($seed['red_flags'] ?? []),
            'medical_history' => self::stringList($seed['medical_history'] ?? []),
            'pain_score' => isset($seed['pain_score']) && $seed['pain_score'] !== null && $seed['pain_score'] !== ''
                ? (int) $seed['pain_score']
                : null,
            'pain_qualifier' => (string) ($seed['pain_qualifier'] ?? ''),
            'onset' => (string) ($seed['onset'] ?? ''),
            'duration_label' => (string) ($seed['duration_label'] ?? ''),
            'progression' => (string) ($seed['progression'] ?? ''),
            'denied_associated' => (bool) ($seed['denied_associated'] ?? false),
            'weakness' => $yesNo($seed['weakness'] ?? null),
            'speech_difficulty' => $yesNo($seed['speech_difficulty'] ?? null),
            'vision_change' => $yesNo($seed['vision_change'] ?? null),
            'breathing_difficulty' => $yesNo($seed['breathing_difficulty'] ?? null),
            'bleeding_continuing' => $yesNo($seed['bleeding_continuing'] ?? null),
            'bleeding_heavy' => $yesNo($seed['bleeding_heavy'] ?? null),
            'dizziness' => $yesNo($seed['dizziness'] ?? null),
            'chest_radiation' => $yesNo($seed['chest_radiation'] ?? null),
            'sweating' => $yesNo($seed['sweating'] ?? null),
            'abdominal_associated' => $yesNo($seed['abdominal_associated'] ?? null),
            'has_other_symptoms' => $yesNo($seed['has_other_symptoms'] ?? null),
            'needs_associated_detail' => (bool) ($seed['needs_associated_detail'] ?? false),
            'finding_status' => is_array($seed['finding_status'] ?? null) ? $seed['finding_status'] : [],
            'fever_confirmed' => $yesNo($seed['fever_confirmed'] ?? null),
            'blood_in_stool' => $yesNo($seed['blood_in_stool'] ?? null),
            'pregnancy' => $yesNo($seed['pregnancy'] ?? null),
            'patient_uncertain' => (bool) ($seed['patient_uncertain'] ?? false),
            'patient_conditional' => (bool) ($seed['patient_conditional'] ?? false),
            'clinical_state' => is_array($seed['clinical_state'] ?? null) ? $seed['clinical_state'] : [],
            'body_location_matches' => is_array($seed['body_location_matches'] ?? null)
                ? $seed['body_location_matches']
                : [],
            'body_location_verification' => is_array($seed['body_location_verification'] ?? null)
                ? $seed['body_location_verification']
                : [],
            'needs_location_clarification' => (bool) ($seed['needs_location_clarification'] ?? false),
        ];
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
            } elseif (is_array($item)) {
                $label = trim((string) ($item['id'] ?? $item['name'] ?? $item['label'] ?? ''));
                if ($label !== '') {
                    $out[] = $label;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Stage 2 Batch 1: record a symptom name with provenance.
     * Always keeps legacy facts.symptoms compatible with AdaptivePolicy/UI/haystack.
     *
     * @param array<string, mixed> $facts
     * @param 'patient'|'kb'|'ai' $source
     * @return array<string, mixed>
     */
    private static function recordSymptomName(array $facts, string $name, string $source): array
    {
        $name = trim($name);
        if ($name === '') {
            return $facts;
        }
        $negList = self::stringList($facts['negative_symptoms'] ?? []);
        foreach ($negList as $neg) {
            if (self::symptomMatchesConcept($name, $neg)) {
                return $facts;
            }
        }

        $legacy = self::stringList($facts['symptoms'] ?? []);
        if (!in_array($name, $legacy, true)) {
            $legacy[] = $name;
        }
        $facts['symptoms'] = $legacy;

        $bucket = match ($source) {
            'kb' => 'symptoms_kb',
            'ai' => 'symptoms_ai',
            default => 'symptoms_patient',
        };
        $prov = self::stringList($facts[$bucket] ?? []);
        if (!in_array($name, $prov, true)) {
            $prov[] = $name;
        }
        $facts[$bucket] = $prov;

        return $facts;
    }

    /**
     * Drop negated names from legacy + provenance symptom buckets.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function filterSymptomsAgainstNegatives(array $facts): array
    {
        $negList = self::stringList($facts['negative_symptoms'] ?? []);
        if ($negList === []) {
            return $facts;
        }
        $keep = static function (string $name) use ($negList): bool {
            foreach ($negList as $neg) {
                if (self::symptomMatchesConcept($name, $neg)) {
                    return false;
                }
            }

            return true;
        };
        foreach (['symptoms', 'symptoms_patient', 'symptoms_kb', 'symptoms_ai'] as $key) {
            $facts[$key] = array_values(array_filter(self::stringList($facts[$key] ?? []), $keep));
        }

        return $facts;
    }

    /**
     * Stage 2A: structured interview facts for ClinicalTriageEngine (plumbing only).
     * Single-complaint → current context facts.
     * Multi-complaint → active facts + per-track facts (no synthetic English phrases).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function interviewFactsForTriage(array $context): array
    {
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $tracks = is_array($context['complaints'] ?? null) ? $context['complaints'] : [];
        $patientEvidenceText = self::patientEvidenceText($context);

        if (count($tracks) <= 1) {
            // Stage 2 Batch 4: always expose patient_evidence_text for shadow comparison.
            // Does not change clinical fact meanings; key is ignored by normalizeFactBag polarity.
            $facts['patient_evidence_text'] = $patientEvidenceText;

            return $facts;
        }

        $trackPacks = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $trackPacks[] = [
                'complaint_id' => (string) ($track['id'] ?? ''),
                'text_span' => (string) ($track['text_span'] ?? ''),
                'family_keys' => is_array($track['family_keys'] ?? null) ? $track['family_keys'] : [],
                'facts' => is_array($track['facts'] ?? null) ? $track['facts'] : [],
            ];
        }

        return [
            'active_complaint_id' => (string) ($context['active_complaint_id'] ?? ''),
            'active_facts' => $facts,
            'tracks' => $trackPacks,
            'patient_evidence_text' => $patientEvidenceText,
        ];
    }

    /**
     * Serialize accumulated interview facts into phrases ClinicalTriageEngine / WHO IITT understand.
     * Public so finalize haystacks (including single-complaint) include polarity facts.
     *
     * @param array<string, mixed> $facts
     */
    public static function factsHaystack(array $facts): string
    {
        $parts = [];
        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
            $parts[] = 'pain ' . (int) $facts['pain_score'] . '/10';
        }
        $onset = trim((string) ($facts['onset'] ?? ''));
        if ($onset === 'uncertain') {
            $parts[] = 'onset uncertain';
            $parts[] = 'patient unsure about onset';
        } elseif ($onset !== '') {
            $parts[] = $onset === 'sudden' ? 'sudden onset' : ($onset === 'gradual' ? 'gradual onset' : $onset);
        }
        if (!empty($facts['patient_uncertain'])) {
            $parts[] = 'patient uncertain about some symptoms';
        }
        if (!empty($facts['needs_associated_detail'])) {
            $parts[] = 'patient confirmed other complaints exist but has not named them yet';
        }
        $duration = trim((string) ($facts['duration_label'] ?? ''));
        if ($duration !== '') {
            $parts[] = $duration;
        }
        $qualifier = trim((string) ($facts['pain_qualifier'] ?? ''));
        if ($qualifier !== '') {
            $parts[] = $qualifier . ' pain';
        }
        foreach (self::stringList($facts['body_locations'] ?? []) as $loc) {
            $parts[] = $loc;
        }
        foreach (array_merge(
            self::stringList($facts['symptoms'] ?? []),
            self::stringList($facts['associated_symptoms'] ?? [])
        ) as $symptom) {
            $parts[] = $symptom;
        }
        $positive = [
            'weakness' => 'weakness in one arm or leg',
            'speech_difficulty' => 'difficulty speaking',
            'vision_change' => 'sudden vision change',
            'breathing_difficulty' => 'difficulty breathing',
            'bleeding_continuing' => 'ongoing bleeding',
            'bleeding_heavy' => 'heavy bleeding',
            'dizziness' => 'dizziness',
            'chest_radiation' => 'chest pain spreading to arm',
            'sweating' => 'sweating with chest pain',
            'abdominal_associated' => 'vomiting with abdominal pain',
            'fever_confirmed' => 'fever',
            'blood_in_stool' => 'blood in stool',
            'pregnancy' => 'pregnant',
        ];
        foreach ($positive as $key => $phrase) {
            if (($facts[$key] ?? null) === true) {
                $parts[] = $phrase;
            } elseif (($facts[$key] ?? null) === false) {
                $parts[] = 'no ' . $phrase;
            }
        }
        // Chest-atom presence is scoped in finding_status; still surface for WHO/IITT haystack
        // without writing shared dizziness that would close DIZZINESS_TYPE.
        $findingStatus = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        if (($findingStatus['dizziness_with_chest'] ?? '') === 'positive'
            && ($facts['dizziness'] ?? null) !== true
        ) {
            $parts[] = 'dizziness';
        }
        foreach (self::stringList($facts['negative_symptoms'] ?? []) as $neg) {
            $parts[] = 'no ' . $neg;
            $parts[] = 'wala ko ' . $neg;
        }
        foreach (self::stringList($facts['risk_factors'] ?? []) as $risk) {
            $parts[] = $risk;
        }
        foreach (self::stringList($facts['vital_signs'] ?? []) as $vital) {
            $parts[] = $vital;
        }
        foreach (self::stringList($facts['medical_history'] ?? []) as $hx) {
            $parts[] = $hx;
        }

        $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));

        return implode('. ', $parts);
    }

    /**
     * Fold engine output into the accumulated clinical case without duplicating or undoing negations.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function absorbAssessmentIntoCase(array $context, array $assessment, array $raw): array
    {
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $negated = self::stringList($raw['negated_concepts'] ?? []);
        if ($negated === []) {
            $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
            $negated = self::stringList($triage['negated_concepts'] ?? []);
        }
        $negList = self::stringList($facts['negative_symptoms'] ?? []);
        foreach ($negated as $neg) {
            $neg = strtolower(trim($neg));
            if ($neg !== '' && !in_array($neg, $negList, true)) {
                $negList[] = $neg;
            }
        }
        $facts['negative_symptoms'] = $negList;

        // KB-derived engine labels → symptoms_kb (not patient-reported evidence).
        // Legacy facts.symptoms still updated for AdaptivePolicy/UI/haystack compatibility.
        foreach (self::stringList($assessment['detected_symptoms'] ?? []) as $name) {
            $facts = self::recordSymptomName($facts, $name, 'kb');
        }
        $facts = self::filterSymptomsAgainstNegatives($facts);

        $flags = self::redFlagNames($assessment);
        $facts['red_flags'] = array_values(array_unique(array_merge(
            self::stringList($facts['red_flags'] ?? []),
            $flags
        )));
        $context['facts'] = $facts;
        $context['red_flags'] = $facts['red_flags'];

        return $context;
    }

    private static function symptomMatchesConcept(string $symptom, string $concept): bool
    {
        $symptom = strtolower(trim($symptom));
        $concept = strtolower(trim($concept));
        if ($symptom === '' || $concept === '') {
            return false;
        }
        if ($symptom === $concept || str_contains($symptom, $concept) || str_contains($concept, $symptom)) {
            return true;
        }
        $aliases = [
            ['cough', 'ubo'],
            ['fever', 'lagnat', 'hilanat'],
            ['vomiting', 'suka', 'nausea'],
            ['diarrhea', 'diarrhoea', 'libang', 'galupot', 'kalibanga'],
            ['headache', 'sakit ulo'],
            ['difficulty breathing', 'shortness of breath', 'dyspnea', 'ginhawa'],
            ['chest pain', 'dughan', 'dibdib'],
            ['dizziness', 'dizzy', 'lipong', 'hilo'],
        ];
        foreach ($aliases as $words) {
            $inConcept = false;
            $inSymptom = false;
            foreach ($words as $w) {
                if ($concept === $w || str_contains($concept, $w)) {
                    $inConcept = true;
                }
                if ($symptom === $w || str_contains($symptom, $w)) {
                    $inSymptom = true;
                }
            }
            if ($inConcept && $inSymptom) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function mergeExtractedFacts(array $context, string $turn, string $awaiting): array
    {
        $facts = $context['facts'];
        $low = mb_strtolower($turn);
        // Always include original primary complaint — cleaners may drop soft timing tokens
        // from the NLP turn, but they must still count as known clinical context.
        $originalComplaint = trim((string) ($context['chief_complaint'] ?? ''));
        $combined = trim(implode('. ', array_filter([
            $originalComplaint,
            $turn,
            self::transcript($context),
        ], static fn ($v): bool => trim((string) $v) !== '')));

        $onsetFromTurn = ClinicalFeatureExtractors::extractOnset($turn);
        $awaitingUpper = strtoupper(trim($awaiting));
        $timingSlotOpen = in_array($awaitingUpper, ['ONSET', 'DURATION', ''], true);
        // Only write timing from the current turn when the patient is answering a timing
        // question (or on the opening turn). Avoid stealing "gulpi"/"oo" from other slots.
        if ($timingSlotOpen && $onsetFromTurn !== '') {
            $facts['onset'] = $onsetFromTurn;
        } elseif ($timingSlotOpen) {
            $onset = ClinicalFeatureExtractors::extractOnset($combined);
            if ($onset !== '' && ($facts['onset'] === '' || $facts['onset'] === 'uncertain')) {
                $facts['onset'] = $onset;
            }
        } elseif ($onsetFromTurn !== '' && ($facts['onset'] === '' || $facts['onset'] === 'uncertain')) {
            // Free-text timing volunteered while answering another slot — still accumulate.
            $facts['onset'] = $onsetFromTurn;
        }
        $durationFromTurn = ClinicalFeatureExtractors::extractDuration($turn);
        if ($timingSlotOpen && ($durationFromTurn['label'] ?? '') !== '') {
            $facts['duration_label'] = (string) $durationFromTurn['label'];
        } elseif ($timingSlotOpen) {
            $duration = ClinicalFeatureExtractors::extractDuration($combined);
            if (($duration['label'] ?? '') !== '' && $facts['duration_label'] === '') {
                $facts['duration_label'] = (string) $duration['label'];
            }
        } elseif (($durationFromTurn['label'] ?? '') !== '' && $facts['duration_label'] === '') {
            $facts['duration_label'] = (string) $durationFromTurn['label'];
        }
        // Soft timing phrases ("ligad pa") count as known onset — do not re-ask ONSET.
        if ($facts['onset'] === '' && $facts['duration_label'] !== '') {
            $derived = ClinicalFeatureExtractors::onsetFromDuration([
                'label' => $facts['duration_label'],
            ]);
            if ($derived !== '') {
                $facts['onset'] = $derived;
            }
        }
        // Lexicon fallback for ambiguous timing answers when extractors still miss.
        if ($facts['duration_label'] === ''
            && class_exists('NlpStep3DemoGeminiAnswerInterpreter')
            && in_array(strtoupper($awaiting), ['ONSET', 'DURATION', ''], true)
        ) {
            try {
                $slot = $awaiting !== '' ? strtoupper($awaiting) : 'DURATION';
                $interp = NlpStep3DemoGeminiAnswerInterpreter::tryLocalSemanticInterpretation($turn, $slot);
                if (is_array($interp)
                    && !empty($interp['relevant'])
                    && strtoupper((string) ($interp['answer_type'] ?? '')) !== 'UNRELATED'
                ) {
                    $value = trim((string) ($interp['normalized_value'] ?? ''));
                    if ($value !== '') {
                        $facts['duration_label'] = $value;
                        if ($facts['onset'] === '') {
                            $facts['onset'] = ClinicalFeatureExtractors::onsetFromDuration(['label' => $value]) ?: $value;
                        }
                    }
                }
            } catch (Throwable) {
                // keep extractor-only facts
            }
        }
        $pain = ClinicalFeatureExtractors::extractPainScale($turn);
        $painScore = isset($pain['score']) && $pain['score'] !== null && $pain['score'] !== ''
            ? (int) $pain['score']
            : null;
        // Interview pain scale is 1–10; do not store 0 or out-of-range values as confirmed scores.
        if ($painScore !== null && $painScore >= 1 && $painScore <= 10) {
            $facts['pain_score'] = $painScore;
        } elseif ($awaiting === 'PAIN_SEVERITY' || $awaiting === '') {
            $standalone = ClinicalFeatureExtractors::extractStandalonePainScore($turn, $awaiting === 'PAIN_SEVERITY');
            if ($standalone !== null) {
                $facts['pain_score'] = $standalone;
            }
        }
        $qualifier = ClinicalFeatureExtractors::extractPainQualifier($turn);
        if ($qualifier !== '') {
            $facts['pain_qualifier'] = $qualifier;
        }
        foreach (ClinicalFeatureExtractors::extractBodyLocations($combined) as $loc) {
            if (!in_array($loc, $facts['body_locations'], true)) {
                $facts['body_locations'][] = $loc;
            }
        }
        if (ClinicalFeatureExtractors::deniedAssociatedSymptoms($turn)) {
            $facts['denied_associated'] = true;
            $facts['has_other_symptoms'] = false;
        }

        $yesNo = ClinicalFeatureExtractors::extractYesNo($turn);
        if ($yesNo !== null && $awaiting !== '') {
            $map = [
                'NEURO_WEAKNESS' => 'weakness',
                'NEURO_SPEECH' => 'speech_difficulty',
                'NEURO_VISION' => 'vision_change',
                'BREATHING_SEVERITY' => 'breathing_difficulty',
                'BLEEDING_CONTINUING' => 'bleeding_continuing',
                'BLEEDING_HEAVY' => 'bleeding_heavy',
                'BLEEDING_DIZZY' => 'dizziness',
                'CHEST_RADIATION' => 'chest_radiation',
                'CHEST_SWEATING' => 'sweating',
                'ABDOMINAL_ASSOCIATED' => 'abdominal_associated',
                'ASSOCIATED_SYMPTOMS' => 'has_other_symptoms',
                'FEVER_CONFIRM' => 'fever_confirmed',
                'VISION_CHANGE' => 'vision_change',
            ];
            // Atomic PARENT__ATOM answers must not write the parent bundle boolean.
            $isAtomic = str_contains($awaiting, '__');
            $awaitingKey = $isAtomic ? explode('__', $awaiting, 2)[0] : $awaiting;
            if (!$isAtomic && isset($map[$awaitingKey])) {
                $facts[$map[$awaitingKey]] = $yesNo;
            }
            // Bind polarity to the active target finding (universal adaptive path).
            $targetFinding = strtolower(trim((string) ($context['awaiting_target_finding'] ?? '')));
            if ($targetFinding !== '') {
                $facts = self::applyTargetFindingPolarity($facts, $targetFinding, $yesNo);
            }
            // Negative reply to generic associated probes only (not ABDOMINAL_ASSOCIATED__*).
            if ($yesNo === false && (
                $awaitingKey === 'ASSOCIATED_SYMPTOMS'
                || $awaitingKey === 'ASSOCIATED_DETAIL'
            )) {
                $facts['denied_associated'] = true;
                $facts['has_other_symptoms'] = false;
            }
        }

        if (class_exists('NegationDetector')) {
            try {
                $negated = NegationDetector::detectNegatedConcepts($combined);
                $negList = self::stringList($facts['negative_symptoms'] ?? []);
                foreach ($negated as $neg) {
                    $neg = strtolower(trim((string) $neg));
                    if ($neg === '' || in_array($neg, $negList, true)) {
                        continue;
                    }
                    $negList[] = $neg;
                    if (self::symptomMatchesConcept('cough', $neg) || $neg === 'ubo') {
                        // Keep cough off the positive list; do not invent a cough fact.
                    }
                    if (self::symptomMatchesConcept('difficulty breathing', $neg)
                        || str_contains($neg, 'breath')
                        || str_contains($neg, 'ginhawa')
                    ) {
                        $facts['breathing_difficulty'] = false;
                    }
                }
                $facts['negative_symptoms'] = $negList;
                $facts = self::filterSymptomsAgainstNegatives($facts);
            } catch (Throwable) {
                // keep extractor-only facts
            }
        }

        $facts = self::absorbImplicitRedFlags($facts, mb_strtolower($combined));

        // Free-text follow-ups can introduce new clinical facts (e.g. "Ga suka ko"
        // while awaiting ONSET). Accumulate them into the case without replacing prior facts.
        // Bare yes/no must not invent named symptoms from lexicon noise ("oo" ≠ BPH).
        $yn = ClinicalFeatureExtractors::extractYesNo($turn);
        $bareYesNo = $yn !== null && !preg_match(
            '/\b(may|mayroon|naa|gina|naga|ga|kasakit|sakit|pain|suka|ubo|hilo|hubag|ginhawa|breath|makaginhawa|kaginhawa)\b/u',
            $low
        ) && mb_strlen(preg_replace('/\s+/u', ' ', $low)) <= 12;
        if ($turn !== '' && !$bareYesNo && class_exists('SymptomKnowledgeBase')) {
            try {
                $turnMatches = SymptomKnowledgeBase::matchSymptoms($turn, $turn);
                $assoc = self::stringList($facts['associated_symptoms'] ?? []);
                $negList = self::stringList($facts['negative_symptoms'] ?? []);
                $addedNamed = false;
                foreach ($turnMatches as $row) {
                    $name = trim((string) ($row['symptom_name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $denied = false;
                    foreach ($negList as $neg) {
                        if (self::symptomMatchesConcept($name, $neg)) {
                            $denied = true;
                            break;
                        }
                    }
                    if ($denied) {
                        continue;
                    }
                    // Lexicon-matched labels are KB-derived (patient text triggered the match).
                    $facts = self::recordSymptomName($facts, $name, 'kb');
                    if ($awaiting !== '' && !in_array($name, $assoc, true)) {
                        $assoc[] = $name;
                        $addedNamed = true;
                    }
                }
                $facts['associated_symptoms'] = $assoc;
                if ($addedNamed && (
                    $awaitingUpper === 'ASSOCIATED_DETAIL'
                    || $awaitingUpper === 'ASSOCIATED_SYMPTOMS'
                    || !empty($facts['needs_associated_detail'])
                )) {
                    $facts['has_other_symptoms'] = true;
                    $facts['needs_associated_detail'] = false;
                }
            } catch (Throwable) {
                // keep extractor-only facts
            }
        }

        // Descriptive ASSOCIATED_DETAIL answers without KB hits still count (e.g. "May kasakit").
        if ($awaitingUpper === 'ASSOCIATED_DETAIL'
            && self::stringList($facts['associated_symptoms'] ?? []) === []
            && !$bareYesNo
            && $turn !== ''
        ) {
            $label = self::freeTextAssociatedLabel($turn);
            if ($label !== '') {
                $assoc = self::stringList($facts['associated_symptoms'] ?? []);
                if (!in_array($label, $assoc, true)) {
                    $assoc[] = $label;
                }
                $facts['associated_symptoms'] = $assoc;
                $facts = self::recordSymptomName($facts, $label, 'patient');
                $facts['has_other_symptoms'] = true;
                $facts['needs_associated_detail'] = false;
            }
        }

        // Keep the detail gate aligned with accumulated facts.
        if (($facts['has_other_symptoms'] ?? null) === true
            && self::stringList($facts['associated_symptoms'] ?? []) === []
            && empty($facts['denied_associated'])
        ) {
            $facts['needs_associated_detail'] = true;
        }

        $context['facts'] = $facts;

        return $context;
    }

    /**
     * Lightweight label for free-text associated answers when SymptomKnowledgeBase misses.
     */
    private static function freeTextAssociatedLabel(string $turn): string
    {
        $low = mb_strtolower(trim($turn));
        if ($low === '') {
            return '';
        }
        if (preg_match('/\b(kasakit|sakit|pain|hapdi)\b/u', $low)) {
            return 'pain';
        }
        if (preg_match('/\b(suka|vomit|vomiting|nausea)\b/u', $low)) {
            return 'vomiting';
        }
        if (preg_match('/\b(hilo|dizzy|dizziness|nahilo)\b/u', $low)) {
            return 'dizziness';
        }
        if (preg_match('/\b(ubo|cough)\b/u', $low)) {
            return 'cough';
        }
        if (preg_match('/\b(hilanat|lagnat|fever)\b/u', $low)) {
            return 'fever';
        }
        if (preg_match('/\b(hubag|gahabok|swelling|swollen)\b/u', $low)) {
            return 'swelling';
        }
        // Keep a short cleaned phrase rather than inventing a diagnosis.
        $clean = trim((string) preg_replace('/\b(may|mayroon|naa|ako|ko|ang|sang|nga|my|i have|i feel)\b/u', ' ', $low));
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        if (mb_strlen($clean) < 3 || mb_strlen($clean) > 48) {
            return '';
        }

        return $clean;
    }

    /**
     * Apply validated follow-up answer polarity / extracted facts before triage re-run.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private static function applyFollowUpValidationFacts(array $context, array $validation, string $awaiting): array
    {
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $extracted = is_array($validation['extracted'] ?? null) ? $validation['extracted'] : [];
        $class = strtoupper((string) ($validation['answer_class'] ?? ''));
        $polarity = strtolower((string) ($validation['polarity'] ?? ''));
        $awaiting = strtoupper(trim($awaiting));
        $answerText = trim((string) ($validation['corrected_answer'] ?? ''));

        // Uncertainty blocks inventing POS/NEG clinical booleans (including Gemini-derived).
        $locallyUncertain = $answerText !== ''
            && class_exists('ClinicalFeatureExtractors')
            && ClinicalFeatureExtractors::looksPatientUncertain($answerText);
        $isUncertain = $locallyUncertain
            || !empty($extracted['patient_uncertain'])
            || in_array($class, ['VALID_UNCERTAIN', 'VALID_UNKNOWN'], true)
            || $polarity === 'uncertain'
            || $polarity === 'unknown';

        if ($isUncertain) {
            $class = 'VALID_UNCERTAIN';
            $polarity = 'uncertain';
            $extracted['patient_uncertain'] = true;
            unset($extracted['yes_no'], $extracted['denied'], $extracted['denied_associated']);
            foreach ([
                'vision_change', 'weakness', 'speech_difficulty', 'breathing_difficulty',
                'bleeding_continuing', 'bleeding_heavy', 'dizziness', 'chest_radiation',
                'sweating', 'abdominal_associated', 'has_other_symptoms', 'fever_confirmed',
                'blood_in_stool',
            ] as $boolKey) {
                unset($extracted[$boolKey]);
            }
        }

        if (!$isUncertain && (
            !empty($extracted['denied_associated']) || ($class === 'VALID_NEGATIVE' && (
                $awaiting === 'ASSOCIATED_SYMPTOMS' || $awaiting === 'ASSOCIATED_DETAIL'
            ))
        )) {
            $facts['denied_associated'] = true;
            $facts['has_other_symptoms'] = false;
        }

        if ($isUncertain) {
            $facts['patient_uncertain'] = true;
            $targetFinding = strtolower(trim((string) ($context['awaiting_target_finding'] ?? '')));
            if ($targetFinding !== '') {
                $status = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
                $status[$targetFinding] = 'uncertain';
                $facts['finding_status'] = $status;
            }
            // Mark timing slot resolved when the patient cannot answer onset/duration.
            if (($awaiting === 'ONSET' || $awaiting === 'DURATION' || str_contains($awaiting, 'ONSET') || str_contains($awaiting, 'DURATION'))
                && trim((string) ($facts['onset'] ?? '')) === ''
                && trim((string) ($facts['duration_label'] ?? '')) === ''
            ) {
                $facts['onset'] = 'uncertain';
            }
            // Patient cannot name the extra symptom — stop looping the detail question.
            if ($awaiting === 'ASSOCIATED_DETAIL') {
                $facts['needs_associated_detail'] = false;
            }
        }

        if (!empty($extracted['patient_conditional']) || ($class === 'VALID_PARTIAL' && $polarity === 'partial')) {
            $facts['patient_conditional'] = true;
        }

        // Never persist yes_no / polarity booleans for uncertain answers.
        if (!$isUncertain
            && (array_key_exists('yes_no', $extracted) || $polarity === 'positive' || $polarity === 'negative')
        ) {
            $yn = array_key_exists('yes_no', $extracted)
                ? (bool) $extracted['yes_no']
                : ($polarity === 'positive' ? true : ($polarity === 'negative' ? false : null));
            $map = [
                'NEURO_WEAKNESS' => 'weakness',
                'NEURO_SPEECH' => 'speech_difficulty',
                'NEURO_VISION' => 'vision_change',
                'BREATHING_SEVERITY' => 'breathing_difficulty',
                'BLEEDING_CONTINUING' => 'bleeding_continuing',
                'BLEEDING_HEAVY' => 'bleeding_heavy',
                'BLEEDING_DIZZY' => 'dizziness',
                'CHEST_RADIATION' => 'chest_radiation',
                'CHEST_SWEATING' => 'sweating',
                'ABDOMINAL_ASSOCIATED' => 'abdominal_associated',
                'ASSOCIATED_SYMPTOMS' => 'has_other_symptoms',
                'FEVER_CONFIRM' => 'fever_confirmed',
                'VISION_CHANGE' => 'vision_change',
            ];
            // Atomic PARENT__ATOM answers persist the atom only — not the parent bundle boolean.
            $isAtomic = str_contains($awaiting, '__');
            $awaitingKey = $isAtomic ? explode('__', $awaiting, 2)[0] : $awaiting;
            if (!$isAtomic && $yn !== null && isset($map[$awaitingKey])) {
                $facts[$map[$awaitingKey]] = $yn;
            }
            $targetFinding = strtolower(trim((string) ($context['awaiting_target_finding'] ?? '')));
            if ($yn !== null && $targetFinding !== '') {
                $facts = self::applyTargetFindingPolarity($facts, $targetFinding, $yn);
            }
            // "Oo" to generic associated symptoms confirms extras exist but does NOT name them.
            if ($yn === true
                && $awaitingKey === 'ASSOCIATED_SYMPTOMS'
                && $awaiting !== 'ASSOCIATED_DETAIL'
            ) {
                $namedNow = self::stringList($extracted['named_symptoms'] ?? $extracted['associated_symptoms'] ?? []);
                $existingAssoc = self::stringList($facts['associated_symptoms'] ?? []);
                if ($namedNow === [] && $existingAssoc === []) {
                    $facts['has_other_symptoms'] = true;
                    $facts['needs_associated_detail'] = true;
                }
            }
        }

        // Merge any named symptoms extracted for this answer into the accumulated case.
        foreach (['named_symptoms', 'associated_symptoms'] as $listKey) {
            if (empty($extracted[$listKey]) || !is_array($extracted[$listKey])) {
                continue;
            }
            $assoc = self::stringList($facts['associated_symptoms'] ?? []);
            foreach ($extracted[$listKey] as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }
                if (!in_array($name, $assoc, true)) {
                    $assoc[] = $name;
                }
                $facts = self::recordSymptomName($facts, $name, 'patient');
            }
            $facts['associated_symptoms'] = $assoc;
            if ($assoc !== []) {
                $facts['has_other_symptoms'] = true;
                $facts['needs_associated_detail'] = false;
            }
        }

        if ($awaiting === 'ASSOCIATED_DETAIL') {
            $assoc = self::stringList($facts['associated_symptoms'] ?? []);
            if ($assoc !== [] || self::stringList($extracted['named_symptoms'] ?? []) !== []) {
                $facts['needs_associated_detail'] = false;
                $facts['has_other_symptoms'] = true;
            } elseif (!$isUncertain && ($class === 'VALID_NEGATIVE' || !empty($extracted['denied_associated']))) {
                $facts['needs_associated_detail'] = false;
                $facts['has_other_symptoms'] = false;
                $facts['denied_associated'] = true;
            }
        }

        if (($extracted['pain_severity'] ?? null) !== null) {
            $facts['pain_score'] = (int) $extracted['pain_severity'];
        }
        if (trim((string) ($extracted['pain_qualifier'] ?? '')) !== '') {
            $facts['pain_qualifier'] = (string) $extracted['pain_qualifier'];
        }
        if (trim((string) ($extracted['duration'] ?? '')) !== '') {
            $facts['duration_label'] = (string) $extracted['duration'];
        }
        if (trim((string) ($extracted['onset'] ?? '')) !== '') {
            $facts['onset'] = (string) $extracted['onset'];
        }
        if (!empty($extracted['body_locations']) && is_array($extracted['body_locations'])) {
            $locs = is_array($facts['body_locations'] ?? null) ? $facts['body_locations'] : [];
            foreach ($extracted['body_locations'] as $loc) {
                $loc = trim((string) $loc);
                if ($loc !== '' && !in_array($loc, $locs, true)) {
                    $locs[] = $loc;
                }
            }
            $facts['body_locations'] = $locs;
        }

        // Named clinical booleans (incl. Gemini) — never for uncertain answers.
        if (!$isUncertain) {
            foreach ([
                'vision_change', 'weakness', 'speech_difficulty', 'breathing_difficulty',
                'bleeding_continuing', 'bleeding_heavy', 'dizziness', 'chest_radiation',
                'sweating', 'abdominal_associated', 'has_other_symptoms', 'fever_confirmed',
                'blood_in_stool',
            ] as $key) {
                if (array_key_exists($key, $extracted) && $extracted[$key] !== null && $extracted[$key] !== '') {
                    $facts[$key] = is_bool($extracted[$key]) ? (bool) $extracted[$key] : $extracted[$key];
                }
            }
        }

        $context['facts'] = $facts;
        $context['last_answer_class'] = $class;
        $context['last_answer_polarity'] = $polarity;

        return $context;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function absorbImplicitRedFlags(array $facts, string $low): array
    {
        $negatedBreathing = (bool) preg_match(
            '/\b(no|not|without|denies|wala(?:\s+(?:ko|ako|akong|sang|man))?|walang|walay|hindi(?:\s+ako)?|indi)\s+'
            . '(difficulty breathing|shortness of breath|dyspnea|budlay(?:\s+gid)?(?:\s+mag)?ginhawa)\b/u',
            $low
        );
        if ($negatedBreathing) {
            $facts['breathing_difficulty'] = false;
        }

        if (preg_match('/\b(no|wala|hindi|indi|without)\s+(weakness|numbness|pamamanhid|numb)\b/u', $low)) {
            $facts['weakness'] = false;
        } elseif (preg_match(
            '/nangaluya|kaluya|one[- ]sided|wala nga kamot|left arm|weakness in one|pamamanhid|naga\s*numb'
            . '|(?<!\bno\s)(?<!\bwithout\s)(?<!\bwala\s)(?<!\bhindi\s)(?<!\bindi\s)\b(?:weakness|numbness)\b/u',
            $low
        )) {
            $facts['weakness'] = true;
        }
        if (preg_match('/indi\s+ko\s+makahambal|cannot speak|slurred|hirap magsalita/u', $low)) {
            $facts['speech_difficulty'] = true;
        }
        if (preg_match('/nabulag|vision loss|double vision|nawala panulok/u', $low)) {
            $facts['vision_change'] = true;
        }
        if (!$negatedBreathing && preg_match(
            '/(?<!no )(?<!not )(?<!wala ko )(?<!wala )(cannot breathe|can\'t breathe|indi ko makaginhawa|indi ko kaginhawa|budlay.{0,12}ginhawa|hirap huminga)\b/u',
            $low
        )) {
            $facts['breathing_difficulty'] = true;
        }
        if (preg_match('/\b(malipong|nalipong|dizzy|nahihilo|nahilo|punaw|faint)\b/u', $low)
            && !preg_match('/\b(no|wala|hindi|indi|without)\s+(malipong|dizzy|hilo)\b/u', $low)
        ) {
            $facts['dizziness'] = true;
        }
        if (preg_match('/grabe.{0,20}dugo|indi.{0,12}(untat|mapunggan)|uncontrolled bleeding|heavy bleeding/u', $low)) {
            $facts['bleeding_heavy'] = true;
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    /**
     * Public for multi-complaint track↔context merges (never lose newer polarity).
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    public static function mergeFacts(array $left, array $right): array
    {
        $out = $left;
        $listKeys = [
            'body_locations', 'symptoms', 'associated_symptoms', 'negative_symptoms',
            'symptoms_patient', 'symptoms_kb', 'symptoms_ai',
            'vital_signs', 'risk_factors', 'red_flags', 'medical_history',
        ];
        $replaceKeys = [
            'pain_score', 'pain_qualifier', 'onset', 'duration_label', 'progression',
            'weakness', 'speech_difficulty', 'vision_change', 'breathing_difficulty',
            'bleeding_continuing', 'bleeding_heavy', 'dizziness', 'chest_radiation',
            'sweating', 'abdominal_associated', 'has_other_symptoms', 'fever_confirmed',
            'blood_in_stool', 'pregnancy', 'needs_associated_detail',
        ];
        foreach ($right as $key => $value) {
            if ($key === 'finding_status' && is_array($value)) {
                $leftMap = is_array($out['finding_status'] ?? null) ? $out['finding_status'] : [];
                $out['finding_status'] = array_merge($leftMap, $value);
                continue;
            }
            if (in_array($key, $listKeys, true) && is_array($value)) {
                $out[$key] = array_values(array_unique(array_merge(
                    self::stringList($out[$key] ?? []),
                    self::stringList($value)
                )));
                continue;
            }
            if ($key === 'needs_associated_detail') {
                // Explicit false must clear the pending-detail gate.
                $out[$key] = !empty($value);
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (in_array($key, $replaceKeys, true)) {
                // Later validated follow-up answers may correct an earlier fact
                // (e.g. pain 5 → 8) without wiping unrelated slots.
                $out[$key] = $value;
                continue;
            }
            if ($key === 'patient_uncertain' || $key === 'patient_conditional' || $key === 'denied_associated') {
                $out[$key] = !empty($out[$key]) || !empty($value);
                continue;
            }
            if (($out[$key] ?? null) === null || $out[$key] === '' || $out[$key] === []) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private static function factsFromAssessment(array $assessment, string $transcript): array
    {
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $pain = is_array($triage['pain_scale'] ?? null) ? $triage['pain_scale'] : [];
        $duration = trim((string) ($triage['duration'] ?? ''));
        if ($duration === '') {
            $duration = trim((string) (ClinicalFeatureExtractors::extractDuration($transcript)['label'] ?? ''));
        }
        $body = self::stringList($triage['detected_body_parts'] ?? []);
        // Multi-level: local CSV datasets FIRST; Gemini only if unresolved/low confidence.
        $lang = '';
        if (class_exists('HiligaynonLanguageDetector')) {
            try {
                $lang = strtolower((string) (HiligaynonLanguageDetector::detect($transcript)['primary'] ?? ''));
            } catch (Throwable) {
                $lang = '';
            }
        }
        $resolved = class_exists('BodyLocationLexicon')
            ? BodyLocationLexicon::resolveMultiLevel($transcript, $lang, [], ['body_locations' => $body])
            : [
                'matches' => ClinicalFeatureExtractors::extractBodyLocationDetails($transcript, $transcript),
                'body_locations' => ClinicalFeatureExtractors::extractBodyLocations($transcript),
                'verification_status' => 'local_dataset_match',
                'needs_clarification' => false,
                'not_medical' => false,
                'gemini_called' => false,
                'reason' => 'lexicon_unavailable',
            ];
        $locationMatches = is_array($resolved['matches'] ?? null) ? $resolved['matches'] : [];
        foreach ((array) ($resolved['body_locations'] ?? []) as $c) {
            $c = strtolower(trim((string) $c));
            if ($c !== '' && !in_array($c, $body, true)) {
                $body[] = $c;
            }
        }
        $body = array_values(array_unique(array_filter(array_map(
            static fn ($v) => strtolower(trim((string) $v)),
            $body
        ))));

        // Domain-detector clinical findings → structured symptom facts (category-driven).
        $findingSymptoms = [];
        if (class_exists('HealthComplaintDomainDetector')) {
            try {
                $domain = HealthComplaintDomainDetector::detect($transcript);
                foreach ((array) ($domain['signals'] ?? []) as $sig) {
                    if (!is_array($sig)) {
                        continue;
                    }
                    $type = (string) ($sig['type'] ?? '');
                    $value = trim((string) ($sig['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    if ($type === 'body_part') {
                        $canon = class_exists('BodyLocationLexicon')
                            ? BodyLocationLexicon::extractCanonical($value)
                            : [];
                        foreach ($canon as $c) {
                            $c = strtolower(trim((string) $c));
                            if ($c !== '' && !in_array($c, $body, true)) {
                                $body[] = $c;
                            }
                        }
                        continue;
                    }
                    if (!in_array($type, [
                        'symptom', 'physical_change', 'finding', 'condition',
                        'injury', 'bleeding', 'breathing', 'malaise', 'dataset_symptom',
                    ], true)) {
                        continue;
                    }
                    // Prefer English gloss after "→" when present (dictionary hits).
                    $label = $value;
                    if (str_contains($value, '→')) {
                        $parts = explode('→', $value, 2);
                        $label = trim((string) ($parts[1] ?? $value));
                    }
                    if ($label !== '') {
                        $findingSymptoms[] = $label;
                    }
                }
            } catch (Throwable) {
                // keep prior body/symptoms
            }
        }

        // Gemini normalized concept assists extraction when local body/findings are thin —
        // additive only; never replaces original complaint; never sets triage class.
        $bridgeConcept = '';
        // Prefer concept already present on assessment semantic bridge when available.
        if (is_array($assessment['semantic_bridge'] ?? null)) {
            $bridgeConcept = trim((string) ($assessment['semantic_bridge']['gemini_concept'] ?? ''));
        }
        if ($bridgeConcept === '' && is_array($assessment['semantic_validation'] ?? null)) {
            $bridgeConcept = trim((string) ($assessment['semantic_validation']['gemini_medical_concept'] ?? ''));
        }
        if ($bridgeConcept !== '') {
            if ($body === [] && class_exists('BodyLocationLexicon')) {
                foreach (BodyLocationLexicon::extractCanonical($bridgeConcept) as $c) {
                    $c = strtolower(trim((string) $c));
                    if ($c !== '' && !in_array($c, $body, true)) {
                        $body[] = $c;
                    }
                }
            }
            if ($findingSymptoms === [] && class_exists('SymptomKnowledgeBase')) {
                foreach (SymptomKnowledgeBase::matchSymptoms($bridgeConcept, $bridgeConcept) as $row) {
                    $name = trim((string) ($row['symptom_name'] ?? ''));
                    if ($name !== '') {
                        $findingSymptoms[] = $name;
                    }
                }
            }
            if ($findingSymptoms === []) {
                $findingSymptoms[] = $bridgeConcept;
            }
        }

        $onset = ClinicalFeatureExtractors::extractOnset($transcript);
        if ($onset === '' && $duration !== '') {
            $onset = ClinicalFeatureExtractors::onsetFromDuration(['label' => $duration]);
        }

        $detectedSymptoms = self::stringList($assessment['detected_symptoms'] ?? []);
        $symptoms = array_values(array_unique(array_filter(array_merge(
            $detectedSymptoms,
            $findingSymptoms
        ))));

        $facts = self::blankFacts([
            'body_locations' => $body,
            'symptoms' => $symptoms,
            'symptoms_kb' => $detectedSymptoms,
            // Bridge/Gemini lexical finding names are AI-derived, not patient-reported.
            'symptoms_ai' => $findingSymptoms,
            'pain_score' => $pain['score'] ?? null,
            'pain_qualifier' => ClinicalFeatureExtractors::extractPainQualifier($transcript),
            'onset' => $onset,
            'duration_label' => $duration,
            'denied_associated' => ClinicalFeatureExtractors::deniedAssociatedSymptoms($transcript),
            'body_location_matches' => $locationMatches,
        ]);
        $facts['body_location_verification'] = [
            'status' => (string) ($resolved['verification_status'] ?? ''),
            'needs_clarification' => !empty($resolved['needs_clarification']),
            'not_medical' => !empty($resolved['not_medical']),
            'gemini_called' => !empty($resolved['gemini_called']),
            'reason' => (string) ($resolved['reason'] ?? ''),
            'original_complaint' => $transcript,
        ];
        if (!empty($resolved['needs_clarification'])) {
            $facts['needs_location_clarification'] = true;
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $assessment
     * @return list<string>
     */
    private static function redFlagNames(array $assessment): array
    {
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $flags = $triage['red_flags'] ?? $triage['emergency_flags'] ?? [];
        if (!is_array($flags)) {
            return [];
        }
        $names = [];
        foreach ($flags as $flag) {
            if (is_string($flag) && trim($flag) !== '') {
                $names[] = trim($flag);
            } elseif (is_array($flag)) {
                $label = trim((string) ($flag['flag_name'] ?? $flag['name'] ?? $flag['english_pattern'] ?? ''));
                if ($label !== '') {
                    $names[] = $label;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Universal NLP gate: follow-up only when information is insufficient for safe triage.
     *
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $context
     * @param array<string, mixed> $rawTriage
     */
    private static function needsFollowUpQuestion(array $assessment, array $context, string $transcript, array $rawTriage): bool
    {
        return !self::isInformationSufficient($assessment, $context, $transcript, $rawTriage);
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $context
     * @param array<string, mixed> $rawTriage
     */
    private static function isInformationSufficient(array $assessment, array $context, string $transcript, array $rawTriage): bool
    {
        unset($rawTriage);
        if (ClinicalFeatureExtractors::isVagueComplaint($transcript)
            || ClinicalFeatureExtractors::isUnintelligibleComplaint($transcript)
        ) {
            return false;
        }
        if (self::hasContradictoryFacts($context['facts'], $transcript, $context)) {
            return false;
        }

        $turns = is_array($context['patient_turns'] ?? null) ? $context['patient_turns'] : [];
        $lastTurn = end($turns);
        if (is_string($lastTurn) && (string) ($context['awaiting_question_id'] ?? '') !== ''
            && ClinicalFeatureExtractors::isUnclearAnswer($lastTurn)
        ) {
            return false;
        }

        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $factors = is_array($triage['assessment_factors'] ?? null) ? $triage['assessment_factors'] : [];
        if (!empty($factors['insufficient_context'])) {
            return false;
        }

        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        // Confirmed extras without a named symptom → keep interviewing.
        if (!empty($facts['needs_associated_detail'])
            || (($facts['has_other_symptoms'] ?? null) === true
                && self::stringList($facts['associated_symptoms'] ?? []) === []
                && empty($facts['denied_associated']))
        ) {
            return false;
        }

        // Adaptive policy is the authority for "enough for triage?".
        // Multi-complaint: every track must be sufficient (facts stay isolated).
        try {
            if (class_exists('ClinicalInterviewMultiComplaint')
                && count((array) ($context['complaints'] ?? [])) > 1
            ) {
                return ClinicalInterviewMultiComplaint::allTracksSufficient($context, $transcript, $assessment);
            }
            if (class_exists('ClinicalInterviewAdaptivePolicy')) {
                $adaptiveReady = ClinicalInterviewAdaptivePolicy::isTriageSufficient($context, $transcript, $assessment);
                if (!$adaptiveReady) {
                    return false;
                }
                $stillNeeded = ClinicalInterviewAdaptivePolicy::selectNextSlot($context, $transcript, $assessment);
                if ($stillNeeded === null) {
                    return true;
                }
                // Still ask when the next slot is red-flag related or top-priority.
                $pri = (int) ($stillNeeded['priority'] ?? 99);
                if (!empty($stillNeeded['red_flag_related']) || $pri <= 3) {
                    return false;
                }
                // Associated-detail follow-ups must not be skipped as "low priority".
                $stillQid = strtoupper((string) ($stillNeeded['question_id'] ?? ''));
                if ($stillQid === 'ASSOCIATED_DETAIL' || $stillQid === 'ASSOCIATED_SYMPTOMS') {
                    return false;
                }
                $stillParent = str_contains($stillQid, '__')
                    ? explode('__', $stillQid, 2)[0]
                    : $stillQid;
                // Clinically relevant clarify must not be treated as optional leftover.
                if ($stillParent === 'URINARY_DETAIL' || $stillParent === 'FEVER_CONFIRM') {
                    return false;
                }
                // Lower-priority leftover slots (e.g. COUGH_TYPE, DIZZINESS_TYPE) do not block.
                return true;
            }
        } catch (Throwable $e) {
            error_log('ClinicalInterviewAdaptivePolicy isTriageSufficient fallback: ' . $e->getMessage());
        }

        if (self::nextBlockingQuestionSlot($context, $transcript, $assessment) !== null) {
            return false;
        }

        $clinicalContext = is_array($triage['clinical_context'] ?? null) ? $triage['clinical_context'] : [];
        if (($clinicalContext['sufficient_context'] ?? false) === true) {
            return true;
        }
        if (!empty($triage['confidence_accepted']) && empty($triage['needs_provider_review'])) {
            return true;
        }
        if (($triage['detected_symptoms'] ?? []) !== [] && self::hasClinicalModifiers($context['facts'])) {
            return (int) ($triage['confidence_score'] ?? 0) >= ClinicalTriageEngine::CONFIDENCE_THRESHOLD
                || empty($triage['needs_provider_review']);
        }

        return ($triage['detected_symptoms'] ?? []) !== [];
    }

    /**
     * Highest-priority unanswered slot that still blocks safe classification.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>|null
     */
    private static function nextBlockingQuestionSlot(array $context, string $transcript, array $assessment): ?array
    {
        $slot = self::nextQuestionSlot($context, $transcript, $assessment);
        if ($slot === null) {
            return null;
        }

        $priority = (int) ($slot['priority'] ?? 99);
        $threshold = ClinicalInterviewContextResolver::lowPriorityThreshold();
        if (!empty($slot['red_flag_related']) || $priority < $threshold) {
            return $slot;
        }

        if (ClinicalFeatureExtractors::isVagueComplaint($transcript)
            || ClinicalFeatureExtractors::isUnintelligibleComplaint($transcript)
        ) {
            return $slot;
        }

        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $factors = is_array($triage['assessment_factors'] ?? null) ? $triage['assessment_factors'] : [];
        if (!empty($factors['insufficient_context']) || !empty($triage['needs_provider_review'])) {
            return $slot;
        }

        $clinicalContext = is_array($triage['clinical_context'] ?? null) ? $triage['clinical_context'] : [];
        if (($clinicalContext['sufficient_context'] ?? false) === true
            && self::hasClinicalModifiers($context['facts'])
        ) {
            return null;
        }

        return $slot;
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $context
     */
    private static function hasContradictoryFacts(array $facts, string $transcript, array $context = []): bool
    {
        if (($facts['denied_associated'] ?? false) === true) {
            foreach (['weakness', 'speech_difficulty', 'vision_change', 'breathing_difficulty', 'bleeding_heavy'] as $key) {
                if (($facts[$key] ?? null) === true) {
                    return true;
                }
            }
        }
        $low = mb_strtolower($transcript);
        if (($facts['pain_score'] ?? null) !== null && (int) $facts['pain_score'] <= 3
            && preg_match('/\b(10\/10|9\/10|8\/10|grabe gid|worst pain|unbearable)\b/u', $low)
        ) {
            return true;
        }

        $onsets = [];
        foreach ((array) ($context['patient_turns'] ?? []) as $turn) {
            if (!is_string($turn) || trim($turn) === '') {
                continue;
            }
            $onset = ClinicalFeatureExtractors::extractOnset($turn);
            if ($onset !== '') {
                $onsets[$onset] = true;
            }
        }

        return isset($onsets['sudden'], $onsets['gradual']);
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function hasClinicalModifiers(array $facts): bool
    {
        return $facts['body_locations'] !== []
            || ($facts['duration_label'] ?? '') !== ''
            || ($facts['onset'] ?? '') !== ''
            || $facts['pain_score'] !== null
            || ($facts['pain_qualifier'] ?? '') !== ''
            || ($facts['denied_associated'] ?? false) === true
            || $facts['has_other_symptoms'] !== null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>|null
     */
    private static function nextQuestionSlot(array $context, string $transcript, array $assessment = []): ?array
    {
        // Prefer triage-relevant adaptive selection over the full accumulated case.
        // On any failure, fall back to legacy bank-order selection below.
        try {
            if (class_exists('ClinicalInterviewAdaptivePolicy')) {
                $adaptive = ClinicalInterviewAdaptivePolicy::selectNextSlot($context, $transcript, $assessment);
                if (is_array($adaptive) && ($adaptive['question_id'] ?? '') !== '') {
                    return [
                        'question_id' => (string) $adaptive['question_id'],
                        'clinical_purpose' => (string) ($adaptive['clinical_purpose'] ?? ''),
                        'red_flag_related' => (bool) ($adaptive['red_flag_related'] ?? false),
                        'priority' => (int) ($adaptive['priority'] ?? 99),
                        'target_finding' => (string) ($adaptive['target_finding'] ?? strtolower((string) $adaptive['question_id'])),
                        'parent_question_id' => (string) ($adaptive['parent_question_id'] ?? ''),
                        'bank_template' => (string) ($adaptive['bank_template'] ?? ''),
                    ];
                }
                // Adaptive policy found no triage-relevant question → do not keep asking.
                return null;
            }
        } catch (Throwable $e) {
            error_log('ClinicalInterviewAdaptivePolicy selectNextSlot fallback: ' . $e->getMessage());
        }

        $families = self::familyKeys($context['chief_complaints'], $transcript, $context['facts']);
        if ($families === []) {
            $hasPainToken = (bool) preg_match(
                '/\b(sakit|masakit|pain|hurts|hapdi|discomfort|kasakit|gasakit)\b/u',
                mb_strtolower($transcript)
            );
            $families = [$hasPainToken ? 'pain_unspecified' : 'general_unwell'];
        }
        $asked = array_map('strtoupper', $context['questions_asked']);
        $facts = $context['facts'];

        foreach (ClinicalFollowUpQuestionBank::questions() as $question) {
            $qid = strtoupper((string) ($question['question_id'] ?? ''));
            if ($qid === '' || in_array($qid, $asked, true)) {
                continue;
            }
            $when = array_map('strtolower', (array) ($question['required_when'] ?? []));
            if ($when !== [] && array_intersect($when, $families) === []) {
                continue;
            }
            if (self::questionAlreadyAnswered($qid, $facts, $transcript, $families)) {
                continue;
            }

            return [
                'question_id' => $qid,
                'clinical_purpose' => (string) ($question['clinical_purpose'] ?? ''),
                'red_flag_related' => (bool) ($question['red_flag_related'] ?? false),
                'priority' => (int) ($question['priority'] ?? 99),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>|null
     */
    private static function nextQuestion(array $context, string $transcript, array $assessment = []): ?array
    {
        $lang = $context['question_language'] !== '' ? $context['question_language'] : 'english';
        $language = strtoupper($lang === 'tagalog' ? 'TAGALOG' : ($lang === 'hiligaynon' ? 'HILIGAYNON' : 'ENGLISH'));

        // 1) Gemini select among PHP allow-list candidates (already-known slots filtered by AdaptivePolicy).
        try {
            if (class_exists('ClinicalInterviewAdaptivePolicy')
                && class_exists('ClinicalInterviewGeminiFollowUp')
                && ClinicalInterviewGeminiFollowUp::enabled()
            ) {
                $candidates = ClinicalInterviewAdaptivePolicy::listCandidateSlots($context, $transcript, $assessment);
                if ($candidates === []) {
                    // Nothing clinically missing — finish interview → ClinicalTriageEngine.
                    return null;
                }
                $picked = ClinicalInterviewGeminiFollowUp::selectNext($candidates, $context, $transcript);
                if (is_array($picked) && trim((string) ($picked['text'] ?? '')) !== '') {
                    // Final PHP gate: still must be in the current open-candidate set.
                    $openIds = [];
                    foreach ($candidates as $row) {
                        if (is_array($row)) {
                            $openIds[] = strtoupper(trim((string) ($row['question_id'] ?? '')));
                        }
                    }
                    $pickedId = strtoupper(trim((string) ($picked['question_id'] ?? '')));
                    if ($pickedId !== '' && in_array($pickedId, $openIds, true)) {
                        $picked['language'] = (string) ($picked['language'] ?? $language);

                        return $picked;
                    }
                }
                // Valid Gemini finish: do NOT fall through to the generic bank.
                if (ClinicalInterviewGeminiFollowUp::isFinishDecision()) {
                    return null;
                }
                // Otherwise Gemini failed/unavailable/invalid/unusable → PHP bank fallback below.
            }
        } catch (Throwable $e) {
            error_log('Gemini adaptive select fallback: ' . $e->getMessage());
        }

        // 2) Deterministic bank/policy fallback (Gemini error/disabled/invalid only).
        $slot = self::nextQuestionSlot($context, $transcript, $assessment);
        if ($slot === null) {
            return null;
        }
        $slot['language'] = $language;

        $bankText = '';
        $bankQid = strtoupper((string) ($slot['question_id'] ?? ''));
        $parent = '';
        if (str_contains($bankQid, '__')) {
            $parent = explode('__', $bankQid, 2)[0];
        }
        foreach (ClinicalFollowUpQuestionBank::questions() as $question) {
            $qid = strtoupper((string) ($question['question_id'] ?? ''));
            if ($qid === $bankQid || ($parent !== '' && $qid === $parent)) {
                $bankText = ClinicalFollowUpQuestionBank::textForLanguage($question, $lang);
                if ($parent !== '' && class_exists('ClinicalInterviewAdaptivePolicy')) {
                    // Prefer a simple English atomic template when falling back without Gemini.
                    $finding = strtolower((string) ($slot['target_finding'] ?? ''));
                    $bankText = self::atomicFallbackText($finding, $lang, $bankText);
                }
                break;
            }
        }
        if ($bankText === '') {
            return null;
        }

        $geminiText = class_exists('ClinicalInterviewGeminiFollowUp')
            ? ClinicalInterviewGeminiFollowUp::phrase($slot, $context, $transcript, $bankText)
            : '';
        $text = $geminiText !== '' ? $geminiText : $bankText;
        if (self::questionLooksBundled($text)) {
            $finding = strtolower((string) ($slot['target_finding'] ?? ''));
            $atomic = self::atomicFallbackText($finding, $lang, '');
            if ($atomic !== '') {
                $text = $atomic;
            } elseif ($bankText !== '' && !self::questionLooksBundled($bankText)) {
                $text = $bankText;
            }
        }

        return [
            'question_id' => (string) ($slot['question_id'] ?? ''),
            'target_finding' => (string) ($slot['target_finding'] ?? strtolower((string) ($slot['question_id'] ?? ''))),
            'clinical_purpose' => (string) ($slot['clinical_purpose'] ?? ''),
            'red_flag_related' => (bool) ($slot['red_flag_related'] ?? false),
            'priority' => (int) ($slot['priority'] ?? 99),
            'text' => $text,
            'language' => $language,
            'source' => $geminiText !== '' ? 'gemini' : 'question_bank',
            'parent_question_id' => (string) ($slot['parent_question_id'] ?? $parent),
            'complaint_id' => (string) ($context['active_complaint_id'] ?? ''),
        ];
    }

    private static function questionLooksBundled(string $text): bool
    {
        $low = mb_strtolower($text);
        if (substr_count($text, '?') > 1) {
            return true;
        }

        return (bool) preg_match(
            '/\b(vomit|fever|bleed|suka|hilanat|lagnat|dugo).{0,40}\b(or|ukon|,).{0,40}\b(vomit|fever|bleed|suka|hilanat|lagnat|dugo)/u',
            $low
        );
    }

    private static function atomicFallbackText(string $finding, string $lang, string $fallback): string
    {
        $lang = strtolower($lang);
        $map = [
            'vomiting' => [
                'english' => 'Are you vomiting?',
                'tagalog' => 'Nagsusuka ka ba?',
                'hiligaynon' => 'Nagasuka bala ikaw?',
            ],
            'fever_with_abdomen' => [
                'english' => 'Do you have a fever with the abdominal pain?',
                'tagalog' => 'May lagnat ka ba kasama ng sakit sa tiyan?',
                'hiligaynon' => 'May hilanat bala ikaw upod sa sakit sang tiyan?',
            ],
            'bleeding_with_abdomen' => [
                'english' => 'Is there any bleeding with the abdominal pain?',
                'tagalog' => 'May pagdurugo ba kasama ng sakit sa tiyan?',
                'hiligaynon' => 'May pagdugo bala upod sa sakit sang tiyan?',
            ],
            'sweating_with_chest' => [
                'english' => 'Are you sweating a lot with the chest pain?',
                'tagalog' => 'Pinagpapawisan ka ba nang malakas kasama ng sakit sa dibdib?',
                'hiligaynon' => 'Naga singot bala gid ikaw upod sa sakit sang dughan?',
            ],
            'dizziness_with_chest' => [
                'english' => 'Do you feel dizzy or like you might faint with the chest pain?',
                'tagalog' => 'Nahihilo ka ba o para kang mahimatay kasama ng sakit sa dibdib?',
                'hiligaynon' => 'Nalilipong bala ikaw ukon daw magapunaw upod sa sakit sang dughan?',
            ],
            'urinary_burning' => [
                'english' => 'Does it burn or hurt when you urinate?',
                'tagalog' => 'May hapdi o sakit ba kapag umiihi?',
                'hiligaynon' => 'May hapdi ukon sakit bala kung mag-ihi?',
            ],
            'urinary_blood' => [
                'english' => 'Is there blood in your urine?',
                'tagalog' => 'May dugo ba sa ihi?',
                'hiligaynon' => 'May dugo bala sa imo ihi?',
            ],
            'urinary_fever' => [
                'english' => 'Do you have a fever with these urinary symptoms?',
                'tagalog' => 'May lagnat ka ba kasama ng problema sa ihi?',
                'hiligaynon' => 'May hilanat bala ikaw upod sa problema sa ihi?',
            ],
        ];
        if (!isset($map[$finding])) {
            return $fallback;
        }
        $row = $map[$finding];
        if ($lang === 'tagalog' || $lang === 'filipino') {
            return (string) $row['tagalog'];
        }
        if ($lang === 'hiligaynon' || $lang === 'ilonggo') {
            return (string) $row['hiligaynon'];
        }

        return (string) $row['english'];
    }

    /**
     * @param list<array{id?:string,name?:string}> $complaints
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function familyKeys(array $complaints, string $transcript, array $facts): array
    {
        return ClinicalInterviewContextResolver::deriveFamilies($complaints, $transcript, $facts);
    }

    /**
     * @param array<string, mixed> $facts
     * @param list<string> $families
     */
    private static function questionAlreadyAnswered(string $qid, array $facts, string $transcript, array $families): bool
    {
        $low = mb_strtolower($transcript);
        $findingStatus = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        $uncertainFinding = match ($qid) {
            'NEURO_WEAKNESS' => 'weakness',
            'NEURO_SPEECH' => 'speech_difficulty',
            'NEURO_VISION' => 'vision_change',
            'BREATHING_SEVERITY' => 'breathing_difficulty',
            'BLEEDING_CONTINUING' => 'bleeding_continuing',
            'BLEEDING_HEAVY' => 'bleeding_heavy',
            'BLEEDING_DIZZY' => 'dizziness',
            'CHEST_RADIATION' => 'chest_radiation',
            'FEVER_CONFIRM' => 'fever_confirmed',
            'ASSOCIATED_SYMPTOMS' => 'has_other_symptoms',
            default => '',
        };
        if ($uncertainFinding !== '' && ($findingStatus[$uncertainFinding] ?? '') === 'uncertain') {
            return true;
        }

        return match ($qid) {
            'PAIN_LOCATION', 'UNWELL_WHAT' => (is_array($facts['body_locations'] ?? null) ? $facts['body_locations'] : []) !== []
                || (bool) preg_match('/\b(ulo|head|dughan|dibdib|chest|tiyan|ilong|nose|kamot|hand)\b/u', $low)
                || (
                    !in_array('pain_unspecified', $families, true)
                    && !in_array('general_unwell', $families, true)
                    && !in_array('pain_no_location', $families, true)
                ),
            'NOSE_PAIN_WHERE' => (bool) preg_match('/\b(bridge|tip|nostril|tuod|pungos)\b/u', $low),
            // Numeric 0–10 only — qualitative intensifiers must not skip the pain scale.
            'PAIN_SEVERITY' => ($facts['pain_score'] ?? null) !== null,
            'ONSET', 'DURATION' => ClinicalFeatureExtractors::hasTimingInformation($transcript, $facts),
            'NEURO_WEAKNESS' => ($facts['weakness'] ?? null) !== null,
            // Independent neuro findings — do not couple speech/vision to weakness.
            'NEURO_SPEECH' => ($facts['speech_difficulty'] ?? null) !== null,
            'NEURO_VISION' => ($facts['vision_change'] ?? null) !== null,
            'BREATHING_SEVERITY' => ($facts['breathing_difficulty'] ?? null) !== null,
            'BLEEDING_CONTINUING' => ($facts['bleeding_continuing'] ?? null) !== null,
            'BLEEDING_HEAVY' => ($facts['bleeding_heavy'] ?? null) !== null,
            'BLEEDING_DIZZY' => ($facts['dizziness'] ?? null) !== null,
            // Independent of breathing answers and generic denied_associated.
            'CHEST_RADIATION' => ($facts['chest_radiation'] ?? null) !== null,
            // Parent bundle completion is adaptive-policy atom resolution; sweating alone
            // (from sweating_with_chest) must not suppress the sibling dizziness atom here.
            'CHEST_SWEATING' => false,
            'ABDOMINAL_ASSOCIATED' => ($facts['abdominal_associated'] ?? null) !== null,
            'ASSOCIATED_SYMPTOMS' => (
                !empty($facts['denied_associated'])
                || (($facts['has_other_symptoms'] ?? null) === false)
                || (
                    ($facts['has_other_symptoms'] ?? null) === true
                    && self::stringList($facts['associated_symptoms'] ?? []) !== []
                    && empty($facts['needs_associated_detail'])
                )
            ),
            'ASSOCIATED_DETAIL' => empty($facts['needs_associated_detail'])
                && (
                    self::stringList($facts['associated_symptoms'] ?? []) !== []
                    || ($facts['has_other_symptoms'] ?? null) !== true
                    || !empty($facts['denied_associated'])
                ),
            default => false,
        };
    }

    /**
     * Ordinary symptom / ICD / fuzzy matches must not outrank collected clinical context.
     * Emergency red flags already finalized earlier. Do not invent negatives.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     */
    private static function applyInterviewSafetyOverride(string $display, array $context, array $assessment): string
    {
        if ($display === 'EMERGENCY' || self::redFlagNames($assessment) !== []) {
            return 'EMERGENCY';
        }

        // WHO/IITT match is final for interview handoff — do not downgrade URGENT→NON-URGENT.
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $who = $triage['assessment_factors']['who_iitt']
            ?? $assessment['assessment_factors']['who_iitt']
            ?? null;
        if (is_array($who) && trim((string) ($who['triage_level'] ?? '')) !== '') {
            return $display;
        }

        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $ids = [];
        foreach ((array) ($context['chief_complaints'] ?? []) as $row) {
            if (is_array($row)) {
                $ids[] = strtoupper((string) ($row['id'] ?? ''));
            } elseif (is_string($row)) {
                $ids[] = strtoupper($row);
            }
        }

        if (array_intersect($ids, ['CHEST_PAIN', 'DIFFICULTY_BREATHING', 'BLEEDING', 'NEURO', 'ABDOMINAL_PAIN']) !== []) {
            return $display;
        }
        if (($facts['onset'] ?? '') === 'sudden') {
            return $display;
        }
        if (($facts['breathing_difficulty'] ?? null) === true || ($facts['weakness'] ?? null) === true) {
            return $display;
        }

        $denied = ($facts['denied_associated'] ?? false) === true || ($facts['has_other_symptoms'] ?? null) === false;
        $pain = $facts['pain_score'] ?? null;
        $hasTiming = ($facts['duration_label'] ?? '') !== '' || ($facts['onset'] ?? '') === 'gradual';
        // Unknown pain (null) must not count as mild — only a known score 1–4 does.
        $mildPain = is_int($pain) && $pain <= 4;

        if ($display === 'URGENT' && $denied && $hasTiming && $mildPain) {
            return 'NON-URGENT';
        }

        return $display;
    }

    /**
     * @param array<string, mixed> $assessment
     */
    private static function finalDisplayFromAssessment(array $assessment): string
    {
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $raw = strtoupper(str_replace('_', '-', (string) ($triage['triage_display'] ?? $triage['triage_classification'] ?? 'NON-URGENT')));
        if (str_contains($raw, 'EMERGENCY')) {
            return 'EMERGENCY';
        }
        if (str_contains($raw, 'URGENT') && !str_contains($raw, 'NON')) {
            return 'URGENT';
        }

        return 'NON-URGENT';
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $context
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    private static function wrapInProgress(array $assessment, array $context, array $question, string $transcript): array
    {
        $context['assessment_status'] = self::STATUS_IN_PROGRESS;
        $context['last_followup_question'] = self::questionSnapshot($question);
        $interview = self::publicInterview($context, $question, null);

        $engineDisplay = self::finalDisplayFromAssessment($assessment);

        $assessment['assessment_status'] = self::STATUS_IN_PROGRESS;
        $assessment['followup_required'] = true;
        $assessment['followup_question'] = $question;
        $assessment['interview'] = $interview;
        $assessment['original_chief_complaint'] = $context['chief_complaint'];
        $assessment['chief_complaint'] = $context['chief_complaint'];
        $assessment['clinical_transcript'] = $transcript;
        $assessment['patient_message'] = (string) ($question['text'] ?? '');

        if (!isset($assessment['triage']) || !is_array($assessment['triage'])) {
            $assessment['triage'] = [];
        }
        $assessment['triage']['provisional_engine_classification'] = $engineDisplay;
        $assessment['triage']['assessment_status'] = self::STATUS_IN_PROGRESS;
        $assessment['triage']['triage_classification'] = '';
        $assessment['triage']['triage_display'] = '';
        $assessment['triage']['gis_triage_level'] = '';
        $assessment['triage']['db_level'] = 'pending';
        $assessment['triage']['urgency_label'] = 'Assessment in progress';
        $assessment['db_level'] = 'pending';
        $assessment['urgency_label'] = 'Assessment in progress';

        if (getenv('NLP_DEBUG') === '1' || (!empty($_ENV['NLP_DEBUG']) && $_ENV['NLP_DEBUG'] === '1')) {
            error_log('[NLP_DEBUG] IN_PROGRESS '
                . json_encode([
                    'complaint' => (string) ($context['chief_complaint'] ?? ''),
                    'question_language' => (string) ($context['question_language'] ?? ''),
                    'detected_language' => (string) ($context['detected_language'] ?? ''),
                    'awaiting' => (string) ($question['question_id'] ?? ''),
                    'pain_score' => $context['facts']['pain_score'] ?? null,
                    'duration' => $context['facts']['duration_label'] ?? null,
                    'onset' => $context['facts']['onset'] ?? null,
                ], JSON_UNESCAPED_UNICODE));
        }

        return $assessment;
    }

    /**
     * Invalid follow-up: keep the same question, do not append the answer or update facts.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    /**
     * Capture named symptoms / implicit red flags from an answer that failed the
     * current-question validator, without advancing or filling the awaited slot.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function absorbOffSlotClinicalFacts(array $context, string $turn, string $awaiting): array
    {
        $turn = trim($turn);
        if ($turn === '') {
            return $context;
        }
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $low = mb_strtolower($turn);
        $awaitingUpper = strtoupper(trim($awaiting));
        $originalComplaint = trim((string) ($context['chief_complaint'] ?? ''));
        $combined = trim(implode('. ', array_filter([
            $originalComplaint,
            $turn,
            self::transcript($context),
        ], static fn ($v): bool => trim((string) $v) !== '')));

        // Never invent facts from bare yes/no.
        $yn = ClinicalFeatureExtractors::extractYesNo($turn);
        $bareYesNo = $yn !== null && !preg_match(
            '/\b(may|mayroon|naa|gina|naga|ga|kasakit|sakit|pain|suka|ubo|hilo|hubag|ginhawa|breath|makaginhawa|kaginhawa)\b/u',
            $low
        ) && mb_strlen(preg_replace('/\s+/u', ' ', $low)) <= 12;
        if ($bareYesNo) {
            return $context;
        }

        if (class_exists('SymptomKnowledgeBase')) {
            try {
                $assoc = self::stringList($facts['associated_symptoms'] ?? []);
                $negList = self::stringList($facts['negative_symptoms'] ?? []);
                foreach (SymptomKnowledgeBase::matchSymptoms($turn, $turn) as $row) {
                    $name = trim((string) ($row['symptom_name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $denied = false;
                    foreach ($negList as $neg) {
                        if (self::symptomMatchesConcept($name, $neg)) {
                            $denied = true;
                            break;
                        }
                    }
                    if ($denied) {
                        continue;
                    }
                    $facts = self::recordSymptomName($facts, $name, 'kb');
                    if (!in_array($name, $assoc, true)) {
                        $assoc[] = $name;
                    }
                }
                $label = self::freeTextAssociatedLabel($turn);
                if ($label !== '' && !in_array($label, $assoc, true)) {
                    $assoc[] = $label;
                }
                if ($label !== '') {
                    $facts = self::recordSymptomName($facts, $label, 'patient');
                }
                $facts['associated_symptoms'] = $assoc;
            } catch (Throwable) {
                // keep prior facts
            }
        }

        $facts = self::absorbImplicitRedFlags($facts, mb_strtolower($combined));

        // Timing volunteered inside a wrong-slot answer can still fill an empty onset.
        if (!in_array($awaitingUpper, ['ONSET', 'DURATION'], true)) {
            $onset = ClinicalFeatureExtractors::extractOnset($turn);
            if ($onset !== '' && ($facts['onset'] === '' || $facts['onset'] === 'uncertain')) {
                $facts['onset'] = $onset;
            }
        }

        $context['facts'] = $facts;

        return $context;
    }

    private static function wrapRetryCurrentQuestion(array $context, array $validation): array
    {
        $question = self::heldFollowUpQuestion($context);
        $transcript = self::transcript($context);
        $acceptedUncertain = !empty($validation['accept'])
            && strtoupper((string) ($validation['answer_class'] ?? '')) === 'VALID_UNCERTAIN';
        $assessment = [
            'detected_symptoms' => is_array($context['matched_dataset_entries'] ?? null)
                ? $context['matched_dataset_entries']
                : [],
            'triage' => [
                'assessment_status' => self::STATUS_IN_PROGRESS,
                'triage_display' => '',
                'triage_classification' => '',
            ],
        ];
        $wrapped = self::wrapInProgress($assessment, $context, $question, $transcript);
        $langKey = (string) ($context['question_language'] ?? 'english');
        $rawMessage = (string) ($validation['message'] ?? '');
        $wrapped['patient_message'] = class_exists('ClinicalFollowUpAnswerValidator')
            ? ClinicalFollowUpAnswerValidator::normalizeRetryMessage($rawMessage, $langKey)
            : $rawMessage;
        if ($wrapped['patient_message'] === '' && class_exists('ClinicalFollowUpAnswerValidator')) {
            $wrapped['patient_message'] = ClinicalFollowUpAnswerValidator::retryMessage($langKey);
        }
        $wrapped['answer_rejected'] = !$acceptedUncertain;
        $wrapped['retry_current_question'] = true;
        $wrapped['followup_answer_validation'] = [
            'accept' => $acceptedUncertain,
            'reason' => (string) ($validation['reason'] ?? 'unrelated'),
            'expected_field' => (string) ($validation['expected_field'] ?? ''),
            'corrected_answer' => (string) ($validation['corrected_answer'] ?? ''),
            'empty' => !empty($validation['empty']),
            'answer_class' => (string) ($validation['answer_class'] ?? 'UNRELATED'),
            'polarity' => $validation['polarity'] ?? null,
        ];
        $wrapped['interview']['answer_rejected'] = !$acceptedUncertain;
        $wrapped['interview']['retry_current_question'] = true;
        $wrapped['interview']['last_followup_question'] = self::questionSnapshot($question);

        return $wrapped;
    }

    /**
     * Required yes/no uncertainty gets one clarification using the held question.
     * A repeated uncertain answer falls through to the existing unresolved/incomplete flow.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $validation
     */
    private static function shouldRetryUncertainYesNo(
        array $context,
        array $validation,
        string $awaiting
    ): bool {
        if (strtoupper((string) ($validation['answer_class'] ?? '')) !== 'VALID_UNCERTAIN'
            || strtolower((string) ($validation['polarity'] ?? '')) !== 'uncertain'
            || strtoupper((string) ($validation['expected_field'] ?? '')) !== 'ASSOCIATED_SYMPTOMS'
        ) {
            return false;
        }

        $qid = strtoupper(trim($awaiting));
        if ($qid === '') {
            return false;
        }

        foreach ((array) ($context['questions_answered'] ?? []) as $row) {
            if (!is_array($row)
                || strtoupper(trim((string) ($row['question_id'] ?? ''))) !== $qid
            ) {
                continue;
            }
            $prior = trim((string) ($row['answer'] ?? ''));
            if ($prior !== ''
                && class_exists('ClinicalFeatureExtractors')
                && ClinicalFeatureExtractors::looksPatientUncertain($prior)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function heldFollowUpQuestion(array $context): array
    {
        $held = self::questionSnapshot($context['last_followup_question'] ?? null);
        if (($held['question_id'] ?? '') !== '' && ($held['text'] ?? '') !== '') {
            return $held;
        }

        $qid = strtoupper(trim((string) ($context['awaiting_question_id'] ?? '')));
        $lang = $context['question_language'] !== '' ? $context['question_language'] : 'english';
        $language = strtoupper($lang === 'tagalog' ? 'TAGALOG' : ($lang === 'hiligaynon' ? 'HILIGAYNON' : 'ENGLISH'));
        $text = '';
        if ($qid !== '' && class_exists('ClinicalFollowUpQuestionBank')) {
            $row = ClinicalFollowUpQuestionBank::byId($qid);
            if (is_array($row)) {
                $text = ClinicalFollowUpQuestionBank::textForLanguage($row, $lang);
            }
        }
        if ($text === '') {
            $text = 'Please answer the follow-up question.';
        }

        return [
            'question_id' => $qid,
            'text' => $text,
            'language' => $language,
            'clinical_purpose' => '',
            'source' => 'held',
        ];
    }

    /** @param mixed $raw */
    private static function questionSnapshot($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $qid = strtoupper(trim((string) ($raw['question_id'] ?? '')));
        $text = trim((string) ($raw['text'] ?? ''));
        if ($qid === '' && $text === '') {
            return [];
        }

        return [
            'question_id' => $qid,
            'text' => $text,
            'language' => (string) ($raw['language'] ?? ''),
            'clinical_purpose' => (string) ($raw['clinical_purpose'] ?? ''),
            'source' => (string) ($raw['source'] ?? ''),
            'red_flag_related' => (bool) ($raw['red_flag_related'] ?? false),
            'priority' => (int) ($raw['priority'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $validation
     */
    private static function logFollowUpValidation(array $context, string $qid, string $answer, array $validation): void
    {
        if (getenv('NLP_DEBUG') !== '1' && empty($_ENV['NLP_DEBUG'])) {
            return;
        }
        error_log('[NLP_DEBUG] FOLLOWUP_ANSWER '
            . json_encode([
                'primary_complaint' => (string) ($context['chief_complaint'] ?? ''),
                'normalized_complaint' => $context['chief_complaints'] ?? [],
                'current_question' => (string) (($context['last_followup_question']['text'] ?? '') ?: $qid),
                'expected_field' => (string) ($validation['expected_field'] ?? ''),
                'patient_answer' => $answer,
                'detected_language' => (string) ($context['question_language'] ?? $context['detected_language'] ?? ''),
                'corrected_answer' => (string) ($validation['corrected_answer'] ?? ''),
                'relevance_result' => !empty($validation['accept']),
                'question_answered' => !empty($validation['accept']),
                'extracted_information' => $validation['extracted'] ?? [],
                'validation_reason' => (string) ($validation['reason'] ?? ''),
                'workflow_action' => !empty($validation['accept']) ? 'ADVANCE_TO_NEXT_REQUIRED_QUESTION' : 'RETRY_CURRENT_QUESTION',
            ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function finalize(array $assessment, array $context, string $display, string $transcript): array
    {
        if (!in_array($display, self::FINAL_CLASSES, true)) {
            $display = 'NON-URGENT';
        }
        $interviewIncomplete = !empty($context['who_interview_incomplete'])
            || !empty($assessment['triage']['who_interview_incomplete'])
            || !empty($assessment['triage']['interview_incomplete']);
        $context['red_flags'] = self::redFlagNames($assessment);
        $context['assessment_status'] = self::STATUS_COMPLETED;
        $context['awaiting_question_id'] = '';
        $classification = $display === 'NON-URGENT' ? 'NON_URGENT' : $display;
        $gis = match ($display) {
            'EMERGENCY' => 'emergency',
            'URGENT' => 'urgent',
            default => 'non_urgent',
        };
        $icon = match ($display) {
            'EMERGENCY' => '🔴',
            'URGENT' => '🟡',
            default => '🟢',
        };
        $patientMessage = self::patientMessage($display, $interviewIncomplete);

        if (!isset($assessment['triage']) || !is_array($assessment['triage'])) {
            $assessment['triage'] = [];
        }
        $assessment['triage']['triage_display'] = $display;
        $assessment['triage']['triage_classification'] = $classification;
        $assessment['triage']['gis_triage_level'] = $gis;
        $assessment['triage']['triage_icon'] = $icon;
        $assessment['triage']['assessment_status'] = self::STATUS_COMPLETED;
        $assessment['triage']['db_level'] = match ($display) {
            'EMERGENCY' => '1',
            'URGENT' => '2',
            default => '3',
        };
        // Incomplete interviews keep the engine class/db_level but must not read as routine clearance.
        $assessment['triage']['urgency_label'] = match (true) {
            $display === 'EMERGENCY' => 'Emergency (Immediate)',
            $display === 'URGENT' => 'Urgent (Priority)',
            $interviewIncomplete => 'Needs Provider Review (Incomplete Assessment)',
            default => 'Non-Urgent (Routine)',
        };

        $assessment['assessment_status'] = self::STATUS_COMPLETED;
        $assessment['followup_required'] = false;
        $assessment['followup_question'] = null;
        $assessment['interview'] = self::publicInterview($context, null, $display);
        $assessment['original_chief_complaint'] = $context['chief_complaint'];
        $assessment['chief_complaint'] = $context['chief_complaint'];
        $assessment['clinical_transcript'] = $transcript;
        $assessment['patient_message'] = $patientMessage;
        $assessment['db_level'] = $assessment['triage']['db_level'];
        $assessment['urgency_label'] = $assessment['triage']['urgency_label'];

        // Explicit authority: complete-case ClinicalTriageEngine decides final class.
        // Per-complaint provisionals are supporting metadata only (never MAX-aggregated).
        $assessment['triage']['final_authority'] = 'ClinicalTriageEngine';
        $assessment['triage']['final_authority_scope'] = 'complete_accumulated_case';
        $assessment['triage']['max_urgency_aggregation'] = false;
        if ($interviewIncomplete) {
            $assessment['triage']['needs_provider_review'] = true;
            $assessment['triage']['interview_incomplete'] = true;
            $assessment['triage']['who_interview_incomplete'] = true;
            $assessment['triage']['confidence_accepted'] = false;
            $assessment['needs_provider_review'] = true;
        }
        $provisionals = is_array($context['complaint_provisionals'] ?? null)
            ? $context['complaint_provisionals']
            : [];
        $assessment['triage']['complaint_provisionals'] = $provisionals;
        $assessment['complaint_provisionals'] = $provisionals;

        return $assessment;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $question
     * @return array<string, mixed>
     */
    private static function publicInterview(array $context, ?array $question, ?string $finalDisplay): array
    {
        return [
            'assessment_status' => $context['assessment_status'],
            'chief_complaint' => $context['chief_complaint'],
            'detected_language' => $context['detected_language'],
            'question_language' => strtoupper($context['question_language'] === 'tagalog'
                ? 'TAGALOG'
                : ($context['question_language'] === 'english' ? 'ENGLISH' : 'HILIGAYNON')),
            'normalized_complaints' => $context['chief_complaints'],
            'complaints' => is_array($context['complaints'] ?? null) ? $context['complaints'] : [],
            'active_complaint_id' => (string) ($context['active_complaint_id'] ?? ''),
            'complaint_provisionals' => is_array($context['complaint_provisionals'] ?? null)
                ? $context['complaint_provisionals']
                : [],
            'body_locations' => $context['facts']['body_locations'] ?? [],
            'pain_score' => $context['facts']['pain_score'] ?? null,
            'onset' => $context['facts']['onset'] ?? '',
            'duration' => $context['facts']['duration_label'] ?? '',
            'associated_symptoms' => $context['matched_dataset_entries'],
            'red_flags' => self::stringList($context['red_flags'] ?? []),
            'patient_answers' => $context['questions_answered'],
            'questions_already_asked' => $context['questions_asked'],
            'questions_answered' => $context['questions_answered'],
            'awaiting_question_id' => $context['awaiting_question_id'],
            'last_followup_question' => self::questionSnapshot($context['last_followup_question'] ?? $question),
            'matched_dataset_entries' => $context['matched_dataset_entries'],
            'next_question' => $question,
            'final_classification' => $finalDisplay,
            'facts' => $context['facts'],
            'patient_turns' => $context['patient_turns'],
            'patient_evidence_text' => self::patientEvidenceText($context),
            'chief_complaints' => $context['chief_complaints'],
            'semantic_bridge' => is_array($context['semantic_bridge'] ?? null) ? $context['semantic_bridge'] : [],
            'python_enrichment' => is_array($context['python_enrichment'] ?? null) ? $context['python_enrichment'] : [],
            'findings_asked' => self::stringList($context['findings_asked'] ?? []),
            'awaiting_target_finding' => (string) ($context['awaiting_target_finding'] ?? ''),
        ];
    }

    public static function patientMessage(string $display, bool $interviewIncomplete = false): string
    {
        if ($interviewIncomplete) {
            return match ($display) {
                'EMERGENCY' => "🔴 EMERGENCY\n\nYour reported symptoms may require immediate medical attention. Please seek emergency care immediately.\n\nThis assessment is incomplete and needs provider review. Unanswered information was not assumed to be negative.",
                'URGENT' => "🟡 URGENT\n\nYour symptoms should be assessed by a healthcare professional promptly.\n\nThis assessment is incomplete and needs provider review. Unanswered information was not assumed to be negative.",
                default => "Assessment incomplete — needs provider review\n\nThis assessment could not be completed with the information available. A healthcare provider should review your case. Unanswered questions were not assumed to be negative, and this is not a routine clearance.",
            };
        }

        return match ($display) {
            'EMERGENCY' => "🔴 EMERGENCY\n\nYour reported symptoms may require immediate medical attention. Please seek emergency care immediately.",
            'URGENT' => "🟡 URGENT\n\nYour symptoms should be assessed by a healthcare professional promptly.",
            default => "🟢 NON-URGENT\n\nBased on the information provided, your symptoms do not currently show signs requiring emergency attention. Routine consultation is appropriate.",
        };
    }

    /** @return array<string, mixed> */
    private static function wrapEmpty(): array
    {
        return [
            'assessment_status' => self::STATUS_IN_PROGRESS,
            'followup_required' => true,
            'followup_question' => [
                'question_id' => 'UNWELL_WHAT',
                'text' => 'Please describe your symptoms.',
                'language' => 'ENGLISH',
            ],
            'triage' => [
                'triage_classification' => '',
                'triage_display' => '',
                'assessment_status' => self::STATUS_IN_PROGRESS,
            ],
            'interview' => [
                'assessment_status' => self::STATUS_IN_PROGRESS,
            ],
        ];
    }

    /**
     * Non-health opening turn: do not run triage classification.
     *
     * @param array<string, mixed> $domain
     * @return array<string, mixed>
     */
    private static function wrapDomainSkip(array $domain, string $utterance): array
    {
        $lang = 'english';
        if (class_exists('HiligaynonLanguageDetector')) {
            try {
                $detected = HiligaynonLanguageDetector::detect($utterance);
                $primary = strtolower((string) ($detected['primary'] ?? 'english'));
                $lang = match ($primary) {
                    'hiligaynon', 'ilonggo' => 'hiligaynon',
                    'tagalog', 'filipino' => 'tagalog',
                    default => 'english',
                };
            } catch (Throwable) {
                $lang = 'english';
            }
        }
        $message = class_exists('ComplaintSemanticValidator')
            ? ComplaintSemanticValidator::clarificationMessage($lang)
            : 'Please describe a health concern or symptom you are experiencing so we can continue.';

        return self::wrapNeedsValidComplaint([
            'patient_message' => $message,
            'detected_language' => $lang,
            'php_domain' => $domain,
            'classification' => ComplaintSemanticValidator::CLASS_INVALID,
            'final_validation_result' => ComplaintSemanticValidator::CLASS_INVALID,
            'gemini_validation_result' => 'UNAVAILABLE',
            'combine_reason' => (string) ($domain['reason'] ?? 'legacy domain skip'),
        ], $utterance);
    }

    /**
     * Pre-triage state: ask for a real health concern. Not a fourth triage class.
     *
     * @param array<string, mixed> $semantic
     * @return array<string, mixed>
     */
    private static function wrapNeedsValidComplaint(array $semantic, string $utterance): array
    {
        $domainLabel = (string) ($semantic['domain_label'] ?? ComplaintSemanticValidator::DOMAIN_NON_HEALTH);
        $isUnclear = $domainLabel === ComplaintSemanticValidator::DOMAIN_UNCLEAR
            || !empty($semantic['needs_clarification']);
        // NEEDS_VALID_COMPLAINT only for confirmed non-health / prank.
        $needsValid = !$isUnclear && (
            !empty($semantic['needs_valid_complaint'])
            || $domainLabel === ComplaintSemanticValidator::DOMAIN_NON_HEALTH
        );
        $message = trim((string) ($semantic['patient_message'] ?? ''));
        if ($message === '') {
            $message = class_exists('ComplaintSemanticValidator')
                ? ComplaintSemanticValidator::patientGateMessage(
                    (string) ($semantic['detected_language'] ?? 'english'),
                    $isUnclear ? ComplaintSemanticValidator::DOMAIN_UNCLEAR : $domainLabel
                )
                : 'Please describe a health concern or symptom you are experiencing so we can continue.';
        }

        $status = self::STATUS_NEEDS_VALID_COMPLAINT;

        return [
            'assessment_status' => $status,
            'followup_required' => false,
            'followup_question' => null,
            'patient_message' => $message,
            'needs_valid_complaint' => $needsValid,
            'needs_clarification' => $isUnclear,
            'domain_label' => $isUnclear ? ComplaintSemanticValidator::DOMAIN_UNCLEAR : $domainLabel,
            'domain_skipped' => true,
            'domain_detection' => is_array($semantic['php_domain'] ?? null) ? $semantic['php_domain'] : ($semantic['domain_detection'] ?? []),
            'semantic_validation' => $semantic,
            'chief_complaint' => $utterance,
            'original_chief_complaint' => $utterance,
            'detected_symptoms' => [],
            'detected_language' => (string) ($semantic['detected_language'] ?? ''),
            'triage' => [
                'triage_classification' => '',
                'triage_display' => '',
                'triage_status' => 'NOT_READY',
                'assessment_status' => $status,
                'reason' => (string) ($semantic['combine_reason'] ?? (
                    $isUnclear ? 'Health concern unclear — ask to clarify' : 'Invalid medical input'
                )),
                'clinical_reasoning' => $isUnclear
                    ? 'No clinical triage — opening complaint needs clarification (not confirmed non-health).'
                    : 'No clinical triage — input failed semantic medical-complaint validation.',
                'needs_valid_complaint' => $needsValid,
                'needs_clarification' => $isUnclear,
                'domain_label' => $isUnclear ? ComplaintSemanticValidator::DOMAIN_UNCLEAR : $domainLabel,
                'domain_skipped' => true,
            ],
            'interview' => [
                'assessment_status' => $status,
                'needs_valid_complaint' => $needsValid,
                'needs_clarification' => $isUnclear,
                'domain_label' => $isUnclear ? ComplaintSemanticValidator::DOMAIN_UNCLEAR : $domainLabel,
                'domain_skipped' => true,
            ],
            'engine' => 'clinical-interview-semantic-gate',
            'engine_version' => MedicalAssessmentEngine::VERSION,
        ];
    }

    /**
     * Persist Yes/No against the active target finding (and related legacy fact keys).
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function applyTargetFindingPolarity(array $facts, string $finding, bool $yesNo): array
    {
        $finding = strtolower(trim($finding));
        if ($finding === '') {
            return $facts;
        }
        $status = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        $status[$finding] = $yesNo ? 'positive' : 'negative';
        $facts['finding_status'] = $status;

        // Direct mappings.
        $direct = [
            'weakness' => 'weakness',
            'speech_difficulty' => 'speech_difficulty',
            'vision_change' => 'vision_change',
            'breathing_difficulty' => 'breathing_difficulty',
            'bleeding_continuing' => 'bleeding_continuing',
            'bleeding_heavy' => 'bleeding_heavy',
            'dizziness' => 'dizziness',
            'chest_radiation' => 'chest_radiation',
            // Persist sweating from chest atoms without implying the full CHEST_SWEATING
            // parent bundle is complete (see alreadyAnswered). Chest dizziness stays scoped
            // to finding_status['dizziness_with_chest'] — do not write shared dizziness
            // (that would incorrectly close DIZZINESS_TYPE characterization).
            'sweating_with_chest' => 'sweating',
            'fever_confirmed' => 'fever_confirmed',
            'fever_with_abdomen' => 'fever_confirmed',
            'has_other_symptoms' => 'has_other_symptoms',
        ];
        if (isset($direct[$finding])) {
            $facts[$direct[$finding]] = $yesNo;
        }

        if ($finding === 'vomiting') {
            $neg = self::stringList($facts['negative_symptoms'] ?? []);
            if ($yesNo) {
                $neg = array_values(array_filter($neg, static fn (string $s): bool => !str_contains(mb_strtolower($s), 'vomit') && !str_contains(mb_strtolower($s), 'suka')));
                $facts['negative_symptoms'] = $neg;
                $facts = self::recordSymptomName($facts, 'Vomiting', 'patient');
            } else {
                if (!in_array('vomiting', $neg, true)) {
                    $neg[] = 'vomiting';
                }
                $facts['negative_symptoms'] = $neg;
                $facts = self::filterSymptomsAgainstNegatives($facts);
            }
        }

        // bleeding_with_abdomen is presence only — never map to bleeding_continuing
        // (continuation is a separate BLEEDING_CONTINUING fact / QID).

        if (in_array($finding, ['urinary_burning', 'urinary_blood', 'urinary_fever'], true) && $yesNo) {
            $facts['has_other_symptoms'] = true;
        }

        return $facts;
    }

    /**
     * Optional Python dataset/matcher enrichment. Fail-open; never sets triage class.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function maybePythonEnrichment(array $context, string $turn, bool $isOpeningTurn): array
    {
        if (!class_exists('ClinicalInterviewPythonEnrichment')) {
            return $context;
        }
        if (!ClinicalInterviewPythonEnrichment::enabled()) {
            return $context;
        }

        $prior = is_array($context['python_enrichment'] ?? null) ? $context['python_enrichment'] : [];
        // Opening: enrich full case text. Follow-ups: enrich new turn only when useful.
        $seed = '';
        if ($isOpeningTurn) {
            $seed = trim((string) (($context['complaint_text_cleaner']['original'] ?? '') ?: ''));
            if ($seed === '') {
                $seed = trim((string) ($context['chief_complaint'] ?? ''));
            }
            if ($seed === '') {
                $seed = $turn;
            }
        } else {
            $seed = $turn;
            // Skip tiny yes/no answers — PHP extractors already handle polarity.
            if (mb_strlen($seed) < 4 || preg_match('/^(yes|no|oo|indi|hindi|oo\s*po)\.?$/iu', $seed)) {
                return $context;
            }
        }
        if ($seed === '') {
            return $context;
        }

        try {
            $enrichment = ClinicalInterviewPythonEnrichment::enrich($seed);
            if (empty($enrichment['used']) && $prior !== [] && !empty($prior['used'])) {
                // Keep prior successful enrichment when this turn had no signal.
                $context['python_enrichment'] = $prior;

                return $context;
            }

            return ClinicalInterviewPythonEnrichment::applyToContext($context, $enrichment);
        } catch (Throwable $e) {
            error_log('ClinicalInterview Python enrichment fallback: ' . $e->getMessage());
            if ($prior !== []) {
                $context['python_enrichment'] = $prior;
            }

            return $context;
        }
    }

    /**
     * Optional AI meaning support (Groq → OpenAI → local). Fails soft.
     * Never replaces the original patient wording and never sets triage class.
     * Runs for English / Hiligaynon / Tagalog / mixed.
     *
     * @return array<string, mixed>
     */
    private static function maybeAiMeaningBridge(string $originalText, string $detectedLanguage = ''): array
    {
        unset($detectedLanguage);
        $originalText = trim($originalText);
        if ($originalText === '' || !class_exists('MedicalAiInterpreter')) {
            return [];
        }

        try {
            $result = MedicalAiInterpreter::interpretComplaintMeaning($originalText);
            if (($result['status'] ?? '') !== 'complete') {
                return [];
            }
            $meaning = trim((string) ($result['english_interpretation'] ?? ''));
            if ($meaning === '' || mb_strlen($meaning) > 180) {
                return [];
            }
            if (preg_match('/\b(EMERGENCY|URGENT|NON-URGENT|diagnos|prescription)\b/iu', $meaning)) {
                return [];
            }

            return [
                'english_interpretation' => $meaning,
                'provider' => (string) ($result['provider'] ?? ''),
                'confidence_score' => (int) ($result['confidence_score'] ?? 0),
                'concepts' => is_array($result['concepts'] ?? null) ? $result['concepts'] : [],
                'evidence_source' => 'ai_interpreter',
            ];
        } catch (Throwable $e) {
            error_log('AI meaning bridge fallback: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @deprecated Use maybeAiMeaningBridge — kept for older call sites.
     * @return array<string, mixed>
     */
    private static function maybeHiligaynonMeaningBridge(string $originalText, string $detectedLanguage): array
    {
        return self::maybeAiMeaningBridge($originalText, $detectedLanguage);
    }
}
