<?php
/**
 * AI-Assisted Medical Assessment Engine — clinical triage decision support.
 *
 * Orchestrates NLP + evidence-based ClinicalTriageEngine.
 * Provides triage recommendation only — never diagnoses disease or prescribes medication.
 */

final class MedicalAssessmentEngine
{
    public const VERSION = '2.0';
    public const CONFIDENCE_REVIEW_THRESHOLD = 60;

    /**
     * @param list<string> $checkboxSymptoms
     * @return array<string, mixed>
     */
    public static function assess(string $chiefComplaint, array $checkboxSymptoms = []): array
    {
        SymptomEvidenceGate::resetPipelineState();
        $checkboxSymptoms = array_values(array_filter(array_map('trim', $checkboxSymptoms)));
        $combinedText = self::buildCombinedText($chiefComplaint, $checkboxSymptoms);

        if ($combinedText === '') {
            return self::emptyAssessment();
        }

        $nlpPipeline = self::runNlpPipeline($combinedText);
        $nlpResult = is_array($nlpPipeline['nlp_result'] ?? null) ? $nlpPipeline['nlp_result'] : [];
        $clinicalRec = is_array($nlpPipeline['clinical_recommendation'] ?? null)
            ? $nlpPipeline['clinical_recommendation']
            : [];
        $rawTriage = is_array($nlpPipeline['triage'] ?? null) ? $nlpPipeline['triage'] : [];

        $conditionMatch = MedicalConditionMatcher::match($nlpPipeline, $checkboxSymptoms);
        $detectedSymptoms = $rawTriage['detected_symptoms']
            ?? $clinicalRec['detected_symptoms']
            ?? $nlpResult['detected_symptoms']
            ?? [];
        if (!is_array($detectedSymptoms)) {
            $detectedSymptoms = [];
        }
        $detectedSymptoms = SymptomEvidenceGate::filterSymptomNames(
            $detectedSymptoms,
            trim($chiefComplaint),
            (string) ($nlpResult['corrected_text'] ?? ($nlpPipeline['corrected_input'] ?? '')),
            (string) ($nlpResult['english_translation'] ?? ($nlpPipeline['translated_english'] ?? ''))
        );
        $symptomEvidence = is_array($rawTriage['symptom_evidence'] ?? null)
            ? $rawTriage['symptom_evidence']
            : (is_array($nlpResult['symptom_evidence'] ?? null) ? $nlpResult['symptom_evidence'] : []);
        // Keep condition matcher output for provider context only — not as diagnosis.
        $possibleConditions = $conditionMatch['possible_conditions'];

        // Optional ML layer retained for provider reference only; triage classification
        // is driven by the rule-based ClinicalTriageEngine, not disease prediction.
        $mlLayer = self::runMlLayer($combinedText, $detectedSymptoms, $nlpResult);
        if (!empty($mlLayer['predictions'])) {
            $nlpPipeline['ml_predictions'] = $mlLayer['predictions'];
        }

        $severity = MedicalSeverityDetector::detect(
            $combinedText,
            (string) ($nlpResult['english_translation'] ?? ''),
            is_array($nlpResult['medical_concepts'] ?? null) ? $nlpResult['medical_concepts'] : [],
            (string) ($nlpResult['severity'] ?? ($rawTriage['severity'] ?? ''))
        );

        $confidence = MedicalConfidenceScorer::score($nlpPipeline, $detectedSymptoms, []);
        // Prefer CDS engine confidence when available
        if (isset($rawTriage['confidence_score']) || isset($clinicalRec['confidence'])) {
            $cdsConf = (int) ($rawTriage['confidence_score'] ?? $clinicalRec['confidence'] ?? 0);
            if ($cdsConf > 0) {
                $confidence['score'] = $cdsConf;
                $confidence['score_display'] = $cdsConf . '%';
                $confidence['level'] = $cdsConf >= 90 ? 'very_high' : ($cdsConf >= 75 ? 'high' : ($cdsConf >= self::CONFIDENCE_REVIEW_THRESHOLD ? 'moderate' : 'review_needed'));
                $confidence['level_label'] = match ($confidence['level']) {
                    'very_high' => 'Very High',
                    'high' => 'High',
                    'moderate' => 'Moderate',
                    default => 'Review Needed',
                };
            }
        }

        $triageMeta = MedicalRecommendationEngine::classify([
            'nlp_triage_level' => (string) ($nlpResult['triage_level'] ?? ($rawTriage['triage_level'] ?? 'LOW')),
            'severity'         => (string) ($severity['severity'] ?? ($rawTriage['severity'] ?? 'mild')),
            // Do not let disease-model triage override rule-based CDS acuity
            'ml_triage_level'  => '',
        ]);

        // Prefer CDS display/action from ClinicalTriageEngine
        if (!empty($rawTriage['triage_display'])) {
            $triageMeta['triage_display'] = (string) $rawTriage['triage_display'];
            $triageMeta['triage_classification'] = (string) ($rawTriage['triage_classification'] ?? $triageMeta['triage_classification']);
            $triageMeta['triage_level'] = (string) ($rawTriage['triage_level'] ?? $triageMeta['triage_level']);
            $triageMeta['triage_icon'] = (string) ($rawTriage['triage_icon'] ?? $triageMeta['triage_icon']);
            $triageMeta['recommended_action'] = (string) ($rawTriage['recommended_action'] ?? $triageMeta['recommended_action']);
            $triageMeta['db_level'] = match ($triageMeta['triage_display']) {
                'EMERGENCY' => '1',
                'URGENT' => '2',
                default => '3',
            };
            $triageMeta['urgency_label'] = match ($triageMeta['triage_display']) {
                'EMERGENCY' => 'Emergency (Immediate)',
                'URGENT' => 'Urgent (Priority)',
                default => 'Non-Urgent (Routine)',
            };
            $triageMeta['gis_triage_level'] = match ($triageMeta['triage_display']) {
                'EMERGENCY' => 'emergency',
                'URGENT' => 'urgent',
                default => 'non_urgent',
            };
        }

        $needsReview = (int) ($confidence['score'] ?? 0) < self::CONFIDENCE_REVIEW_THRESHOLD
            || !empty($rawTriage['needs_provider_review'])
            || !empty($clinicalRec['needs_provider_review']);

        if ($needsReview) {
            $triageMeta['recommended_action'] = ClinicalTriageEngine::REVIEW_RECOMMENDATION;
            $triageMeta['needs_provider_review'] = true;
        }

        $recommendations = MedicalRecommendationEngine::buildRecommendations(
            $triageMeta,
            [], // do not surface disease names as care tips
            trim($chiefComplaint),
            (string) ($nlpResult['english_translation'] ?? ($nlpPipeline['translated_english'] ?? '')),
            $detectedSymptoms
        );

        if ($needsReview) {
            array_unshift($recommendations, ClinicalTriageEngine::REVIEW_RECOMMENDATION);
            $recommendations = array_values(array_unique($recommendations));
        }

        if ($clinicalRec === [] && $rawTriage !== []) {
            $clinicalRec = $rawTriage['recommendation_payload'] ?? [
                'chief_complaint'   => trim($chiefComplaint),
                'detected_symptoms' => $detectedSymptoms,
                'duration'          => $rawTriage['duration'] ?? null,
                'red_flags'         => $rawTriage['red_flags'] ?? [],
                'risk_factors'      => $rawTriage['risk_factors'] ?? [],
                'severity_score'    => $rawTriage['severity_score'] ?? 0,
                'classification'    => $rawTriage['triage_display'] ?? 'NON-URGENT',
                'priority'          => $rawTriage['priority'] ?? 'Normal',
                'confidence'        => $confidence['score'] ?? 0,
                'reason'            => $rawTriage['reason'] ?? '',
                'recommendation'    => $triageMeta['recommended_action'] ?? '',
            ];
        }

        $workflowSteps = [
            'normalize_text',
            'correct_misspellings',
            'detect_language',
            'translate_hiligaynon_to_english',
            'tokenize',
            'remove_unnecessary_words',
            'extract_symptoms',
            'extract_duration',
            'extract_pain_scale',
            'extract_temperature',
            'extract_risk_factors',
            'extract_age_group',
            'detect_emergency_red_flags',
            'calculate_severity_score',
            'determine_highest_priority',
            'generate_explanation',
            'return_recommendation',
        ];

        return [
            'engine_version'        => self::VERSION,
            'engine'                => (string) ($nlpPipeline['engine'] ?? 'php-medical-assessment'),
            'service_used'          => (bool) ($nlpPipeline['service_used'] ?? false),
            'workflow_steps'        => $workflowSteps,
            'original_input'        => $combinedText,
            'chief_complaint'       => trim($chiefComplaint),
            'original_chief_complaint' => trim($chiefComplaint),
            'checkbox_symptoms'     => $checkboxSymptoms,
            'detected_language'     => (string) ($nlpResult['detected_language'] ?? ($nlpPipeline['detected_language'] ?? 'unknown')),
            'normalized_text'       => (string) ($nlpResult['normalized_text'] ?? ($nlpPipeline['normalized_input'] ?? '')),
            'corrected_text'        => (string) ($nlpResult['corrected_text'] ?? ($nlpPipeline['corrected_input'] ?? '')),
            'corrected_words'       => is_array($nlpResult['corrected_words'] ?? null)
                ? $nlpResult['corrected_words']
                : (is_array($nlpPipeline['corrected_words'] ?? null) ? $nlpPipeline['corrected_words'] : []),
            'english_translation'   => (string) ($nlpResult['english_translation'] ?? ($nlpPipeline['translated_english'] ?? '')),
            'standardized_medical_concepts' => is_array($nlpResult['standardized_medical_concepts'] ?? null)
                ? $nlpResult['standardized_medical_concepts']
                : (is_array($nlpResult['medical_concepts'] ?? null) ? $nlpResult['medical_concepts'] : []),
            'associated_symptoms'   => is_array($nlpResult['associated_symptoms'] ?? null) ? $nlpResult['associated_symptoms'] : [],
            'pipeline_stages'       => is_array($nlpResult['pipeline_stages'] ?? null) ? $nlpResult['pipeline_stages'] : [],
            'detected_symptoms'     => $detectedSymptoms,
            'symptom_evidence'      => $symptomEvidence,
            // Provider-only reference context — not a diagnosis
            'possible_conditions'   => $possibleConditions,
            'confidence'            => $confidence,
            'severity'              => array_merge($severity, [
                'severity_score' => (int) ($rawTriage['severity_score'] ?? $clinicalRec['severity_score'] ?? 0),
            ]),
            'triage'                => array_merge($triageMeta, [
                'severity_score'        => (int) ($rawTriage['severity_score'] ?? 0),
                'priority'              => (string) ($rawTriage['priority'] ?? 'Normal'),
                'reason'                => (string) ($rawTriage['reason'] ?? ''),
                'red_flags'             => $rawTriage['red_flags'] ?? ($clinicalRec['red_flags'] ?? []),
                'risk_factors'          => $rawTriage['risk_factors'] ?? ($clinicalRec['risk_factors'] ?? []),
                'duration'              => $rawTriage['duration'] ?? ($clinicalRec['duration'] ?? null),
                'pain_scale'            => $rawTriage['pain_scale'] ?? null,
                'temperature'           => $rawTriage['temperature'] ?? null,
                'age_group'             => $rawTriage['age_group'] ?? 'Unknown',
                'needs_provider_review' => $needsReview,
                'clinical_reasoning'    => (string) ($rawTriage['clinical_reasoning'] ?? ($rawTriage['reason'] ?? '')),
                'clinical_context'      => $rawTriage['clinical_context'] ?? [],
                'validation'            => $rawTriage['validation'] ?? [],
            ]),
            'clinical_context'        => $rawTriage['clinical_context'] ?? [],
            'clinical_recommendation'=> $clinicalRec,
            'recommendations'       => $recommendations,
            'recommended_action'    => (string) ($triageMeta['recommended_action'] ?? ''),
            'disclaimer'            => MedicalRecommendationEngine::DISCLAIMER,
            'db_level'              => (string) ($triageMeta['db_level'] ?? '3'),
            'urgency_label'         => (string) ($triageMeta['urgency_label'] ?? 'Routine'),
            'match_methods'         => $conditionMatch['match_methods'],
            'nlp_pipeline'          => [
                'nlp_result'          => $nlpResult,
                'term_results'        => $nlpPipeline['term_results'] ?? [],
                'translated_english'  => $nlpPipeline['translated_english'] ?? '',
                'valid_count'         => (int) ($nlpPipeline['valid_count'] ?? 0),
                'total_count'         => (int) ($nlpPipeline['total_count'] ?? 0),
            ],
            'ml_layer'              => array_merge($mlLayer, [
                'role' => 'provider_reference_only',
                'note' => 'Disease model output is not used for triage classification and is not a diagnosis.',
            ]),
            'assessed_at'           => date('c'),
        ];
    }

