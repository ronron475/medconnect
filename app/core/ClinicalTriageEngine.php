<?php
/**
 * Evidence-based clinical triage CDS engine (v3).
 *
 * Rule-based severity scoring with red-flag override, duration/pain/temperature/
 * risk modifiers, confidence gating, and explainable structured output.
 * Never diagnoses disease and never prescribes medication.
 */

final class ClinicalTriageEngine
{
    public const CONFIDENCE_THRESHOLD = 60;
    public const REVIEW_RECOMMENDATION = 'Needs Healthcare Provider Review';

    private const PRIORITY_MAP = [
        'NON-URGENT' => 'Normal',
        'URGENT'     => 'High',
        'EMERGENCY'  => 'Critical',
    ];

    private const RECOMMENDATION_MAP = [
        'NON-URGENT' => 'Schedule the patient for a regular consultation.',
        'URGENT'     => 'Arrange prompt clinical evaluation within hours to 24 hours.',
        'EMERGENCY'  => 'Refer for immediate emergency care now.',
    ];

    /**
     * @param list<array<string, mixed>> $entities
     * @param list<string> $validatedTerms
     * @return array<string, mixed>
     */
    public static function assess(
        string $originalText = '',
        string $englishText = '',
        array $entities = [],
        array $validatedTerms = [],
        int $confidenceScore = 0,
        bool $allowReprocess = true
    ): array {
        $rawInput = trim($originalText);
        $original = $rawInput;
        $english = trim($englishText);
        // Spelling / abbreviation normalization (CSV-expandable)
        $correctedOriginal = MedicalMisspellingsLoader::applyCorrections($original);
        $correctedEnglish = MedicalMisspellingsLoader::applyCorrections($english !== '' ? $english : $correctedOriginal);
        if ($correctedOriginal !== '') {
            $original = $correctedOriginal;
        }
        if ($correctedEnglish !== '') {
            $english = $correctedEnglish;
        }
        $detectedLanguage = class_exists('HiligaynonLanguageDetector')
            ? (string) (HiligaynonLanguageDetector::detect($rawInput)['primary'] ?? 'unknown')
            : 'unknown';

        if ($entities === [] && $original !== '') {
            $entities = MedicalEntityExtractor::extractEntities($original);
        }

        [$entitySymptoms, $conditions, $bodyParts] = self::collectFromEntities($entities);
        foreach ($validatedTerms as $term) {
            $t = trim($term);
            if ($t !== '' && !in_array($t, $entitySymptoms, true) && !in_array($t, $conditions, true)) {
                $entitySymptoms[] = $t;
            }
        }

        $negatedConcepts = NegationDetector::detectNegatedConcepts($original . ' ' . $english);
        $features = ClinicalFeatureExtractors::extractAll($original, $english, $negatedConcepts);
        $kbSymptoms = SymptomKnowledgeBase::matchSymptoms(
            $original,
            $english,
            array_merge($entitySymptoms, $validatedTerms)
        );
        // Negation: never keep denied/negated symptoms
        $kbSymptoms = NegationDetector::filterSymptoms($kbSymptoms, $original, $english);

        $detectedNames = array_values(array_filter(array_map(
            static fn (array $s): string => (string) ($s['symptom_name'] ?? ''),
            $kbSymptoms
        )));

        foreach ($entitySymptoms as $name) {
            $pretty = trim($name);
            if ($pretty === '') {
                continue;
            }
            if ($pretty === strtolower($pretty)) {
                $pretty = ucwords($pretty);
            }
            if (!in_array($pretty, $detectedNames, true)) {
                $kbSymptoms[] = [
                    'id'                 => 'entity_' . strtolower(str_replace(' ', '_', $pretty)),
                    'symptom_name'       => $pretty,
                    'medical_category'   => 'general',
                    'severity_weight'     => 1,
                    'emergency_weight'   => 0,
                    'urgent_weight'      => 0,
                    'danger_sign'        => false,
                    'recommended_action' => 'Clinician review recommended if persistent.',
                    'matched_term'       => $name,
                ];
                $detectedNames[] = $pretty;
            }
        }
        $kbSymptoms = NegationDetector::filterSymptoms($kbSymptoms, $original, $english);
        $detectedNames = array_values(array_filter(array_map(
            static fn (array $s): string => (string) ($s['symptom_name'] ?? ''),
            $kbSymptoms
        )));

        $redFlags = NegationDetector::filterRedFlags(self::mergeRedFlags($original, $english), $original, $english);
        [$severityScore, $factors] = self::scoreFromKb($kbSymptoms, $features, $redFlags);
        [$severityScore, $factors] = self::applySymptomCombinations($kbSymptoms, $features, $severityScore, $factors, $redFlags);
        $display = self::classify($severityScore, $redFlags, $kbSymptoms);
        [$triageLevel, $classification] = self::displayToLevel($display);

        $confidence = self::computeConfidence($confidenceScore, $kbSymptoms, $features, $redFlags, $validatedTerms);
        $conf = self::confidenceLevel($confidence);

        $durationLabel = (string) (($features['duration']['label'] ?? '') ?: '');
        $riskLabels = array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['label'] ?? ''),
            $features['risk_factors'] ?? []
        )));

        $reason = self::buildReason(
            $display,
            $detectedNames,
            $durationLabel,
            $redFlags,
            $riskLabels,
            $severityScore,
            (bool) ($features['vague_complaint'] ?? false)
        );

        $recommendation = self::RECOMMENDATION_MAP[$display];
        foreach ($kbSymptoms as $sym) {
            if (!empty($sym['danger_sign']) && !empty($sym['recommended_action'])) {
                $recommendation = (string) $sym['recommended_action'];
                break;
            }
        }
        if ($redFlags !== []) {
            $recommendation = 'Refer for immediate emergency care now.';
        }

        $needsReview = $confidence < self::CONFIDENCE_THRESHOLD;
        if ($needsReview) {
            $recommendation = self::REVIEW_RECOMMENDATION;
            if ($redFlags === [] && $display !== 'EMERGENCY') {
                $reason = "Confidence is {$confidence}% (below " . self::CONFIDENCE_THRESHOLD . '%). ' . $reason;
            }
        }

        $emergencyFlagNames = array_values(array_unique(array_map(
            static fn (array $f): string => (string) (($f['flag_name'] ?? '') !== '' ? $f['flag_name'] : ($f['english_pattern'] ?? '')),
            $redFlags
        )));
        $icons = ['NON-URGENT' => '🟢', 'URGENT' => '🟡', 'EMERGENCY' => '🔴'];

        $recommendationPayload = [
            'chief_complaint'      => $rawInput,
            'detected_symptoms'    => $detectedNames,
            'duration'             => $durationLabel !== '' ? $durationLabel : null,
            'pain_scale'           => ($features['pain_scale']['label'] ?? '') ?: null,
            'temperature'          => ($features['temperature']['label'] ?? '') ?: null,
            'red_flags'            => $emergencyFlagNames,
            'risk_factors'         => $riskLabels,
            'age_group'            => (string) ($features['age_group'] ?? 'Unknown'),
            'severity_score'       => $severityScore,
            'classification'       => $display,
            'priority'             => self::PRIORITY_MAP[$display],
            'confidence'           => $confidence,
            'reason'               => $reason,
            'recommendation'       => $recommendation,
            'needs_provider_review'=> $needsReview,
            'disclaimer'           => 'This is a triage decision-support recommendation only. It does not diagnose disease and does not prescribe medication.',
        ];

        $result = [
            'triage_display'         => $display,
            'triage_classification'  => $classification,
            'triage_level'           => $triageLevel,
            'triage_icon'            => $icons[$display] ?? '🟢',
            'priority'               => self::PRIORITY_MAP[$display],
            'severity_score'         => $severityScore,
            'severity'               => (string) ($factors['symptom_severity'] ?? 'mild'),
            'confidence_score'       => $confidence,
            'confidence'              => $confidence,
            'confidence_display'     => $confidence . '%',
            'confidence_level'       => $conf['level'],
            'confidence_level_label' => $conf['label'],
            'confidence_accepted'    => $conf['accepted'] && !$needsReview,
            'confidence_threshold'   => self::CONFIDENCE_THRESHOLD,
            'needs_provider_review'  => $needsReview,
            'detected_symptoms'      => $detectedNames,
            'detected_conditions'    => [],
            'detected_body_parts'    => array_values(array_unique($bodyParts)),
            'duration'               => $durationLabel,
            'pain_scale'             => $features['pain_scale'] ?? [],
            'temperature'            => $features['temperature'] ?? [],
            'risk_factors'           => $riskLabels,
            'age_group'              => (string) ($features['age_group'] ?? 'Unknown'),
            'emergency_flags'        => $emergencyFlagNames,
            'red_flags'              => $emergencyFlagNames,
            'red_flags_triggered'    => $redFlags,
            'assessment_factors'     => $factors,
            'clinical_reasoning'     => $reason,
            'reason'                 => $reason,
            'recommendation'         => $recommendation,
            'recommended_action'     => $recommendation,
            'recommendation_payload' => $recommendationPayload,
            'kb_matched_symptoms'    => $kbSymptoms,
            'detected_language'      => $detectedLanguage,
            'normalized_text'        => $original,
            'negated_concepts'       => $negatedConcepts,
            'source'                 => 'clinical_triage_engine_v3',
            'engine_version'         => '3.1',
        ];

        // Internal self-validation + consistency / conflict resolution
        $validation = TriageSelfValidationEngine::validate($result, [
            'original_input'    => $rawInput,
            'normalized_text'   => $original,
            'english_text'      => $english,
            'detected_language' => $detectedLanguage,
            'negated_concepts'  => $negatedConcepts,
        ]);
        $result = $validation['result'];

        // Re-process once only when validation could not auto-correct (e.g. negation residue)
        $criticalFails = array_intersect(
            $validation['failures'],
            ['classification_consistent', 'negated_symptoms_removed', 'highest_priority_selected']
        );
        $alreadyCorrected = $validation['corrected_classification'] !== null;
        if ($allowReprocess && $criticalFails !== [] && !$alreadyCorrected) {
            $re = self::assess($rawInput, $englishText, $entities, $validatedTerms, $confidenceScore, false);
            $reValidation = TriageSelfValidationEngine::validate($re, [
                'original_input'    => $rawInput,
                'normalized_text'   => (string) ($re['normalized_text'] ?? $original),
                'english_text'      => $english,
                'detected_language' => $detectedLanguage,
                'negated_concepts'  => $negatedConcepts,
            ]);
            $out = $reValidation['result'];
            $out['validation']['reprocessed'] = true;
            $out['validation']['prior_failures'] = $validation['failures'];
            $out['knowledge_suggestions'] = array_values(array_unique(
                array_merge($result['knowledge_suggestions'] ?? [], $out['knowledge_suggestions'] ?? []),
                SORT_REGULAR
            ));

            return $out;
        }

        $result['validation']['reprocessed'] = false;

        return $result;
    }

    /** @param list<array<string, mixed>> $entities
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private static function collectFromEntities(array $entities): array
    {
        $symptoms = [];
        $conditions = [];
        $bodyParts = [];
        foreach ($entities as $e) {
            $eng = trim((string) ($e['english_term'] ?? ''));
            if ($eng === '') {
                continue;
            }
            $sym = trim((string) ($e['symptom'] ?? ''));
            $cond = trim((string) ($e['condition'] ?? ''));
            $bp = trim((string) ($e['body_part'] ?? ''));
            if ($sym !== '' && $sym !== 'symptom') {
                $symptoms[] = str_replace('_', ' ', $sym);
            }
            if ($cond !== '' || str_contains(strtolower($eng), 'infection') || ($e['type'] ?? '') === 'condition') {
                $conditions[] = $eng;
            } else {
                $symptoms[] = $eng;
            }
            if ($bp !== '') {
                $bodyParts[] = $bp;
            }
        }

        return [
            array_values(array_unique($symptoms)),
            array_values(array_unique($conditions)),
            array_values(array_unique($bodyParts)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function mergeRedFlags(string $original, string $english): array
    {
        $flags = SymptomKnowledgeBase::scanRedFlagsLibrary($original, $english);
        // Also scan expandable emergency_red_flags.csv
        $flags = array_merge($flags, self::scanEmergencyRedFlagsCsv($original, $english));
        $csvFlags = EmergencyFlagsLoader::scanEmergencyFlags($original, $english);
        $seen = [];
        foreach ($flags as $f) {
            $seen[strtolower((string) ($f['flag_name'] ?? ''))] = true;
        }
        foreach ($csvFlags as $f) {
            $name = trim((string) (($f['flag_name'] ?? '') !== '' ? $f['flag_name'] : ($f['english_pattern'] ?? '')));
            if ($name === '' || isset($seen[strtolower($name)])) {
                continue;
            }
            $flags[] = [
                'flag_id'            => (string) ($f['flag_id'] ?? ''),
                'flag_name'          => $name,
                'category'           => (string) ($f['category'] ?? ''),
                'auto_triage'        => strtoupper((string) ($f['auto_triage'] ?? 'EMERGENCY')),
                'severity_points'     => 12,
                'clinical_rationale' => (string) ($f['clinical_rationale'] ?? ''),
                'matched_on'         => (string) ($f['matched_on'] ?? ''),
                'matched_pattern'    => (string) (($f['english_pattern'] ?? '') ?: ($f['hiligaynon_pattern'] ?? '')),
                'english_pattern'    => (string) ($f['english_pattern'] ?? ''),
                'source'             => 'emergency_flags.csv',
            ];
            $seen[strtolower($name)] = true;
        }

        return $flags;
    }

    /** @return list<array<string, mixed>> */
    private static function scanEmergencyRedFlagsCsv(string $original, string $english): array
    {
        $path = BASE_PATH . '/data/nlp/emergency_red_flags.csv';
        if (!is_readable($path)) {
            return [];
        }
        $hay = strtolower(trim($original . ' ' . $english));
        $matched = [];
        $seen = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $hil = strtolower((string) ($data['pattern_hiligaynon'] ?? ''));
            $eng = strtolower((string) ($data['pattern_english'] ?? ''));
            // Ignore generator padding variants
            if (str_contains($hil, 'case') || str_contains($eng, 'case') || str_contains($eng, '#')) {
                $hil = preg_replace('/\s+case\d+/', '', $hil) ?? $hil;
                $eng = preg_replace('/\s*(case\d+|#\d+)/', '', $eng) ?? $eng;
            }
            $hil = trim($hil);
            $eng = trim($eng);
            $hit = '';
            if ($hil !== '' && str_contains($hay, $hil)) {
                $hit = $hil;
            } elseif ($eng !== '' && str_contains($hay, $eng)) {
                $hit = $eng;
            }
            if ($hit === '') {
                continue;
            }
            $name = (string) ($data['name'] ?? $hit);
            if (isset($seen[strtolower($name)])) {
                continue;
            }
            $seen[strtolower($name)] = true;
            $matched[] = [
                'flag_id'            => (string) ($data['rule_id'] ?? ''),
                'flag_name'          => $name,
                'category'           => 'emergency',
                'auto_triage'        => 'EMERGENCY',
                'severity_points'     => 12,
                'clinical_rationale' => (string) ($data['rationale'] ?? ''),
                'matched_on'         => 'emergency_red_flags.csv',
                'matched_pattern'    => $hit,
                'english_pattern'    => $eng,
                'source'             => 'emergency_red_flags.csv',
            ];
        }
        fclose($handle);

        return $matched;
    }

    /**
     * Apply CSV symptom-combination escalations (data/nlp/symptom_combinations.csv).
     *
     * @param list<array<string, mixed>> $kbSymptoms
     * @param array<string, mixed> $features
     * @param list<array<string, mixed>> $redFlags
     * @return array{0:int,1:array<string,mixed>}
     */
    private static function applySymptomCombinations(
        array $kbSymptoms,
        array $features,
        int $score,
        array $factors,
        array $redFlags
    ): array {
        $ids = [];
        foreach ($kbSymptoms as $sym) {
            $ids[] = strtolower((string) ($sym['id'] ?? ''));
            $ids[] = strtolower(str_replace(' ', '_', (string) ($sym['symptom_name'] ?? '')));
        }
        foreach (($features['risk_factors'] ?? []) as $risk) {
            if (is_array($risk)) {
                $label = (string) ($risk['label'] ?? $risk['id'] ?? '');
            } else {
                $label = (string) $risk;
            }
            if ($label !== '') {
                $ids[] = strtolower(str_replace(' ', '_', $label));
            }
        }
        $bucket = (string) (($features['duration']['bucket'] ?? '') ?: '');
        if ($bucket !== '') {
            $ids[] = $bucket;
            if ($bucket === '5_plus_days') {
                $ids[] = 'duration_5_plus';
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        $path = BASE_PATH . '/data/nlp/symptom_combinations.csv';
        if (!is_readable($path) || $ids === []) {
            return [$score, $factors];
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [$score, $factors];
        }
        $header = fgetcsv($handle);
        $bestPts = 0;
        $bestClass = '';
        $seenPair = [];
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $a = strtolower((string) ($data['symptom_a'] ?? ''));
            $b = strtolower((string) ($data['symptom_b'] ?? ''));
            if ($a === '' || $b === '') {
                continue;
            }
            $pairKey = $a . '|' . $b;
            if (isset($seenPair[$pairKey])) {
                continue;
            }
            if (!in_array($a, $ids, true) || !in_array($b, $ids, true)) {
                continue;
            }
            $seenPair[$pairKey] = true;
            $pts = (int) ($data['severity_points'] ?? 0);
            $cls = strtoupper((string) ($data['classification'] ?? ''));
            if ($pts > $bestPts) {
                $bestPts = $pts;
                $bestClass = $cls;
            }
            if ($cls === 'EMERGENCY' && $redFlags === []) {
                // Combination itself acts as clinical escalation signal
                $factors['symptom_combination'] = $a . ' + ' . $b;
            }
        }
        fclose($handle);

        if ($bestPts > $score) {
            $score = $bestPts;
            $factors['score_contributions'][] = [
                'factor' => 'Symptom combination',
                'points' => $bestPts,
                'type'   => 'combination',
            ];
            if ($bestClass !== '') {
                $factors['combination_classification'] = $bestClass;
            }
        }

        return [$score, $factors];
    }

    /**
     * @param list<array<string, mixed>> $kbSymptoms
     * @param array<string, mixed> $features
     * @param list<array<string, mixed>> $redFlags
     * @return array{0:int,1:array<string,mixed>}
     */
    private static function scoreFromKb(array $kbSymptoms, array $features, array $redFlags): array
    {
        $cfg = SymptomKnowledgeBase::scoringConfig();
        $durationMods = is_array($cfg['duration_modifiers'] ?? null) ? $cfg['duration_modifiers'] : [];
        $painMods = is_array($cfg['pain_scale_modifiers'] ?? null) ? $cfg['pain_scale_modifiers'] : [];
        $tempMods = is_array($cfg['temperature_modifiers'] ?? null) ? $cfg['temperature_modifiers'] : [];
        $riskBonus = (int) ($cfg['risk_factor_bonus'] ?? 2);
        $highRiskBonus = (int) ($cfg['high_risk_with_chest_or_breathing_bonus'] ?? 6);

        $score = 0;
        $contributions = [];
        foreach ($kbSymptoms as $sym) {
            $pts = (int) ($sym['severity_weight'] ?? 0);
            $score += $pts;
            $contributions[] = [
                'factor' => $sym['symptom_name'] ?? '',
                'points' => $pts,
                'type'   => 'symptom',
            ];
        }

        $dangerIds = [];
        foreach ($kbSymptoms as $sym) {
            if (!empty($sym['danger_sign'])) {
                $dangerIds[] = $sym['id'] ?? '';
            }
        }
        if ($dangerIds === []) {
            foreach ($redFlags as $flag) {
                $pts = (int) ($flag['severity_points'] ?? 12);
                $score += $pts;
                $contributions[] = [
                    'factor' => $flag['flag_name'] ?? '',
                    'points' => $pts,
                    'type'   => 'red_flag',
                ];
            }
        }

        $duration = $features['duration'] ?? [];
        $bucket = (string) ($duration['bucket'] ?? 'unknown');
        if (isset($durationMods[$bucket])) {
            $pts = (int) $durationMods[$bucket];
            $feverish = false;
            foreach ($kbSymptoms as $sym) {
                if (($sym['id'] ?? '') === 'fever') {
                    $feverish = true;
                    break;
                }
            }
            if ($feverish && $bucket === '5_plus_days') {
                $pts = max($pts, 4);
            }
            if ($pts > 0) {
                $score += $pts;
                $contributions[] = [
                    'factor' => 'Duration (' . ($duration['label'] ?? '') . ')',
                    'points' => $pts,
                    'type'   => 'duration',
                ];
            }
        }

        $pain = $features['pain_scale'] ?? [];
        $painKey = (string) ($pain['modifier_key'] ?? '');
        if ($painKey !== '' && isset($painMods[$painKey])) {
            $pts = (int) $painMods[$painKey];
            if ($pts > 0) {
                $score += $pts;
                $contributions[] = [
                    'factor' => (string) ($pain['label'] ?? 'Pain scale'),
                    'points' => $pts,
                    'type'   => 'pain',
                ];
            }
        }

        $temp = $features['temperature'] ?? [];
        $tempKey = (string) ($temp['modifier_key'] ?? '');
        $hasFeverSymptom = false;
        foreach ($kbSymptoms as $sym) {
            if (($sym['id'] ?? '') === 'fever') {
                $hasFeverSymptom = true;
                break;
            }
        }
        if ($tempKey !== '' && isset($tempMods[$tempKey])) {
            $pts = (int) $tempMods[$tempKey];
            if ($hasFeverSymptom && in_array($tempKey, ['fever', 'low_grade'], true)) {
                $pts = 0;
            }
            if ($hasFeverSymptom && $tempKey === 'high_fever') {
                $pts = max(0, $pts - 2);
            }
            if ($pts > 0) {
                $score += $pts;
                $contributions[] = [
                    'factor' => (string) ($temp['label'] ?? 'Temperature'),
                    'points' => $pts,
                    'type'   => 'temperature',
                ];
            }
        }

        $risks = $features['risk_factors'] ?? [];
        if ($risks !== []) {
            $pts = $riskBonus * min(count($risks), 3);
            $hasCardioResp = false;
            foreach ($kbSymptoms as $sym) {
                if (in_array($sym['id'] ?? '', ['chest_pain', 'difficulty_breathing', 'palpitations'], true)) {
                    $hasCardioResp = true;
                    break;
                }
            }
            $riskIds = array_column($risks, 'id');
            if ($hasCardioResp && array_intersect($riskIds, ['heart_disease', 'hypertension', 'asthma', 'pregnant', 'senior']) !== []) {
                $pts = max($pts, $highRiskBonus);
            }
            $score += $pts;
            $labels = implode(', ', array_slice(array_column($risks, 'label'), 0, 3));
            $contributions[] = [
                'factor' => 'Risk factors (' . $labels . ')',
                'points' => $pts,
                'type'   => 'risk',
            ];
        }

        $factors = [
            'primary_symptom'    => $kbSymptoms[0]['symptom_name'] ?? '',
            'symptom_severity'   => $score >= 12 ? 'severe' : ($score >= 6 ? 'moderate' : 'mild'),
            'symptom_duration'   => (string) (($duration['label'] ?? '') ?: ($duration['raw'] ?? '')),
            'symptom_count'      => count($kbSymptoms),
            'pain_intensity'     => (string) ($pain['band'] ?? ''),
            'pain_score'         => $pain['score'] ?? null,
            'temperature'        => (string) ($temp['label'] ?? ''),
            'age_group'          => (string) ($features['age_group'] ?? 'Unknown'),
            'risk_factors'       => array_column($risks, 'label'),
            'score_contributions'=> $contributions,
            'duration_bucket'    => $bucket,
        ];

        return [$score, $factors];
    }

    /** @param list<array<string, mixed>> $redFlags */
    /**
     * @param list<array<string, mixed>> $redFlags
     * @param list<array<string, mixed>> $kbSymptoms
     */
    private static function classify(int $score, array $redFlags, array $kbSymptoms = []): string
    {
        if ($redFlags !== []) {
            return 'EMERGENCY';
        }
        foreach ($kbSymptoms as $sym) {
            if (!empty($sym['danger_sign']) || (int) ($sym['emergency_weight'] ?? 0) >= 8) {
                return 'EMERGENCY';
            }
        }
        if ($score >= 12) {
            return 'EMERGENCY';
        }
        if ($score >= 6) {
            return 'URGENT';
        }

        return 'NON-URGENT';
    }

    /** @return array{0:string,1:string} */
    private static function displayToLevel(string $display): array
    {
        return match ($display) {
            'EMERGENCY' => ['EMERGENCY', 'EMERGENCY'],
            'URGENT'    => ['HIGH', 'URGENT'],
            default     => ['LOW', 'NON_URGENT'],
        };
    }

    /** @return array{level:string,label:string,accepted:bool} */
    private static function confidenceLevel(int $score): array
    {
        if ($score >= 90) {
            return ['level' => 'very_high', 'label' => 'Very High', 'accepted' => true];
        }
        if ($score >= 75) {
            return ['level' => 'high', 'label' => 'High', 'accepted' => true];
        }
        if ($score >= self::CONFIDENCE_THRESHOLD) {
            return ['level' => 'moderate', 'label' => 'Moderate', 'accepted' => true];
        }

        return ['level' => 'review_needed', 'label' => 'Review Needed', 'accepted' => false];
    }

    /**
     * @param list<array<string, mixed>> $kbSymptoms
     * @param array<string, mixed> $features
     * @param list<array<string, mixed>> $redFlags
     * @param list<string> $validatedTerms
     */
    private static function computeConfidence(
        int $baseConfidence,
        array $kbSymptoms,
        array $features,
        array $redFlags,
        array $validatedTerms
    ): int {
        $score = max(0, min(100, $baseConfidence));
        if ($score === 0) {
            if ($kbSymptoms !== []) {
                $score = 70 + min(20, count($kbSymptoms) * 5);
            } elseif ($validatedTerms !== []) {
                $score = 65;
            } else {
                $score = 40;
            }
        }
        $weakOnly = $kbSymptoms !== [];
        foreach ($kbSymptoms as $sym) {
            $sid = (string) ($sym['id'] ?? '');
            $weight = (int) ($sym['severity_weight'] ?? 0);
            if ($sid !== 'fatigue' && $weight > 1) {
                $weakOnly = false;
                break;
            }
        }
        if (!empty($features['vague_complaint']) && $redFlags === [] && ($kbSymptoms === [] || $weakOnly)) {
            $score = min($score, 42);
        }
        if ($kbSymptoms === [] && $redFlags === []) {
            $score = min($score, 50);
        }
        if ($kbSymptoms !== [] && (($features['duration']['label'] ?? '') !== '')) {
            $score = min(100, $score + 5);
        }
        if ($redFlags !== []) {
            $score = min(100, max($score, 85));
        }
        if (count($kbSymptoms) >= 2) {
            $score = min(100, $score + 3);
        }

        return (int) $score;
    }

    /**
     * @param list<string> $symptoms
     * @param list<array<string, mixed>> $redFlags
     * @param list<string> $riskLabels
     */
    private static function buildReason(
        string $display,
        array $symptoms,
        string $durationLabel,
        array $redFlags,
        array $riskLabels,
        int $score,
        bool $vague
    ): string {
        if ($vague && $symptoms === [] && $redFlags === []) {
            return 'The complaint is too vague to support a confident triage recommendation. A healthcare provider should review the case.';
        }
        if ($display === 'EMERGENCY') {
            if ($redFlags !== []) {
                $names = [];
                foreach (array_slice($redFlags, 0, 3) as $f) {
                    $names[] = (string) (($f['flag_name'] ?? '') ?: ($f['english_pattern'] ?? 'warning sign'));
                }

                return 'Emergency warning sign(s) detected (' . implode(', ', $names) . '). Immediate emergency evaluation is recommended for patient safety.';
            }

            return "Severity score is {$score}, which meets emergency triage criteria based on detected high-acuity symptoms and clinical modifiers.";
        }
        if ($display === 'URGENT') {
            $sym = $symptoms !== [] ? implode(', ', array_slice($symptoms, 0, 4)) : 'reported symptoms';
            $dur = $durationLabel !== '' ? " Duration: {$durationLabel}." : '';
            $risk = $riskLabels !== [] ? ' Risk factors: ' . implode(', ', $riskLabels) . '.' : '';

            return 'The presentation includes ' . strtolower($sym) . " with a severity score of {$score}.{$dur}{$risk} No confirmed emergency red flag was required for escalation, but prompt clinician review is warranted.";
        }
        $sym = $symptoms !== [] ? implode(', ', array_slice($symptoms, 0, 4)) : 'mild symptoms';
        $dur = $durationLabel !== '' ? ' Duration is ' . strtolower($durationLabel) . '.' : '';

        return 'The complaint contains ' . strtolower($sym) . ' with no emergency warning signs' . ($dur !== '' ? ',' : '.') . $dur . " Severity score is {$score} (non-urgent range).";
    }
}
