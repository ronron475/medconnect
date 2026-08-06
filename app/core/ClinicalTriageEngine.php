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

        NlpPipelineDebug::step('input_received', ['raw' => $rawInput]);

        // Normalize slang/chat shorthand, then spelling / abbreviation corrections
        $normalizedBase = HiligaynonTextNormalizer::normalize($rawInput);
        $correctionLog = MedicalMisspellingsLoader::applyCorrectionsWithLog($normalizedBase !== '' ? $normalizedBase : $rawInput);
        $correctedOriginal = (string) ($correctionLog['text'] ?? '');
        $correctedWords = is_array($correctionLog['corrections'] ?? null) ? $correctionLog['corrections'] : [];
        $englishCorrection = MedicalMisspellingsLoader::applyCorrectionsWithLog($english !== '' ? $english : $correctedOriginal);
        $correctedEnglish = (string) ($englishCorrection['text'] ?? $correctedOriginal);
        if ($correctedOriginal !== '') {
            $original = $correctedOriginal;
        }
        if ($correctedEnglish !== '') {
            $english = $correctedEnglish;
        }

        NlpPipelineDebug::step('normalization', [
            'normalized_base' => $normalizedBase,
            'corrected_text'  => $original,
            'corrected_words' => $correctedWords,
            'english'         => $english,
        ]);
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
        $kbSymptoms = self::filterContextualSymptomMatches($kbSymptoms, $original, $english, $features);
        $kbSymptoms = self::enrichTraumaSymptoms($kbSymptoms, $original, $english);

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

        $redFlags = NegationDetector::filterRedFlags(
            self::mergeRedFlags($rawInput, $english, $normalizedBase),
            $original,
            $english
        );
        $redFlags = array_merge($redFlags, self::scanBreathingEmergencyPatterns($original, $english));
        $redFlags = array_merge($redFlags, self::scanTraumaEmergencyPatterns($original, $english, $normalizedBase));
        $redFlags = ClinicalContextReasoningEngine::filterContextGatedRedFlags($redFlags, $original, $english, $kbSymptoms);

        NlpPipelineDebug::step('entity_extraction', [
            'symptoms'   => $detectedNames,
            'body_parts' => $bodyParts,
            'red_flags'  => array_map(static fn (array $f): string => (string) ($f['flag_name'] ?? ''), $redFlags),
            'features'   => $features,
        ]);
        [$severityScore, $factors] = self::scoreFromKb($kbSymptoms, $features, $redFlags, $original, $english);
        [$severityScore, $factors] = self::applySymptomCombinations($kbSymptoms, $features, $severityScore, $factors, $redFlags);
        [$severityScore, $factors, $cdsDisplay] = self::applyCdsTriageRules($rawInput, $english, $severityScore, $factors);
        $preliminaryDisplay = self::classify($severityScore, $redFlags, $kbSymptoms, true);
        if ($cdsDisplay !== null && $redFlags === []) {
            $preliminaryDisplay = self::maxDisplay($preliminaryDisplay, $cdsDisplay);
        }

        $context = ClinicalContextReasoningEngine::apply(
            $original,
            $english,
            $kbSymptoms,
            $features,
            $redFlags,
            $severityScore,
            $preliminaryDisplay
        );
        $display = (string) ($context['display'] ?? $preliminaryDisplay);
        $severityScore = (int) ($context['score'] ?? $severityScore);
        $factors = array_merge($factors, is_array($context['factors'] ?? null) ? $context['factors'] : []);
        [$triageLevel, $classification] = self::displayToLevel($display);

        $confidence = self::computeConfidence($confidenceScore, $kbSymptoms, $features, $redFlags, $validatedTerms);
        if (!empty($context['needs_provider_review'])) {
            $confidence = min($confidence, self::CONFIDENCE_THRESHOLD - 1);
        } elseif (!empty($context['sufficient_context']) && ($context['rule_id'] ?? '') !== 'CTX_NONE') {
            $confidence = min(100, max($confidence, self::CONFIDENCE_THRESHOLD));
        }
        $conf = self::confidenceLevel($confidence);

        $durationLabel = (string) (($features['duration']['label'] ?? '') ?: '');
        $riskLabels = array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['label'] ?? ''),
            $features['risk_factors'] ?? []
        )));

        $reason = trim((string) ($context['reason'] ?? ''));
        if ($reason === '') {
            $reason = self::buildReason(
                $display,
                $detectedNames,
                $durationLabel,
                $redFlags,
                $riskLabels,
                $severityScore,
                (bool) ($features['vague_complaint'] ?? false)
            );
        } elseif (($context['evaluated_context'] ?? []) !== []) {
            $reason .= ' Evaluated: ' . implode('; ', array_slice((array) $context['evaluated_context'], 0, 6)) . '.';
        }

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

        $needsReview = $confidence < self::CONFIDENCE_THRESHOLD
            || !empty($context['needs_provider_review']);
        if ($needsReview) {
            $recommendation = self::REVIEW_RECOMMENDATION;
            if ($redFlags === [] && $display !== 'EMERGENCY') {
                if (!empty($context['needs_provider_review'])) {
                    $reviewReason = (string) ($context['reason'] ?? 'Insufficient clinical information to determine urgency safely.');
                    $reason = $reviewReason;
                } else {
                    $reason = "Confidence is {$confidence}% (below " . self::CONFIDENCE_THRESHOLD . '%). ' . $reason;
                }
            }
        }

        $emergencyFlagNames = array_values(array_unique(array_map(
            static fn (array $f): string => (string) (($f['flag_name'] ?? '') !== '' ? $f['flag_name'] : ($f['english_pattern'] ?? '')),
            $redFlags
        )));
        $icons = ['NON-URGENT' => '🟢', 'URGENT' => '🟡', 'EMERGENCY' => '🔴'];

        $structured = self::buildStructuredOutput(
            $rawInput,
            $original,
            $english,
            $detectedLanguage,
            $detectedNames,
            $bodyParts,
            $features,
            $riskLabels,
            $redFlags,
            $factors,
            $context,
            $severityScore,
            $display,
            $confidence,
            $reason,
            $correctedWords
        );

        $recommendationPayload = [
            'chief_complaint'      => $rawInput,
            'normalized_complaint' => $original,
            'corrected_words'      => $correctedWords,
            'detected_language'    => $detectedLanguage,
            'detected_symptoms'    => $detectedNames,
            'associated_symptoms'  => $structured['associated_symptoms'],
            'detected_body_parts'  => $bodyParts,
            'duration'             => $durationLabel !== '' ? $durationLabel : null,
            'pain_scale'           => ($features['pain_scale']['label'] ?? '') ?: null,
            'temperature'          => ($features['temperature']['label'] ?? '') ?: null,
            'pregnancy_status'     => $structured['pregnancy_status'],
            'chronic_diseases'     => $structured['chronic_diseases'],
            'red_flags'            => $emergencyFlagNames,
            'risk_factors'         => $riskLabels,
            'age_group'            => (string) ($features['age_group'] ?? 'Unknown'),
            'severity_score'       => $severityScore,
            'classification'       => $display,
            'priority'             => self::PRIORITY_MAP[$display],
            'confidence'           => $confidence,
            'clinical_reasoning'   => $reason,
            'evidence_used'        => $structured['evidence_used'],
            'matched_rules'        => $structured['matched_rules'],
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
            'corrected_words'        => $correctedWords,
            'negated_concepts'       => $negatedConcepts,
            'clinical_context'       => [
                'rule_id'            => (string) ($context['rule_id'] ?? ''),
                'rule_name'          => (string) ($context['rule_name'] ?? ''),
                'evaluated_context'  => (array) ($context['evaluated_context'] ?? []),
                'sufficient_context' => (bool) ($context['sufficient_context'] ?? true),
            ],
            'structured_output'      => $structured,
            'associated_symptoms'    => $structured['associated_symptoms'],
            'evidence_used'          => $structured['evidence_used'],
            'matched_rules'          => $structured['matched_rules'],
            'pregnancy_status'       => $structured['pregnancy_status'],
            'chronic_diseases'       => $structured['chronic_diseases'],
            'administrative_request' => $structured['administrative_request'],
            'normalized_complaint'   => $original,
            'english_translation'    => $english,
            'source'                 => 'clinical_triage_engine_v3',
            'engine_version'         => '3.2',
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
            NlpPipelineDebug::attach($out);

            return $out;
        }

        $result['validation']['reprocessed'] = false;

        NlpPipelineDebug::step('classification', [
            'display'       => (string) ($result['triage_display'] ?? ''),
            'severity_score'=> (int) ($result['severity_score'] ?? 0),
            'winning_rule'  => (string) ($result['validation']['winning_rule'] ?? ''),
            'confidence'    => (int) ($result['confidence_score'] ?? 0),
        ]);
        NlpPipelineDebug::attach($result);

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
    private static function mergeRedFlags(string $original, string $english, string $preCorrection = ''): array
    {
        $haystack = trim(implode(' ', array_filter([$preCorrection, $original, $english])));
        $flags = SymptomKnowledgeBase::scanRedFlagsLibrary($haystack, $english);
        // Also scan expandable emergency_red_flags.csv
        $flags = array_merge($flags, self::scanEmergencyRedFlagsCsv($haystack, $english));
        $csvFlags = EmergencyFlagsLoader::scanEmergencyFlags($haystack, $english);
        $seen = [];
        $deduped = [];
        foreach ($flags as $f) {
            $name = strtolower((string) (($f['flag_name'] ?? '') ?: ($f['english_pattern'] ?? '')));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $deduped[] = $f;
        }
        $flags = $deduped;
        $seen = array_fill_keys(array_keys($seen), true);
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

    private static function patternMatchesHaystack(string $hay, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }
        if (strlen($pattern) <= 3) {
            return (bool) preg_match('/(?<!\w)' . preg_quote($pattern, '/') . '(?!\w)/iu', $hay);
        }

        return str_contains($hay, $pattern);
    }

    /**
     * Add trauma-specific symptoms when cut/amputation/wound patterns are present.
     *
     * @param list<array<string, mixed>> $kbSymptoms
     * @return list<array<string, mixed>>
     */
    private static function enrichTraumaSymptoms(array $kbSymptoms, string $original, string $english): array
    {
        $hay = strtolower(trim($original . ' ' . $english));
        if ($hay === '') {
            return $kbSymptoms;
        }

        $names = array_map(static fn (array $s): string => strtolower((string) ($s['symptom_name'] ?? '')), $kbSymptoms);
        $hasMild = (bool) preg_match('/\b(gamay|maliit|minor|mild|slight|superficial|small)\b/u', $hay);
        $hasSevere = (bool) preg_match('/\b(?:na)?putol|nautod|naputol|grabe|dako|malalom|severe|nagdugo gid\b/u', $hay);

        $add = static function (string $id, string $name, int $weight) use (&$kbSymptoms, &$names): void {
            if (in_array(strtolower($name), $names, true)) {
                return;
            }
            $kbSymptoms[] = [
                'id'               => $id,
                'symptom_name'     => $name,
                'medical_category' => 'trauma',
                'severity_weight'   => $weight,
                'emergency_weight' => $weight >= 10 ? 10 : 0,
                'urgent_weight'    => $weight >= 6 ? 6 : 0,
                'danger_sign'      => $weight >= 10,
                'matched_term'     => 'trauma_context',
            ];
            $names[] = strtolower($name);
        };

        if (preg_match('/\b(?:na)?putol|nautod|naputol|cut off|amputation|severed\b/u', $hay)) {
            $add('amputation', 'Amputation', 12);
            $add('laceration', 'Laceration', 8);
        } elseif (preg_match('/\b(?:grabe|dako|malalom|severe)\s+(?:pilas|wound|cut)\b/u', $hay)) {
            $add('deep_laceration', 'Deep Laceration', 10);
        } elseif (preg_match('/\bpilas\b|\bwound\b|\blaceration\b/u', $hay)) {
            $add('laceration', 'Laceration', $hasMild && !$hasSevere ? 2 : 6);
        }

        if (preg_match('/\b(?:nagdugo|gadugo|nagadugo|bleeding)\b/u', $hay)) {
            $add('bleeding', 'Bleeding', 8);
        }

        return $kbSymptoms;
    }

    /**
     * Remove high-acuity compound symptoms when required clinical qualifiers are absent.
     *
     * @param list<array<string, mixed>> $kbSymptoms
     * @param array<string, mixed> $features
     * @return list<array<string, mixed>>
     */
    private static function filterContextualSymptomMatches(
        array $kbSymptoms,
        string $original,
        string $english,
        array $features
    ): array {
        $hay = strtolower(trim($original . ' ' . $english));
        if ($hay === '' || $kbSymptoms === []) {
            return $kbSymptoms;
        }

        $hasFever = (bool) preg_match('/\b(fever|lagnat|hilanat|pyrexia|hyperthermia|nilalagnat|ginakalagnat)\b/u', $hay);
        $hasSwelling = (bool) preg_match('/\b(swelling|swollen|hubag|gahabok|edema|pamamaga)\b/u', $hay);
        $hasPregnancy = (bool) preg_match('/\b(pregnan|buntis|gravid)\b/u', $hay)
            || in_array('pregnant', array_column($features['risk_factors'] ?? [], 'id'), true);
        $hasSevere = (bool) preg_match('/\b(severe|gravely|worst|unbearable|grabe gid|8\/10|9\/10|10\/10)\b/u', $hay);
        $hasMild = (bool) preg_match('/\b(mild|slight|slightly|minor|a little|mildly)\b/u', $hay);

        return array_values(array_filter($kbSymptoms, static function (array $sym) use (
            $hay,
            $hasFever,
            $hasSwelling,
            $hasPregnancy,
            $hasSevere,
            $hasMild
        ): bool {
            $name = strtolower((string) ($sym['symptom_name'] ?? ''));

            if (str_contains($name, ' with fever') && !$hasFever) {
                return false;
            }
            if (str_contains($name, 'pregnancy') && !$hasPregnancy) {
                return false;
            }
            if (str_contains($name, 'swelling') && !$hasSwelling) {
                return false;
            }
            if (str_contains($name, 'severe') && !$hasSevere && $hasMild) {
                return false;
            }
            if ($name === 'throat swelling' && str_contains($hay, 'sore throat') && !$hasSwelling) {
                return false;
            }

            return true;
        }));
    }

    /** @return list<array<string, mixed>> */
    private static function scanBreathingEmergencyPatterns(string $original, string $english): array
    {
        $hay = strtolower(trim($original . ' ' . $english));
        if ($hay === '') {
            return [];
        }

        $patterns = [
            'indi ko kaginhawa'     => 'Unable to breathe (Hiligaynon)',
            'indi ko makaginhawa'   => 'Unable to breathe (Hiligaynon)',
            'indi makaginhawa'      => 'Difficulty breathing (Hiligaynon)',
            'cannot breathe'        => 'Cannot breathe',
            'difficulty breathing'  => 'Difficulty breathing',
            'i can\'t breathe'      => 'Cannot breathe',
        ];

        $matched = [];
        foreach ($patterns as $pattern => $label) {
            if (str_contains($hay, $pattern)) {
                $matched[] = [
                    'flag_id'            => 'BREATH_' . strtoupper(substr(md5($pattern), 0, 8)),
                    'flag_name'          => $label,
                    'category'           => 'respiratory',
                    'auto_triage'        => 'EMERGENCY',
                    'severity_points'    => 15,
                    'clinical_rationale' => 'Respiratory distress requires immediate emergency evaluation.',
                    'matched_on'         => $pattern,
                    'matched_pattern'    => $pattern,
                    'english_pattern'    => $pattern,
                    'source'             => 'breathing_pattern_scan',
                ];
                break;
            }
        }

        if ($matched === [] && preg_match('/\b(indi|dili|wala).{0,25}(kaginhawa|makaginhawa|ginhawa)\b/u', $hay)) {
            $matched[] = [
                'flag_id'            => 'BREATH_CONTEXT',
                'flag_name'          => 'Respiratory distress (contextual)',
                'category'           => 'respiratory',
                'auto_triage'        => 'EMERGENCY',
                'severity_points'    => 15,
                'clinical_rationale' => 'Negated breathing capacity in local language indicates emergency respiratory distress.',
                'matched_on'         => $hay,
                'matched_pattern'    => '(indi|dili|wala) + breathing',
                'english_pattern'    => 'cannot breathe',
                'source'             => 'breathing_pattern_scan',
            ];
        }

        return $matched;
    }

    /** @return list<array<string, mixed>> */
    private static function scanTraumaEmergencyPatterns(string $original, string $english, string $preCorrection = ''): array
    {
        $hay = strtolower(trim(implode(' ', array_filter([$preCorrection, $original, $english]))));
        if ($hay === '') {
            return [];
        }

        $traumaPatterns = [
            '/\bnautod\b/u' => ['Amputation', 'Amputation reported — emergency hemorrhage control required'],
            '/\b(?:na)?putol\s+ang\s+(?:akon\s+)?(?:sang\s+)?(kamot|kamay|tudlo|daliri|tiil|paa)\b/u' => ['Amputation', 'Severed body part — emergency care required'],
            '/\b(?:na)?putol\s+(?:ang\s+)?(?:akon\s+)?(kamot|kamay|tudlo|daliri|tiil)\b/u' => ['Amputation', 'Cut-off injury — evaluate for amputation and bleeding'],
            '/\bnaputol\s+(?:ang\s+)?(kamot|kamay|tudlo|daliri)\b/u' => ['Amputation', 'Severed digit or limb — emergency care'],
            '/\b(?:nagdugo|gadugo|nagadugo)\s+gid\s+.*\b(kamot|tiil|tudlo)\b/u' => ['Severe Bleeding', 'Uncontrolled bleeding from extremity'],
            '/\b(?:grabe|dako|malalom)\s+pilas\b/u' => ['Deep Laceration', 'Deep wound requiring urgent evaluation'],
        ];

        $matched = [];
        foreach ($traumaPatterns as $pattern => [$name, $rationale]) {
            if (preg_match($pattern, $hay)) {
                $matched[] = [
                    'flag_id'            => 'TRAUMA_' . strtoupper(substr(md5($pattern), 0, 8)),
                    'flag_name'          => $name,
                    'category'           => 'trauma',
                    'auto_triage'        => 'EMERGENCY',
                    'severity_points'    => 15,
                    'clinical_rationale' => $rationale,
                    'matched_on'         => $hay,
                    'matched_pattern'    => $pattern,
                    'english_pattern'    => $name,
                    'source'             => 'trauma_pattern_scan',
                ];
                break;
            }
        }

        // Mild wound qualifiers downgrade — superficial cut without severe indicators
        if ($matched !== [] && preg_match('/\b(gamay|minor|maliit|mild|slight|superficial)\b/u', $hay)
            && !preg_match('/\b(?:na)?putol|nautod|naputol|nagdugo gid|grabe pilas\b/u', $hay)) {
            return [];
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $factors
     * @return array{0:int,1:array<string,mixed>,2:?string}
     */
    private static function applyCdsTriageRules(string $original, string $english, int $score, array $factors): array
    {
        $match = TriageRulesLoader::matchTriage($original, $english);
        if ($match === null) {
            return [$score, $factors, null];
        }

        NlpPipelineDebug::step('cds_rule_match', $match);

        $level = strtoupper((string) ($match['triage_level'] ?? ''));
        $display = match ($level) {
            'EMERGENCY', 'CRITICAL' => 'EMERGENCY',
            'HIGH', 'URGENT' => 'URGENT',
            default => 'NON-URGENT',
        };
        $pts = match ($display) {
            'EMERGENCY' => 15,
            'URGENT' => 8,
            default => 2,
        };
        $score = max($score, $pts);
        $factors['cds_rule'] = (string) ($match['pattern'] ?? '');
        $factors['cds_rule_source'] = (string) ($match['source'] ?? '');
        $factors['score_contributions'][] = [
            'factor' => 'CDS rule (' . ($match['pattern'] ?? '') . ')',
            'points' => $pts,
            'type'   => 'cds_rule',
        ];

        return [$score, $factors, $display];
    }

    private static function maxDisplay(string $a, string $b): string
    {
        $rank = ['NON-URGENT' => 1, 'URGENT' => 2, 'EMERGENCY' => 3];

        return ($rank[$b] ?? 0) >= ($rank[$a] ?? 0) ? $b : $a;
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
            if ($hil !== '' && self::patternMatchesHaystack($hay, $hil)) {
                $hit = $hil;
            } elseif ($eng !== '' && self::patternMatchesHaystack($hay, $eng)) {
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
        $emergencyIds = [
            'chest_pain', 'difficulty_breathing', 'stroke_symptoms', 'vomiting_blood', 'coughing_blood',
            'severe_bleeding', 'loss_of_consciousness', 'seizure', 'poisoning', 'pregnancy_bleeding',
            'head_injury', 'major_trauma', 'angina', 'cardiac_arrest_symptoms', 'anaphylaxis',
        ];
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
            if ($cls === 'EMERGENCY'
                && !in_array($a, $emergencyIds, true)
                && !in_array($b, $emergencyIds, true)
                && $redFlags === []) {
                $cls = 'URGENT';
                $pts = min($pts, 8);
            }
            $priority = match ($cls) {
                'EMERGENCY' => 3,
                'URGENT' => 2,
                'NON-URGENT' => 1,
                default => 0,
            };
            $bestPriority = match ($bestClass) {
                'EMERGENCY' => 3,
                'URGENT' => 2,
                'NON-URGENT' => 1,
                default => 0,
            };
            if ($priority > $bestPriority || ($priority === $bestPriority && $pts > $bestPts)) {
                $bestPts = $pts;
                $bestClass = $cls;
            }
            if ($cls === 'EMERGENCY' && $redFlags === []) {
                $factors['symptom_combination'] = $a . ' + ' . $b;
            }
        }
        fclose($handle);

        if ($bestPts > 0 && $bestClass !== '') {
            $score = min(999, max($score, $bestPts));
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
    private static function scoreFromKb(
        array $kbSymptoms,
        array $features,
        array $redFlags,
        string $original = '',
        string $english = ''
    ): array {
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

        $hay = strtolower(trim($original . ' ' . $english));
        if ($hay !== '' && preg_match('/\b(mild|slight|slightly|minor|a little|mildly)\b/u', $hay)) {
            $reduction = min(4, max(2, (int) floor($score * 0.25)));
            $score = max(0, $score - $reduction);
            $contributions[] = [
                'factor' => 'Mild severity qualifier',
                'points' => -$reduction,
                'type'   => 'modifier',
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
    private static function classify(
        int $score,
        array $redFlags,
        array $kbSymptoms = [],
        bool $deferDangerSign = false
    ): string {
        if ($redFlags !== []) {
            return 'EMERGENCY';
        }
        if (!$deferDangerSign) {
            foreach ($kbSymptoms as $sym) {
                if (!empty($sym['danger_sign']) || (int) ($sym['emergency_weight'] ?? 0) >= 8) {
                    return 'EMERGENCY';
                }
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
                $template = ClinicalReasoningRulesLoader::render('red_flag_present', ['flags' => implode(', ', $names)]);

                return $template !== ''
                    ? $template
                    : 'Emergency warning sign(s) detected (' . implode(', ', $names) . '). Immediate emergency evaluation is recommended for patient safety.';
            }

            $template = ClinicalReasoningRulesLoader::render('score_emergency');

            return $template !== ''
                ? $template
                : "Severity score is {$score}, which meets emergency triage criteria based on detected high-acuity symptoms and clinical modifiers.";
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

    /**
     * Build canonical structured CDS output fields for APIs and QA.
     *
     * @param list<string> $detectedNames
     * @param list<string> $bodyParts
     * @param array<string, mixed> $features
     * @param list<string> $riskLabels
     * @param list<array<string, mixed>> $redFlags
     * @param array<string, mixed> $factors
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function buildStructuredOutput(
        string $rawInput,
        string $normalized,
        string $english,
        string $language,
        array $detectedNames,
        array $bodyParts,
        array $features,
        array $riskLabels,
        array $redFlags,
        array $factors,
        array $context,
        int $severityScore,
        string $display,
        int $confidence,
        string $reason,
        array $correctedWords = []
    ): array {
        $primary = (string) ($factors['primary_symptom'] ?? ($detectedNames[0] ?? ''));
        $associated = array_values(array_filter(
            $detectedNames,
            static fn (string $s): bool => $primary === '' || strcasecmp($s, $primary) !== 0
        ));

        $chronicIds = ['diabetes', 'hypertension', 'heart_disease', 'kidney_disease', 'cancer', 'asthma'];
        $chronic = [];
        $pregnancy = 'Not reported';
        foreach ($features['risk_factors'] ?? [] as $risk) {
            if (!is_array($risk)) {
                continue;
            }
            $id = (string) ($risk['id'] ?? '');
            $label = (string) ($risk['label'] ?? '');
            if ($id === 'pregnant') {
                $pregnancy = 'Pregnant';
            }
            if (in_array($id, $chronicIds, true) && $label !== '') {
                $chronic[] = $label;
            }
        }

        $contributions = is_array($factors['score_contributions'] ?? null) ? $factors['score_contributions'] : [];
        $evidence = [];
        foreach (array_slice($contributions, 0, 8) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $evidence[] = ((string) ($c['factor'] ?? '')) . ' (' . ((int) ($c['points'] ?? 0)) . ' pts)';
        }

        $matchedRules = array_values(array_filter([
            (string) ($context['rule_id'] ?? ''),
            !empty($factors['symptom_combination']) ? 'combination:' . $factors['symptom_combination'] : '',
            !empty($factors['combination_classification']) ? 'combo_class:' . $factors['combination_classification'] : '',
        ]));

        $hay = strtolower($rawInput . ' ' . $normalized);
        $admin = (bool) preg_match(
            '/\b(follow[- ]?up|check[- ]?up|medicine refill|refill|maintenance medicine|medical certificate|lab result)\b/u',
            $hay
        );

        return [
            'chief_complaint'        => $rawInput,
            'normalized_complaint'   => $normalized,
            'detected_language'      => $language,
            'english_translation'    => $english,
            'corrected_words'        => $correctedWords,
            'primary_symptom'        => $primary,
            'detected_symptoms'      => $detectedNames,
            'associated_symptoms'    => $associated,
            'body_parts'             => $bodyParts,
            'pain_scale'             => ($features['pain_scale']['label'] ?? '') ?: null,
            'duration'               => ($features['duration']['label'] ?? '') ?: null,
            'temperature'            => ($features['temperature']['label'] ?? '') ?: null,
            'risk_factors'           => $riskLabels,
            'pregnancy_status'       => $pregnancy,
            'chronic_diseases'       => $chronic,
            'emergency_red_flags'    => array_values(array_unique(array_map(
                static fn (array $f): string => (string) (($f['flag_name'] ?? '') ?: ($f['english_pattern'] ?? '')),
                $redFlags
            ))),
            'severity_score'         => $severityScore,
            'classification'         => $display,
            'confidence_score'       => $confidence,
            'clinical_reasoning'     => $reason,
            'evidence_used'          => $evidence,
            'matched_rules'          => $matchedRules,
            'clinical_context_rule'  => (string) ($context['rule_id'] ?? ''),
            'administrative_request' => $admin,
        ];
    }
}
