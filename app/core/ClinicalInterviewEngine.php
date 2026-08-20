<?php
/**
 * Adaptive preliminary-triage interview on top of the existing NLP engine.
 *
 * Does not replace HiligaynonMedicalNlpPipeline / ClinicalTriageEngine.
 * Uses those engines on the accumulated patient transcript, then decides
 * whether enough clinical information exists to finalize NON-URGENT / URGENT /
 * EMERGENCY. If not, existing NLP decides whether clarification is still required;
 * only then does Gemini (with question-bank fallback) ask one follow-up.
 */

final class ClinicalInterviewEngine
{
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_COMPLETED = 'COMPLETED';
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
        if ($turn !== '') {
            $context = self::appendPatientTurn($context, $turn);
        }

        $transcript = self::transcript($context);
        if ($transcript === '') {
            return self::wrapEmpty();
        }

        if ($context['question_language'] === '') {
            $detected = HiligaynonLanguageDetector::detect($turn !== '' ? $turn : $transcript);
            $context['detected_language'] = strtoupper(self::languageLabel((string) ($detected['primary'] ?? 'hiligaynon')));
            $context['question_language'] = self::questionLanguageFromDetection($detected, $turn !== '' ? $turn : $transcript);
        } else {
            $latest = HiligaynonLanguageDetector::detect($turn);
            if (($latest['primary'] ?? '') !== '' && ($latest['primary'] ?? 'unknown') !== 'unknown') {
                $context['detected_language'] = strtoupper(self::languageLabel((string) $latest['primary']));
            }
        }

        $awaiting = (string) ($context['awaiting_question_id'] ?? '');
        $context = self::mergeExtractedFacts($context, $turn, $awaiting);

        $raw = ClinicalTriageEngine::assess($transcript, $transcript);
        $assessment = self::assessmentFromEngine($raw, $transcript, (string) ($raw['english_translation'] ?? $transcript), $checkboxSymptoms);
        $nlpFacts = self::factsFromAssessment($assessment, $transcript);
        $context['facts'] = self::mergeFacts($context['facts'], $nlpFacts);
        $context['chief_complaints'] = ClinicalInterviewContextResolver::deriveComplaints($assessment, $transcript, $context['facts']);
        $context['matched_dataset_entries'] = array_values(array_filter(array_map(
            'strval',
            is_array($assessment['detected_symptoms'] ?? null) ? $assessment['detected_symptoms'] : []
        )));

        $redFlags = self::redFlagNames($assessment);
        $trueEmergency = $redFlags !== [];

        if ($trueEmergency) {
            return self::finalize($assessment, $context, 'EMERGENCY', $transcript);
        }

        $missing = null;
        if (self::needsFollowUpQuestion($assessment, $context, $transcript, $raw)) {
            $missing = self::nextQuestion($context, $transcript);
        }
        $askedCount = count($context['questions_asked']);
        $sufficient = $missing === null || $askedCount >= self::MAX_QUESTIONS;

        if (!$sufficient && $missing !== null) {
            $qid = (string) ($missing['question_id'] ?? '');
            if ($qid !== '' && !in_array($qid, $context['questions_asked'], true)) {
                $context['questions_asked'][] = $qid;
            }
            $context['awaiting_question_id'] = $qid;
            $context['questions_already_asked'] = $context['questions_asked'];
            $context['assessment_status'] = self::STATUS_IN_PROGRESS;

            return self::wrapInProgress($assessment, $context, $missing, $transcript);
        }

