<?php
/**
 * Production adaptive interview policy (promoted from Step 3 demo logic).
 *
 * Selects only triage-relevant follow-ups and decides when enough information
 * exists for EMERGENCY / URGENT / NON-URGENT. Does not diagnose and does not
 * replace ClinicalTriageEngine / WHO IITT classification.
 *
 * Safe to call from ClinicalInterviewEngine; callers should catch Throwables
 * and fall back to the legacy bank-order path.
 */
final class ClinicalInterviewAdaptivePolicy
{
    /**
     * Pick the next triage-relevant question slot, or null when none needed.
     *
     * @param array<string, mixed> $context ClinicalInterviewEngine context
     * @param array<string, mixed> $assessment Assessment payload (optional)
     * @return array{question_id:string,clinical_purpose:string,red_flag_related:bool,priority:int,source?:string}|null
     */
    public static function selectNextSlot(array $context, string $transcript, array $assessment = []): ?array
    {
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $asked = array_map('strtoupper', array_map('strval', (array) ($context['questions_asked'] ?? [])));
        $complaints = is_array($context['chief_complaints'] ?? null) ? $context['chief_complaints'] : [];
        if ($complaints === [] && $assessment !== []) {
            $complaints = ClinicalInterviewContextResolver::deriveComplaints($assessment, $transcript, $facts);
        }
        $concepts = ClinicalInterviewContextResolver::deriveFamilies($complaints, $transcript, $facts);
        $concepts = self::enrichConcepts($concepts, $facts, $transcript);
        $concepts = array_values(array_unique(array_filter(array_map(
            static fn ($c): string => strtolower(trim((string) $c)),
            $concepts
        ))));

        $queue = [];
        foreach (ClinicalFollowUpQuestionBank::questions() as $question) {
            if (!is_array($question)) {
                continue;
            }
            $qid = strtoupper(trim((string) ($question['question_id'] ?? '')));
            if ($qid === '' || in_array($qid, $asked, true)) {
                continue;
            }
            $when = array_map('strtolower', (array) ($question['required_when'] ?? []));
            if ($when !== [] && array_intersect($when, $concepts) === []) {
                continue;
            }
            if (!self::shouldConsiderQuestion($question, $concepts, $facts)) {
                continue;
            }
            if (self::alreadyAnswered($qid, $facts, $transcript, $concepts)) {
                continue;
            }
            $impact = self::triageRelevantPriority($qid, $concepts, $facts, $transcript);
            if ($impact === null) {
                continue;
            }
            $queue[] = [
                'question_id' => $qid,
                'clinical_purpose' => (string) ($question['clinical_purpose'] ?? ''),
                'red_flag_related' => (bool) ($question['red_flag_related'] ?? false),
                'priority' => $impact,
                'source' => 'adaptive_policy',
            ];
        }

        if ($queue === []) {
            return null;
        }

        usort($queue, static fn (array $a, array $b): int => ((int) $a['priority']) <=> ((int) $b['priority']));

        return $queue[0];
    }