    /** @param list<string> $symptoms */
    private static function buildCombinedText(string $complaint, array $symptoms): string
    {
        $parts = [];
        if (trim($complaint) !== '') {
            $parts[] = trim($complaint);
        }
        if ($symptoms !== []) {
            $parts[] = implode(', ', $symptoms);
        }

        return trim(implode('. ', $parts));
    }

    /** @return array<string, mixed> */
    private static function runNlpPipeline(string $text): array
    {
        // Rule-based PHP NLP is the default CDS path. Python is opt-in only.
        $phpOnly = filter_var(getenv('MEDCONNECT_PHP_NLP_ONLY') ?: '1', FILTER_VALIDATE_BOOLEAN);
        if (!$phpOnly) {
            $serviceData = AiServiceClient::analyzeMedicalText($text);
            if ($serviceData) {
                return array_merge($serviceData, [
                    'engine'       => (string) ($serviceData['engine'] ?? 'python-medical-text-nlp'),
                    'service_used' => true,
                ]);
            }
        }

        $pipeline = MedicalTextAnalysisWorkflow::analyze($text);
        $pipeline['engine'] = (string) ($pipeline['engine'] ?? 'php-medical-text-analysis');
        $pipeline['service_used'] = false;

        return $pipeline;
    }

    /**
     * @param list<string> $symptoms
     * @param array<string, mixed> $nlpResult
     * @return array<string, mixed>
     */
    private static function runMlLayer(string $text, array $symptoms, array $nlpResult): array
    {
        $skip = filter_var(getenv('MEDCONNECT_SKIP_ML_LAYER') ?: '0', FILTER_VALIDATE_BOOLEAN);
        if ($skip) {
            return [
                'available'     => false,
                'predictions'   => [],
                'triage_level'  => '',
                'triage_label'  => '',
            ];
        }
        $english = (string) ($nlpResult['english_translation'] ?? $text);
        $urgentFlags = [];
        if (mb_strtoupper((string) ($nlpResult['triage_level'] ?? '')) === 'EMERGENCY') {
            $urgentFlags[] = 'possible_emergency';
        }

        $ml = AiServiceClient::predictDisease($english, $symptoms, $urgentFlags);
        if (!$ml) {
            return [
                'available'     => false,
                'predictions'   => [],
                'triage_level'  => '',
                'triage_label'  => '',
            ];
        }

        return [
            'available'     => true,
            'predictions'   => is_array($ml['disease_predictions'] ?? null) ? $ml['disease_predictions'] : [],
            'triage_level'  => (string) (($ml['triage']['level'] ?? '') ?: ''),
            'triage_label'  => (string) (($ml['triage']['label'] ?? '') ?: ''),
            'precautions'   => $ml['precautions'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyAssessment(): array
    {
        return [
            'engine_version'      => self::VERSION,
            'error'               => 'empty_input',
            'detected_symptoms'   => [],
            'possible_conditions' => [],
            'confidence'          => [
                'score' => 0,
                'score_display' => '0%',
                'level' => 'insufficient',
                'level_label' => 'Insufficient Data',
            ],
            'recommended_action'  => ClinicalTriageEngine::REVIEW_RECOMMENDATION,
            'disclaimer'          => MedicalRecommendationEngine::DISCLAIMER,
        ];
    }
}
