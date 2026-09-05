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
        $family = self::detectFamily($state, $transcript, $assessment);
        $state['family'] = $family;

        $redFlagPriority = self::hasImmediateRedFlagPriority($state, $transcript, $assessment);
        if ($redFlagPriority) {
            return [
                'status' => self::STATUS_SUFFICIENT,
                'family' => $family,
                'missing' => [],
                'next_question_id' => '',
                'next_purpose' => '',
                'clinical_summary' => self::toDisplaySummary($facts, $transcript),
                'red_flag_priority' => true,
            ];
        }

        [$missing, $nextId, $purpose] = self::missingForFamily($family, $state, $facts);
        $unknown = is_array($facts['unknown_fields'] ?? null) ? $facts['unknown_fields'] : [];
        $missing = array_values(array_filter($missing, static fn (string $m): bool => !in_array($m, $unknown, true)));

        if ($missing === []) {
            $status = self::STATUS_SUFFICIENT;
        } elseif (self::isAmbiguousOnly($transcript)) {
            $status = self::STATUS_NOT_DETERMINED;
        } else {
            $status = self::STATUS_INSUFFICIENT;
        }

        return [
            'status' => $status,
            'family' => $family,
            'missing' => $missing,
            'next_question_id' => $status === self::STATUS_SUFFICIENT ? '' : $nextId,
            'next_purpose' => $status === self::STATUS_SUFFICIENT ? '' : $purpose,
            'clinical_summary' => self::toDisplaySummary($facts, $transcript),
            'red_flag_priority' => false,
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

        return [
            'chief_complaint' => (string) ($state['chief_complaint'] ?? ''),
            'complaint' => (string) ($state['chief_complaint'] ?? ''),
            'location' => $locs !== [] ? implode(', ', $locs) : '',
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

    public static function softTriageDisplay(string $display, array $facts, string $transcript): string
    {
        $display = strtoupper(str_replace('_', '-', $display));
        if ($display === 'NON URGENT') {
            $display = 'NON-URGENT';
        }
        if (!in_array($display, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
            return 'NON-URGENT';
        }
        if ($display === 'EMERGENCY') {
            return $display;
        }

        $state = is_array($facts['clinical_state'] ?? null)
            ? $facts['clinical_state']
            : self::extractState($facts, $transcript);
        $family = self::detectFamily($state, $transcript, []);
        if ($family !== 'eye_pain') {
            return $display;
        }

        $low = mb_strtolower($transcript);
        $eyeRed = (bool) preg_match(
            '/\b(vision loss|nawala\s+panulok|double vision|chemical|trauma|nasaktan|naga\s*dugo|sudden blindness|photophobia|grabe\s+gid)\b/u',
            $low
        );
        if ($eyeRed) {
            return $display;
        }

        $score = $state['severity'] ?? ($facts['pain_score'] ?? null);
        if ($display === 'URGENT' && $score !== null && (int) $score <= 6) {
            return 'NON-URGENT';
        }

        return $display;
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

        if (trim((string) ($facts['onset'] ?? '')) === '' && trim((string) ($state['onset'] ?? '')) !== '') {
            $facts['onset'] = (string) $state['onset'];
        }
        if (trim((string) ($facts['duration_label'] ?? '')) === '' && trim((string) ($state['duration'] ?? '')) !== '') {
            $facts['duration_label'] = (string) $state['duration'];
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
        $low = mb_strtolower($transcript);
        $locs = is_array($state['anatomical_location'] ?? null) ? $state['anatomical_location'] : [];

        foreach ((array) ($assessment['interview']['chief_complaints'] ?? []) as $row) {
            $family = strtolower((string) (is_array($row) ? ($row['family_key'] ?? $row['id'] ?? '') : $row));
            if ($family !== '') {
                if (str_contains($family, 'chest')) {
                    return 'chest_pain';
                }
                if (str_contains($family, 'abdom')) {
                    return 'abdominal_pain';
                }
                if (str_contains($family, 'head')) {
                    return 'headache';
                }
                if (str_contains($family, 'eye')) {
                    return 'eye_pain';
                }
                if (str_contains($family, 'breath') || str_contains($family, 'dyspnea')) {
                    return 'dyspnea';
                }
                if (str_contains($family, 'fever')) {
                    return 'fever';
                }
                if (str_contains($family, 'cough')) {
                    return 'cough';
                }
                if (str_contains($family, 'dizz')) {
                    return 'dizziness';
                }
            }
        }

        if (($state['dyspnea'] ?? null) === true && (in_array('chest', $locs, true) || preg_match('/\b(dughan|dibdib|chest)\b/u', $low))) {
            return 'chest_pain';
        }
        if (in_array('chest', $locs, true) || preg_match('/\b(dughan|dibdib|chest pain)\b/u', $low)) {
            return 'chest_pain';
        }
        if (in_array('eye', $locs, true) || preg_match('/\b(mata|eye pain|sakit.*mata)\b/u', $low)) {
            return 'eye_pain';
        }
        if (in_array('abdomen', $locs, true) || preg_match('/\b(tiyan|abdomen|stomach)\b/u', $low)) {
            return 'abdominal_pain';
        }
        if (in_array('head', $locs, true) || preg_match('/\b(ulo|headache|sakit.*ulo)\b/u', $low)) {
            return 'headache';
        }
        if (($state['fever'] ?? null) === true || preg_match('/\b(hilanat|lagnat|fever)\b/u', $low)) {
            return 'fever';
        }
        if (preg_match('/\b(ubo|cough)\b/u', $low)) {
            return 'cough';
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

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $facts
     * @return array{0: list<string>, 1: string, 2: string}
     */
    private static function missingForFamily(string $family, array $state, array $facts): array
    {
        $missing = [];
        $next = '';
        $purpose = '';

        $hasSeverity = ($state['severity'] ?? null) !== null || ($facts['pain_score'] ?? null) !== null;
        $hasLocation = ($state['anatomical_location'] ?? []) !== [] || ($facts['body_locations'] ?? []) !== [];
        $hasTiming = trim((string) ($state['onset'] ?? '')) !== ''
            || trim((string) ($state['duration'] ?? '')) !== ''
            || trim((string) ($facts['duration_label'] ?? '')) !== ''
            || trim((string) ($facts['onset'] ?? '')) !== '';
        $assocDone = ($state['associated_symptoms'] ?? []) !== []
            || ($state['pertinent_negatives'] ?? []) !== []
            || ($facts['has_other_symptoms'] ?? null) !== null
            || !empty($facts['denied_associated']);
        $hasCharacter = trim((string) ($state['character'] ?? '')) !== ''
            && !in_array((string) ($state['character'] ?? ''), ['mild', 'moderate', 'severe'], true);

        switch ($family) {
            case 'fever':
                if (($state['fever'] ?? null) !== true && ($state['temperature_c'] ?? null) === null) {
                    $missing[] = 'fever_confirmation';
                    $next = 'FEVER_CONFIRM';
                    $purpose = 'Confirm fever / temperature';
                }
                if (!$hasTiming) {
                    $missing[] = 'onset';
                    if ($next === '') {
                        $next = 'ONSET';
                        $purpose = 'Determine fever onset';
                    }
                }
                break;

            case 'cough':
                if (!$hasTiming) {
                    $missing[] = 'onset';
                    $next = 'ONSET';
                    $purpose = 'Cough onset / duration';
                }
                if (trim((string) ($state['cough_type'] ?? '')) === '') {
                    $missing[] = 'cough_type';
                    if ($next === '') {
                        $next = 'COUGH_TYPE';
                        $purpose = 'Dry vs productive cough';
                    }
                }
                if (($state['dyspnea'] ?? null) === null && !$assocDone) {
                    $missing[] = 'dyspnea';
                    if ($next === '') {
                        $next = 'BREATHING_SEVERITY';
                        $purpose = 'Breathing difficulty with cough';
                    }
                }
                break;

            case 'dyspnea':
                if (!$hasTiming) {
                    $missing[] = 'onset';
                    $next = 'ONSET';
                    $purpose = 'Dyspnea onset';
                }
                if (!$assocDone) {
                    $missing[] = 'associated_symptoms';
                    if ($next === '') {
                        $next = 'ASSOCIATED_SYMPTOMS';
                        $purpose = 'Associated symptoms with dyspnea';
                    }
                }
                break;

            case 'dizziness':
                if (trim((string) ($state['dizziness_type'] ?? '')) === '') {
                    $missing[] = 'dizziness_type';
                    $next = 'DIZZINESS_TYPE';
                    $purpose = 'Clarify dizziness type';
                }
                if (!$assocDone) {
                    $missing[] = 'neuro_associated';
                    if ($next === '') {
                        $next = 'NEURO_WEAKNESS';
                        $purpose = 'Neurologic associated symptoms';
                    }
                }
                break;

            case 'eye_pain':
                if (!$hasSeverity) {
                    $missing[] = 'severity';
                    $next = 'PAIN_SEVERITY';
                    $purpose = 'Eye pain severity 0–10';
                }
                if (!$hasTiming) {
                    $missing[] = 'onset';
                    if ($next === '') {
                        $next = 'ONSET';
                        $purpose = 'Eye pain onset';
                    }
                }
                if (($state['eye_symptoms'] ?? []) === [] && !$assocDone) {
                    $missing[] = 'eye_red_flags';
                    if ($next === '') {
                        $next = 'EYE_VISION';
                        $purpose = 'Vision change / eye red flags';
                    }
                }
                break;

            case 'chest_pain':
                if (!$hasSeverity) {
                    $missing[] = 'severity';
                    $next = 'PAIN_SEVERITY';
                    $purpose = 'Chest pain severity 0–10';
                }
                if (($state['dyspnea'] ?? null) === null) {
                    $missing[] = 'dyspnea';
                    if ($next === '') {
                        $next = 'BREATHING_SEVERITY';
                        $purpose = 'Breathing with chest pain';
                    }
                }
                if (!$hasTiming) {
                    $missing[] = 'onset';
                    if ($next === '') {
                        $next = 'ONSET';
                        $purpose = 'Chest pain onset';
                    }
                }
                break;

            case 'abdominal_pain':
                if (!$hasSeverity) {
                    $missing[] = 'severity';
                    $next = 'PAIN_SEVERITY';
                    $purpose = 'Abdominal pain severity 0–10';
                }
                if (!$hasLocation) {
                    $missing[] = 'location';
                    if ($next === '') {
                        $next = 'PAIN_LOCATION';
                        $purpose = 'Abdominal pain location';
                    }
                }
                if (!$hasTiming) {
                    $missing[] = 'onset';
                    if ($next === '') {
                        $next = 'ONSET';
                        $purpose = 'Abdominal pain onset';
                    }
                }
                if (($state['vomiting'] ?? null) === null && !$assocDone) {
                    $missing[] = 'associated_symptoms';
                    if ($next === '') {
                        $next = 'ABDOMINAL_ASSOCIATED';
                        $purpose = 'Vomiting / fever with abdominal pain';
                    }
                }
                break;

            case 'headache':
            case 'pain':
            default:
                if (!$hasSeverity) {
                    $missing[] = 'severity';
                    $next = 'PAIN_SEVERITY';
                    $purpose = 'Pain severity 0–10';
                }
                if (!$hasLocation) {
                    $missing[] = 'location';
                    if ($next === '') {
                        $next = 'PAIN_LOCATION';
                        $purpose = 'Pain location';
                    }
                }
                if (!$hasTiming) {
                    $missing[] = 'onset';
                    if ($next === '') {
                        $next = 'ONSET';
                        $purpose = 'Pain onset / duration';
                    }
                }
                if ($hasSeverity && $hasLocation && $hasTiming && !$assocDone && !$hasCharacter) {
                    $missing[] = 'associated_or_character';
                    if ($next === '') {
                        $next = 'ASSOCIATED_SYMPTOMS';
                        $purpose = 'Associated symptoms / character';
                    }
                } elseif ($hasSeverity && $hasLocation && $hasTiming && $hasCharacter && !$assocDone) {
                    $missing[] = 'associated_symptoms';
                    if ($next === '') {
                        $next = 'ASSOCIATED_SYMPTOMS';
                        $purpose = 'Associated symptoms';
                    }
                }
                break;
        }

        return [$missing, $next, $purpose];
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
     * @return array{text:string,helper:string,purpose:string,priority:int}
     */
    public static function questionPack(string $qid, string $lang = 'HILIGAYNON'): array
    {
        $qid = strtoupper($qid);
        $lang = strtoupper($lang);

        return match ($qid) {
            'PAIN_SEVERITY' => match ($lang) {
                'ENGLISH' => [
                    'text' => 'On a pain scale of 0–10, how severe is your pain right now?',
                    'helper' => "0 = no pain\n10 = worst pain imaginable",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
                'TAGALOG' => [
                    'text' => 'Kung 0–10 ang pain scale, gaano kasakit ngayon?',
                    'helper' => "0 = walang sakit\n10 = pinakamalalang sakit na maisip",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
                default => [
                    'text' => 'Kung 0–10 ang pain scale, pila ang imo kasakit subong?',
                    'helper' => "0 = wala sang kasakit\n10 = pinakagrabe nga kasakit nga ma-imagine",
                    'purpose' => 'Collect numeric pain score as supporting information',
                    'priority' => 5,
                ],
            },
            'PAIN_LOCATION' => [
                'text' => $lang === 'ENGLISH' ? 'Where exactly is the pain?' : ($lang === 'TAGALOG' ? 'Saan exactamente ang masakit?' : 'Diin ang masakit sa imo?'),
                'helper' => '',
                'purpose' => 'Locate pain',
                'priority' => 10,
            ],
            'ONSET', 'DURATION' => [
                'text' => $lang === 'ENGLISH' ? 'When did this start?' : ($lang === 'TAGALOG' ? 'Kailan pa ito nagsimula?' : 'San-o pa nagsugod ang kasakit?'),
                'helper' => '',
                'purpose' => 'Determine onset / duration',
                'priority' => 50,
            ],
            'ASSOCIATED_SYMPTOMS', 'ABDOMINAL_ASSOCIATED' => [
                'text' => $lang === 'ENGLISH'
                    ? 'Do you have other symptoms such as dizziness, vomiting, fever, or breathing difficulty?'
                    : ($lang === 'TAGALOG'
                        ? 'May iba pa bang sintomas tulad ng pagkahilo, pagsusuka, lagnat, o hirap sa paghinga?'
                        : 'May iban ka pa nga sintomas pareho sang hilo, pagsuka, hilanat, ukon budlay nga pagginhawa?'),
                'helper' => '',
                'purpose' => 'Associated symptoms',
                'priority' => 70,
            ],
            'BREATHING_SEVERITY' => [
                'text' => $lang === 'ENGLISH'
                    ? 'Are you having difficulty breathing, or does it feel like you cannot get enough air?'
                    : 'Budlay bala ang imo pagginhawa ukon daw kulang ang imo ginhawa?',
                'helper' => '',
                'purpose' => 'Assess breathing difficulty',
                'priority' => 20,
            ],
            'EYE_VISION' => [
                'text' => $lang === 'ENGLISH'
                    ? 'Have you noticed any vision loss, blurry vision, or sensitivity to light?'
                    : 'May pagbag-o bala sa imo panulok, malabo, ukon masakit kung makakita ka sang suga/hayag?',
                'helper' => '',
                'purpose' => 'Eye red-flag screening',
                'priority' => 25,
            ],
            'COUGH_TYPE' => [
                'text' => $lang === 'ENGLISH'
                    ? 'Is your cough dry, or do you bring up phlegm?'
                    : 'Uga bala ang imo ubo, ukon may plema?',
                'helper' => '',
                'purpose' => 'Cough character',
                'priority' => 55,
            ],
            'DIZZINESS_TYPE' => [
                'text' => $lang === 'ENGLISH'
                    ? 'When you say dizzy, does the room spin, do you feel lightheaded, or unsteady?'
                    : 'Ang imo hilo, daw naga-tuyok bala ang palibot, gaan ang ulo, ukon indi ka stabile magtindog?',
                'helper' => '',
                'purpose' => 'Clarify dizziness type',
                'priority' => 40,
            ],
            'FEVER_CONFIRM' => [
                'text' => $lang === 'ENGLISH'
                    ? 'Do you have a fever, and if measured, what was the temperature?'
                    : 'May hilanat bala ikaw, kag kung ginsukot, pila ang temperatura?',
                'helper' => '',
                'purpose' => 'Confirm fever / temperature',
                'priority' => 30,
            ],
            'NEURO_WEAKNESS' => [
                'text' => 'May kaluya ukon pamamanhid bala sa isa ka kamot ukon tiil mo?',
                'helper' => '',
                'purpose' => 'Neurologic weakness screen',
                'priority' => 21,
            ],
            default => [
                'text' => $lang === 'ENGLISH' ? 'Can you tell me a bit more about your symptoms?' : 'Palihog isugid pa ang iban nga detalye sang imo sintomas.',
                'helper' => '',
                'purpose' => 'Clarify clinically relevant detail',
                'priority' => 80,
            ],
        };
    }
}
