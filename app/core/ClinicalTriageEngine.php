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
     * @param array<string, mixed> $interviewFacts Stage 2A plumbing only — echoed on result; unused for triage decisions yet
     * @return array<string, mixed>
     */
    public static function assess(
        string $originalText = '',
        string $englishText = '',
        array $entities = [],
        array $validatedTerms = [],
        int $confidenceScore = 0,
        bool $allowReprocess = true,
        array $interviewFacts = [],
        bool $runPathComparison = true
    ): array {
        $rawInput = trim($originalText);
        $original = $rawInput;
        $english = trim($englishText);
        // Stage 2A: accept structured interview facts at the boundary only (no decision use yet).
        $interviewFacts = is_array($interviewFacts) ? $interviewFacts : [];

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

        [$entitySymptoms, $conditions, $bodyParts, $entityMatchBoosts] = self::collectFromEntities($entities);
        foreach ($validatedTerms as $term) {
            $t = trim($term);
            if ($t !== '' && !self::listHasCaseInsensitive($entitySymptoms, $t) && !self::listHasCaseInsensitive($conditions, $t)) {
                $entitySymptoms[] = $t;
            }
        }

        $negatedConcepts = NegationDetector::detectNegatedConcepts($original . ' ' . $english);
        $features = ClinicalFeatureExtractors::extractAll($original, $english, $negatedConcepts);
        // Stage 2 Batch 2: normalize interviewFacts + gap-fill existing feature slots only.
        // Does not implement WHO/red-flag predicates; OLD haystack text path remains authoritative.
        $structuredEvidence = self::normalizeInterviewEvidence($interviewFacts);
        $features = self::seedFeaturesFromStructuredEvidence($features, $structuredEvidence);
        $structuredEvidence['track_features'] = is_array($features['structured_track_features'] ?? null)
            ? $features['structured_track_features']
            : [];
        foreach ((array) ($features['body_locations'] ?? []) as $loc) {
            $loc = strtolower(trim((string) $loc));
            if ($loc !== '' && !self::listHasCaseInsensitive($bodyParts, $loc)) {
                $bodyParts[] = $loc;
            }
        }
        $kbSymptoms = SymptomKnowledgeBase::matchSymptoms(
            $original,
            $english,
            array_merge($entitySymptoms, $entityMatchBoosts, $validatedTerms)
        );
        // Negation: never keep denied/negated symptoms
        $kbSymptoms = NegationDetector::filterSymptoms($kbSymptoms, $original, $english);
        $kbSymptoms = self::filterContextualSymptomMatches($kbSymptoms, $original, $english, $features);
        $kbSymptoms = self::enrichTraumaSymptoms($kbSymptoms, $original, $english);
        $kbSymptoms = SymptomEvidenceGate::filterKbSymptoms($kbSymptoms, $rawInput, $original, $english);

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
            if (!self::listHasCaseInsensitive($detectedNames, $pretty)) {
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
        $kbSymptoms = SymptomEvidenceGate::filterKbSymptoms($kbSymptoms, $rawInput, $original, $english);
        $detectedNames = array_values(array_filter(array_map(
            static fn (array $s): string => (string) ($s['symptom_name'] ?? ''),
            $kbSymptoms
        )));

        $redFlags = NegationDetector::filterRedFlags(
            self::mergeRedFlags($rawInput, $english, $normalizedBase),
            $original,
            $english
        );
        $scannedFlags = array_merge(
            self::scanBreathingEmergencyPatterns($original, $english),
            self::scanNeuroEmergencyPatterns($original, $english),
            self::scanTraumaEmergencyPatterns($original, $english, $normalizedBase)
        );
        $redFlags = array_merge(
            $redFlags,
            NegationDetector::filterRedFlags($scannedFlags, $original, $english)
        );
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

        // WHO IITT primary clinical reference: EMERGENCY → URGENT → else leave
        // non-urgent / defer (never escalate from duration or moderate pain alone).
        $hadConfirmedRedFlags = $redFlags !== [];
        $whoMatch = WhoIittTriageRulesLoader::evaluate(
            trim($rawInput . ' ' . $original),
            $english,
            $negatedConcepts
        );
        if ($whoMatch !== null) {
            $whoDisplay = (string) ($whoMatch['triage_level'] ?? 'NON-URGENT');
            $factors['who_iitt'] = [
                'rule_id'          => (string) ($whoMatch['rule_id'] ?? ''),
                'clinical_sign'    => (string) ($whoMatch['clinical_sign'] ?? ''),
                'triage_level'     => $whoDisplay,
                'source'           => (string) ($whoMatch['source'] ?? 'WHO IITT'),
                'source_reference' => (string) ($whoMatch['source_reference'] ?? ''),
                'matched_count'    => count((array) ($whoMatch['matched_rules'] ?? [])),
            ];
            $factors['score_contributions'] = is_array($factors['score_contributions'] ?? null)
                ? $factors['score_contributions']
                : [];
            $factors['score_contributions'][] = [
                'factor' => 'WHO IITT: ' . (string) ($whoMatch['clinical_sign'] ?? $whoMatch['rule_id'] ?? ''),
                'points' => $whoDisplay === 'EMERGENCY' ? 15 : 8,
                'type'   => 'who_iitt',
                'source' => (string) ($whoMatch['source_reference'] ?? 'WHO IITT'),
            ];

            // WHO/IITT match is authoritative over CDS/context severity scoring.
            // Confirmed emergency_red_flags.csv hits may only UPGRADE further (e.g. keep
            // EMERGENCY if already higher than a YELLOW WHO match) — never block WHO
            // from replacing a weaker non-WHO escalation.
            $priorDisplay = $display;
            $display = $whoDisplay;
            if ($hadConfirmedRedFlags
                && self::displayPriority($priorDisplay) > self::displayPriority($whoDisplay)
            ) {
                $display = $priorDisplay;
            }
            $severityScore = max(
                $severityScore,
                $display === 'EMERGENCY' ? 12 : ($display === 'URGENT' ? 6 : $severityScore)
            );
            $factors['who_iitt_authority'] = true;
            if ($whoDisplay === 'EMERGENCY' && !empty($whoMatch['red_flag'])) {
                $redFlags[] = [
                    'flag_id'          => (string) ($whoMatch['rule_id'] ?? 'WHO_IITT'),
                    'flag_name'        => (string) ($whoMatch['clinical_sign'] ?? 'WHO IITT red criterion'),
                    'matched_on'       => 'who_iitt_triage_rules.csv',
                    'source'           => 'who_iitt_triage_rules.csv',
                    'source_reference' => (string) ($whoMatch['source_reference'] ?? ''),
                ];
            }
        } elseif (!$hadConfirmedRedFlags && self::displayPriority($display) >= 2) {
            // No WHO RED/YELLOW criteria: remove weak CDS/context escalations.
            if (self::isNonWhoWeakEscalation($factors, $context)) {
                $display = 'NON-URGENT';
                $severityScore = min($severityScore, 5);
                $factors['who_iitt_downgrade'] = 'No WHO IITT emergency/urgent criteria matched; weak escalation removed.';
            }
        }
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
        if (!empty($factors['who_iitt']['source_reference'])) {
            $whoReason = 'WHO IITT: ' . (string) ($factors['who_iitt']['clinical_sign'] ?? 'criteria matched')
                . ' → ' . (string) ($factors['who_iitt']['triage_level'] ?? $display) . '.';
            if ($reason === '' || self::displayPriority($display) >= 2) {
                $reason = trim($whoReason . ' ' . $reason);
            }
        }
        if (!empty($factors['who_iitt_downgrade'])) {
            $reason = trim((string) $factors['who_iitt_downgrade'] . ' ' . $reason);
        }
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
            'symptom_evidence'       => SymptomEvidenceGate::evidenceMap($kbSymptoms, $rawInput, $original, $english),
            'pregnancy_status'       => $structured['pregnancy_status'],
            'chronic_diseases'       => $structured['chronic_diseases'],
            'administrative_request' => $structured['administrative_request'],
            'normalized_complaint'   => $original,
            'english_translation'    => $english,
            'source'                 => 'clinical_triage_engine_v3',
            'engine_version'         => '3.2',
            // Stage 2A echo-through — raw interviewFacts pack.
            'interview_facts'        => $interviewFacts,
            // Stage 2 Batch 2: normalized clinical evidence view.
            'structured_clinical_evidence' => $structuredEvidence,
            // Stage 2 Batch 5: per-track seeded feature snapshots (inspection / NEW coverage).
            'structured_track_features' => is_array($features['structured_track_features'] ?? null)
                ? $features['structured_track_features']
                : [],
            // Stage 2 Batch 3/6: shadow candidates only — OLD display remains authoritative.
            'structured_polarity_shadow' => null,
            'structured_who_combo_shadow' => null,
        ];

        $polarityShadowInitial = self::evaluateStructuredPolarityShadow(
            $structuredEvidence,
            trim($rawInput . ' ' . $original . ' ' . $english),
            $display
        );
        $result['structured_polarity_shadow'] = $polarityShadowInitial;
        $result['structured_who_combo_shadow'] = is_array($polarityShadowInitial['who_combo_shadow'] ?? null)
            ? $polarityShadowInitial['who_combo_shadow']
            : self::evaluateStructuredWhoComboShadow($structuredEvidence);

        // Internal self-validation + consistency / conflict resolution
        $validation = TriageSelfValidationEngine::validate($result, [
            'original_input'    => $rawInput,
            'normalized_text'   => $original,
            'english_text'      => $english,
            'detected_language' => $detectedLanguage,
            'negated_concepts'  => $negatedConcepts,
        ]);
        $result = $validation['result'];
        // Preserve Stage 2 plumbing echo even if validation mutates the payload.
        $result['interview_facts'] = $interviewFacts;
        $result['structured_clinical_evidence'] = $structuredEvidence;
        $result['structured_track_features'] = is_array($features['structured_track_features'] ?? null)
            ? $features['structured_track_features']
            : [];
        $polarityShadow = self::evaluateStructuredPolarityShadow(
            $structuredEvidence,
            trim($rawInput . ' ' . $original . ' ' . $english),
            (string) ($result['triage_display'] ?? $display)
        );
        $result['structured_polarity_shadow'] = $polarityShadow;
        $result['structured_who_combo_shadow'] = is_array($polarityShadow['who_combo_shadow'] ?? null)
            ? $polarityShadow['who_combo_shadow']
            : self::evaluateStructuredWhoComboShadow($structuredEvidence);

        // Re-process once only when validation could not auto-correct (e.g. negation residue)
        $criticalFails = array_intersect(
            $validation['failures'],
            ['classification_consistent', 'negated_symptoms_removed', 'highest_priority_selected']
        );
        $alreadyCorrected = $validation['corrected_classification'] !== null;
        if ($allowReprocess && $criticalFails !== [] && !$alreadyCorrected) {
            $re = self::assess(
                $rawInput,
                $englishText,
                $entities,
                $validatedTerms,
                $confidenceScore,
                false,
                $interviewFacts,
                false
            );
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
            $out['interview_facts'] = $interviewFacts;
            $out['structured_clinical_evidence'] = $structuredEvidence;
            $out['structured_track_features'] = is_array($features['structured_track_features'] ?? null)
                ? $features['structured_track_features']
                : [];
            $out['structured_polarity_shadow'] = self::evaluateStructuredPolarityShadow(
                $structuredEvidence,
                trim($rawInput . ' ' . $original . ' ' . $english),
                (string) ($out['triage_display'] ?? '')
            );
            $out['structured_who_combo_shadow'] = is_array($out['structured_polarity_shadow']['who_combo_shadow'] ?? null)
                ? $out['structured_polarity_shadow']['who_combo_shadow']
                : self::evaluateStructuredWhoComboShadow($structuredEvidence);
            $out = self::attachTriagePathComparison(
                $out,
                $interviewFacts,
                $structuredEvidence,
                $runPathComparison
            );
            NlpPipelineDebug::attach($out);

            return $out;
        }

        $result['validation']['reprocessed'] = false;
        $result = self::attachTriagePathComparison(
            $result,
            $interviewFacts,
            $structuredEvidence,
            $runPathComparison
        );

        NlpPipelineDebug::step('classification', [
            'display'       => (string) ($result['triage_display'] ?? ''),
            'severity_score'=> (int) ($result['severity_score'] ?? 0),
            'winning_rule'  => (string) ($result['validation']['winning_rule'] ?? ''),
            'confidence'    => (int) ($result['confidence_score'] ?? 0),
        ]);
        NlpPipelineDebug::attach($result);

        return $result;
    }

    /**
     * Case-insensitive membership via HiligaynonTextNormalizer::caseFold.
     *
     * @param list<string> $haystack
     */
    private static function listHasCaseInsensitive(array $haystack, string $needle): bool
    {
        $needleKey = class_exists('HiligaynonTextNormalizer')
            ? HiligaynonTextNormalizer::caseFold($needle)
            : mb_strtolower(trim($needle));
        if ($needleKey === '') {
            return false;
        }
        foreach ($haystack as $item) {
            $itemKey = class_exists('HiligaynonTextNormalizer')
                ? HiligaynonTextNormalizer::caseFold((string) $item)
                : mb_strtolower(trim((string) $item));
            if ($itemKey === $needleKey) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $entities
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private static function collectFromEntities(array $entities): array
    {
        $symptoms = [];
        $matchBoosts = [];
        $conditions = [];
        $bodyParts = [];
        foreach ($entities as $e) {
            $local = trim((string) ($e['hiligaynon_term'] ?? $e['local_term'] ?? ''));
            $eng = trim((string) ($e['english_term'] ?? ''));
            if ($local === '' && $eng === '') {
                continue;
            }
            $sym = trim((string) ($e['symptom'] ?? ''));
            $cond = trim((string) ($e['condition'] ?? ''));
            $bp = trim((string) ($e['body_part'] ?? ''));
            // Local wording boosts matching but is not dumped as a display symptom label.
            if ($local !== '') {
                $matchBoosts[] = $local;
            }
            if ($sym !== '' && $sym !== 'symptom' && $sym !== 'pain') {
                $symptoms[] = str_replace('_', ' ', $sym);
            }
            if ($cond !== '' || str_contains(strtolower($eng), 'infection') || ($e['type'] ?? '') === 'condition') {
                if ($eng !== '') {
                    $conditions[] = $eng;
                }
            } elseif ($eng !== '' && !in_array(strtolower($eng), ['pain', 'symptom', 'symptoms'], true)) {
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
            array_values(array_unique($matchBoosts)),
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
            'indi ko makahinga'     => 'Unable to breathe (Hiligaynon)',
            'indi makaginhawa'      => 'Difficulty breathing (Hiligaynon)',
            'indi makahinga'        => 'Difficulty breathing (Hiligaynon)',
            'cannot breathe'        => 'Cannot breathe',
            'can\'t breathe'        => 'Cannot breathe',
            'cant breathe'          => 'Cannot breathe',
            'difficulty breathing'  => 'Difficulty breathing',
            'i can\'t breathe'      => 'Cannot breathe',
            'hindi ako makahinga'   => 'Cannot breathe (Filipino)',
            'hindi makahinga'       => 'Cannot breathe (Filipino)',
        ];

        $matched = [];
        foreach ($patterns as $pattern => $label) {
            if (!str_contains($hay, $pattern)) {
                continue;
            }
            if (self::isFindingNegated($hay, $pattern)) {
                continue;
            }
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

        if ($matched === [] && preg_match('/\b(indi|dili|hindi)\s+(ko\s+)?(kaginhawa|makaginhawa|makahinga)\b/u', $hay)) {
            if (!self::isFindingNegated($hay, 'difficulty breathing')) {
                $matched[] = [
                    'flag_id'            => 'BREATH_CONTEXT',
                    'flag_name'          => 'Respiratory distress (contextual)',
                    'category'           => 'respiratory',
                    'auto_triage'        => 'EMERGENCY',
                    'severity_points'    => 15,
                    'clinical_rationale' => 'Negated breathing capacity in local language indicates emergency respiratory distress.',
                    'matched_on'         => $hay,
                    'matched_pattern'    => '(indi|dili|hindi) + breathing',
                    'english_pattern'    => 'cannot breathe',
                    'source'             => 'breathing_pattern_scan',
                ];
            }
        }

        return $matched;
    }

    private static function isFindingNegated(string $hay, string $finding): bool
    {
        $finding = strtolower(trim($finding));
        if ($finding === '' || $hay === '') {
            return false;
        }
        if (class_exists('NegationDetector')) {
            try {
                foreach (NegationDetector::detectNegatedConcepts($hay) as $neg) {
                    $neg = strtolower(trim((string) $neg));
                    if ($neg === '') {
                        continue;
                    }
                    if ($neg === $finding || str_contains($finding, $neg) || str_contains($neg, $finding)) {
                        return true;
                    }
                }
            } catch (Throwable) {
                // fall through to prefix check
            }
        }
        $quoted = preg_quote($finding, '/');

        return (bool) preg_match(
            '/\b(no|not|without|denies|wala(?:\s+(?:ko|ako|akong|sang|man))?|walang|walay|hindi(?:\s+ako)?)\s+' . $quoted . '\b/u',
            $hay
        );
    }

    /** @return list<array<string, mixed>> */
    private static function scanNeuroEmergencyPatterns(string $original, string $english): array
    {
        $hay = strtolower(trim($original . ' ' . $english));
        if ($hay === '') {
            return [];
        }

        $patterns = [
            '/nangaluya.{0,40}(kamot|kamay|tiil|braso|arm|leg|paa)/u' => 'One-sided weakness',
            '/kaluya.{0,30}(isa ka|one|wala|left|right).{0,20}(kamot|kamay|tiil|arm|leg)/u' => 'One-sided weakness',
            '/\b(one[- ]sided weakness|left arm.{0,20}weak|weakness in one)\b/u' => 'One-sided weakness',
            '/\b(pamamanhid|naga\s*numb).{0,30}(kamot|kamay|tiil|arm|leg|wala)\b/u' => 'Focal numbness',
            '/\b(facial droop|naparalysis ang atubang|nakasimangot)\b/u' => 'Facial droop',
        ];

        $matched = [];
        foreach ($patterns as $pattern => $label) {
            if (!preg_match($pattern, $hay)) {
                continue;
            }
            $matched[] = [
                'flag_id'            => 'NEURO_' . strtoupper(substr(md5($pattern), 0, 8)),
                'flag_name'          => $label,
                'category'           => 'neurological',
                'auto_triage'        => 'EMERGENCY',
                'severity_points'    => 15,
                'clinical_rationale' => 'Focal neurological deficit requires emergency stroke evaluation.',
                'matched_on'         => $hay,
                'matched_pattern'    => $pattern,
                'english_pattern'    => $label,
                'source'             => 'neuro_pattern_scan',
            ];
            break;
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
        return self::displayPriority($b) >= self::displayPriority($a) ? $b : $a;
    }

    private static function displayPriority(string $display): int
    {
        return match (strtoupper(str_replace('_', '-', $display))) {
            'EMERGENCY' => 3,
            'URGENT' => 2,
            default => 1,
        };
    }

    /**
     * True when escalation came from duration/moderate-pain/CDS substring alone,
     * without WHO-aligned emergency/urgent clinical criteria.
     *
     * @param array<string, mixed> $factors
     * @param array<string, mixed> $context
     */
    private static function isNonWhoWeakEscalation(array $factors, array $context): bool
    {
        $urgentHits = (array) ($context['factors']['context_urgent_hits'] ?? []);
        $emergencyHits = (array) ($context['factors']['context_emergency_hits'] ?? []);
        if ($emergencyHits !== []) {
            return false;
        }

        $weakFeatureKeys = [
            'feature:pain_moderate_or_severe',
            'feature:duration_1_to_2_days',
            'feature:duration_3_plus_days',
        ];
        $onlyWeakFeatures = $urgentHits !== []
            && array_diff($urgentHits, array_merge($weakFeatureKeys, [
                // Allow pattern tokens that are themselves duration-only phrases
                '2 days', '3 days', '5 days', 'one week', 'persistent', 'hours',
                'since yesterday', '5/10', '6/10', '7/10', 'moderate',
            ])) === [];

        if ($onlyWeakFeatures) {
            return true;
        }

        // CDS substring escalate without contextual emergency hits.
        if (!empty($factors['cds_rule']) && $urgentHits === [] && $emergencyHits === []) {
            $cds = strtolower((string) $factors['cds_rule']);
            if (preg_match('/\b(sakit ulo|headache|sakit tiyan|abdominal|ubo|cough|lagnat|fever)\b/u', $cds)
                && !preg_match('/\b(budlay|ginhawa|breath|dugo|bleed|seizure|naguyam|stroke|unconscious)\b/u', $cds)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @var list<array<string, string>>|null */
    private static ?array $emergencyRedFlagRows = null;

    /** @var list<array<string, string>>|null */
    private static ?array $symptomCombinationRows = null;

    /** @return list<array<string, mixed>> */
    private static function scanEmergencyRedFlagsCsv(string $original, string $english): array
    {
        $hay = strtolower(trim($original . ' ' . $english));
        if ($hay === '') {
            return [];
        }
        if (self::$emergencyRedFlagRows === null) {
            self::$emergencyRedFlagRows = self::loadEmergencyRedFlagRows();
        }
        $matched = [];
        $seen = [];
        foreach (self::$emergencyRedFlagRows as $data) {
            $status = strtolower((string) ($data['status'] ?? 'active'));
            if ($status !== '' && $status !== 'active') {
                continue;
            }
            $rowClass = strtoupper((string) ($data['classification'] ?? 'EMERGENCY'));
            // This scanner only contributes confirmed EMERGENCY red flags.
            // URGENT-aligned rows (e.g. urinary retention per WHO IITT YELLOW) are skipped.
            if ($rowClass !== '' && $rowClass !== 'EMERGENCY' && $rowClass !== 'CRITICAL') {
                continue;
            }
            $hil = strtolower((string) ($data['pattern_hiligaynon'] ?? ''));
            $eng = strtolower((string) ($data['pattern_english'] ?? ''));
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

        return $matched;
    }

    /** @return list<array<string, string>> */
    private static function loadEmergencyRedFlagRows(): array
    {
        $path = BASE_PATH . '/data/nlp/emergency_red_flags.csv';
        if (!is_readable($path)) {
            return [];
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        $header = fgetcsv($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            if ($data !== []) {
                $rows[] = $data;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<array<string, string>> */
    private static function symptomCombinationRows(): array
    {
        if (self::$symptomCombinationRows !== null) {
            return self::$symptomCombinationRows;
        }
        self::$symptomCombinationRows = [];
        $path = BASE_PATH . '/data/nlp/symptom_combinations.csv';
        if (!is_readable($path)) {
            return self::$symptomCombinationRows;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return self::$symptomCombinationRows;
        }
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            if (($data['symptom_a'] ?? '') === '' || ($data['symptom_b'] ?? '') === '') {
                continue;
            }
            self::$symptomCombinationRows[] = $data;
        }
        fclose($handle);

        return self::$symptomCombinationRows;
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

        if ($ids === []) {
            return [$score, $factors];
        }

        $bestPts = 0;
        $bestClass = '';
        $emergencyIds = [
            'chest_pain', 'difficulty_breathing', 'stroke_symptoms', 'vomiting_blood', 'coughing_blood',
            'severe_bleeding', 'loss_of_consciousness', 'seizure', 'poisoning', 'pregnancy_bleeding',
            'head_injury', 'major_trauma', 'angina', 'cardiac_arrest_symptoms', 'anaphylaxis',
        ];
        $seenPair = [];
        foreach (self::symptomCombinationRows() as $data) {
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

    /**
     * Stage 2 Batch 2: interview-only metadata keys (not clinical evidence).
     * Data-driven list — not complaint/language specific.
     *
     * @return list<string>
     */
    private static function interviewOnlyFactKeys(): array
    {
        return [
            'needs_associated_detail',
            'has_other_symptoms',
            'patient_conditional',
            'patient_uncertain',
            'needs_location_clarification',
            'body_location_matches',
            'body_location_verification',
            'clinical_state',
            'denied_associated',
            'questions_answered',
            'questions_asked',
            'findings_asked',
            'awaiting_question_id',
            'awaiting_target_finding',
            'awaiting_parent_question_id',
        ];
    }

    /**
     * Stage 2 Batch 2/3: structured polarity keys (tri-state: true|false|null).
     *
     * @return list<string>
     */
    public static function clinicalPolarityKeys(): array
    {
        return [
            'weakness',
            'speech_difficulty',
            'vision_change',
            'breathing_difficulty',
            'bleeding_continuing',
            'bleeding_heavy',
            'dizziness',
            'chest_radiation',
            'sweating',
            'abdominal_associated',
            'fever_confirmed',
            'blood_in_stool',
            'pregnancy',
        ];
    }

    /**
     * Stage 2 Batch 3: data-driven bridge from polarity keys → existing consumers.
     * No complaint/language branches. Soft entries do not invent new E/U rules.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function polarityConsumerBridge(): array
    {
        return [
            'breathing_difficulty' => [
                'consumer' => 'red_flag_breathing',
                'strength' => 'hard',
                'triage_level' => 'EMERGENCY',
                // Batch 7: same existing distress meaning → WHO R003 id parity (shadow only).
                'who_rule_ids' => ['IITT-R003'],
                'synthetic_phrases' => ['difficulty breathing'],
                'negation_concepts' => ['difficulty breathing', 'shortness of breath', 'breath', 'ginhawa', 'hinga'],
            ],
            'bleeding_heavy' => [
                'consumer' => 'who_iitt',
                'strength' => 'hard',
                'triage_level' => 'EMERGENCY',
                'who_rule_ids' => ['IITT-R004'],
                'synthetic_phrases' => ['heavy bleeding'],
                'negation_concepts' => ['heavy bleeding', 'bleeding'],
            ],
            'bleeding_continuing' => [
                'consumer' => 'who_iitt',
                'strength' => 'hard',
                'triage_level' => 'URGENT',
                'who_rule_ids' => ['IITT-Y006'],
                'synthetic_phrases' => ['ongoing bleeding'],
                'negation_concepts' => ['ongoing bleeding', 'bleeding'],
            ],
            'weakness' => [
                'consumer' => 'who_iitt',
                'strength' => 'hard',
                'triage_level' => 'URGENT',
                'who_rule_ids' => ['IITT-Y009'],
                'synthetic_phrases' => ['weakness in one arm or leg'],
                'negation_concepts' => ['weakness'],
            ],
            'speech_difficulty' => [
                'consumer' => 'who_iitt',
                'strength' => 'hard',
                'triage_level' => 'URGENT',
                'who_rule_ids' => ['IITT-Y009'],
                'synthetic_phrases' => ['difficulty speaking'],
                'negation_concepts' => ['difficulty speaking', 'speech'],
            ],
            'vision_change' => [
                'consumer' => 'who_iitt',
                'strength' => 'hard',
                'triage_level' => 'URGENT',
                'who_rule_ids' => ['IITT-Y010'],
                'synthetic_phrases' => ['sudden vision change'],
                'negation_concepts' => ['sudden vision change', 'vision'],
            ],
            'dizziness' => [
                'consumer' => 'soft',
                'strength' => 'soft',
                'triage_level' => '',
                'who_rule_ids' => [],
                'synthetic_phrases' => ['dizziness'],
                'negation_concepts' => ['dizziness'],
            ],
            'chest_radiation' => [
                'consumer' => 'who_iitt',
                'strength' => 'soft',
                'triage_level' => 'URGENT',
                'who_rule_ids' => ['IITT-Y015'],
                'synthetic_phrases' => ['chest pain spreading to arm'],
                'negation_concepts' => ['chest pain'],
            ],
            'sweating' => [
                'consumer' => 'soft',
                'strength' => 'soft',
                'triage_level' => '',
                'who_rule_ids' => [],
                'synthetic_phrases' => ['sweating with chest pain'],
                'negation_concepts' => ['sweating'],
            ],
            'abdominal_associated' => [
                'consumer' => 'soft',
                'strength' => 'soft',
                'triage_level' => '',
                'who_rule_ids' => [],
                'synthetic_phrases' => ['vomiting with abdominal pain'],
                'negation_concepts' => ['vomiting'],
            ],
            'fever_confirmed' => [
                'consumer' => 'soft',
                'strength' => 'soft',
                'triage_level' => '',
                'who_rule_ids' => [],
                'synthetic_phrases' => ['fever'],
                'negation_concepts' => ['fever', 'lagnat', 'hilanat'],
            ],
            'blood_in_stool' => [
                'consumer' => 'soft',
                'strength' => 'soft',
                'triage_level' => '',
                'who_rule_ids' => [],
                'synthetic_phrases' => ['blood in stool'],
                'negation_concepts' => ['blood in stool'],
            ],
            'pregnancy' => [
                'consumer' => 'soft',
                'strength' => 'soft',
                'triage_level' => '',
                'who_rule_ids' => [],
                'synthetic_phrases' => ['pregnant'],
                'negation_concepts' => ['pregnant', 'pregnancy'],
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function evidenceStringList(mixed $value): array
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
     * Normalize one facts bag into clinical vs interview-only evidence.
     * Universal / data-driven — works for any domain producing the standard fact shape.
     *
     * @param array<string, mixed> $facts
     * @return array{clinical: array<string, mixed>, interview_only: array<string, mixed>}
     */
    public static function normalizeFactBag(array $facts): array
    {
        $interviewOnly = [];
        foreach (self::interviewOnlyFactKeys() as $key) {
            if (array_key_exists($key, $facts)) {
                $interviewOnly[$key] = $facts[$key];
            }
        }

        $polarity = [];
        foreach (self::clinicalPolarityKeys() as $key) {
            if (!array_key_exists($key, $facts)) {
                $polarity[$key] = null;
                continue;
            }
            $v = $facts[$key];
            if ($v === true || $v === false) {
                $polarity[$key] = $v;
            } elseif ($v === null || $v === '') {
                $polarity[$key] = null;
            } else {
                $s = strtolower(trim((string) $v));
                if (in_array($s, ['1', 'true', 'yes'], true)) {
                    $polarity[$key] = true;
                } elseif (in_array($s, ['0', 'false', 'no'], true)) {
                    $polarity[$key] = false;
                } else {
                    $polarity[$key] = null;
                }
            }
        }

        $painScore = null;
        if (isset($facts['pain_score']) && $facts['pain_score'] !== null && $facts['pain_score'] !== '') {
            $painScore = (int) $facts['pain_score'];
        }

        $clinical = [
            'polarity' => $polarity,
            'pain_score' => $painScore,
            'pain_qualifier' => trim((string) ($facts['pain_qualifier'] ?? '')),
            'onset' => trim((string) ($facts['onset'] ?? '')),
            'duration_label' => trim((string) ($facts['duration_label'] ?? '')),
            'progression' => trim((string) ($facts['progression'] ?? '')),
            'body_locations' => self::evidenceStringList($facts['body_locations'] ?? []),
            'symptoms_patient' => self::evidenceStringList($facts['symptoms_patient'] ?? []),
            'symptoms_kb' => self::evidenceStringList($facts['symptoms_kb'] ?? []),
            'symptoms_ai' => self::evidenceStringList($facts['symptoms_ai'] ?? []),
            // Legacy lists retained for compatibility inspection (not promoted as patient-only).
            'symptoms_legacy' => self::evidenceStringList($facts['symptoms'] ?? []),
            'associated_symptoms' => self::evidenceStringList($facts['associated_symptoms'] ?? []),
            'negative_symptoms' => self::evidenceStringList($facts['negative_symptoms'] ?? []),
            'risk_factors' => self::evidenceStringList($facts['risk_factors'] ?? []),
            'vital_signs' => self::evidenceStringList($facts['vital_signs'] ?? []),
            'medical_history' => self::evidenceStringList($facts['medical_history'] ?? []),
            'finding_status' => is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [],
        ];

        return [
            'clinical' => $clinical,
            'interview_only' => $interviewOnly,
        ];
    }

    /**
     * Stage 2 Batch 2: normalize Stage 2A interviewFacts into a universal evidence view.
     * Single bag or multi-track pack — no synthetic English flattening.
     *
     * @param array<string, mixed> $interviewFacts
     * @return array<string, mixed>
     */
    public static function normalizeInterviewEvidence(array $interviewFacts): array
    {
        if ($interviewFacts === []) {
            return [
                'mode' => 'empty',
                'patient_evidence_text' => '',
                'clinical' => self::normalizeFactBag([])['clinical'],
                'interview_only' => [],
                'tracks' => [],
            ];
        }

        $hasTracks = isset($interviewFacts['tracks']) && is_array($interviewFacts['tracks']);
        if ($hasTracks) {
            $tracksOut = [];
            foreach ($interviewFacts['tracks'] as $track) {
                if (!is_array($track)) {
                    continue;
                }
                $bag = is_array($track['facts'] ?? null) ? $track['facts'] : [];
                $norm = self::normalizeFactBag($bag);
                $tracksOut[] = [
                    'complaint_id' => (string) ($track['complaint_id'] ?? $track['id'] ?? ''),
                    'text_span' => (string) ($track['text_span'] ?? ''),
                    'family_keys' => is_array($track['family_keys'] ?? null)
                        ? array_values(array_map('strval', $track['family_keys']))
                        : [],
                    'clinical' => $norm['clinical'],
                    'interview_only' => $norm['interview_only'],
                ];
            }
            $activeFacts = is_array($interviewFacts['active_facts'] ?? null)
                ? $interviewFacts['active_facts']
                : [];
            $activeNorm = self::normalizeFactBag($activeFacts);

            return [
                'mode' => 'multi',
                'active_complaint_id' => (string) ($interviewFacts['active_complaint_id'] ?? ''),
                'patient_evidence_text' => trim((string) ($interviewFacts['patient_evidence_text'] ?? '')),
                'clinical' => $activeNorm['clinical'],
                'interview_only' => $activeNorm['interview_only'],
                'tracks' => $tracksOut,
            ];
        }

        $norm = self::normalizeFactBag($interviewFacts);

        return [
            'mode' => 'single',
            'patient_evidence_text' => trim((string) ($interviewFacts['patient_evidence_text'] ?? '')),
            'clinical' => $norm['clinical'],
            'interview_only' => $norm['interview_only'],
            'tracks' => [],
        ];
    }

    /**
     * Gap-fill existing extractAll feature slots from structured clinical evidence.
     * Never overwrites non-empty text-extracted features (preserves OLD triage parity).
     * Does not apply WHO/red-flag predicates.
     *
     * Stage 2 Batch 5: multi-mode also builds per-track feature snapshots (no flatten /
     * no cross-track merge into the flat feature bag used by text-path scoring).
     *
     * @param array<string, mixed> $features
     * @param array<string, mixed> $evidence from normalizeInterviewEvidence()
     * @return array<string, mixed>
     */
    public static function seedFeaturesFromStructuredEvidence(array $features, array $evidence): array
    {
        if (($evidence['mode'] ?? 'empty') === 'empty') {
            $features['structured_track_features'] = [];

            return $features;
        }

        $mode = (string) ($evidence['mode'] ?? 'single');
        $clinical = is_array($evidence['clinical'] ?? null) ? $evidence['clinical'] : [];
        $interviewOnly = is_array($evidence['interview_only'] ?? null) ? $evidence['interview_only'] : [];

        // Flat feature bag: single bag, or active_facts only in multi (unchanged Batch 2 behavior).
        // Non-active tracks must NOT be merged here — that would invent cross-track combinations.
        $features = self::seedFeaturesFromClinicalBag($features, $clinical, $interviewOnly);

        // Per-track isolated snapshots for NEW structured coverage (Batch 5).
        $trackFeatures = [];
        if ($mode === 'multi') {
            $activeId = (string) ($evidence['active_complaint_id'] ?? '');
            foreach ((array) ($evidence['tracks'] ?? []) as $idx => $track) {
                if (!is_array($track)) {
                    continue;
                }
                $trackClinical = is_array($track['clinical'] ?? null) ? $track['clinical'] : [];
                $trackInterview = is_array($track['interview_only'] ?? null) ? $track['interview_only'] : [];
                $trackId = (string) ($track['complaint_id'] ?? ('track_' . $idx));
                $seeded = self::seedFeaturesFromClinicalBag([], $trackClinical, $trackInterview);
                $trackFeatures[] = self::buildTrackFeatureRecord(
                    $trackId,
                    (string) ($track['text_span'] ?? ''),
                    is_array($track['family_keys'] ?? null) ? $track['family_keys'] : [],
                    $trackClinical,
                    $seeded,
                    $activeId !== '' && $trackId === $activeId
                );
            }
        } else {
            // Single: one isolated record mirroring the flat bag (parity / inspection).
            $seeded = self::seedFeaturesFromClinicalBag([], $clinical, $interviewOnly);
            $trackFeatures[] = self::buildTrackFeatureRecord(
                'single',
                '',
                [],
                $clinical,
                $seeded,
                true
            );
        }

        $features['structured_track_features'] = $trackFeatures;

        return $features;
    }

    /**
     * Seed existing feature extractors from one clinical bag (no new rules/thresholds).
     *
     * @param array<string, mixed> $features
     * @param array<string, mixed> $clinical
     * @param array<string, mixed> $interviewOnly
     * @return array<string, mixed>
     */
    public static function seedFeaturesFromClinicalBag(
        array $features,
        array $clinical,
        array $interviewOnly = []
    ): array {
        $polarity = is_array($clinical['polarity'] ?? null) ? $clinical['polarity'] : [];

        // Pain score → reuse extractPainScale banding via synthetic numeric phrase (no new thresholds).
        $pain = is_array($features['pain_scale'] ?? null) ? $features['pain_scale'] : [];
        if (($pain['score'] ?? null) === null && ($clinical['pain_score'] ?? null) !== null) {
            $features['pain_scale'] = ClinicalFeatureExtractors::extractPainScale(
                'pain ' . (int) $clinical['pain_score'] . '/10'
            );
        }

        $pq = trim((string) ($features['pain_qualifier'] ?? ''));
        $pqFact = trim((string) ($clinical['pain_qualifier'] ?? ''));
        if ($pq === '' && $pqFact !== '') {
            $features['pain_qualifier'] = $pqFact;
        }

        $onset = trim((string) ($features['onset'] ?? ''));
        $onsetFact = trim((string) ($clinical['onset'] ?? ''));
        if ($onset === '' && $onsetFact !== '' && $onsetFact !== 'uncertain') {
            $features['onset'] = $onsetFact;
        }

        $duration = is_array($features['duration'] ?? null) ? $features['duration'] : [];
        $durLabel = trim((string) ($duration['label'] ?? ''));
        $durFact = trim((string) ($clinical['duration_label'] ?? ''));
        if ($durLabel === '' && $durFact !== '') {
            $features['duration'] = ClinicalFeatureExtractors::extractDuration($durFact);
            if (trim((string) ($features['duration']['label'] ?? '')) === '') {
                $features['duration'] = array_merge(
                    ['raw' => $durFact, 'label' => $durFact, 'bucket' => 'unknown', 'days' => null, 'hours' => null],
                    is_array($features['duration'] ?? null) ? $features['duration'] : []
                );
                $features['duration']['label'] = $durFact;
                $features['duration']['raw'] = $durFact;
            }
        }

        $locs = is_array($features['body_locations'] ?? null) ? $features['body_locations'] : [];
        if ($locs === []) {
            $features['body_locations'] = self::evidenceStringList($clinical['body_locations'] ?? []);
        }

        $risks = is_array($features['risk_factors'] ?? null) ? $features['risk_factors'] : [];
        if ($risks === [] && ($polarity['pregnancy'] ?? null) === true) {
            $features['risk_factors'] = ClinicalFeatureExtractors::extractRiskFactors('pregnant', 'pregnant');
        }

        $temp = is_array($features['temperature'] ?? null) ? $features['temperature'] : [];
        if (trim((string) ($temp['modifier_key'] ?? '')) === '' && ($polarity['fever_confirmed'] ?? null) === true) {
            $features['temperature'] = ClinicalFeatureExtractors::extractTemperature('fever');
        }

        if (empty($features['denied_associated'])
            && !empty($interviewOnly['denied_associated'])
        ) {
            // denied_associated remains interview-oriented; only mirror if already empty.
            $features['denied_associated'] = true;
        }

        // Attach provenance snapshot (not used as patient-equivalent polarity).
        $features['structured_symptom_provenance'] = [
            'patient' => self::evidenceStringList($clinical['symptoms_patient'] ?? []),
            'kb' => self::evidenceStringList($clinical['symptoms_kb'] ?? []),
            'ai' => self::evidenceStringList($clinical['symptoms_ai'] ?? []),
            'negative' => self::evidenceStringList($clinical['negative_symptoms'] ?? []),
            'legacy' => self::evidenceStringList($clinical['symptoms_legacy'] ?? []),
        ];
        $features['structured_polarity'] = $polarity;

        return $features;
    }

    /**
     * Isolated per-track feature record for NEW structured coverage (Batch 5).
     * Does not invent triage rules for fields without existing consumers.
     *
     * @param list<string> $familyKeys
     * @param array<string, mixed> $clinical
     * @param array<string, mixed> $seededFeatures
     * @return array<string, mixed>
     */
    public static function buildTrackFeatureRecord(
        string $trackId,
        string $textSpan,
        array $familyKeys,
        array $clinical,
        array $seededFeatures,
        bool $isActive
    ): array {
        $polarity = is_array($clinical['polarity'] ?? null) ? $clinical['polarity'] : [];

        return [
            'track_id' => $trackId,
            'text_span' => $textSpan,
            'family_keys' => array_values(array_map('strval', $familyKeys)),
            'is_active' => $isActive,
            'seeded_features' => [
                'pain_scale' => $seededFeatures['pain_scale'] ?? null,
                'pain_qualifier' => $seededFeatures['pain_qualifier'] ?? '',
                'onset' => $seededFeatures['onset'] ?? '',
                'duration' => $seededFeatures['duration'] ?? null,
                'body_locations' => $seededFeatures['body_locations'] ?? [],
                'risk_factors' => $seededFeatures['risk_factors'] ?? [],
                'temperature' => $seededFeatures['temperature'] ?? null,
                'denied_associated' => !empty($seededFeatures['denied_associated']),
            ],
            'structured_polarity' => $polarity,
            'provenance' => is_array($seededFeatures['structured_symptom_provenance'] ?? null)
                ? $seededFeatures['structured_symptom_provenance']
                : [
                    'patient' => [],
                    'kb' => [],
                    'ai' => [],
                    'negative' => [],
                    'legacy' => [],
                ],
            // Available as structured evidence; no new independent triage rules.
            'available_structured_fields' => [
                'progression' => trim((string) ($clinical['progression'] ?? '')),
                'vital_signs' => self::evidenceStringList($clinical['vital_signs'] ?? []),
                'medical_history' => self::evidenceStringList($clinical['medical_history'] ?? []),
                'finding_status' => is_array($clinical['finding_status'] ?? null)
                    ? $clinical['finding_status']
                    : [],
                'associated_symptoms' => self::evidenceStringList($clinical['associated_symptoms'] ?? []),
                'symptoms_patient' => self::evidenceStringList($clinical['symptoms_patient'] ?? []),
            ],
            'field_consumer_status' => [
                'pain_score' => 'seeds_existing_pain_scale',
                'pain_qualifier' => 'seeds_existing_qualifier',
                'onset' => 'seeds_existing_onset',
                'duration_label' => 'seeds_existing_duration',
                'body_locations' => 'seeds_existing_body_locations',
                'pregnancy' => 'seeds_existing_risk_factors_when_true',
                'fever_confirmed' => 'seeds_existing_temperature_when_true',
                'progression' => 'stored_no_independent_triage_consumer',
                'vital_signs' => 'stored_no_independent_triage_consumer',
                'medical_history' => 'stored_no_independent_triage_consumer',
                'finding_status' => 'stored_no_independent_triage_consumer',
                'associated_symptoms' => 'stored_no_independent_triage_consumer',
                'symptoms_patient' => 'provenance_eligible_not_injected_into_patient_text',
                'symptoms_kb' => 'provenance_only_not_patient_equivalent',
                'symptoms_ai' => 'provenance_only_not_patient_equivalent',
                'symptoms_legacy' => 'provenance_only_not_patient_equivalent',
            ],
            'cross_track_merged' => false,
        ];
    }

    /**
     * Stage 2 Batch 3: collect per-track polarity units (track isolation; no cross-track combo).
     *
     * @param array<string, mixed> $evidence from normalizeInterviewEvidence()
     * @return list<array<string, mixed>>
     */
    public static function collectPolarityUnits(array $evidence): array
    {
        $units = [];
        $mode = (string) ($evidence['mode'] ?? 'empty');
        if ($mode === 'empty') {
            // Still emit an empty clinical bag so unset polarity is observable as unknown.
            $units[] = [
                'track_id' => 'empty',
                'text_span' => '',
                'clinical' => self::normalizeFactBag([])['clinical'],
            ];

            return $units;
        }

        if ($mode === 'multi') {
            foreach ((array) ($evidence['tracks'] ?? []) as $idx => $track) {
                if (!is_array($track)) {
                    continue;
                }
                $clinical = is_array($track['clinical'] ?? null) ? $track['clinical'] : [];
                $units[] = [
                    'track_id' => (string) ($track['complaint_id'] ?? ('track_' . $idx)),
                    'text_span' => (string) ($track['text_span'] ?? ''),
                    'clinical' => $clinical,
                ];
            }

            return $units;
        }

        $units[] = [
            'track_id' => 'single',
            'text_span' => '',
            'clinical' => is_array($evidence['clinical'] ?? null) ? $evidence['clinical'] : [],
        ];

        return $units;
    }

    /**
     * Stage 2 Batch 3: shadow/candidate polarity → existing consumers.
     * OLD production display is never mutated. No new medical rules or thresholds.
     *
     * @param array<string, mixed> $evidence from normalizeInterviewEvidence()
     * @return array<string, mixed>
     */
    public static function evaluateStructuredPolarityShadow(
        array $evidence,
        string $oldProductionText = '',
        string $productionDisplay = ''
    ): array {
        $bridge = self::polarityConsumerBridge();
        $oldHay = strtolower(trim($oldProductionText));
        $units = self::collectPolarityUnits($evidence);

        $hits = [];
        $suppressed = [];
        $unknown = [];
        $softHits = [];
        $trackSummaries = [];
        $dedupeNotes = [];
        $provenanceBlocked = [];

        foreach ($units as $unit) {
            $trackId = (string) ($unit['track_id'] ?? 'single');
            $clinical = is_array($unit['clinical'] ?? null) ? $unit['clinical'] : [];
            $polarity = is_array($clinical['polarity'] ?? null) ? $clinical['polarity'] : [];
            $negSymptoms = array_map(
                static fn (string $s): string => strtolower(trim($s)),
                self::evidenceStringList($clinical['negative_symptoms'] ?? [])
            );
            // Provenance guard: KB/AI/legacy symptom names never invent polarity.
            $kbNames = self::evidenceStringList($clinical['symptoms_kb'] ?? []);
            $aiNames = self::evidenceStringList($clinical['symptoms_ai'] ?? []);
            $legacyNames = self::evidenceStringList($clinical['symptoms_legacy'] ?? []);
            foreach (array_merge($kbNames, $aiNames, $legacyNames) as $label) {
                $provenanceBlocked[] = [
                    'track_id' => $trackId,
                    'source_label' => $label,
                    'note' => 'non_patient_symptom_name_ignored_for_polarity',
                ];
            }

            $trackHits = [];
            $trackSuppressed = [];

            foreach (self::clinicalPolarityKeys() as $key) {
                $meta = $bridge[$key] ?? null;
                if (!is_array($meta)) {
                    continue;
                }
                $hasKey = array_key_exists($key, $polarity);
                $value = $hasKey ? $polarity[$key] : null;

                if ($value === null) {
                    // negative_symptoms may suppress an equivalent meaning when polarity is unknown.
                    if (self::negativeSymptomsSuppressKey($negSymptoms, $meta)) {
                        $row = [
                            'track_id' => $trackId,
                            'key' => $key,
                            'reason' => 'negative_symptoms',
                            'consumer' => (string) ($meta['consumer'] ?? ''),
                        ];
                        $suppressed[] = $row;
                        $trackSuppressed[] = $row;
                    } else {
                        $unknown[] = ['track_id' => $trackId, 'key' => $key];
                    }
                    continue;
                }

                if ($value === false) {
                    $row = [
                        'track_id' => $trackId,
                        'key' => $key,
                        'reason' => 'explicit_false',
                        'consumer' => (string) ($meta['consumer'] ?? ''),
                        'negation_concepts' => (array) ($meta['negation_concepts'] ?? []),
                    ];
                    $suppressed[] = $row;
                    $trackSuppressed[] = $row;
                    continue;
                }

                // true — positive structured evidence (patient interview polarity only).
                $phrases = (array) ($meta['synthetic_phrases'] ?? []);
                $alsoInHaystack = false;
                foreach ($phrases as $phrase) {
                    $phrase = strtolower(trim((string) $phrase));
                    if ($phrase !== '' && $oldHay !== '' && str_contains($oldHay, $phrase)) {
                        $alsoInHaystack = true;
                        break;
                    }
                }
                if ($alsoInHaystack) {
                    $dedupeNotes[] = [
                        'track_id' => $trackId,
                        'key' => $key,
                        'note' => 'same_clinical_meaning_present_in_haystack_and_structured',
                        'synthetic_phrases' => $phrases,
                    ];
                }

                $hit = [
                    'track_id' => $trackId,
                    'key' => $key,
                    'value' => true,
                    'consumer' => (string) ($meta['consumer'] ?? ''),
                    'strength' => (string) ($meta['strength'] ?? 'soft'),
                    'triage_level' => (string) ($meta['triage_level'] ?? ''),
                    'who_rule_ids' => array_values(array_map('strval', (array) ($meta['who_rule_ids'] ?? []))),
                    'also_in_haystack' => $alsoInHaystack,
                    'evidence_source' => 'structured_polarity',
                ];

                if (($meta['strength'] ?? '') === 'hard') {
                    $hits[] = $hit;
                    $trackHits[] = $hit;
                } else {
                    $softHits[] = $hit;
                }
            }

            $trackSummaries[] = [
                'track_id' => $trackId,
                'text_span' => (string) ($unit['text_span'] ?? ''),
                'hard_hits' => $trackHits,
                'suppressed' => $trackSuppressed,
                // Per-track candidate level (not used as MAX-of-track production authority).
                'track_candidate_display' => self::shadowDisplayFromHits($trackHits),
            ];
        }

        $candidateDisplay = self::shadowDisplayFromHits($hits);
        $whoRuleIds = [];
        $redFlagsCandidate = [];
        foreach ($hits as $hit) {
            foreach ((array) ($hit['who_rule_ids'] ?? []) as $rid) {
                $rid = trim((string) $rid);
                if ($rid !== '') {
                    $whoRuleIds[] = $rid;
                }
            }
            if (($hit['consumer'] ?? '') === 'red_flag_breathing') {
                $redFlagsCandidate[] = [
                    'flag_id' => 'STRUCT_BREATH_' . strtoupper((string) ($hit['track_id'] ?? 'X')),
                    'flag_name' => 'Difficulty breathing (structured polarity)',
                    'category' => 'respiratory',
                    'auto_triage' => 'EMERGENCY',
                    'matched_on' => 'structured_polarity:breathing_difficulty',
                    'source' => 'structured_polarity_shadow',
                    'track_id' => (string) ($hit['track_id'] ?? ''),
                    'evidence_source' => 'structured_polarity',
                ];
            }
        }
        $whoRuleIds = array_values(array_unique($whoRuleIds));

        $comboShadow = self::evaluateStructuredWhoComboShadow($evidence);
        // Batch 8: WHO/RF combo hits must respect Batch 3 structured negation suppressions.
        $comboShadow = self::applyNegationParityToWhoComboShadow($comboShadow, $suppressed);
        $comboDisplay = (string) ($comboShadow['triage_display_candidate'] ?? 'NON-URGENT');
        $candidateDisplay = self::maxDisplay($candidateDisplay, $comboDisplay);
        foreach ((array) ($comboShadow['who_rule_ids_candidate'] ?? []) as $rid) {
            $rid = trim((string) $rid);
            if ($rid !== '') {
                $whoRuleIds[] = $rid;
            }
        }
        $whoRuleIds = array_values(array_unique($whoRuleIds));

        // Drop WHO ids that only came from polarity keys suppressed on the same track.
        $whoRuleIds = self::filterWhoIdsAgainstSuppressedPolarity($whoRuleIds, $hits, $suppressed);

        return [
            'mode' => 'shadow_candidate',
            'authority' => false,
            'patient_evidence_text' => (string) ($evidence['patient_evidence_text'] ?? ''),
            'evidence_mode' => (string) ($evidence['mode'] ?? 'empty'),
            'production_display' => $productionDisplay,
            'triage_display_candidate' => $candidateDisplay,
            'who_rule_ids_candidate' => $whoRuleIds,
            'red_flags_candidate' => $redFlagsCandidate,
            'hard_hits' => $hits,
            'soft_hits' => $softHits,
            'suppressed' => $suppressed,
            'unknown_keys' => $unknown,
            'dedupe_notes' => $dedupeNotes,
            'provenance_blocked' => $provenanceBlocked,
            'track_summaries' => $trackSummaries,
            'bridge_keys' => array_keys($bridge),
            'who_combo_shadow' => $comboShadow,
            'finding_status_shadow' => self::findingStatusShadowDeferredReport(),
            'negation_parity' => [
                'suppressed_count' => count($suppressed),
                'combo_hits_removed' => (array) ($comboShadow['negation_removed_hits'] ?? []),
                'uncertain_not_treated_as_negative' => true,
            ],
        ];
    }

    /**
     * Stage 2 Batch 6/7: track-safe structured evaluation of EXISTING WHO/IITT combo meanings.
     * Shadow only — does not change CSV, thresholds, or production WhoIittTriageRulesLoader::evaluate.
     *
     * Implemented existing rule meanings:
     * - IITT-R010 pregnant + heavy bleeding (same track)
     * - IITT-R011 pregnant + seizure/AMS companion when represented in patient symptoms (same track)
     * - IITT-R012 pregnant + vision_change polarity and/or severe+headache patient evidence (same track)
     * - IITT-R014 meningism COMBO2 (≥2 atoms same track); fever alone insufficient
     * - IITT-R013 chest location + breathing_difficulty (same track) — Batch 7
     * - IITT-Y001 severe pain via existing pain_score/qualifier bands (per track) — Batch 7
     *
     * @param array<string, mixed> $evidence from normalizeInterviewEvidence()
     * @return array<string, mixed>
     */
    public static function evaluateStructuredWhoComboShadow(array $evidence): array
    {
        $units = self::collectPolarityUnits($evidence);
        $hits = [];
        $rejectedCrossTrack = [];
        $missingNotes = [];
        $atomAudit = [];

        // Document atoms not yet represented as first-class structured polarity keys.
        $missingNotes[] = [
            'rule_id' => 'IITT-R014',
            'missing_structured_fields' => ['stiff_neck_polarity', 'altered_mental_status_polarity'],
            'note' => 'COMBO2 uses only atoms available in structured clinical evidence; missing atoms cannot be invented',
        ];
        $missingNotes[] = [
            'rule_id' => 'IITT-R011',
            'missing_structured_fields' => ['seizure_polarity', 'altered_mental_status_polarity'],
            'note' => 'Companion may only come from patient-authored symptoms_patient concepts when present; KB/AI ignored',
        ];

        foreach ($units as $unit) {
            $trackId = (string) ($unit['track_id'] ?? 'single');
            $clinical = is_array($unit['clinical'] ?? null) ? $unit['clinical'] : [];
            $polarity = is_array($clinical['polarity'] ?? null) ? $clinical['polarity'] : [];

            $pregnancy = self::structuredPolarityState($polarity, 'pregnancy');
            $bleedingHeavy = self::structuredPolarityState($polarity, 'bleeding_heavy');
            $vision = self::structuredPolarityState($polarity, 'vision_change');
            $fever = self::structuredPolarityState($polarity, 'fever_confirmed');
            $breathing = self::structuredPolarityState($polarity, 'breathing_difficulty');
            $chestLocation = self::clinicalHasChestBodyLocation($clinical);
            $severePain = self::clinicalMeetsExistingSeverePainBand($clinical);

            $headache = self::patientAuthoredConceptState($clinical, ['headache']);
            $seizureOrAms = self::patientAuthoredConceptState($clinical, [
                'seizure', 'convulsion', 'convulsions', 'confusion', 'altered mental status',
            ]);

            $atomAudit[] = [
                'track_id' => $trackId,
                'pregnancy' => $pregnancy,
                'bleeding_heavy' => $bleedingHeavy,
                'vision_change' => $vision,
                'fever_confirmed' => $fever,
                'headache_patient' => $headache,
                'seizure_or_ams_patient' => $seizureOrAms,
                'breathing_difficulty' => $breathing,
                'chest_body_location' => $chestLocation,
                'severe_pain_existing_band' => $severePain,
                'pain_score' => $clinical['pain_score'] ?? null,
                'pain_qualifier' => trim((string) ($clinical['pain_qualifier'] ?? '')),
            ];

            // R010 — existing meaning: pregnant with heavy bleeding (same track only).
            if ($pregnancy === true && $bleedingHeavy === true) {
                $hits[] = self::whoComboHit(
                    'IITT-R010',
                    'Pregnant with heavy bleeding',
                    'EMERGENCY',
                    $trackId,
                    ['pregnancy', 'bleeding_heavy']
                );
            }

            // R011 — pregnant + seizure/AMS; companion only from patient-authored concepts.
            if ($pregnancy === true && $seizureOrAms === true) {
                $hits[] = self::whoComboHit(
                    'IITT-R011',
                    'Pregnant with seizures or altered mental status',
                    'EMERGENCY',
                    $trackId,
                    ['pregnancy', 'seizure_or_ams_patient']
                );
            }

            // R012 — pregnant + visual changes polarity OR severe headache (patient headache + severe qualifier).
            $r012Visual = $pregnancy === true && $vision === true;
            $r012Headache = $pregnancy === true
                && trim((string) ($clinical['pain_qualifier'] ?? '')) === 'severe'
                && $headache === true;
            if ($r012Visual || $r012Headache) {
                $atoms = ['pregnancy'];
                if ($r012Visual) {
                    $atoms[] = 'vision_change';
                }
                if ($r012Headache) {
                    $atoms[] = 'severe_headache_patient';
                }
                $hits[] = self::whoComboHit(
                    'IITT-R012',
                    'Pregnant with severe headache or visual changes',
                    'EMERGENCY',
                    $trackId,
                    $atoms
                );
            }

            // R014 — existing COMBO2 meaning: any two of AMS / stiff neck / fever / headache.
            $r014Positive = [];
            if ($fever === true) {
                $r014Positive[] = 'fever';
            }
            if ($headache === true) {
                $r014Positive[] = 'headache';
            }
            if (count($r014Positive) >= 2) {
                $hits[] = self::whoComboHit(
                    'IITT-R014',
                    'Meningism cluster (any two of AMS/stiff neck/fever/headache)',
                    'EMERGENCY',
                    $trackId,
                    $r014Positive
                );
            }

            // R013 — existing meaning: chest symptoms with respiratory distress (same track only).
            // Chest body location alone is NOT chest pain and does not fire R013.
            if ($breathing === true && $chestLocation) {
                $hits[] = self::whoComboHit(
                    'IITT-R013',
                    'Chest pain with respiratory distress',
                    'EMERGENCY',
                    $trackId,
                    ['breathing_difficulty', 'chest_body_location']
                );
            }

            // Y001 — existing severe-pain yellow meaning via existing extractor bands / severe qualifier.
            // Evaluated per track only — never MAX across tracks.
            if ($severePain) {
                $hits[] = self::whoComboHit(
                    'IITT-Y001',
                    'Severe pain without red criteria',
                    'URGENT',
                    $trackId,
                    ['severe_pain_existing_band']
                );
            }
        }

        // Explicit cross-track rejection notes for tests / parity (do not create hits).
        if (count($units) >= 2) {
            $byTrack = [];
            foreach ($atomAudit as $row) {
                $byTrack[(string) ($row['track_id'] ?? '')] = $row;
            }
            $ids = array_keys($byTrack);
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $a = $byTrack[$ids[$i]];
                    $b = $byTrack[$ids[$j]];
                    if (($a['pregnancy'] ?? null) === true && ($b['bleeding_heavy'] ?? null) === true) {
                        $rejectedCrossTrack[] = [
                            'rule_id' => 'IITT-R010',
                            'reason' => 'cross_track_combination_rejected',
                            'track_a' => $ids[$i],
                            'track_b' => $ids[$j],
                            'atoms' => ['pregnancy@' . $ids[$i], 'bleeding_heavy@' . $ids[$j]],
                        ];
                    }
                    if (($a['pregnancy'] ?? null) === true && ($b['vision_change'] ?? null) === true) {
                        $rejectedCrossTrack[] = [
                            'rule_id' => 'IITT-R012',
                            'reason' => 'cross_track_combination_rejected',
                            'track_a' => $ids[$i],
                            'track_b' => $ids[$j],
                            'atoms' => ['pregnancy@' . $ids[$i], 'vision_change@' . $ids[$j]],
                        ];
                    }
                    if (($a['fever_confirmed'] ?? null) === true && ($b['headache_patient'] ?? null) === true) {
                        $rejectedCrossTrack[] = [
                            'rule_id' => 'IITT-R014',
                            'reason' => 'cross_track_combination_rejected',
                            'track_a' => $ids[$i],
                            'track_b' => $ids[$j],
                            'atoms' => ['fever@' . $ids[$i], 'headache@' . $ids[$j]],
                        ];
                    }
                    if (($a['breathing_difficulty'] ?? null) === true && !empty($b['chest_body_location'])) {
                        $rejectedCrossTrack[] = [
                            'rule_id' => 'IITT-R013',
                            'reason' => 'cross_track_combination_rejected',
                            'track_a' => $ids[$i],
                            'track_b' => $ids[$j],
                            'atoms' => ['breathing_difficulty@' . $ids[$i], 'chest_body_location@' . $ids[$j]],
                        ];
                    }
                    if (!empty($a['chest_body_location']) && ($b['breathing_difficulty'] ?? null) === true) {
                        $rejectedCrossTrack[] = [
                            'rule_id' => 'IITT-R013',
                            'reason' => 'cross_track_combination_rejected',
                            'track_a' => $ids[$j],
                            'track_b' => $ids[$i],
                            'atoms' => ['breathing_difficulty@' . $ids[$j], 'chest_body_location@' . $ids[$i]],
                        ];
                    }
                    // Symmetric Batch 6 reverses
                    if (($b['pregnancy'] ?? null) === true && ($a['bleeding_heavy'] ?? null) === true) {
                        $rejectedCrossTrack[] = [
                            'rule_id' => 'IITT-R010',
                            'reason' => 'cross_track_combination_rejected',
                            'track_a' => $ids[$j],
                            'track_b' => $ids[$i],
                            'atoms' => ['pregnancy@' . $ids[$j], 'bleeding_heavy@' . $ids[$i]],
                        ];
                    }
                    if (($b['fever_confirmed'] ?? null) === true && ($a['headache_patient'] ?? null) === true) {
                        $rejectedCrossTrack[] = [
                            'rule_id' => 'IITT-R014',
                            'reason' => 'cross_track_combination_rejected',
                            'track_a' => $ids[$j],
                            'track_b' => $ids[$i],
                            'atoms' => ['fever@' . $ids[$j], 'headache@' . $ids[$i]],
                        ];
                    }
                    // Y001: document that cross-track MAX is rejected (each track evaluated alone).
                    if (!empty($a['severe_pain_existing_band']) && !empty($b['severe_pain_existing_band'])) {
                        $rejectedCrossTrack[] = [
                            'rule_id' => 'IITT-Y001',
                            'reason' => 'cross_track_max_pain_rejected',
                            'track_a' => $ids[$i],
                            'track_b' => $ids[$j],
                            'atoms' => ['severe_pain@' . $ids[$i], 'severe_pain@' . $ids[$j]],
                            'note' => 'Each track keeps its own Y001 candidate; no MAX-of-track authority',
                        ];
                    }
                }
            }
        }

        $whoIds = [];
        $best = 'NON-URGENT';
        foreach ($hits as $hit) {
            $whoIds[] = (string) ($hit['rule_id'] ?? '');
            $best = self::maxDisplay($best, (string) ($hit['triage_level'] ?? 'NON-URGENT'));
        }

        return [
            'mode' => 'shadow_who_combo',
            'authority' => false,
            'triage_display_candidate' => $best,
            'who_rule_ids_candidate' => array_values(array_unique(array_filter($whoIds))),
            'hits' => $hits,
            'cross_track_rejected' => $rejectedCrossTrack,
            'missing_structured_fields' => $missingNotes,
            'track_atom_audit' => $atomAudit,
            'max_of_track_authority' => false,
            'flattened_facts' => false,
            'finding_status_shadow' => self::findingStatusShadowDeferredReport(),
        ];
    }

    /**
     * Batch 8: finding_status has no unambiguous hard triage consumer beyond synthetic haystack
     * injection of soft "dizziness". Soft dizziness does not escalate WHO. Mapping deferred.
     *
     * @return array<string, mixed>
     */
    public static function findingStatusShadowDeferredReport(): array
    {
        return [
            'implemented' => false,
            'deferred' => true,
            'reason' => 'no_unambiguous_existing_triage_consumer',
            'note' => 'dizziness_with_chest only feeds synthetic haystack text; soft dizziness polarity has no hard WHO/RF consumer. Do not invent a mapping.',
            'authority' => false,
        ];
    }

    /**
     * Batch 8: remove WHO combo hits whose polarity atoms are structured-suppressed on the same track.
     * Uncertain (unknown) atoms are not treated as negative — only explicit false / negative_symptoms.
     *
     * @param array<string, mixed> $comboShadow
     * @param list<array<string, mixed>> $suppressed from Batch 3 polarity shadow
     * @return array<string, mixed>
     */
    public static function applyNegationParityToWhoComboShadow(array $comboShadow, array $suppressed): array
    {
        $supMap = [];
        foreach ($suppressed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tid = (string) ($row['track_id'] ?? '');
            $key = (string) ($row['key'] ?? '');
            if ($tid === '' || $key === '') {
                continue;
            }
            $supMap[$tid][$key] = (string) ($row['reason'] ?? 'suppressed');
        }

        $kept = [];
        $removed = [];
        foreach ((array) ($comboShadow['hits'] ?? []) as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $tid = (string) ($hit['track_id'] ?? '');
            $blockedKey = '';
            foreach ((array) ($hit['atoms'] ?? []) as $atom) {
                $pkey = self::whoComboAtomToPolarityKey((string) $atom);
                if ($pkey !== '' && !empty($supMap[$tid][$pkey])) {
                    $blockedKey = $pkey;
                    break;
                }
            }
            if ($blockedKey !== '') {
                $hit['removed_reason'] = 'structured_negation_parity';
                $hit['suppressed_polarity_key'] = $blockedKey;
                $removed[] = $hit;
                continue;
            }
            $kept[] = $hit;
        }

        $whoIds = [];
        $best = 'NON-URGENT';
        foreach ($kept as $hit) {
            $whoIds[] = (string) ($hit['rule_id'] ?? '');
            $best = self::maxDisplay($best, (string) ($hit['triage_level'] ?? 'NON-URGENT'));
        }

        $comboShadow['hits'] = $kept;
        $comboShadow['who_rule_ids_candidate'] = array_values(array_unique(array_filter($whoIds)));
        $comboShadow['triage_display_candidate'] = $best;
        $comboShadow['negation_removed_hits'] = $removed;
        $comboShadow['negation_parity_applied'] = true;

        return $comboShadow;
    }

    /**
     * Map WHO combo atom labels back to clinical polarity keys when applicable.
     */
    private static function whoComboAtomToPolarityKey(string $atom): string
    {
        $atom = strtolower(trim($atom));

        return match ($atom) {
            'pregnancy' => 'pregnancy',
            'bleeding_heavy' => 'bleeding_heavy',
            'vision_change' => 'vision_change',
            'fever', 'fever_confirmed' => 'fever_confirmed',
            'breathing_difficulty' => 'breathing_difficulty',
            default => '',
        };
    }

    /**
     * Defensive pass after combo negation parity. Hard hits never include false polarity;
     * combo who ids are already rebuilt by applyNegationParityToWhoComboShadow.
     *
     * @param list<string> $whoIds
     * @param list<array<string, mixed>> $hardHits
     * @param list<array<string, mixed>> $suppressed
     * @return list<string>
     */
    private static function filterWhoIdsAgainstSuppressedPolarity(
        array $whoIds,
        array $hardHits,
        array $suppressed
    ): array {
        unset($hardHits, $suppressed);

        return array_values(array_unique(array_filter(array_map(
            static fn ($rid): string => trim((string) $rid),
            $whoIds
        ), static fn (string $rid): bool => $rid !== '')));
    }

    /**
     * @param array<string, mixed|null> $polarity
     */
    private static function structuredPolarityState(array $polarity, string $key): ?bool
    {
        if (!array_key_exists($key, $polarity)) {
            return null;
        }
        $v = $polarity[$key];
        if ($v === true || $v === false) {
            return $v;
        }

        return null;
    }

    /**
     * Chest anatomical signal from structured body_locations only (lexicon-canonical when available).
     * Language-independent: does not match patient free-text phrases.
     *
     * @param array<string, mixed> $clinical
     */
    private static function clinicalHasChestBodyLocation(array $clinical): bool
    {
        foreach (self::evidenceStringList($clinical['body_locations'] ?? []) as $loc) {
            $low = strtolower(trim($loc));
            if ($low === '') {
                continue;
            }
            if ($low === 'chest' || $low === 'thorax') {
                return true;
            }
            if (class_exists('BodyLocationLexicon')) {
                try {
                    foreach (BodyLocationLexicon::extractCanonical($loc) as $canonical) {
                        $c = strtolower(trim((string) $canonical));
                        if ($c === 'chest' || $c === 'thorax') {
                            return true;
                        }
                    }
                } catch (Throwable) {
                    // fall through
                }
            }
        }

        return false;
    }

    /**
     * Existing severe-pain interpretation only (extractor bands + severe qualifier).
     * Does not invent new numeric thresholds beyond extractPainScale banding.
     *
     * @param array<string, mixed> $clinical
     */
    private static function clinicalMeetsExistingSeverePainBand(array $clinical): bool
    {
        if (trim((string) ($clinical['pain_qualifier'] ?? '')) === 'severe') {
            return true;
        }
        $score = $clinical['pain_score'] ?? null;
        if ($score === null || $score === '') {
            return false;
        }
        if (!class_exists('ClinicalFeatureExtractors')) {
            return false;
        }
        $scaled = ClinicalFeatureExtractors::extractPainScale('pain ' . (int) $score . '/10');

        return strtolower(trim((string) ($scaled['band'] ?? ''))) === 'severe';
    }

    /**
     * Patient-authored concept presence from symptoms_patient / negative_symptoms only.
     * Never reads symptoms_kb, symptoms_ai, or legacy mixed symptoms.
     *
     * @param array<string, mixed> $clinical
     * @param list<string> $concepts English medical concept tokens (NLP-normalized); not language phrase lists.
     */
    private static function patientAuthoredConceptState(array $clinical, array $concepts): ?bool
    {
        $concepts = array_values(array_filter(array_map(
            static fn ($c): string => strtolower(trim((string) $c)),
            $concepts
        )));
        if ($concepts === []) {
            return null;
        }

        $neg = array_map(
            static fn (string $s): string => strtolower(trim($s)),
            self::evidenceStringList($clinical['negative_symptoms'] ?? [])
        );
        foreach ($neg as $n) {
            foreach ($concepts as $concept) {
                if ($n === $concept || str_contains($n, $concept) || str_contains($concept, $n)) {
                    return false;
                }
            }
        }

        $patient = array_map(
            static fn (string $s): string => strtolower(trim($s)),
            self::evidenceStringList($clinical['symptoms_patient'] ?? [])
        );
        foreach ($patient as $p) {
            foreach ($concepts as $concept) {
                if ($p === $concept || str_contains($p, $concept)) {
                    return true;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $atoms
     * @return array<string, mixed>
     */
    private static function whoComboHit(
        string $ruleId,
        string $clinicalSign,
        string $triageLevel,
        string $trackId,
        array $atoms
    ): array {
        return [
            'rule_id' => $ruleId,
            'clinical_sign' => $clinicalSign,
            'triage_level' => $triageLevel,
            'track_id' => $trackId,
            'atoms' => array_values($atoms),
            'evidence_source' => 'structured_who_combo',
            'authority' => false,
            'red_flag' => strtoupper($triageLevel) === 'EMERGENCY',
            'source' => 'WHO IITT',
            'source_reference' => 'who_iitt_triage_rules.csv (structured shadow; definitions unchanged)',
        ];
    }

    /**
     * @param list<string> $negSymptoms lowercased
     * @param array<string, mixed> $meta bridge entry
     */
    private static function negativeSymptomsSuppressKey(array $negSymptoms, array $meta): bool
    {
        if ($negSymptoms === []) {
            return false;
        }
        $concepts = array_map(
            static fn ($c): string => strtolower(trim((string) $c)),
            (array) ($meta['negation_concepts'] ?? [])
        );
        foreach ($concepts as $concept) {
            if ($concept === '') {
                continue;
            }
            foreach ($negSymptoms as $neg) {
                if ($neg === '') {
                    continue;
                }
                if ($neg === $concept || str_contains($concept, $neg) || str_contains($neg, $concept)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $hits
     */
    private static function shadowDisplayFromHits(array $hits): string
    {
        $best = 'NON-URGENT';
        foreach ($hits as $hit) {
            $level = strtoupper(trim((string) ($hit['triage_level'] ?? '')));
            if ($level === 'EMERGENCY') {
                return 'EMERGENCY';
            }
            if ($level === 'URGENT' && $best !== 'EMERGENCY') {
                $best = 'URGENT';
            }
        }

        return $best;
    }

    /**
     * Stage 2 Batch 4: attach OLD-vs-NEW shadow comparison (never mutates production display).
     *
     * @param array<string, mixed> $oldResult
     * @param array<string, mixed> $interviewFacts
     * @param array<string, mixed> $structuredEvidence
     * @return array<string, mixed>
     */
    private static function attachTriagePathComparison(
        array $oldResult,
        array $interviewFacts,
        array $structuredEvidence,
        bool $runPathComparison
    ): array {
        if (!$runPathComparison || $interviewFacts === []) {
            return $oldResult;
        }

        $patientText = trim((string) (
            $interviewFacts['patient_evidence_text']
            ?? $structuredEvidence['patient_evidence_text']
            ?? ''
        ));

        // Purified NEW assess: patient text only + same structured pack. No nested comparison.
        $newRaw = self::assess(
            $patientText,
            $patientText,
            [],
            [],
            0,
            false,
            $interviewFacts,
            false
        );

        $oldResult['triage_path_comparison'] = self::buildTriagePathComparison(
            $oldResult,
            $newRaw,
            $patientText,
            $interviewFacts,
            $structuredEvidence
        );

        // Hard guarantee: production fields remain the OLD assess result.
        return $oldResult;
    }

    /**
     * Stage 2 Batch 4: compare production (OLD) vs purified+structured shadow (NEW).
     *
     * @param array<string, mixed> $oldResult
     * @param array<string, mixed> $newResult purified-text assess output
     * @param array<string, mixed> $interviewFacts
     * @param array<string, mixed> $structuredEvidence
     * @return array<string, mixed>
     */
    public static function buildTriagePathComparison(
        array $oldResult,
        array $newResult,
        string $patientEvidenceText,
        array $interviewFacts = [],
        array $structuredEvidence = []
    ): array {
        if ($structuredEvidence === []) {
            $structuredEvidence = is_array($newResult['structured_clinical_evidence'] ?? null)
                ? $newResult['structured_clinical_evidence']
                : self::normalizeInterviewEvidence($interviewFacts);
        }

        $polarityShadow = is_array($newResult['structured_polarity_shadow'] ?? null)
            ? $newResult['structured_polarity_shadow']
            : self::evaluateStructuredPolarityShadow(
                $structuredEvidence,
                $patientEvidenceText,
                (string) ($newResult['triage_display'] ?? '')
            );

        $whoComboShadow = is_array($polarityShadow['who_combo_shadow'] ?? null)
            ? $polarityShadow['who_combo_shadow']
            : (is_array($newResult['structured_who_combo_shadow'] ?? null)
                ? $newResult['structured_who_combo_shadow']
                : self::evaluateStructuredWhoComboShadow($structuredEvidence));

        $oldDisplay = strtoupper((string) ($oldResult['triage_display'] ?? 'NON-URGENT'));
        $purifiedDisplay = strtoupper((string) ($newResult['triage_display'] ?? 'NON-URGENT'));
        $polarityDisplay = strtoupper((string) ($polarityShadow['triage_display_candidate'] ?? 'NON-URGENT'));
        $comboDisplay = strtoupper((string) ($whoComboShadow['triage_display_candidate'] ?? 'NON-URGENT'));
        // Compose NEW candidate from purified-text + polarity shadow + WHO combo shadow (no new rules).
        $newCandidate = self::maxDisplay(
            self::maxDisplay($purifiedDisplay, $polarityDisplay),
            $comboDisplay
        );

        $oldWho = self::extractWhoRuleIds($oldResult);
        $newWhoText = self::extractWhoRuleIds($newResult);
        // Full purified-text WHO matches (all hits), for double-fire dedupe — does not change OLD.
        $purifiedWhoAll = $newWhoText;
        if ($patientEvidenceText !== '' && class_exists('WhoIittTriageRulesLoader')) {
            try {
                $fullWho = WhoIittTriageRulesLoader::evaluate($patientEvidenceText, $patientEvidenceText);
                if (is_array($fullWho)) {
                    $rid = trim((string) ($fullWho['rule_id'] ?? ''));
                    if ($rid !== '') {
                        $purifiedWhoAll[] = $rid;
                    }
                    foreach ((array) ($fullWho['matched_rules'] ?? []) as $rule) {
                        if (!is_array($rule)) {
                            continue;
                        }
                        $id = trim((string) ($rule['rule_id'] ?? ''));
                        if ($id !== '') {
                            $purifiedWhoAll[] = $id;
                        }
                    }
                }
            } catch (Throwable) {
                // keep primary ids only
            }
        }
        $purifiedWhoAll = array_values(array_unique($purifiedWhoAll));

        $newWhoPolarity = array_values(array_map(
            'strval',
            (array) ($polarityShadow['who_rule_ids_candidate'] ?? [])
        ));
        $newWhoCombo = array_values(array_map(
            'strval',
            (array) ($whoComboShadow['who_rule_ids_candidate'] ?? [])
        ));

        // Batch 8: if combo shadow was rebuilt without polarity negation, apply suppressions now.
        if (
            empty($whoComboShadow['negation_parity_applied'])
            && (array) ($polarityShadow['suppressed'] ?? []) !== []
        ) {
            $whoComboShadow = self::applyNegationParityToWhoComboShadow(
                $whoComboShadow,
                (array) ($polarityShadow['suppressed'] ?? [])
            );
            $newWhoCombo = array_values(array_map(
                'strval',
                (array) ($whoComboShadow['who_rule_ids_candidate'] ?? [])
            ));
            $comboDisplay = strtoupper((string) ($whoComboShadow['triage_display_candidate'] ?? 'NON-URGENT'));
            $newCandidate = self::maxDisplay(
                self::maxDisplay($purifiedDisplay, $polarityDisplay),
                $comboDisplay
            );
        }

        // Double-fire: same existing WHO rule from purified text + structured = one logical candidate.
        $comboHits = [];
        foreach ((array) ($whoComboShadow['hits'] ?? []) as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $rid = trim((string) ($hit['rule_id'] ?? ''));
            $sources = ['structured'];
            if ($rid !== '' && in_array($rid, $purifiedWhoAll, true)) {
                $sources[] = 'purified_text';
                $hit['also_in_purified_text'] = true;
            } else {
                $hit['also_in_purified_text'] = false;
            }
            $hit['logical_sources'] = $sources;
            $comboHits[] = $hit;
        }
        $whoComboShadow['hits'] = $comboHits;

        $newWho = array_values(array_unique(array_merge($purifiedWhoAll, $newWhoPolarity, $newWhoCombo)));

        // Batch 8: measurement-only OLD vs NEW WHO parity (does not alter triage decisions).
        $matchedByBoth = array_values(array_intersect($oldWho, $newWho));
        $oldOnlyWho = array_values(array_diff($oldWho, $newWho));
        $newOnlyWho = array_values(array_diff($newWho, $oldWho));
        $whoSourceMap = [];
        foreach ($newWho as $rid) {
            $rid = trim((string) $rid);
            if ($rid === '') {
                continue;
            }
            $srcs = [];
            if (in_array($rid, $purifiedWhoAll, true)) {
                $srcs[] = 'purified_patient_text';
            }
            if (in_array($rid, $newWhoPolarity, true)) {
                $srcs[] = 'structured_polarity_shadow';
            }
            if (in_array($rid, $newWhoCombo, true)) {
                $srcs[] = 'structured_who_combo_shadow';
            }
            $whoSourceMap[$rid] = $srcs;
        }
        $whoParity = [
            'matched_by_both' => $matchedByBoth,
            'old_only' => $oldOnlyWho,
            'new_only' => $newOnlyWho,
            'new_sources' => $whoSourceMap,
            'structured_shadow_source_ids' => array_values(array_unique(array_merge(
                $newWhoPolarity,
                $newWhoCombo
            ))),
            'purified_patient_text_source_ids' => $purifiedWhoAll,
            'measurement_only' => true,
            'alters_triage' => false,
            'finding_status_shadow' => is_array($polarityShadow['finding_status_shadow'] ?? null)
                ? $polarityShadow['finding_status_shadow']
                : self::findingStatusShadowDeferredReport(),
            'negation_parity' => (array) ($polarityShadow['negation_parity'] ?? [
                'suppressed_count' => count((array) ($polarityShadow['suppressed'] ?? [])),
                'uncertain_not_treated_as_negative' => true,
            ]),
        ];

        $oldFlags = array_values(array_map(
            'strval',
            (array) ($oldResult['red_flags'] ?? $oldResult['emergency_flags'] ?? [])
        ));
        $newFlags = [];
        foreach ((array) ($polarityShadow['red_flags_candidate'] ?? []) as $flag) {
            if (is_array($flag)) {
                $name = trim((string) ($flag['flag_name'] ?? $flag['flag_id'] ?? ''));
                if ($name !== '') {
                    $newFlags[] = $name;
                }
            } elseif (is_string($flag) && trim($flag) !== '') {
                $newFlags[] = trim($flag);
            }
        }
        foreach ((array) ($newResult['red_flags'] ?? []) as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $newFlags[] = $name;
            }
        }
        $newFlags = array_values(array_unique($newFlags));

        $oldPri = self::displayPriority($oldDisplay);
        $newPri = self::displayPriority($newCandidate);

        $trackSummaries = (array) ($polarityShadow['track_summaries'] ?? []);
        $evidenceMode = (string) ($structuredEvidence['mode'] ?? '');
        $trackCount = count((array) ($structuredEvidence['tracks'] ?? []));

        $clinicalTop = is_array($structuredEvidence['clinical'] ?? null)
            ? $structuredEvidence['clinical']
            : [];
        $kb = self::evidenceStringList($clinicalTop['symptoms_kb'] ?? []);
        $ai = self::evidenceStringList($clinicalTop['symptoms_ai'] ?? []);
        // Multi: collect KB/AI across tracks for provenance checks.
        foreach ((array) ($structuredEvidence['tracks'] ?? []) as $track) {
            if (!is_array($track)) {
                continue;
            }
            $c = is_array($track['clinical'] ?? null) ? $track['clinical'] : [];
            $kb = array_values(array_unique(array_merge(
                $kb,
                self::evidenceStringList($c['symptoms_kb'] ?? [])
            )));
            $ai = array_values(array_unique(array_merge(
                $ai,
                self::evidenceStringList($c['symptoms_ai'] ?? [])
            )));
        }

        $patientLow = mb_strtolower($patientEvidenceText);
        $kbLeak = [];
        $aiLeak = [];
        foreach ($kb as $label) {
            $l = mb_strtolower(trim($label));
            if ($l !== '' && mb_strlen($l) >= 4 && str_contains($patientLow, $l)) {
                // Patient may coincidentally share wording; only flag exact long KB labels as leak risk notes.
                $kbLeak[] = $label;
            }
        }
        foreach ($ai as $label) {
            $l = mb_strtolower(trim($label));
            if ($l !== '' && mb_strlen($l) >= 4 && str_contains($patientLow, $l)) {
                $aiLeak[] = $label;
            }
        }

        $haystackMarkersPresent = self::newTextContainsSyntheticMarkers($patientEvidenceText);

        $trackFeatures = [];
        if (is_array($newResult['structured_track_features'] ?? null)) {
            $trackFeatures = $newResult['structured_track_features'];
        } elseif (is_array($structuredEvidence['track_features'] ?? null)) {
            $trackFeatures = $structuredEvidence['track_features'];
        }

        $nonActiveWithSeeded = 0;
        foreach ($trackFeatures as $tf) {
            if (!is_array($tf) || !empty($tf['is_active'])) {
                continue;
            }
            $sf = is_array($tf['seeded_features'] ?? null) ? $tf['seeded_features'] : [];
            $hasSeed = ($sf['pain_scale']['score'] ?? null) !== null
                || trim((string) ($sf['onset'] ?? '')) !== ''
                || trim((string) ($sf['pain_qualifier'] ?? '')) !== ''
                || (is_array($sf['body_locations'] ?? null) && ($sf['body_locations'] ?? []) !== [])
                || (is_array($sf['duration'] ?? null) && trim((string) ($sf['duration']['label'] ?? '')) !== '');
            if ($hasSeed) {
                $nonActiveWithSeeded++;
            }
        }

        return [
            'authority_old' => true,
            'authority_new' => false,
            'old' => [
                'triage_display' => $oldDisplay,
                'who_rule_ids' => $oldWho,
                'red_flags' => $oldFlags,
                'input_kind' => 'production_context_or_haystack',
            ],
            'new' => [
                'authority' => false,
                'triage_display_candidate' => $newCandidate,
                'purified_text_display' => $purifiedDisplay,
                'polarity_shadow_display' => $polarityDisplay,
                'who_rule_ids_candidate' => $newWho,
                'who_rule_ids_from_purified_text' => $newWhoText,
                'who_rule_ids_from_polarity_shadow' => $newWhoPolarity,
                'red_flags_candidate' => $newFlags,
                'patient_evidence_text' => $patientEvidenceText,
                'structured_clinical_evidence' => $structuredEvidence,
                'structured_polarity_shadow' => $polarityShadow,
                'structured_who_combo_shadow' => $whoComboShadow,
                'structured_track_features' => $trackFeatures,
                'input_kind' => 'patient_evidence_text_plus_structured',
            ],
            'under_triage' => $newPri < $oldPri,
            'over_triage' => $newPri > $oldPri,
            'display_match' => $oldDisplay === $newCandidate,
            'who_parity' => $whoParity,
            'multi_track' => [
                'evidence_mode' => $evidenceMode,
                'track_count' => $trackCount,
                'track_summaries' => $trackSummaries,
                'track_feature_count' => count($trackFeatures),
                'uses_all_tracks_for_polarity' => $evidenceMode !== 'multi' || $trackCount === count($trackSummaries),
                'uses_all_tracks_for_features' => $evidenceMode !== 'multi' || $trackCount === count($trackFeatures),
                'non_active_tracks_with_seeded_features' => $nonActiveWithSeeded,
                'max_of_track_authority' => false,
                'flattened_facts' => false,
                'cross_track_feature_merge' => false,
                'who_combo_cross_track_rejected_count' => count((array) ($whoComboShadow['cross_track_rejected'] ?? [])),
            ],
            'provenance' => [
                'symptoms_kb_count' => count($kb),
                'symptoms_ai_count' => count($ai),
                'polarity_ignores_kb_ai' => true,
                'kb_labels_in_patient_text' => $kbLeak,
                'ai_labels_in_patient_text' => $aiLeak,
                'interview_only_separated' => isset($structuredEvidence['interview_only'])
                    || ($evidenceMode === 'multi' && $structuredEvidence !== []),
            ],
            'new_text_checks' => [
                'equals_patient_evidence_text' => true,
                'synthetic_haystack_markers_present' => $haystackMarkersPresent,
                'for_wrapper_absent' => !str_contains($patientEvidenceText, 'For "'),
                'qid_assignment_absent' => !preg_match('/\b[A-Z][A-Z0-9_]+=/u', $patientEvidenceText),
            ],
            // Existing architectural limitation: case-level patientEvidenceText may let
            // WHO/text matchers see multiple complaint narratives in one string.
            // Production unchanged; no new isolation rule invented in Batch 5.
            'case_level_patient_text' => [
                'is_case_level' => true,
                'may_combine_narratives_in_text_path' => true,
                'structured_tracks_remain_isolated' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private static function extractWhoRuleIds(array $result): array
    {
        $ids = [];
        $who = is_array($result['assessment_factors']['who_iitt'] ?? null)
            ? $result['assessment_factors']['who_iitt']
            : [];
        $rid = trim((string) ($who['rule_id'] ?? ''));
        if ($rid !== '') {
            $ids[] = $rid;
        }
        foreach ((array) ($who['matched_rules'] ?? []) as $rule) {
            if (is_array($rule)) {
                $id = trim((string) ($rule['rule_id'] ?? ''));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Detect multi-haystack / synthetic wrappers that must not appear on NEW patient text.
     */
    private static function newTextContainsSyntheticMarkers(string $patientEvidenceText): bool
    {
        if ($patientEvidenceText === '') {
            return false;
        }
        if (str_contains($patientEvidenceText, 'For "')) {
            return true;
        }
        // QID=answer chunks from multiFactsHaystackParts
        if (preg_match('/\b[A-Z]{2,}[A-Z0-9_]*=/u', $patientEvidenceText)) {
            return true;
        }

        return false;
    }
}
