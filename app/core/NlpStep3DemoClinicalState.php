<?php
/**
 * DEMO ONLY — universal clinical state, multi-fact enrichment, and
 * complaint-specific completeness for nlp_step3_demo.
 *
 * Does not replace ClinicalInterviewEngine / ClinicalTriageEngine.
 * Never invents symptoms, durations, or triage classes.
 */
final class NlpStep3DemoClinicalState
{
    public const STATUS_SUFFICIENT = 'SUFFICIENT';
    public const STATUS_INSUFFICIENT = 'INSUFFICIENT';
    public const STATUS_NOT_DETERMINED = 'NOT_DETERMINED';

    /** @return array<string, mixed> */
    public static function blank(): array
    {
        return [
            'chief_complaint' => '',
            'symptoms' => [],
            'anatomical_location' => [],
            'location_detail' => '',
            'location_is_specific' => false,
            'laterality' => '',
            'severity' => null,
            'onset' => '',
            'duration' => '',
            'temporal_pattern' => '',
            'character' => '',
            'quality' => '',
            'radiation' => '',
            'aggravating_factors' => '',
            'relieving_factors' => '',
            'associated_symptoms' => [],
            'pertinent_negatives' => [],
            'vital_signs' => [],
            'temperature_c' => null,
            'fever' => null,
            'myalgia' => null,
            'vomiting' => null,
            'vomiting_frequency' => '',
            'cough_type' => '',
            'dyspnea' => null,
            'dizziness_type' => '',
            'eye_symptoms' => [],
            'relevant_exposures' => [],
            'relevant_history' => [],
            'medications' => [],
            'allergies' => [],
            'red_flags' => [],
            'unknown_fields' => [],
            'family' => 'general',
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    public static function enrichInterviewFacts(array $facts, string $transcript): array
    {
        $state = self::extractState($facts, $transcript);
        $facts = self::syncFactsFromState($facts, $state);
        $facts['clinical_state'] = $state;

        return $facts;
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    public static function evaluateCompleteness(array $facts, string $transcript, array $assessment = []): array
    {
        $state = is_array($facts['clinical_state'] ?? null)
            ? $facts['clinical_state']
            : self::extractState($facts, $transcript);

        $selection = self::selectAdaptiveMissing($state, $facts, $transcript, $assessment);
        $family = (string) ($selection['primary_concept'] ?? self::detectFamily($state, $transcript, $assessment));
        $state['family'] = $family;

        $redFlagPriority = self::hasImmediateRedFlagPriority($state, $transcript, $assessment);
        if ($redFlagPriority) {
            return [
                'status' => self::STATUS_SUFFICIENT,
                'family' => $family,
                'active_concepts' => $selection['concepts'] ?? [],
                'missing' => [],
                'next_question_id' => '',
                'next_purpose' => '',
                'clinical_summary' => self::toDisplaySummary($facts, $transcript),
                'red_flag_priority' => true,
            ];
        }

        $missing = is_array($selection['missing'] ?? null) ? $selection['missing'] : [];
        $unknown = is_array($facts['unknown_fields'] ?? null) ? $facts['unknown_fields'] : [];
        $missing = array_values(array_filter($missing, static fn (string $m): bool => !in_array($m, $unknown, true)));
        $nextId = (string) ($selection['next_question_id'] ?? '');
        $purpose = (string) ($selection['next_purpose'] ?? '');
        if ($missing === [] || ($nextId !== '' && in_array(self::slotKeyForQuestion($nextId), $unknown, true))) {
            // Recompute next after unknown filtering.
            $nextId = '';
            $purpose = '';
            foreach ((array) ($selection['missing_queue'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $slot = (string) ($row['slot'] ?? '');
                if ($slot !== '' && in_array($slot, $unknown, true)) {
                    continue;
                }
                $nextId = (string) ($row['question_id'] ?? '');
                $purpose = (string) ($row['purpose'] ?? '');
                break;
            }
            $missing = array_values(array_filter(
                $missing,
                static fn (string $m): bool => !in_array($m, $unknown, true)
            ));
        }

        // Minimum sufficient for safe triage — do not exhaust the question bank.
        // Require triageReady to finalize; empty missing alone must NOT skip numeric pain severity.
        $concepts = is_array($selection['concepts'] ?? null) ? $selection['concepts'] : [];
        $triageReady = self::isTriageSufficient(
            $state,
            $facts,
            $transcript,
            $concepts
        );

        if ($triageReady) {
            $status = self::STATUS_SUFFICIENT;
            $missing = [];
            $nextId = '';
            $purpose = '';
        } elseif ($missing === []) {
            // Slots were incorrectly marked answered while triage is still incomplete.
            $status = self::STATUS_INSUFFICIENT;
            $sev = $state['severity'] ?? ($facts['pain_score'] ?? null);
            $painLike = array_intersect(
                $concepts,
                ['pain', 'headache', 'chest_pain', 'abdominal_pain', 'nose_pain', 'eye', 'eye_pain', 'pain_unspecified']
            ) !== [];
            if ($painLike && $sev === null) {
                $nextId = 'PAIN_SEVERITY';
                $purpose = 'Collect numeric pain score as supporting information';
                $missing = ['severity'];
            } elseif (self::isAmbiguousOnly($transcript)) {
                $status = self::STATUS_NOT_DETERMINED;
            }
        } elseif (self::isAmbiguousOnly($transcript)) {
            $status = self::STATUS_NOT_DETERMINED;
        } else {
            $status = self::STATUS_INSUFFICIENT;
        }

        return [
            'status' => $status,
            'family' => $family,
            'active_concepts' => $selection['concepts'] ?? [],
            'missing' => $missing,
            'next_question_id' => $status === self::STATUS_SUFFICIENT ? '' : $nextId,
            'next_purpose' => $status === self::STATUS_SUFFICIENT ? '' : $purpose,
            'clinical_summary' => self::toDisplaySummary($facts, $transcript),
            'red_flag_priority' => false,
            'triage_sufficient' => $triageReady,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    public static function toDisplaySummary(array $facts, string $transcript): array
    {
        $state = is_array($facts['clinical_state'] ?? null)
            ? $facts['clinical_state']
            : self::extractState($facts, $transcript);

        $assoc = is_array($state['associated_symptoms'] ?? null) ? $state['associated_symptoms'] : [];
        $neg = is_array($state['pertinent_negatives'] ?? null) ? $state['pertinent_negatives'] : [];
        $vitals = is_array($state['vital_signs'] ?? null) ? $state['vital_signs'] : [];
        $locs = is_array($state['anatomical_location'] ?? null) ? $state['anatomical_location'] : [];
        if ($locs === [] && is_array($facts['body_locations'] ?? null)) {
            $locs = $facts['body_locations'];
        }

        $severity = $state['severity'] ?? ($facts['pain_score'] ?? null);
        $onset = trim((string) ($state['onset'] ?? ''));
        $duration = trim((string) ($state['duration'] ?? ''));
        if ($duration === '' && trim((string) ($facts['duration_label'] ?? '')) !== '') {
            $duration = (string) $facts['duration_label'];
        }
        if ($onset === '' && trim((string) ($facts['onset'] ?? '')) !== '') {
            $onset = (string) $facts['onset'];
        }

        $locDetail = trim((string) ($state['location_detail'] ?? ''));
        $locLabel = $locs !== [] ? implode(', ', $locs) : '';
        if ($locDetail !== '') {
            $locLabel = $locLabel !== '' ? ($locLabel . ' (' . $locDetail . ')') : $locDetail;
        }

        return [
            'chief_complaint' => (string) ($state['chief_complaint'] ?? ''),
            'complaint' => (string) ($state['chief_complaint'] ?? ''),
            'location' => $locLabel,
            'location_detail' => $locDetail,
            'location_is_specific' => !empty($state['location_is_specific']),
            'laterality' => (string) ($state['laterality'] ?? ''),
            'pain_severity' => $severity !== null ? ((int) $severity) . '/10' : '',
            'severity' => $severity !== null ? ((int) $severity) . '/10' : '',
            'onset' => $onset,
            'duration' => $duration !== '' ? $duration : $onset,
            'character' => (string) (($state['character'] ?? '') !== '' ? $state['character'] : ($facts['pain_qualifier'] ?? '')),
            'aggravating_factor' => (string) ($state['aggravating_factors'] ?? ($facts['progression'] ?? '')),
            'relieving_factor' => (string) ($state['relieving_factors'] ?? ''),
            'associated_symptoms' => $assoc !== [] ? implode(', ', $assoc) : '',
            'pertinent_negatives' => $neg !== [] ? implode(', ', $neg) : '',
            'vital_signs' => $vitals !== [] ? implode(', ', $vitals) : '',
            'temperature' => ($state['temperature_c'] ?? null) !== null
                ? (string) $state['temperature_c'] . '°C'
                : '',
            'family' => (string) ($state['family'] ?? 'general'),
            'unknown_fields' => is_array($state['unknown_fields'] ?? null)
                ? implode(', ', $state['unknown_fields'])
                : '',
        ];
    }

    /**
     * Demo-only final triage overlay: EMERGENCY > URGENT > NON-URGENT with supporting evidence.
     * Does not diagnose. Severity alone never forces URGENT/EMERGENCY.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $assessment
     */
    public static function softTriageDisplay(
        string $display,
        array $facts,
        string $transcript,
        array $assessment = []
    ): string {
        $display = strtoupper(str_replace('_', '-', $display));
        if ($display === 'NON URGENT') {
            $display = 'NON-URGENT';
        }
        if (!in_array($display, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
            $display = 'NON-URGENT';
        }

        $state = is_array($facts['clinical_state'] ?? null)
            ? $facts['clinical_state']
            : self::extractState($facts, $transcript);

        $evidence = self::collectTriageEvidence($state, $facts, $transcript, $assessment);

        // Prefer WHO IITT match from the clinical engine when present.
        $whoLevel = null;
        $who = $assessment['triage']['assessment_factors']['who_iitt']
            ?? $assessment['assessment_factors']['who_iitt']
            ?? $assessment['triage']['who_iitt']
            ?? null;
        if (is_array($who) && isset($who['triage_level'])) {
            $whoLevel = strtoupper(str_replace('_', '-', (string) $who['triage_level']));
            if ($whoLevel === 'NON URGENT') {
                $whoLevel = 'NON-URGENT';
            }
        }
        if (in_array($whoLevel, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
            if ($whoLevel === 'EMERGENCY' || $evidence['emergency']) {
                return 'EMERGENCY';
            }
            if ($whoLevel === 'URGENT' || $evidence['urgent']) {
                return 'URGENT';
            }

            return 'NON-URGENT';
        }

        // Decision order: emergency → urgent → non-urgent.
        if ($evidence['emergency']) {
            return 'EMERGENCY';
        }
        if ($evidence['urgent']) {
            return 'URGENT';
        }

        return 'NON-URGENT';
    }

    /**
     * Universal evidence collector (concept-aware, not a per-complaint flow).
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $assessment
     * @return array{emergency:bool,urgent:bool,notes:list<string>}
     */
    public static function collectTriageEvidence(
        array $state,
        array $facts,
        string $transcript,
        array $assessment = []
    ): array {
        $low = mb_strtolower($transcript);
        $notes = [];
        $concepts = [];
        if (is_array($assessment['demo_completeness']['active_concepts'] ?? null)) {
            $concepts = $assessment['demo_completeness']['active_concepts'];
        } elseif (is_array($assessment['completeness']['active_concepts'] ?? null)) {
            $concepts = $assessment['completeness']['active_concepts'];
        } else {
            $concepts = ClinicalInterviewContextResolver::deriveFamilies(
                ClinicalInterviewContextResolver::deriveComplaints($assessment, $transcript, $facts),
                $transcript,
                $facts
            );
            $concepts = self::enrichConceptsFromState($concepts, $state, $transcript);
        }
        $concepts = array_values(array_unique(array_map(
            static fn ($c): string => strtolower(trim((string) $c)),
            $concepts
        )));

        $severity = $state['severity'] ?? ($facts['pain_score'] ?? null);
        $severity = $severity !== null ? (int) $severity : null;
        $onset = mb_strtolower(trim((string) ($state['onset'] ?? $facts['onset'] ?? '')));
        $duration = mb_strtolower(trim((string) ($state['duration'] ?? $facts['duration_label'] ?? '')));
        $sudden = (bool) preg_match('/\b(sudden|gulpi|bigla|abrupt)\b/u', $onset . ' ' . $low);
        $gradual = (bool) preg_match('/\b(gradual|hinay-hinay|unti-unti|slow)\b/u', $onset . ' ' . $low);
        $worsening = trim((string) ($state['aggravating_factors'] ?? $facts['progression'] ?? '')) !== ''
            || (bool) preg_match('/\b(nagagrabe|naga\s*grabe|worse|worsen|aggravat)\b/u', $low);
        $assoc = is_array($state['associated_symptoms'] ?? null) ? $state['associated_symptoms'] : [];
        $neg = is_array($state['pertinent_negatives'] ?? null) ? $state['pertinent_negatives'] : [];
        $hasAssocPositive = $assoc !== [];
        $hasAssocDenial = $neg !== [] || !empty($facts['denied_associated'])
            || ($facts['has_other_symptoms'] ?? null) === false;

        $engineRed = [];
        foreach ((array) ($assessment['triage']['emergency_red_flags'] ?? []) as $flag) {
            if (is_string($flag) && trim($flag) !== '') {
                $engineRed[] = $flag;
            } elseif (is_array($flag)) {
                $name = trim((string) ($flag['flag_name'] ?? $flag['name'] ?? ''));
                if ($name !== '') {
                    $engineRed[] = $name;
                }
            }
        }
        $stateRed = is_array($state['red_flags'] ?? null) ? $state['red_flags'] : [];

        // --- EMERGENCY evidence (must be clinically supporting, not a word alone) ---
        $emergency = false;
        if ($engineRed !== [] || $stateRed !== []) {
            $emergency = true;
            $notes[] = 'red_flag';
        }
        if (self::hasImmediateRedFlagPriority($state, $transcript, $assessment)) {
            $emergency = true;
            $notes[] = 'immediate_red_flag_priority';
        }
        if (($state['dyspnea'] ?? null) === true || ($facts['breathing_difficulty'] ?? null) === true) {
            if (in_array('chest', self::normalizedLocations($state), true)
                || array_intersect($concepts, ['chest_pain', 'breathing']) !== []
                || preg_match('/\b(dughan|dibdib|chest)\b/u', $low)
            ) {
                $emergency = true;
                $notes[] = 'chest_with_dyspnea';
            }
        }
        if (preg_match(
            '/\b(cannot breathe|can\'t breathe|indi\s+makaginhawa|indi\s+ko\s+kaginhawa|choking|airway|unconscious|nadulaan\s+malay|seizure|convulsion|stroke|one-sided\s+weakness|slurred\s+speech|vomiting\s+blood|nagasuka\s+sang\s+dugo|severe\s+bleeding|nagadugo\s+gid)\b/u',
            $low
        )) {
            $emergency = true;
            $notes[] = 'life_threat_pattern';
        }

        // --- URGENT evidence (combination; severity alone is never enough) ---
        $urgent = false;
        $concerningAssoc = false;
        foreach ($assoc as $a) {
            $al = mb_strtolower((string) $a);
            if (preg_match('/\b(dizziness|fever|vomiting|dyspnea|breath|weakness|vision|bleeding|rash\s+spread)\b/u', $al)) {
                $concerningAssoc = true;
                break;
            }
        }
        if (($facts['weakness'] ?? null) === true
            || ($facts['speech_difficulty'] ?? null) === true
            || ($facts['vision_change'] ?? null) === true
            || ($state['eye_symptoms'] ?? []) !== []
        ) {
            $concerningAssoc = true;
        }
        if (($state['fever'] ?? null) === true || ($state['temperature_c'] ?? null) !== null) {
            $tempC = $state['temperature_c'] ?? null;
            if ($tempC !== null && (float) $tempC >= 39.0) {
                $concerningAssoc = true;
                $notes[] = 'high_fever';
            } elseif (($state['fever'] ?? null) === true
                && array_intersect($concepts, ['headache', 'cough', 'respiratory', 'urinary', 'abdominal_pain']) !== []
            ) {
                $concerningAssoc = true;
            }
        }

        // High pain aligned with WHO YELLOW "Severe pain (no red criteria)".
        if ($severity !== null && $severity >= 8) {
            $urgent = true;
            $notes[] = 'who_yellow_severe_pain';
        } elseif ($severity !== null && $severity >= 7 && $concerningAssoc) {
            $urgent = true;
            $notes[] = 'pain_with_concerning_associated';
        } elseif ($severity !== null && $severity >= 7 && $sudden && !$gradual) {
            $urgent = true;
            $notes[] = 'sudden_high_pain';
        }

        // Concept-relevant urgency without relying on a fixed complaint switch.
        if (($facts['breathing_difficulty'] ?? null) === true || ($state['dyspnea'] ?? null) === true) {
            $urgent = true;
            $notes[] = 'dyspnea';
        }
        if (($facts['bleeding_heavy'] ?? null) === true || ($facts['bleeding_continuing'] ?? null) === true) {
            $urgent = true;
            $notes[] = 'bleeding_active';
        }
        if (array_intersect($concepts, ['neuro']) !== [] && $concerningAssoc) {
            $urgent = true;
            $notes[] = 'neuro_with_assoc';
        }
        if (in_array('abdominal_pain', $concepts, true)
            && (($state['vomiting'] ?? null) === true || $concerningAssoc)
            && (($severity !== null && $severity >= 6) || $sudden)
        ) {
            $urgent = true;
            $notes[] = 'abdominal_with_systemic';
        }

        // Isolated moderate/mild pain, gradual/unknown onset, no concerning assoc → not urgent.
        if ($severity !== null && $severity <= 6 && !$sudden && !$concerningAssoc
            && ($hasAssocDenial || !$hasAssocPositive)
        ) {
            $urgent = false;
            $notes[] = 'isolated_mild_moderate_presentation';
        }

        // Never let emergency false-positive from severity words alone.
        if ($emergency && $engineRed === [] && $stateRed === []
            && !in_array('chest_with_dyspnea', $notes, true)
            && !in_array('life_threat_pattern', $notes, true)
            && !in_array('immediate_red_flag_priority', $notes, true)
        ) {
            $emergency = false;
        }

        return [
            'emergency' => $emergency,
            'urgent' => $urgent && !$emergency,
            'notes' => $notes,
            'concepts' => $concepts,
            'severity' => $severity,
            'sudden' => $sudden,
            'gradual' => $gradual,
            'duration' => $duration,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    public static function extractState(array $facts, string $transcript): array
    {
        $state = self::blank();
        $low = mb_strtolower($transcript);

        $state['anatomical_location'] = is_array($facts['body_locations'] ?? null)
            ? array_values(array_filter(array_map('strval', $facts['body_locations'])))
            : [];
        foreach (ClinicalFeatureExtractors::extractBodyLocations($transcript) as $loc) {
            if (!in_array($loc, $state['anatomical_location'], true)) {
                $state['anatomical_location'][] = $loc;
            }
        }

        $score = $facts['pain_score'] ?? null;
        if (preg_match_all('/\b(\d{1,2})\s*\/\s*10\b/u', $low, $mm) && !empty($mm[1])) {
            $last = (int) $mm[1][count($mm[1]) - 1];
            if ($last >= 0 && $last <= 10) {
                $score = $last;
            }
        } elseif ($score === null) {
            $score = ClinicalFeatureExtractors::extractPainScale($transcript)['score'] ?? null;
            if ($score === null) {
                $score = ClinicalFeatureExtractors::extractStandalonePainScore($transcript, false);
            }
        }
        $state['severity'] = $score !== null ? (int) $score : null;

        $onset = trim((string) ($facts['onset'] ?? ''));
        if ($onset === '') {
            $onset = ClinicalFeatureExtractors::extractOnset($transcript);
        }
        $duration = ClinicalFeatureExtractors::extractDuration($transcript);
        $durLabel = trim((string) ($facts['duration_label'] ?? ''));
        if ($durLabel === '') {
            $durLabel = trim((string) ($duration['label'] ?? ''));
        }
        if ($durLabel === '' && preg_match('/\b(ligad pa|started earlier|some time ago|dugay na|for a long time)\b/u', $low, $m)) {
            $durLabel = $m[1];
        }
        if (preg_match('/\b(halin\s+)?(kagab-i|kagabi|last\s+night)\b/u', $low)) {
            $durLabel = $durLabel !== '' ? $durLabel : 'Since last night';
        }
        // If the patient already gave duration/timing, treat onset as known (do not re-ask ONSET).
        if ($onset === '' && $durLabel !== '') {
            $onset = ClinicalFeatureExtractors::onsetFromDuration(
                $duration['label'] !== '' ? $duration : ['label' => $durLabel]
            );
        }
        $state['onset'] = $onset;
        $state['duration'] = $durLabel;

        if (preg_match('/\b(sa\s+)?(tuo|right)(\s+nga\s+bahin)?\b/u', $low)
            && preg_match('/\b(sa\s+)?(wala|left)(\s+nga\s+bahin)?\b/u', $low)
            && !preg_match('/\b(wala\s+ko|wala\s+sang|wala\s+gid|walang)\b/u', $low)
        ) {
            $state['laterality'] = 'bilateral';
        } elseif (preg_match('/\b(sa\s+)?(tuo|right)(\s+nga\s+bahin)?\b/u', $low)) {
            $state['laterality'] = 'right';
        } elseif (preg_match('/\b(sa\s+)?(left)(\s+nga\s+bahin)?\b/u', $low)
            || preg_match('/\b(sa\s+wala|wala\s+nga\s+bahin|kaliwa)\b/u', $low)
        ) {
            $state['laterality'] = 'left';
        } elseif (preg_match('/\b(duha\s+ka\s+bahin|both\s+sides|bilateral)\b/u', $low)) {
            $state['laterality'] = 'bilateral';
        }

        $state['location_detail'] = self::extractLocationDetail($transcript);
        if ($state['location_detail'] === '' && trim((string) ($facts['location_detail'] ?? '')) !== '') {
            $state['location_detail'] = trim((string) $facts['location_detail']);
        }
        // Family is needed for specificity; provisional detect, then finalize after associated symptoms.
        $provisionalFamily = self::detectFamily($state, $transcript, []);
        $state['location_is_specific'] = self::isLocationSpecific($state, $provisionalFamily, $transcript);

        $char = '';
        if (preg_match('/\b(pulsing|pulsating|pitik-pitik|naga-pitik|throb|throbbing|tumutibok|tumitibok)\b/u', $low)) {
            $char = 'pulsating';
        } elseif (preg_match('/\b(tusok|gina-tusok|stabbing|sharp|kurot)\b/u', $low)) {
            $char = 'stabbing';
        } elseif (preg_match('/\b(sunog|burning|pagsunog)\b/u', $low)) {
            $char = 'burning';
        } elseif (preg_match('/\b(pamilit|pressure|squeezing)\b/u', $low)) {
            $char = 'pressure';
        } elseif (trim((string) ($facts['pain_qualifier'] ?? '')) !== ''
            && !in_array((string) $facts['pain_qualifier'], ['mild', 'moderate', 'severe'], true)
        ) {
            $char = (string) $facts['pain_qualifier'];
        }
        $state['character'] = $char;
        $state['quality'] = $char;

        if (preg_match('/\b(nagagrabe|naga\s*grabe|worse|worsen|aggravat|maglihok|movement|lihok|magbangon|standing|exertion)\b/u', $low)) {
            if (preg_match('/\b(magbangon|standing|get(?:ting)?\s+up)\b/u', $low)) {
                $state['aggravating_factors'] = 'standing / getting up';
            } elseif (preg_match('/\b(maglihok|movement|lihok)\b/u', $low)) {
                $state['aggravating_factors'] = 'movement';
            } else {
                $state['aggravating_factors'] = 'worsening / aggravating factors reported';
            }
        } elseif (trim((string) ($facts['progression'] ?? '')) !== '') {
            $state['aggravating_factors'] = (string) $facts['progression'];
        }

        if (preg_match('/\b(nahupay|reliev|mas maayo|improves?|with rest|pagpahuway)\b/u', $low)) {
            $state['relieving_factors'] = 'relieving factor reported';
        }

        if (preg_match('/\b(padayon|continuous|constant)\b/u', $low)) {
            $state['temporal_pattern'] = 'continuous';
        } elseif (preg_match('/\b(nagaabot-abot|intermittent|comes and goes)\b/u', $low)) {
            $state['temporal_pattern'] = 'intermittent';
        }

        $temp = ClinicalFeatureExtractors::extractTemperature($transcript);
        $deniedFever = self::isDenied($low, ['hilanat', 'lagnat', 'fever']);
        if ($deniedFever) {
            $state['fever'] = false;
            $state['pertinent_negatives'][] = 'no fever';
        } elseif (($temp['celsius'] ?? null) !== null || (($temp['band'] ?? '') !== '' && ($temp['band'] ?? '') !== 'normal')) {
            $state['fever'] = true;
            if (($temp['celsius'] ?? null) !== null) {
                $state['temperature_c'] = (float) $temp['celsius'];
                $state['vital_signs'][] = 'temperature ' . $temp['celsius'] . '°C';
            } else {
                $state['vital_signs'][] = (string) ($temp['label'] ?? 'fever reported');
            }
            $state['associated_symptoms'][] = 'fever';
        }

        if (preg_match('/\b(ginapanakit\s+ang\s+lawas|panakit\s+ang\s+lawas|myalgia|body\s+aches?|sakit\s+lawas)\b/u', $low)) {
            $state['myalgia'] = true;
            $state['associated_symptoms'][] = 'body aches / myalgia';
        }

        $deniedVomit = self::isDenied($low, ['nagsuka', 'ginasuka', 'suka', 'vomit', 'vomiting']);
        if ($deniedVomit) {
            $state['vomiting'] = false;
            $state['pertinent_negatives'][] = 'no vomiting';
        } elseif (preg_match('/\b(nagsuka|ginasuka|nagasuka|vomiting|vomited)\b/u', $low)
            || (preg_match('/\bsuka\b/u', $low) && !self::isDenied($low, ['suka', 'nagsuka', 'vomit', 'vomiting']))
        ) {
            $state['vomiting'] = true;
            $state['associated_symptoms'][] = 'vomiting';
            if (preg_match('/\b(duha|two|2)\s*(ka\s*)?(beses|times)\b/u', $low)) {
                $state['vomiting_frequency'] = 'twice';
            } elseif (preg_match('/\b(\d+|isa|tatlo|three)\s*(ka\s*)?(beses|times)\b/u', $low, $vm)) {
                $state['vomiting_frequency'] = $vm[1] . ' times';
            }
        }

        if (($facts['dizziness'] ?? null) === true
            || (preg_match('/\b(hilo|nahilo|nahihilo|dizzy|dizziness|malipong|nalipong)\b/u', $low)
                && !self::isDenied($low, ['hilo', 'dizzy', 'dizziness', 'malipong', 'nalipong']))
        ) {
            $state['associated_symptoms'][] = 'dizziness';
            if (preg_match('/\b(vertigo|turning|naga\s*tuyok)\b/u', $low)) {
                $state['dizziness_type'] = 'vertigo';
            } elseif (preg_match('/\b(lightheaded|gaan\s+ulo)\b/u', $low)) {
                $state['dizziness_type'] = 'lightheadedness';
            }
        }

        if (($facts['breathing_difficulty'] ?? null) === true
            || preg_match('/\b(budlay\s+magginhawa|budlay\s+ginhawa|shortness of breath|dyspnea|hirap huminga|kulang.*ginhawa)\b/u', $low)
        ) {
            $state['dyspnea'] = true;
            $state['associated_symptoms'][] = 'shortness of breath';
            $state['red_flags'][] = 'dyspnea';
        }

        if (preg_match('/\b(ubo|cough)\b/u', $low)) {
            $state['symptoms'][] = 'cough';
            if (preg_match('/\b(dry cough|uga nga ubo)\b/u', $low)) {
                $state['cough_type'] = 'dry';
            } elseif (preg_match('/\b(productive|may plema|phlegm|sputum)\b/u', $low)) {
                $state['cough_type'] = 'productive';
            }
        }

        if (in_array('eye', $state['anatomical_location'], true) || preg_match('/\b(mata|eye)\b/u', $low)) {
            if (preg_match('/\b(pula|redness|red eye)\b/u', $low)) {
                $state['eye_symptoms'][] = 'redness';
            }
            if (preg_match('/\b(discharge|naga\s*dagayday)\b/u', $low)) {
                $state['eye_symptoms'][] = 'discharge';
            }
            if (preg_match('/\b(vision|panulok|blurry|nabulag)\b/u', $low)) {
                $state['eye_symptoms'][] = 'vision change';
                $state['red_flags'][] = 'vision change';
            }
        }

        if (($facts['weakness'] ?? null) === true) {
            $state['associated_symptoms'][] = 'weakness';
            $state['red_flags'][] = 'weakness';
        }
        if (($facts['denied_associated'] ?? false) === true && $state['pertinent_negatives'] === []) {
            $state['pertinent_negatives'][] = 'no other associated symptoms reported';
        }

        $state['associated_symptoms'] = array_values(array_unique($state['associated_symptoms']));
        $state['pertinent_negatives'] = array_values(array_unique($state['pertinent_negatives']));
        $state['vital_signs'] = array_values(array_unique($state['vital_signs']));
        $state['red_flags'] = array_values(array_unique($state['red_flags']));
        $state['symptoms'] = array_values(array_unique($state['symptoms']));
        $state['unknown_fields'] = is_array($facts['unknown_fields'] ?? null)
            ? array_values(array_map('strval', $facts['unknown_fields']))
            : [];

        $state['family'] = self::detectFamily($state, $transcript, []);
        $state['location_is_specific'] = self::isLocationSpecific($state, (string) $state['family'], $transcript);
        $state['chief_complaint'] = self::chiefComplaintLabel($state, $transcript);
        if ($state['chief_complaint'] !== '') {
            $state['symptoms'] = array_values(array_unique(array_merge(
                [$state['chief_complaint']],
                $state['symptoms']
            )));
        }

        return $state;
    }

    /**
     * Extract a free-text specific site (quadrant, eye side, head region, etc.).
     */
    private static function extractLocationDetail(string $transcript): string
    {
        $low = mb_strtolower($transcript);

        if (preg_match(
            '/\b((?:sa\s+)?(?:tuo|wala|right|left|kaliwa)(?:\s+nga)?\s+(?:idalom|taas|ibabaw|lower|upper)(?:\s+sang)?\s+(?:tiyan|abdomen|stomach|belly))\b/u',
            $low,
            $m
        )) {
            return trim($m[1]);
        }
        if (preg_match(
            '/\b((?:idalom|taas|ibabaw|lower|upper)(?:\s+sang)?\s+(?:tiyan|abdomen|stomach))\b/u',
            $low,
            $m
        )) {
            return trim($m[1]);
        }
        if (preg_match(
            '/\b((?:right|left)\s+(?:lower|upper)\s+(?:quadrant|abdomen)|(?:rlq|llq|ruq|luq)|epigastric|umbilical|hypochondrium|flank)\b/u',
            $low,
            $m
        )) {
            return trim($m[1]);
        }
        if (preg_match('/\b(pusod|pus-od|around\s+the\s+navel|near\s+the\s+navel)\b/u', $low, $m)) {
            return trim($m[1]);
        }
        if (preg_match(
            '/\b((?:sa\s+)?(?:tuo|wala|right|left|kaliwa)(?:\s+nga)?\s+(?:mata|eye)|(?:both|duha)(?:\s+ka)?\s+(?:mata|eyes)|bilateral\s+eyes?)\b/u',
            $low,
            $m
        )) {
            return trim($m[1]);
        }
        if (preg_match(
            '/\b((?:forehead|frontal|temple|temporal|occipital|vertex|buo|likod\s+(?:sang\s+)?ulo|(?:sa\s+)?(?:tuo|wala|right|left)(?:\s+nga)?\s+ulo))\b/u',
            $low,
            $m
        )) {
            return trim($m[1]);
        }
        if (preg_match(
            '/\b((?:sa\s+)?(?:tuo|wala|right|left|kaliwa)(?:\s+nga)?\s+(?:dughan|dibdib|chest)|substernal|center\s+of\s+(?:the\s+)?chest|tunga\s+(?:sang\s+)?dughan)\b/u',
            $low,
            $m
        )) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * True when the site is specific enough that we should not re-ask location
     * for the active body region(s). Driven by dataset location_specificity rules.
     *
     * @param array<string, mixed> $state
     */
    private static function isLocationSpecific(array $state, string $family, string $transcript = ''): bool
    {
        unset($family); // Kept for call-site compatibility; specificity is region-driven.
        return self::hasSpecificLocationForActiveRegions($state, $transcript);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function hasSpecificLocationForActiveRegions(array $state, string $transcript = ''): bool
    {
        $detail = trim((string) ($state['location_detail'] ?? ''));
        if ($detail !== '') {
            return true;
        }

        $laterality = trim((string) ($state['laterality'] ?? ''));
        $low = mb_strtolower($transcript);
        $rules = ClinicalInterviewContextResolver::locationSpecificityRules();
        $regions = is_array($rules['regions'] ?? null) ? $rules['regions'] : [];
        $locs = self::normalizedLocations($state);
        if ($locs === []) {
            return false;
        }

        foreach ($locs as $loc) {
            $rule = is_array($regions[$loc] ?? null) ? $regions[$loc] : [];
            if ($rule === []) {
                // Unknown region with a named site is treated as specific enough.
                return true;
            }
            foreach ((array) ($rule['detail_regex'] ?? []) as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '' && preg_match('/' . $pattern . '/iu', $low)) {
                    return true;
                }
            }
            if ($laterality !== '' && !empty($rule['accepts_laterality_as_specific'])) {
                return true;
            }
            if (!empty($rule['needs_laterality']) && $laterality !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @return list<string>
     */
    private static function normalizedLocations(array $state): array
    {
        $locs = is_array($state['anatomical_location'] ?? null) ? $state['anatomical_location'] : [];
        $out = [];
        foreach ($locs as $loc) {
            $l = mb_strtolower(trim((string) $loc));
            if ($l !== '') {
                $out[] = $l;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function regionNeedsSpecificLocation(array $state, string $transcript = ''): bool
    {
        $rules = ClinicalInterviewContextResolver::locationSpecificityRules();
        $regions = is_array($rules['regions'] ?? null) ? $rules['regions'] : [];
        foreach (self::normalizedLocations($state) as $loc) {
            $rule = is_array($regions[$loc] ?? null) ? $regions[$loc] : [];
            if (!empty($rule['needs_specificity']) && !self::hasSpecificLocationForActiveRegions($state, $transcript)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function regionNeedsLaterality(array $state, string $transcript = ''): bool
    {
        if (trim((string) ($state['laterality'] ?? '')) !== '') {
            return false;
        }
        $rules = ClinicalInterviewContextResolver::locationSpecificityRules();
        $regions = is_array($rules['regions'] ?? null) ? $rules['regions'] : [];
        $low = mb_strtolower($transcript);
        foreach (self::normalizedLocations($state) as $loc) {
            $rule = is_array($regions[$loc] ?? null) ? $regions[$loc] : [];
            if (empty($rule['needs_laterality'])) {
                continue;
            }
            foreach ((array) ($rule['detail_regex'] ?? []) as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '' && preg_match('/' . $pattern . '/iu', $low)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Region mentioned at organ-system level (used for display / wording only).
     *
     * @param array<string, mixed> $state
     */
    private static function hasGeneralRegion(array $state, string $family): bool
    {
        unset($family);
        return self::normalizedLocations($state) !== [];
    }

    /**
     * Universal adaptive selection:
     * detect concepts → keep ONLY triage-impacting unanswered questions → ask highest priority one.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $assessment
     * @return array{
     *   missing: list<string>,
     *   next_question_id: string,
     *   next_purpose: string,
     *   concepts: list<string>,
     *   primary_concept: string,
     *   missing_queue: list<array{slot:string,question_id:string,purpose:string,priority:int}>
     * }
     */
    private static function selectAdaptiveMissing(
        array $state,
        array $facts,
        string $transcript,
        array $assessment
    ): array {
        // Ensure body locations from state are visible to the shared family resolver.
        if (($facts['body_locations'] ?? []) === [] && self::normalizedLocations($state) !== []) {
            $facts['body_locations'] = self::normalizedLocations($state);
        } else {
            foreach (self::normalizedLocations($state) as $loc) {
                $existing = is_array($facts['body_locations'] ?? null) ? $facts['body_locations'] : [];
                if (!in_array($loc, $existing, true)) {
                    $existing[] = $loc;
                }
                $facts['body_locations'] = $existing;
            }
        }

        $complaints = ClinicalInterviewContextResolver::deriveComplaints($assessment, $transcript, $facts);
        $concepts = ClinicalInterviewContextResolver::deriveFamilies($complaints, $transcript, $facts);
        $concepts = self::enrichConceptsFromState($concepts, $state, $transcript);

        if (self::regionNeedsSpecificLocation($state, $transcript)) {
            $concepts[] = 'needs_specific_location';
        }
        if (self::regionNeedsLaterality($state, $transcript)) {
            $concepts[] = 'needs_laterality';
        }
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
            if ($qid === '') {
                continue;
            }
            $when = array_map('strtolower', (array) ($question['required_when'] ?? []));
            if ($when !== [] && array_intersect($when, $concepts) === []) {
                continue;
            }
            if (!self::shouldConsiderQuestion($question, $concepts, $state, $facts)) {
                continue;
            }
            if (self::demoQuestionAlreadyAnswered($qid, $state, $facts, $transcript, $concepts)) {
                continue;
            }
            $impact = self::triageRelevantPriority($qid, $concepts, $state, $facts, $transcript);
            if ($impact === null) {
                // Not clinically necessary for THIS presentation / would not change triage.
                continue;
            }
            $queue[] = [
                'slot' => self::slotKeyForQuestion($qid),
                'question_id' => $qid,
                'purpose' => (string) ($question['clinical_purpose'] ?? ''),
                'priority' => $impact,
            ];
        }

        usort($queue, static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority']));

        // Deduplicate by slot — one question per clinical dimension.
        $seenSlots = [];
        $deduped = [];
        foreach ($queue as $row) {
            $slot = (string) ($row['slot'] ?? '');
            if ($slot !== '' && isset($seenSlots[$slot])) {
                continue;
            }
            if ($slot !== '') {
                $seenSlots[$slot] = true;
            }
            $deduped[] = $row;
        }
        $queue = $deduped;

        $missing = [];
        foreach ($queue as $row) {
            $slot = (string) $row['slot'];
            if ($slot !== '' && !in_array($slot, $missing, true)) {
                $missing[] = $slot;
            }
        }

        $primary = self::primaryConceptLabel($concepts, $complaints, $state, $transcript);

        return [
            'missing' => $missing,
            'next_question_id' => (string) ($queue[0]['question_id'] ?? ''),
            'next_purpose' => (string) ($queue[0]['purpose'] ?? ''),
            'concepts' => $concepts,
            'primary_concept' => $primary,
            'missing_queue' => $queue,
        ];
    }

    /**
     * Lower number = ask sooner. null = do not ask (not triage-relevant for this case).
     *
     * @param list<string> $concepts
     * @param array<string, mixed> $state
     * @param array<string, mixed> $facts
     */
    private static function triageRelevantPriority(
        string $qid,
        array $concepts,
        array $state,
        array $facts,
        string $transcript
    ): ?int {
        $qid = strtoupper($qid);
        $sev = $state['severity'] ?? ($facts['pain_score'] ?? null);
        $sev = $sev !== null ? (int) $sev : null;
        $onset = mb_strtolower(trim((string) ($state['onset'] ?? $facts['onset'] ?? '')));
        $sudden = (bool) preg_match('/\b(sudden|gulpi|bigla|abrupt)\b/u', $onset . ' ' . mb_strtolower($transcript));
        $hasTiming = trim((string) ($state['onset'] ?? '')) !== ''
            || trim((string) ($state['duration'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== ''
            || trim((string) ($facts['onset'] ?? '')) !== '';
        $assocDone = ($state['associated_symptoms'] ?? []) !== []
            || ($state['pertinent_negatives'] ?? []) !== []
            || ($facts['has_other_symptoms'] ?? null) !== null
            || !empty($facts['denied_associated']);
        $multiSite = count(self::normalizedLocations($state)) >= 2
            || (
                count(array_intersect($concepts, ['headache', 'abdominal_pain', 'chest_pain', 'eye', 'nose_pain'])) >= 2
            );
        $painLike = array_intersect($concepts, ['pain', 'headache', 'chest_pain', 'abdominal_pain', 'nose_pain', 'eye', 'eye_pain', 'pain_unspecified']) !== [];
        $highRisk = array_intersect($concepts, ['chest_pain', 'breathing', 'bleeding', 'neuro']) !== [];
        $acuity = $sudden || ($sev !== null && $sev >= 7) || $highRisk;

        return match ($qid) {
            'BREATHING_SEVERITY' => array_intersect($concepts, ['chest_pain', 'breathing', 'cough', 'respiratory']) !== []
                ? 1
                : null,
            'EYE_LATERALITY' => array_intersect($concepts, ['eye', 'eye_pain', 'needs_laterality']) !== [] ? 2 : null,
            'EYE_VISION' => array_intersect($concepts, ['eye', 'eye_pain']) !== [] ? 4 : null,
            // Vague pain: severity before location (demo contract + acceptance T1).
            'PAIN_SEVERITY' => $painLike && $sev === null ? 2 : null,
            'PAIN_LOCATION', 'UNWELL_WHAT' => (
                array_intersect($concepts, ['pain_unspecified', 'pain_no_location', 'general_unwell']) !== []
                && self::normalizedLocations($state) === []
            ) ? 3 : null,
            'ONSET', 'DURATION' => !$hasTiming ? 5 : null,
            'SPECIFIC_LOCATION' => self::shouldAskSpecificLocationNow($concepts, $state, $sev, $sudden, $multiSite),
            'ABDOMINAL_ASSOCIATED' => in_array('abdominal_pain', $concepts, true) && !$assocDone ? 7 : null,
            'CHEST_RADIATION', 'CHEST_SWEATING' => in_array('chest_pain', $concepts, true) && !$assocDone
                ? 8
                : null,
            'NEURO_WEAKNESS', 'NEURO_SPEECH', 'NEURO_VISION' => (
                array_intersect($concepts, ['headache', 'neuro']) !== [] && $acuity && !$assocDone
            ) ? 9 : null,
            'FEVER_CONFIRM' => array_intersect($concepts, ['fever', 'cough', 'respiratory']) !== []
                && ($state['fever'] ?? null) === null
                && ($state['temperature_c'] ?? null) === null
                ? 10
                : null,
            'ASSOCIATED_SYMPTOMS' => self::shouldAskAssociatedNow($concepts, $assocDone, $acuity, $sev, $multiSite),
            'COUGH_TYPE' => null, // character rarely changes EMERGENCY/URGENT/NON-URGENT once breathing/fever known
            'DIZZINESS_TYPE' => in_array('dizziness', $concepts, true)
                && trim((string) ($state['dizziness_type'] ?? '')) === ''
                && ($facts['dizziness'] ?? null) === null
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
     * @param array<string, mixed> $state
     */
    private static function shouldAskSpecificLocationNow(
        array $concepts,
        array $state,
        ?int $sev,
        bool $sudden,
        bool $multiSite
    ): ?int {
        if (!in_array('needs_specific_location', $concepts, true)) {
            return null;
        }
        // Chest: site can matter, but after breathing/severity.
        if (in_array('chest_pain', $concepts, true)) {
            return $sev === null ? 12 : 6;
        }
        // Abdomen: severity first for multi-complaint; site mainly when acuity high.
        if (in_array('abdominal_pain', $concepts, true)) {
            if ($sev === null) {
                return 12;
            }
            if ($sev >= 7 || $sudden) {
                return 6;
            }
            // Mild/moderate known severity — site less likely to change triage.
            return $multiSite ? null : 12;
        }

        return 12;
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
        // Abdominal / chest have more specific associated screens.
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
     * Enough information to run EMERGENCY / URGENT / NON-URGENT safely without more bank questions.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $facts
     * @param list<string> $concepts
     */
    private static function isTriageSufficient(
        array $state,
        array $facts,
        string $transcript,
        array $concepts
    ): bool {
        $sev = $state['severity'] ?? ($facts['pain_score'] ?? null);
        $sev = $sev !== null ? (int) $sev : null;
        $hasTiming = trim((string) ($state['onset'] ?? '')) !== ''
            || trim((string) ($state['duration'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== ''
            || trim((string) ($facts['onset'] ?? '')) !== '';
        $assocDone = ($state['associated_symptoms'] ?? []) !== []
            || ($state['pertinent_negatives'] ?? []) !== []
            || ($facts['has_other_symptoms'] ?? null) !== null
            || !empty($facts['denied_associated']);
        $onset = mb_strtolower(trim((string) ($state['onset'] ?? $facts['onset'] ?? '')));
        $sudden = (bool) preg_match('/\b(sudden|gulpi|bigla|abrupt)\b/u', $onset . ' ' . mb_strtolower($transcript));
        $painLike = array_intersect($concepts, ['pain', 'headache', 'chest_pain', 'abdominal_pain', 'nose_pain', 'eye', 'eye_pain', 'pain_unspecified']) !== [];

        if (array_intersect($concepts, ['chest_pain', 'breathing', 'cough', 'respiratory']) !== []) {
            if (($state['dyspnea'] ?? null) === null && ($facts['breathing_difficulty'] ?? null) === null
                && empty($facts['denied_associated'])
            ) {
                return false;
            }
        }
        if (array_intersect($concepts, ['eye', 'eye_pain']) !== []) {
            if (trim((string) ($state['laterality'] ?? '')) === ''
                && ($state['eye_symptoms'] ?? []) === []
                && !$assocDone
            ) {
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
            && ($state['vomiting'] ?? null) === null
            && ($state['dyspnea'] ?? null) === null
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $state
     * @return list<string>
     */
    private static function enrichConceptsFromState(array $concepts, array $state, string $transcript): array
    {
        $low = mb_strtolower($transcript);
        if (($state['fever'] ?? null) === true || ($state['temperature_c'] ?? null) !== null) {
            $concepts[] = 'fever';
        }
        if (($state['dyspnea'] ?? null) === true) {
            $concepts[] = 'breathing';
            $concepts[] = 'respiratory';
        }
        if (trim((string) ($state['cough_type'] ?? '')) !== ''
            || preg_match('/\b(ginaubo|ginauubo|nagaubo|nagauubo|cough|ubo)\b/u', $low)
        ) {
            $concepts[] = 'cough';
            $concepts[] = 'respiratory';
        }

        $concepts = array_values(array_unique(array_filter(array_map(
            static fn ($c): string => strtolower(trim((string) $c)),
            $concepts
        ))));

        $meta = ['pain', 'pain_unspecified', 'pain_no_location', 'respiratory', 'needs_specific_location', 'needs_laterality'];
        $specific = array_values(array_filter(
            $concepts,
            static fn (string $c): bool => !in_array($c, $meta, true)
        ));
        $painLike = ['headache', 'chest_pain', 'abdominal_pain', 'nose_pain', 'eye', 'eye_pain', 'pain'];
        $hasPainSignal = ($state['severity'] ?? null) !== null
            || preg_match('/\b(sakit|masakit|pain|hurts?|hapdi|kasakit)\b/u', $low);

        // Only attach generic pain tags when the complaint is pain-centered (or unknown).
        // Do not force PAIN_LOCATION onto cough/skin/urinary/fever pathways.
        if ($hasPainSignal) {
            if ($specific === []) {
                $locs = self::normalizedLocations($state);
                $concepts[] = $locs === [] ? 'pain_unspecified' : 'pain';
                if ($locs === []) {
                    $concepts[] = 'pain_no_location';
                }
            } elseif (array_intersect($specific, $painLike) !== []) {
                $concepts[] = 'pain';
            }
        }

        return array_values(array_unique($concepts));
    }

    /**
     * Universal acuity gate for red-flag bank items (not a per-complaint flow).
     *
     * @param array<string, mixed> $question
     * @param list<string> $concepts
     * @param array<string, mixed> $state
     * @param array<string, mixed> $facts
     */
    private static function shouldConsiderQuestion(
        array $question,
        array $concepts,
        array $state,
        array $facts
    ): bool {
        if (empty($question['red_flag_related'])) {
            return true;
        }
        $qid = strtoupper((string) ($question['question_id'] ?? ''));
        // Breathing check stays relevant for any active respiratory concept tag.
        if ($qid === 'BREATHING_SEVERITY'
            && array_intersect($concepts, ['breathing', 'cough', 'respiratory', 'chest_pain']) !== []
        ) {
            return true;
        }
        $always = ClinicalInterviewContextResolver::acuityRedFlagFamilies();
        if (array_intersect($concepts, $always) !== []) {
            return true;
        }
        $sev = $state['severity'] ?? ($facts['pain_score'] ?? null);
        if ($sev !== null && (int) $sev >= 7) {
            return true;
        }
        $onset = mb_strtolower(trim((string) ($state['onset'] ?? $facts['onset'] ?? '')));
        if ($onset !== '' && preg_match('/\b(sudden|gulpi|bigla)\b/u', $onset)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $facts
     * @param list<string> $concepts
     */
    private static function demoQuestionAlreadyAnswered(
        string $qid,
        array $state,
        array $facts,
        string $transcript,
        array $concepts
    ): bool {
        $qid = strtoupper($qid);
        $low = mb_strtolower($transcript);
        $hasTiming = trim((string) ($state['onset'] ?? '')) !== ''
            || trim((string) ($state['duration'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== ''
            || trim((string) ($facts['onset'] ?? '')) !== '';
        $hasSeverity = ($state['severity'] ?? null) !== null || ($facts['pain_score'] ?? null) !== null;
        $assocDone = ($state['associated_symptoms'] ?? []) !== []
            || ($state['pertinent_negatives'] ?? []) !== []
            || ($facts['has_other_symptoms'] ?? null) !== null
            || !empty($facts['denied_associated']);
        $hasAnyLocation = self::normalizedLocations($state) !== []
            || (is_array($facts['body_locations'] ?? null) && $facts['body_locations'] !== []);

        return match ($qid) {
            'SPECIFIC_LOCATION' => self::hasSpecificLocationForActiveRegions($state, $transcript),
            'EYE_LATERALITY' => trim((string) ($state['laterality'] ?? '')) !== ''
                || self::hasSpecificLocationForActiveRegions($state, $transcript),
            'PAIN_LOCATION', 'UNWELL_WHAT' => $hasAnyLocation
                || (
                    !in_array('pain_unspecified', $concepts, true)
                    && !in_array('general_unwell', $concepts, true)
                    && !in_array('pain_no_location', $concepts, true)
                ),
            'NOSE_PAIN_WHERE' => (bool) preg_match('/\b(bridge|tip|nostril|tuod|pungos)\b/u', $low),
            'SKIN_SITE' => $hasAnyLocation || trim((string) ($state['location_detail'] ?? '')) !== '',
            // Numeric 0–10 only — qualitative intensifiers (gid/grabe/severe) must NOT skip the scale.
            'PAIN_SEVERITY' => $hasSeverity,
            'ONSET', 'DURATION' => $hasTiming,
            'COUGH_TYPE' => trim((string) ($state['cough_type'] ?? '')) !== '',
            'FEVER_CONFIRM' => ($state['fever'] ?? null) !== null || ($state['temperature_c'] ?? null) !== null,
            'DIZZINESS_TYPE' => trim((string) ($state['dizziness_type'] ?? '')) !== ''
                || ($facts['dizziness'] ?? null) !== null
                || in_array('dizziness', (array) ($state['associated_symptoms'] ?? []), true),
            'URINARY_DETAIL' => $assocDone || (bool) preg_match('/\b(burning|hapdi|dugo|blood|fever|hilanat|lagnat)\b/u', $low),
            'NEURO_WEAKNESS' => ($facts['weakness'] ?? null) !== null || $assocDone,
            'NEURO_SPEECH' => ($facts['speech_difficulty'] ?? null) !== null || $assocDone || ($facts['weakness'] ?? null) !== null,
            'NEURO_VISION', 'EYE_VISION' => ($facts['vision_change'] ?? null) !== null
                || ($state['eye_symptoms'] ?? []) !== []
                || $assocDone
                || ($facts['weakness'] ?? null) !== null,
            'BREATHING_SEVERITY' => ($state['dyspnea'] ?? null) !== null
                || ($facts['breathing_difficulty'] ?? null) !== null
                || !empty($facts['denied_associated']),
            'BLEEDING_CONTINUING' => ($facts['bleeding_continuing'] ?? null) !== null,
            'BLEEDING_HEAVY' => ($facts['bleeding_heavy'] ?? null) !== null || !empty($facts['denied_associated']),
            'BLEEDING_DIZZY' => ($facts['dizziness'] ?? null) !== null || !empty($facts['denied_associated']),
            'CHEST_RADIATION' => ($facts['chest_radiation'] ?? null) !== null
                || !empty($facts['denied_associated'])
                || ($facts['breathing_difficulty'] ?? null) !== null,
            'CHEST_SWEATING' => ($facts['sweating'] ?? null) !== null
                || !empty($facts['denied_associated'])
                || ($facts['breathing_difficulty'] ?? null) !== null,
            'ABDOMINAL_ASSOCIATED' => ($facts['abdominal_associated'] ?? null) !== null
                || ($state['vomiting'] ?? null) !== null
                || $assocDone,
            'ASSOCIATED_SYMPTOMS' => $assocDone
                || ($facts['weakness'] ?? null) !== null
                || ($facts['breathing_difficulty'] ?? null) !== null,
            default => false,
        };
    }

    public static function slotKeyForQuestion(string $qid): string
    {
        return match (strtoupper($qid)) {
            'PAIN_SEVERITY' => 'severity',
            'PAIN_LOCATION', 'UNWELL_WHAT', 'NOSE_PAIN_WHERE', 'SKIN_SITE' => 'location',
            'SPECIFIC_LOCATION' => 'specific_location',
            'EYE_LATERALITY' => 'laterality',
            'ONSET', 'DURATION' => 'onset',
            'EYE_VISION', 'NEURO_VISION' => 'eye_red_flags',
            'ASSOCIATED_SYMPTOMS', 'ABDOMINAL_ASSOCIATED' => 'associated_symptoms',
            'FEVER_CONFIRM' => 'fever',
            'COUGH_TYPE' => 'cough_type',
            'BREATHING_SEVERITY' => 'dyspnea',
            'DIZZINESS_TYPE' => 'dizziness_type',
            'URINARY_DETAIL' => 'urinary_detail',
            'NEURO_WEAKNESS', 'NEURO_SPEECH' => 'neuro_associated',
            'CHEST_RADIATION', 'CHEST_SWEATING' => 'chest_associated',
            'BLEEDING_CONTINUING', 'BLEEDING_HEAVY', 'BLEEDING_DIZZY' => 'bleeding_detail',
            default => strtolower($qid),
        };
    }

    /**
     * @param list<string> $concepts
     * @param list<array{id?:string,name?:string,family_key?:string}> $complaints
     * @param array<string, mixed> $state
     */
    private static function primaryConceptLabel(
        array $concepts,
        array $complaints,
        array $state,
        string $transcript
    ): string {
        foreach ($complaints as $row) {
            $key = strtolower((string) ($row['family_key'] ?? ''));
            if ($key !== '' && !in_array($key, ['pain', 'pain_unspecified', 'pain_no_location', 'respiratory'], true)) {
                return $key;
            }
        }
        foreach ($concepts as $c) {
            if (!in_array($c, ['pain', 'pain_unspecified', 'pain_no_location', 'needs_specific_location', 'needs_laterality', 'respiratory'], true)) {
                return $c;
            }
        }
        if (in_array('pain', $concepts, true) || in_array('pain_unspecified', $concepts, true)) {
            return 'pain';
        }

        return 'general';
    }

    /** @deprecated Use selectAdaptiveMissing via evaluateCompleteness. */
    private static function missingForFamily(string $family, array $state, array $facts, string $transcript = ''): array
    {
        unset($family);
        $selection = self::selectAdaptiveMissing($state, $facts, $transcript, []);

        return [
            $selection['missing'],
            $selection['next_question_id'],
            $selection['next_purpose'],
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function syncFactsFromState(array $facts, array $state): array
    {
        if (($facts['pain_score'] ?? null) === null && ($state['severity'] ?? null) !== null) {
            $facts['pain_score'] = (int) $state['severity'];
        } elseif (($state['severity'] ?? null) !== null) {
            // Allow later turns to update severity when the patient restates a score.
            $facts['pain_score'] = (int) $state['severity'];
        }
        $locs = is_array($facts['body_locations'] ?? null) ? $facts['body_locations'] : [];
        foreach ((array) ($state['anatomical_location'] ?? []) as $loc) {
            if (is_string($loc) && $loc !== '' && !in_array($loc, $locs, true)) {
                $locs[] = $loc;
            }
        }
        $facts['body_locations'] = $locs;

        if (trim((string) ($state['location_detail'] ?? '')) !== '') {
            $facts['location_detail'] = (string) $state['location_detail'];
        }
        $facts['location_is_specific'] = !empty($state['location_is_specific']);
        if (trim((string) ($state['laterality'] ?? '')) !== '') {
            $facts['laterality'] = (string) $state['laterality'];
        }

        if (trim((string) ($facts['onset'] ?? '')) === '' && trim((string) ($state['onset'] ?? '')) !== '') {
            $facts['onset'] = (string) $state['onset'];
        }
        if (trim((string) ($facts['duration_label'] ?? '')) === '' && trim((string) ($state['duration'] ?? '')) !== '') {
            $facts['duration_label'] = (string) $state['duration'];
        }
        // Duration already stated ⇒ timing is complete; keep onset fact filled for question-skipping.
        if (trim((string) ($facts['duration_label'] ?? '')) !== '' && trim((string) ($facts['onset'] ?? '')) === '') {
            $facts['onset'] = ClinicalFeatureExtractors::onsetFromDuration([
                'label' => (string) $facts['duration_label'],
            ]);
        }
        if (trim((string) ($facts['pain_qualifier'] ?? '')) === '' && trim((string) ($state['character'] ?? '')) !== '') {
            $facts['pain_qualifier'] = (string) $state['character'];
        }
        if (trim((string) ($facts['progression'] ?? '')) === '' && trim((string) ($state['aggravating_factors'] ?? '')) !== '') {
            $facts['progression'] = (string) $state['aggravating_factors'];
        }
        if (($facts['dizziness'] ?? null) === null && in_array('dizziness', $state['associated_symptoms'] ?? [], true)) {
            $facts['dizziness'] = true;
        }
        if (($facts['breathing_difficulty'] ?? null) === null && ($state['dyspnea'] ?? null) === true) {
            $facts['breathing_difficulty'] = true;
        }
        if (($facts['abdominal_associated'] ?? null) === null && ($state['vomiting'] ?? null) === true) {
            $facts['abdominal_associated'] = true;
        }
        if (($facts['has_other_symptoms'] ?? null) === null) {
            if (($state['associated_symptoms'] ?? []) !== []) {
                $facts['has_other_symptoms'] = true;
            } elseif (($state['pertinent_negatives'] ?? []) !== []) {
                $facts['has_other_symptoms'] = false;
            }
        }
        if (($state['vomiting'] ?? null) === false && ($state['fever'] ?? null) === false
            && ($state['associated_symptoms'] ?? []) === []
        ) {
            $facts['denied_associated'] = true;
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $assessment
     */
    public static function detectFamily(array $state, string $transcript, array $assessment): string
    {
        $facts = [
            'body_locations' => self::normalizedLocations($state),
        ];
        $complaints = ClinicalInterviewContextResolver::deriveComplaints($assessment, $transcript, $facts);
        $concepts = ClinicalInterviewContextResolver::deriveFamilies($complaints, $transcript, $facts);
        $concepts = self::enrichConceptsFromState($concepts, $state, $transcript);
        $primary = self::primaryConceptLabel($concepts, $complaints, $state, $transcript);
        if ($primary !== '' && $primary !== 'general') {
            // Normalize legacy display aliases.
            return match ($primary) {
                'eye' => 'eye_pain',
                'breathing' => 'dyspnea',
                default => $primary,
            };
        }

        $low = mb_strtolower($transcript);
        $locs = is_array($state['anatomical_location'] ?? null) ? $state['anatomical_location'] : [];

        if (($state['dyspnea'] ?? null) === true && (in_array('chest', $locs, true) || preg_match('/\b(dughan|dibdib|chest)\b/u', $low))) {
            return 'chest_pain';
        }
        if (preg_match('/\b(ginaubo|ginauubo|nagaubo|nagauubo|ga\s*ubo|cough(?:ing)?|ubo)\b/u', $low)
            || preg_match('/\b(cought|couph|ubo\s*ko)\b/u', $low)
        ) {
            return 'cough';
        }
        if (in_array('chest', $locs, true) || preg_match('/\b(dughan|dibdib|chest pain)\b/u', $low)) {
            return 'chest_pain';
        }
        if (in_array('eye', $locs, true) || preg_match('/\b(mata|eye pain|sakit.*mata|masakit.*mata)\b/u', $low)) {
            return 'eye_pain';
        }
        if (in_array('abdomen', $locs, true) || preg_match('/\b(tiyan|abdomen|stomach|belly)\b/u', $low)) {
            return 'abdominal_pain';
        }
        if (in_array('head', $locs, true) || preg_match('/\b(ulo|headache|headech|sakit.*ulo|kasakit\s+ulo)\b/u', $low)) {
            return 'headache';
        }
        if (($state['fever'] ?? null) === true || preg_match('/\b(hilanat|lagnat|fever)\b/u', $low)) {
            return 'fever';
        }
        if (($state['dyspnea'] ?? null) === true || preg_match('/\b(budlay.*ginhawa|shortness of breath|dyspnea)\b/u', $low)) {
            return 'dyspnea';
        }
        if (preg_match('/\b(hilo|dizzy|dizziness|malipong)\b/u', $low) && !preg_match('/\b(sakit|masakit|pain)\b/u', $low)) {
            return 'dizziness';
        }
        if (preg_match('/\b(sakit|masakit|pain|hapdi)\b/u', $low) || ($state['severity'] ?? null) !== null) {
            return 'pain';
        }

        return 'general';
    }

    /** @param array<string, mixed> $state */
    private static function chiefComplaintLabel(array $state, string $transcript): string
    {
        $family = (string) ($state['family'] ?? self::detectFamily($state, $transcript, []));

        return match ($family) {
            'headache' => 'headache / pain',
            'abdominal_pain' => 'abdominal pain',
            'chest_pain' => 'chest pain',
            'eye_pain' => 'eye pain',
            'fever' => 'fever',
            'cough' => 'cough',
            'dyspnea' => 'shortness of breath',
            'dizziness' => 'dizziness',
            'pain' => 'pain',
            default => (preg_match('/\b(sakit|masakit|pain)\b/ui', $transcript) ? 'pain' : ''),
        };
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $assessment
     */
    private static function hasImmediateRedFlagPriority(array $state, string $transcript, array $assessment): bool
    {
        $display = strtoupper(str_replace('_', '-', (string) (
            $assessment['triage']['provisional_engine_classification']
            ?? $assessment['triage']['triage_display']
            ?? ''
        )));
        if (str_contains($display, 'EMERGENCY')) {
            return true;
        }
        $low = mb_strtolower($transcript);
        if (preg_match('/\b(dughan|dibdib|chest)\b/u', $low)
            && preg_match('/\b(budlay|ginhawa|breath|dyspnea)\b/u', $low)
        ) {
            return true;
        }
        if (($state['dyspnea'] ?? null) === true && in_array('chest', $state['anatomical_location'] ?? [], true)) {
            return true;
        }

        return ($state['red_flags'] ?? []) !== [] && in_array('dyspnea', $state['red_flags'], true)
            && in_array('chest', $state['anatomical_location'] ?? [], true);
    }

    private static function isAmbiguousOnly(string $transcript): bool
    {
        $low = mb_strtolower(trim($transcript));

        return (bool) preg_match('/\b(daw|maybe|indi\s+sure|not\s+sure|wala\s+ko\s+kabalo)\b/u', $low)
            && mb_strlen($low) < 40;
    }

    /** @param list<string> $terms */
    private static function isDenied(string $low, array $terms): bool
    {
        foreach ($terms as $term) {
            $t = preg_quote($term, '/');
            // Hiligaynon/English denial appears BEFORE the symptom (wala ko nagsuka / no fever).
            // Do NOT treat "hilo ... wala ko nagsuka" as denying dizziness.
            if (preg_match('/\b(wala|indi|hindi|no|without|walang)\b(?:\s+\w+){0,4}\s+\b' . $t . '\b/u', $low)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ctx Optional: family, anatomical_location (list|string)
     * @return array{text:string,helper:string,purpose:string,priority:int}
     */
    public static function questionPack(string $qid, string $lang = 'HILIGAYNON', array $ctx = []): array
    {
        $qid = strtoupper($qid);
        $lang = strtoupper($lang);
        $family = strtolower((string) ($ctx['family'] ?? ''));
        $region = '';
        $locs = $ctx['anatomical_location'] ?? ($ctx['body_locations'] ?? []);
        if (is_string($locs)) {
            $region = mb_strtolower(trim($locs));
        } elseif (is_array($locs) && $locs !== []) {
            $region = mb_strtolower(trim((string) $locs[0]));
        }
        if ($region === '' && str_contains($family, 'abdom')) {
            $region = 'abdomen';
        } elseif ($region === '' && str_contains($family, 'chest')) {
            $region = 'chest';
        } elseif ($region === '' && str_contains($family, 'eye')) {
            $region = 'eye';
        }

        // Prefer shared question-bank phrasing when available; region-aware overrides for specificity.
        $bank = ClinicalFollowUpQuestionBank::byId($qid);
        $bankLang = match ($lang) {
            'ENGLISH' => 'english',
            'TAGALOG' => 'tagalog',
            default => 'hiligaynon',
        };

        $specificLocation = match ($region) {
            'abdomen', 'stomach', 'belly' => match ($lang) {
                'ENGLISH' => [
                    'text' => 'Where exactly in your abdomen is the problem? (for example: right or left, upper or lower, around the navel)',
                    'helper' => '',
                    'purpose' => 'Exact site within a general body region',
                    'priority' => 8,
                ],
                'TAGALOG' => [
                    'text' => 'Saan exactamente sa tiyan ang problema? (kanan/kaliwa, taas/ibaba, malapit sa pusod)',
                    'helper' => '',
                    'purpose' => 'Exact site within a general body region',
                    'priority' => 8,
                ],
                default => [
                    'text' => 'Diin gid nga parte sang imo tiyan ang masakit? (tuo/wala, idalom/taas, malapit sa pusod)',
                    'helper' => '',
                    'purpose' => 'Exact site within a general body region',
                    'priority' => 8,
                ],
            },
            'chest' => match ($lang) {
                'ENGLISH' => [
                    'text' => 'Where exactly in your chest is the problem? (left, right, or center)',
                    'helper' => '',
                    'purpose' => 'Exact site within a general body region',
                    'priority' => 8,
                ],
                default => [
                    'text' => 'Diin gid nga parte sang imo dughan ang masakit? (tuo, wala, ukon tunga)',
                    'helper' => '',
                    'purpose' => 'Exact site within a general body region',
                    'priority' => 8,
                ],
            },
            'eye' => match ($lang) {
                'ENGLISH' => [
                    'text' => 'Which eye is affected — left, right, or both?',
                    'helper' => '',
                    'purpose' => 'Laterality for paired body parts',
                    'priority' => 8,
                ],
                default => [
                    'text' => 'Diin nga mata ang masakit — tuo, wala, ukon duha?',
                    'helper' => '',
                    'purpose' => 'Laterality for paired body parts',
                    'priority' => 8,
                ],
            },
            default => [
                'text' => $bank
                    ? (ClinicalFollowUpQuestionBank::textForLanguage($bank, $bankLang) ?: ($lang === 'ENGLISH'
                        ? 'Where exactly is the problem located in that area of your body?'
                        : 'Diin gid nga parte sang sina nga bahin sang imo lawas ang problema?'))
                    : ($lang === 'ENGLISH'
                        ? 'Where exactly is the problem located in that area of your body?'
                        : ($lang === 'TAGALOG'
                            ? 'Saan exactamente sa bahaging iyon ng katawan ang problema?'
                            : 'Diin gid nga parte sang sina nga bahin sang imo lawas ang problema?')),
                'helper' => '',
                'purpose' => (string) ($bank['clinical_purpose'] ?? 'Exact site within a general body region'),
                'priority' => (int) ($bank['priority'] ?? 8),
            ],
        };

        if (in_array($qid, ['SPECIFIC_LOCATION', 'PAIN_LOCATION'], true) && $region !== '') {
            if ($qid === 'SPECIFIC_LOCATION' || in_array($region, ['abdomen', 'stomach', 'belly', 'chest'], true)) {
                if ($qid === 'PAIN_LOCATION' && in_array($region, ['abdomen', 'stomach', 'belly', 'chest'], true)) {
                    return $specificLocation;
                }
                if ($qid === 'SPECIFIC_LOCATION') {
                    return $specificLocation;
                }
            }
        }

        if ($bank !== null) {
            $text = ClinicalFollowUpQuestionBank::textForLanguage($bank, $bankLang);
            if ($text !== '') {
                return [
                    'text' => $text,
                    'helper' => '',
                    'purpose' => (string) ($bank['clinical_purpose'] ?? ''),
                    'priority' => (int) ($bank['priority'] ?? 99),
                ];
            }
        }

        return match ($qid) {
            'PAIN_SEVERITY' => match ($lang) {
                'ENGLISH' => [
                    'text' => 'On a scale of 0 to 10, where 0 means no pain and 10 means the worst pain you can imagine, how severe is your pain?',
                    'helper' => "0 = no pain\n10 = worst pain imaginable",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
                'TAGALOG' => [
                    'text' => 'Sa sukat na 0 hanggang 10, kung saan ang 0 ay walang sakit at ang 10 ay pinakamalalang sakit na maiisip mo, gaano kasakit ang nararamdaman mo?',
                    'helper' => "0 = walang sakit\n10 = pinakamalalang sakit na maisip",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
                default => [
                    'text' => 'Sa sukod nga 0 tubtob 10, diin ang 0 wala sang kasakit kag ang 10 amo ang pinakagrabe nga kasakit nga imo mahunahuna, daw ano kagrabe ang imo kasakit?',
                    'helper' => "0 = wala sang kasakit\n10 = pinakagrabe nga kasakit nga ma-imagine",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
            },
            'SPECIFIC_LOCATION', 'PAIN_LOCATION' => $specificLocation,
            'EYE_LATERALITY' => $specificLocation['text'] !== '' && $region === 'eye' ? $specificLocation : match ($lang) {
                'ENGLISH' => [
                    'text' => 'Which eye is affected — left, right, or both?',
                    'helper' => '',
                    'purpose' => 'Laterality for paired body parts',
                    'priority' => 9,
                ],
                default => [
                    'text' => 'Diin nga mata ang masakit — tuo, wala, ukon duha?',
                    'helper' => '',
                    'purpose' => 'Laterality for paired body parts',
                    'priority' => 9,
                ],
            },
            default => [
                'text' => $lang === 'ENGLISH'
                    ? 'Can you tell me a bit more about your symptoms?'
                    : 'Palihog isugid pa ang iban nga detalye sang imo sintomas.',
                'helper' => '',
                'purpose' => 'Clarify clinically relevant detail',
                'priority' => 80,
            ],
        };
    }
}