    /**
     * True when collected facts are enough to classify without more bank questions.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     */
    public static function isTriageSufficient(array $context, string $transcript, array $assessment = []): bool
    {
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $complaints = is_array($context['chief_complaints'] ?? null) ? $context['chief_complaints'] : [];
        if ($complaints === [] && $assessment !== []) {
            $complaints = ClinicalInterviewContextResolver::deriveComplaints($assessment, $transcript, $facts);
        }
        $concepts = ClinicalInterviewContextResolver::deriveFamilies($complaints, $transcript, $facts);
        $concepts = self::enrichConcepts($concepts, $facts, $transcript);

        $sev = $facts['pain_score'] ?? null;
        $sev = $sev !== null ? (int) $sev : null;
        $hasTiming = trim((string) ($facts['onset'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== '';
        $assocDone = ($facts['has_other_symptoms'] ?? null) !== null
            || !empty($facts['denied_associated']);
        $onset = mb_strtolower(trim((string) ($facts['onset'] ?? '')));
        $sudden = (bool) preg_match('/\b(sudden|gulpi|bigla|abrupt)\b/u', $onset . ' ' . mb_strtolower($transcript));
        $painLike = array_intersect($concepts, ['pain', 'headache', 'chest_pain', 'abdominal_pain', 'nose_pain', 'eye', 'eye_pain', 'pain_unspecified']) !== [];

        if (array_intersect($concepts, ['chest_pain', 'breathing', 'cough', 'respiratory']) !== []) {
            if (($facts['breathing_difficulty'] ?? null) === null && empty($facts['denied_associated'])) {
                return false;
            }
        }
        if (array_intersect($concepts, ['eye', 'eye_pain']) !== []) {
            $locs = self::bodyLocations($facts);
            $hasLaterality = (bool) preg_match('/\b(left|right|tuo|wala|both|duha|isa)\b/u', mb_strtolower($transcript))
                || count($locs) >= 1;
            if (!$hasLaterality && !$assocDone) {
                return false;
            }
        }
        if (in_array('bleeding', $concepts, true)) {
            if (($facts['bleeding_continuing'] ?? null) === null && ($facts['bleeding_heavy'] ?? null) === null) {
                return false;
            }
        }
        if ($painLike && $sev === null) {
            return false;
        }
        // Pain-like complaints also need timing before early finalize.
        if ($painLike && !$hasTiming) {
            return false;
        }
        if (!$hasTiming && array_intersect($concepts, ['general_unwell', 'pain_unspecified']) === []) {
            if ($painLike || array_intersect($concepts, ['cough', 'fever', 'urinary', 'skin', 'dizziness']) !== []) {
                return false;
            }
        }
        $needsAssoc = $sudden || ($sev !== null && $sev >= 7)
            || in_array('abdominal_pain', $concepts, true)
            || in_array('chest_pain', $concepts, true)
            || count(array_intersect($concepts, ['headache', 'abdominal_pain', 'chest_pain', 'eye'])) >= 2;
        if ($needsAssoc && !$assocDone
            && ($facts['breathing_difficulty'] ?? null) === null
            && ($facts['abdominal_associated'] ?? null) === null
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function enrichConcepts(array $concepts, array $facts, string $transcript): array
    {
        $low = mb_strtolower($transcript);
        if (($facts['breathing_difficulty'] ?? null) === true
            || preg_match('/\b(budlay|ginhawa|dyspnea|hirap\s+huminga|cannot\s+breathe)\b/u', $low)
        ) {
            $concepts[] = 'breathing';
            $concepts[] = 'respiratory';
        }
        if (preg_match('/\b(ginaubo|nagauubo|cough|ubo)\b/u', $low)) {
            $concepts[] = 'cough';
            $concepts[] = 'respiratory';
        }
        if (preg_match('/\b(fever|lagnat|hilanat)\b/u', $low)) {
            $concepts[] = 'fever';
        }
        $locs = self::bodyLocations($facts);
        if ($locs === [] && preg_match('/\b(sakit|masakit|pain|hapdi|kasakit|gasakit)\b/u', $low)) {
            $concepts[] = 'pain';
            $concepts[] = 'pain_unspecified';
            $concepts[] = 'pain_no_location';
        }
        if (in_array('eye', $locs, true) || preg_match('/\b(mata|eye)\b/u', $low)) {
            $concepts[] = 'eye';
            $concepts[] = 'needs_laterality';
        }
        // Burns / thermal injury: ask site (+ associated) so WHO red vs yellow can be distinguished.
        if ((bool) preg_match('/\b(burn|burns|nasunog|paso|scald|quemadura)\b/u', $low)) {
            $concepts[] = 'skin';
        }
        if (in_array('abdomen', $locs, true) || in_array('chest', $locs, true)) {
            $concepts[] = 'needs_specific_location';
        }

        return $concepts;
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     */
    private static function triageRelevantPriority(
        string $qid,
        array $concepts,
        array $facts,
        string $transcript
    ): ?int {
        $qid = strtoupper($qid);
        $sev = $facts['pain_score'] ?? null;
        $sev = $sev !== null ? (int) $sev : null;
        $onset = mb_strtolower(trim((string) ($facts['onset'] ?? '')));
        $sudden = (bool) preg_match('/\b(sudden|gulpi|bigla|abrupt)\b/u', $onset . ' ' . mb_strtolower($transcript));
        $hasTiming = trim((string) ($facts['onset'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== '';
        $assocDone = ($facts['has_other_symptoms'] ?? null) !== null
            || !empty($facts['denied_associated']);
        $locs = self::bodyLocations($facts);
        $multiSite = count($locs) >= 2
            || count(array_intersect($concepts, ['headache', 'abdominal_pain', 'chest_pain', 'eye', 'nose_pain'])) >= 2;
        $painLike = array_intersect($concepts, ['pain', 'headache', 'chest_pain', 'abdominal_pain', 'nose_pain', 'eye', 'eye_pain', 'pain_unspecified']) !== [];
        $highRisk = array_intersect($concepts, ['chest_pain', 'breathing', 'bleeding', 'neuro']) !== [];
        $acuity = $sudden || ($sev !== null && $sev >= 7) || $highRisk;

        return match ($qid) {
            'BREATHING_SEVERITY' => array_intersect($concepts, ['chest_pain', 'breathing', 'cough', 'respiratory']) !== []
                ? 1
                : null,
            'EYE_LATERALITY' => array_intersect($concepts, ['eye', 'eye_pain', 'needs_laterality']) !== [] ? 2 : null,
            'EYE_VISION' => array_intersect($concepts, ['eye', 'eye_pain']) !== [] ? 4 : null,
            'PAIN_SEVERITY' => $painLike && $sev === null ? 2 : null,
            'PAIN_LOCATION', 'UNWELL_WHAT' => (
                array_intersect($concepts, ['pain_unspecified', 'pain_no_location', 'general_unwell']) !== []
                && $locs === []
            ) ? 3 : null,
            'ONSET', 'DURATION' => !$hasTiming ? 5 : null,
            'SPECIFIC_LOCATION' => in_array('needs_specific_location', $concepts, true)
                && ($sev === null || $sev >= 5 || $sudden || $multiSite)
                ? 6
                : null,
            'ABDOMINAL_ASSOCIATED' => in_array('abdominal_pain', $concepts, true) && !$assocDone ? 7 : null,
            'CHEST_RADIATION', 'CHEST_SWEATING' => in_array('chest_pain', $concepts, true) && !$assocDone
                ? 8
                : null,
            'NEURO_WEAKNESS', 'NEURO_SPEECH', 'NEURO_VISION' => (
                array_intersect($concepts, ['headache', 'neuro']) !== [] && $acuity && !$assocDone
            ) ? 9 : null,
            'FEVER_CONFIRM' => array_intersect($concepts, ['fever', 'cough', 'respiratory']) !== []
                ? 10
                : null,
            'ASSOCIATED_SYMPTOMS' => self::shouldAskAssociatedNow($concepts, $assocDone, $acuity, $sev, $multiSite),
            'COUGH_TYPE' => null,
            'DIZZINESS_TYPE' => in_array('dizziness', $concepts, true) && ($facts['dizziness'] ?? null) === null
                ? 11
                : null,
            'SKIN_SITE' => in_array('skin', $concepts, true) ? 6 : null,
            'URINARY_DETAIL' => in_array('urinary', $concepts, true) ? 6 : null,
            'BLEEDING_CONTINUING', 'BLEEDING_HEAVY', 'BLEEDING_DIZZY' => in_array('bleeding', $concepts, true) ? 2 : null,
            'NOSE_PAIN_WHERE' => in_array('nose_pain', $concepts, true) ? 8 : null,
            default => null,
        };
    }

    /**
     * @param list<string> $concepts
     */
    private static function shouldAskAssociatedNow(
        array $concepts,
        bool $assocDone,
        bool $acuity,
        ?int $sev,
        bool $multiSite
    ): ?int {
        if ($assocDone) {
            return null;
        }
        if (in_array('abdominal_pain', $concepts, true) || in_array('chest_pain', $concepts, true)) {
            return null;
        }
        if (array_intersect($concepts, ['headache', 'pain', 'eye', 'dizziness', 'fever', 'skin', 'urinary', 'cough']) === []) {
            return null;
        }
        if ($acuity || $multiSite || $sev === null || ($sev !== null && $sev >= 5)) {
            return 10;
        }

        return null;
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     */
    private static function shouldConsiderQuestion(array $question, array $concepts, array $facts): bool
    {
        if (empty($question['red_flag_related'])) {
            return true;
        }
        $qid = strtoupper((string) ($question['question_id'] ?? ''));
        if ($qid === 'BREATHING_SEVERITY'
            && array_intersect($concepts, ['breathing', 'cough', 'respiratory', 'chest_pain']) !== []
        ) {
            return true;
        }
        $always = ClinicalInterviewContextResolver::acuityRedFlagFamilies();
        if (array_intersect($concepts, $always) !== []) {
            return true;
        }
        $sev = $facts['pain_score'] ?? null;
        if ($sev !== null && (int) $sev >= 7) {
            return true;
        }
        $onset = mb_strtolower(trim((string) ($facts['onset'] ?? '')));
        if ($onset !== '' && preg_match('/\b(sudden|gulpi|bigla)\b/u', $onset)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $facts
     * @param list<string> $concepts
     */
    private static function alreadyAnswered(string $qid, array $facts, string $transcript, array $concepts): bool
    {
        $qid = strtoupper($qid);
        $low = mb_strtolower($transcript);
        $hasTiming = trim((string) ($facts['onset'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== '';
        $hasSeverity = ($facts['pain_score'] ?? null) !== null
            || trim((string) ($facts['pain_qualifier'] ?? '')) !== '';
        $assocDone = ($facts['has_other_symptoms'] ?? null) !== null
            || !empty($facts['denied_associated']);
        $locs = self::bodyLocations($facts);

        return match ($qid) {
            'PAIN_LOCATION', 'UNWELL_WHAT' => $locs !== []
                || (
                    !in_array('pain_unspecified', $concepts, true)
                    && !in_array('general_unwell', $concepts, true)
                    && !in_array('pain_no_location', $concepts, true)
                ),
            'PAIN_SEVERITY' => $hasSeverity,
            'ONSET', 'DURATION' => $hasTiming,
            'NEURO_WEAKNESS' => ($facts['weakness'] ?? null) !== null || $assocDone,
            'NEURO_SPEECH' => ($facts['speech_difficulty'] ?? null) !== null || $assocDone || ($facts['weakness'] ?? null) !== null,
            'NEURO_VISION', 'EYE_VISION' => ($facts['vision_change'] ?? null) !== null || $assocDone || ($facts['weakness'] ?? null) !== null,
            'BREATHING_SEVERITY' => ($facts['breathing_difficulty'] ?? null) !== null || !empty($facts['denied_associated']),
            'BLEEDING_CONTINUING' => ($facts['bleeding_continuing'] ?? null) !== null,
            'BLEEDING_HEAVY' => ($facts['bleeding_heavy'] ?? null) !== null || !empty($facts['denied_associated']),
            'BLEEDING_DIZZY' => ($facts['dizziness'] ?? null) !== null || !empty($facts['denied_associated']),
            'CHEST_RADIATION' => ($facts['chest_radiation'] ?? null) !== null || !empty($facts['denied_associated']) || ($facts['breathing_difficulty'] ?? null) !== null,
            'CHEST_SWEATING' => ($facts['sweating'] ?? null) !== null || !empty($facts['denied_associated']) || ($facts['breathing_difficulty'] ?? null) !== null,
            'ABDOMINAL_ASSOCIATED' => ($facts['abdominal_associated'] ?? null) !== null || $assocDone,
            'ASSOCIATED_SYMPTOMS' => $assocDone || ($facts['weakness'] ?? null) !== null || ($facts['breathing_difficulty'] ?? null) !== null,
            'EYE_LATERALITY' => (bool) preg_match('/\b(left|right|tuo|wala|both|duha)\b/u', $low) || $locs !== [],
            'SPECIFIC_LOCATION' => (bool) preg_match('/\b(upper|lower|tuo|wala|left|right|pusod|center|tunga)\b/u', $low),
            'NOSE_PAIN_WHERE' => (bool) preg_match('/\b(bridge|tip|nostril|tuod|pungos)\b/u', $low),
            'SKIN_SITE' => $locs !== [],
            'FEVER_CONFIRM' => (bool) preg_match('/\b(fever|lagnat|hilanat|wala\s+lagnat)\b/u', $low),
            'DIZZINESS_TYPE' => ($facts['dizziness'] ?? null) !== null,
            'URINARY_DETAIL' => $assocDone || (bool) preg_match('/\b(burning|hapdi|dugo|blood|fever|hilanat)\b/u', $low),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function bodyLocations(array $facts): array
    {
        $locs = is_array($facts['body_locations'] ?? null) ? $facts['body_locations'] : [];

        return array_values(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $locs
        )));
    }
}
