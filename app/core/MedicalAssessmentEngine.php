<?php
/**
 * AI-Assisted Medical Assessment Engine — end-to-end patient symptom assessment.
 */

final class MedicalAssessmentEngine
{
    public const VERSION = '1.0';

    /**
     * @param list<string> $checkboxSymptoms
     * @return array<string, mixed>
     */
    public static function assess(string $chiefComplaint, array $checkboxSymptoms = []): array
    {
        $checkboxSymptoms = array_values(array_filter(array_map('trim', $checkboxSymptoms)));
        $combinedText = self::buildCombinedText($chiefComplaint, $checkboxSymptoms);

        if ($combinedText === '') {
            return self::emptyAssessment();
        }

        $nlpPipeline = self::runNlpPipeline($combinedText);
        $nlpResult = is_array($nlpPipeline['nlp_result'] ?? null) ? $nlpPipeline['nlp_result'] : [];

        $conditionMatch = MedicalConditionMatcher::match($nlpPipeline, $checkboxSymptoms);
        $detectedSymptoms = $conditionMatch['detected_symptoms'];
        $possibleConditions = $conditionMatch['possible_conditions'];

        $mlLayer = self::runMlLayer($combinedText, $detectedSymptoms, $nlpResult);
        if (!empty($mlLayer['predictions'])) {
            $nlpPipeline['ml_predictions'] = $mlLayer['predictions'];
            foreach ($mlLayer['predictions'] as $prediction) {
                $name = trim((string) ($prediction['disease'] ?? ''));
                if ($name !== '' && !in_array($name, $possibleConditions, true)) {
                    $possibleConditions[] = $name;
                }
            }
        }

        $severity = MedicalSeverityDetector::detect(
            $combinedText,
            (string) ($nlpResult['english_translation'] ?? ''),
            is_array($nlpResult['medical_concepts'] ?? null) ? $nlpResult['medical_concepts'] : [],
            (string) ($nlpResult['severity'] ?? '')
        );

        $confidence = MedicalConfidenceScorer::score($nlpPipeline, $detectedSymptoms, $possibleConditions);

        $triageMeta = MedicalRecommendationEngine::classify([
            'nlp_triage_level' => (string) ($nlpResult['triage_level'] ?? 'LOW'),
            'severity'         => (string) ($severity['severity'] ?? 'mild'),
            'ml_triage_level'  => (string) ($mlLayer['triage_level'] ?? ''),
        ]);

        $recommendations = MedicalRecommendationEngine::buildRecommendations($triageMeta, $possibleConditions);

        $workflowSteps = [
            'language_detection',
            'translation',
            'keyword_extraction',
            'symptom_normalization',
            'condition_matching',
            'confidence_scoring',
            'severity_analysis',
            'triage_classification',
            'recommendation_generation',
            'assessment_report',
        ];

        return [
            'engine_version'        => self::VERSION,
            'engine'                => (string) ($nlpPipeline['engine'] ?? 'php-medical-assessment'),
            'service_used'          => (bool) ($nlpPipeline['service_used'] ?? false),
            'workflow_steps'        => $workflowSteps,
            'original_input'        => $combinedText,
            'chief_complaint'       => trim($chiefComplaint),
            'checkbox_symptoms'     => $checkboxSymptoms,
            'detected_language'     => (string) ($nlpResult['detected_language'] ?? ($nlpPipeline['detected_language'] ?? 'unknown')),
            'english_translation'   => (string) ($nlpResult['english_translation'] ?? ($nlpPipeline['translated_english'] ?? '')),
            'detected_symptoms'     => $detectedSymptoms,
            'possible_conditions'   => $possibleConditions,
            'confidence'            => $confidence,
            'severity'              => $severity,
            'triage'                => $triageMeta,
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
            'ml_layer'              => $mlLayer,
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
        $serviceData = AiServiceClient::analyzeMedicalText($text);
        if ($serviceData) {
            return array_merge($serviceData, [
                'engine'       => (string) ($serviceData['engine'] ?? 'python-medical-text-nlp'),
                'service_used' => true,
            ]);
        }

        $pipeline = MedicalTextAnalysisWorkflow::analyze($text);
        $pipeline['engine'] = 'php-medical-text-analysis';
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
            'disclaimer'          => MedicalRecommendationEngine::DISCLAIMER,
        ];
    }
}