        $display = self::finalDisplayFromAssessment($assessment);
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
            'chief_complaints' => is_array($raw['chief_complaints'] ?? null) ? $raw['chief_complaints'] : [],
            'matched_dataset_entries' => self::stringList($raw['matched_dataset_entries'] ?? []),
            'facts' => self::blankFacts($facts),
            'assessment_status' => strtoupper((string) ($raw['assessment_status'] ?? self::STATUS_IN_PROGRESS)),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function appendPatientTurn(array $context, string $turn): array
    {
        if ($context['chief_complaint'] === '') {
            $context['chief_complaint'] = $turn;
        }
        $context['patient_turns'][] = $turn;
        $awaiting = (string) ($context['awaiting_question_id'] ?? '');
        if ($awaiting !== '') {
            $context['questions_answered'][] = [
                'question_id' => $awaiting,
                'answer' => $turn,
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
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function mergeExtractedFacts(array $context, string $turn, string $awaiting): array
    {
        $facts = $context['facts'];
        $low = mb_strtolower($turn);
        $combined = self::transcript($context);

        $onset = ClinicalFeatureExtractors::extractOnset($turn . ' ' . $combined);
        if ($onset !== '' && $facts['onset'] === '') {
            $facts['onset'] = $onset;
        }
        $duration = ClinicalFeatureExtractors::extractDuration($combined);
        if (($duration['label'] ?? '') !== '' && $facts['duration_label'] === '') {
            $facts['duration_label'] = (string) $duration['label'];
        }
        $pain = ClinicalFeatureExtractors::extractPainScale($turn);
        if ($pain['score'] !== null && $facts['pain_score'] === null) {
            $facts['pain_score'] = (int) $pain['score'];
        } elseif ($facts['pain_score'] === null && ($awaiting === 'PAIN_SEVERITY' || $awaiting === '')) {
            $standalone = ClinicalFeatureExtractors::extractStandalonePainScore($turn, $awaiting === 'PAIN_SEVERITY');
            if ($standalone !== null) {
                $facts['pain_score'] = $standalone;
            }
        }
        $qualifier = ClinicalFeatureExtractors::extractPainQualifier($turn);
        if ($qualifier !== '' && $facts['pain_qualifier'] === '') {
            $facts['pain_qualifier'] = $qualifier;
        }
        foreach (ClinicalFeatureExtractors::extractBodyLocations($turn) as $loc) {
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
            ];
            if (isset($map[$awaiting]) && $facts[$map[$awaiting]] === null) {
                $facts[$map[$awaiting]] = $yesNo;
            }
        }

        $facts = self::absorbImplicitRedFlags($facts, $low);
        $context['facts'] = $facts;

        return $context;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function absorbImplicitRedFlags(array $facts, string $low): array
    {
        if (preg_match('/\b(no|wala|hindi|indi|without)\s+(weakness|numbness|pamamanhid|numb)\b/u', $low)) {
            $facts['weakness'] = false;
        } elseif (preg_match('/nangaluya|kaluya|one[- ]sided|wala nga kamot|left arm|weakness in one|pamamanhid|naga\s*numb|\bnumbness\b/u', $low)
            || (preg_match('/\bweakness\b/u', $low) && !preg_match('/\b(no|without)\s+weakness\b/u', $low))
        ) {
            $facts['weakness'] = true;
        }
        if (preg_match('/indi\s+ko\s+makahambal|cannot speak|slurred|hirap magsalita/u', $low)) {
            $facts['speech_difficulty'] = true;
        }
        if (preg_match('/nabulag|vision loss|double vision|nawala panulok/u', $low)) {
            $facts['vision_change'] = true;
        }
        if (preg_match('/makahinga|makaginhawa|cannot breathe|can\'t breathe|difficulty breathing|budlay.{0,12}ginhawa|hirap huminga/u', $low)) {
            $facts['breathing_difficulty'] = true;
        }
        if (preg_match('/\b(malipong|nalipong|dizzy|nahihilo|nahilo|punaw|faint)\b/u', $low)) {
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
    private static function mergeFacts(array $left, array $right): array
    {
        $out = $left;
        foreach ($right as $key => $value) {
            if ($key === 'body_locations' && is_array($value)) {
                $out['body_locations'] = array_values(array_unique(array_merge(
                    self::stringList($out['body_locations'] ?? []),
                    self::stringList($value)
                )));
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
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
        $duration = (string) ($triage['duration'] ?? '');
        $body = self::stringList($triage['detected_body_parts'] ?? []);
        $body = array_merge($body, ClinicalFeatureExtractors::extractBodyLocations($transcript));

        return self::blankFacts([
            'body_locations' => $body,
            'pain_score' => $pain['score'] ?? null,
            'pain_qualifier' => ClinicalFeatureExtractors::extractPainQualifier($transcript),
            'onset' => ClinicalFeatureExtractors::extractOnset($transcript),
            'duration_label' => $duration,
            'denied_associated' => ClinicalFeatureExtractors::deniedAssociatedSymptoms($transcript),
        ]);
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
        $slot = self::nextQuestionSlot($context, $transcript);
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
     * @return array<string, mixed>|null
     */
    private static function nextQuestionSlot(array $context, string $transcript): ?array
    {
        $families = self::familyKeys($context['chief_complaints'], $transcript, $context['facts']);
        if ($families === []) {
            $families = ['pain_unspecified'];
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
     * @return array<string, mixed>|null
     */
    private static function nextQuestion(array $context, string $transcript): ?array
    {
        $slot = self::nextQuestionSlot($context, $transcript);
        if ($slot === null) {
            return null;
        }

        $lang = $context['question_language'] !== '' ? $context['question_language'] : 'hiligaynon';
        $language = strtoupper($lang === 'tagalog' ? 'TAGALOG' : ($lang === 'english' ? 'ENGLISH' : 'HILIGAYNON'));
        $slot['language'] = $language;

        $bankText = '';
        foreach (ClinicalFollowUpQuestionBank::questions() as $question) {
            if (strtoupper((string) ($question['question_id'] ?? '')) === strtoupper((string) ($slot['question_id'] ?? ''))) {
                $bankText = ClinicalFollowUpQuestionBank::textForLanguage($question, $lang);
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

        return [
            'question_id' => (string) ($slot['question_id'] ?? ''),
            'clinical_purpose' => (string) ($slot['clinical_purpose'] ?? ''),
            'red_flag_related' => (bool) ($slot['red_flag_related'] ?? false),
            'priority' => (int) ($slot['priority'] ?? 99),
            'text' => $text,
            'language' => $language,
            'source' => $geminiText !== '' ? 'gemini' : 'question_bank',
        ];
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
        return match ($qid) {
            'PAIN_LOCATION', 'UNWELL_WHAT' => $facts['body_locations'] !== []
                || (bool) preg_match('/\b(ulo|head|dughan|dibdib|chest|tiyan|ilong|nose|kamot|hand)\b/u', $low)
                || (
                    !in_array('pain_unspecified', $families, true)
                    && !in_array('general_unwell', $families, true)
                    && !in_array('pain_no_location', $families, true)
                ),
            'NOSE_PAIN_WHERE' => (bool) preg_match('/\b(bridge|tip|nostril|tuod|pungos)\b/u', $low),
            'PAIN_SEVERITY' => $facts['pain_score'] !== null || ($facts['pain_qualifier'] ?? '') !== '',
            'ONSET' => $facts['onset'] !== '' || $facts['duration_label'] !== '',
            'DURATION' => $facts['duration_label'] !== '' || $facts['onset'] !== '',
            'NEURO_WEAKNESS' => $facts['weakness'] !== null || $facts['denied_associated'],
            'NEURO_SPEECH' => $facts['speech_difficulty'] !== null || $facts['denied_associated'] || $facts['weakness'] !== null,
            'NEURO_VISION' => $facts['vision_change'] !== null || $facts['denied_associated'] || $facts['weakness'] !== null,
            'BREATHING_SEVERITY' => $facts['breathing_difficulty'] !== null || $facts['denied_associated'],
            'BLEEDING_CONTINUING' => $facts['bleeding_continuing'] !== null,
            'BLEEDING_HEAVY' => $facts['bleeding_heavy'] !== null || $facts['denied_associated'],
            'BLEEDING_DIZZY' => $facts['dizziness'] !== null || $facts['denied_associated'],
            'CHEST_RADIATION' => $facts['chest_radiation'] !== null || $facts['denied_associated'] || $facts['breathing_difficulty'] !== null,
            'CHEST_SWEATING' => $facts['sweating'] !== null || $facts['denied_associated'] || $facts['breathing_difficulty'] !== null,
            'ABDOMINAL_ASSOCIATED' => $facts['abdominal_associated'] !== null || $facts['denied_associated'],
            'ASSOCIATED_SYMPTOMS' => $facts['has_other_symptoms'] !== null || $facts['denied_associated']
                || $facts['weakness'] !== null || $facts['breathing_difficulty'] !== null,
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
        $mildPain = $pain === null || (is_int($pain) && $pain <= 4);

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

        return $assessment;
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
        $patientMessage = self::patientMessage($display);

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
        $assessment['triage']['urgency_label'] = match ($display) {
            'EMERGENCY' => 'Emergency (Immediate)',
            'URGENT' => 'Urgent (Priority)',
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
            'matched_dataset_entries' => $context['matched_dataset_entries'],
            'next_question' => $question,
            'final_classification' => $finalDisplay,
            'facts' => $context['facts'],
            'patient_turns' => $context['patient_turns'],
            'chief_complaints' => $context['chief_complaints'],
        ];
    }

    public static function patientMessage(string $display): string
    {
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
}
