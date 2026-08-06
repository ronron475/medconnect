<?php
/**
 * Single entry point for chief-complaint NLP + CDS triage across medConnect.
 *
 * Demo, patient registration Step 3, BHW triage, patient portal, and provider
 * reassessment must all call this service — never duplicate pipeline logic.
 *
 * Engine chain: HiligaynonMedicalNlpPipeline → MedicalTriageDetector → ClinicalTriageEngine
 */

final class ChiefComplaintNlpService
{
    public const VERSION = '1.0';
    public const ENGINE_CHAIN = 'HiligaynonMedicalNlpPipeline → MedicalTriageDetector → ClinicalTriageEngine';

    /**
     * @param list<string> $checkboxSymptoms
     * @return array<string, mixed>
     */
    public static function assess(string $complaint, array $checkboxSymptoms = []): array
    {
        return MedicalAssessmentEngine::assess($complaint, $checkboxSymptoms);
    }

    /**
     * CDS demo / API summary block (explainable output).
     *
     * @return array<string, mixed>
     */
    public static function buildCdsSummary(array $assessment, string $complaint = ''): array
    {
        $validation = [];
        if (is_array($assessment['clinical_recommendation']['validation'] ?? null)) {
            $validation = $assessment['clinical_recommendation']['validation'];
        } elseif (is_array($assessment['triage']['validation'] ?? null)) {
            $validation = $assessment['triage']['validation'];
        }

        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];

        return [
            'original_chief_complaint' => (string) ($assessment['original_chief_complaint'] ?? $complaint),
            'detected_language'        => (string) ($assessment['detected_language'] ?? 'unknown'),
            'corrected_text'           => (string) ($assessment['corrected_text'] ?? ''),
            'english_translation'      => (string) ($assessment['english_translation'] ?? ''),
            'standardized_medical_concepts' => is_array($assessment['standardized_medical_concepts'] ?? null)
                ? $assessment['standardized_medical_concepts']
                : [],
            'detected_symptoms'        => is_array($assessment['detected_symptoms'] ?? null)
                ? $assessment['detected_symptoms']
                : [],
            'associated_symptoms'      => is_array($assessment['associated_symptoms'] ?? null)
                ? $assessment['associated_symptoms']
                : [],
            'red_flags'                => is_array($triage['red_flags'] ?? null) ? $triage['red_flags'] : [],
            'clinical_reasoning'         => (string) ($triage['clinical_reasoning'] ?? ($triage['reason'] ?? '')),
            'classification'             => (string) ($triage['triage_display'] ?? 'NON-URGENT'),
            'confidence'                 => (int) ($assessment['confidence']['score'] ?? 0),
            'recommended_action'         => (string) ($assessment['recommended_action'] ?? ''),
            'reason'                     => (string) ($triage['reason'] ?? ($triage['clinical_reasoning'] ?? '')),
            'engine'                     => (string) ($assessment['engine'] ?? ''),
            'engine_chain'               => self::ENGINE_CHAIN,
            'service_used'               => (bool) ($assessment['service_used'] ?? false),
            'severity_score'             => (int) ($assessment['severity']['severity_score'] ?? ($triage['severity_score'] ?? 0)),
            'needs_provider_review'      => (bool) ($triage['needs_provider_review'] ?? false),
            'validation_passed'          => (bool) ($validation['passed'] ?? true),
            'winning_rule'               => (string) ($validation['winning_rule'] ?? ($assessment['clinical_recommendation']['winning_rule'] ?? '')),
            'pipeline_stages'            => is_array($assessment['pipeline_stages'] ?? null) ? $assessment['pipeline_stages'] : [],
            'symptom_evidence'           => is_array($assessment['symptom_evidence'] ?? null) ? $assessment['symptom_evidence'] : [],
            'triggered_rules'            => is_array($triage['matched_rules'] ?? null)
                ? $triage['matched_rules']
                : (is_array($triage['assessment_factors']['matched_rules'] ?? null)
                    ? $triage['assessment_factors']['matched_rules']
                    : []),
        ];
    }

    /**
     * clinical_urgency shape used by registration and legacy BHW adapters.
     *
     * @return array<string, mixed>
     */
    public static function buildClinicalUrgency(array $assessment): array
    {
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $confidence = is_array($assessment['confidence'] ?? null) ? $assessment['confidence'] : [];
        $score = (int) ($confidence['score'] ?? ($triage['confidence_score'] ?? 0));
        $display = (string) ($triage['triage_display'] ?? 'NON-URGENT');

        return [
            'triage_level'           => (string) ($triage['triage_level'] ?? 'LOW'),
            'triage_display'         => $display,
            'triage_classification'  => (string) ($triage['triage_classification'] ?? 'NON_URGENT'),
            'triage_icon'            => (string) ($triage['triage_icon'] ?? '🟢'),
            'priority'               => (string) ($triage['priority'] ?? 'Normal'),
            'recommendation'         => (string) ($assessment['recommended_action'] ?? ($triage['recommended_action'] ?? '')),
            'recommended_action'       => (string) ($assessment['recommended_action'] ?? ($triage['recommended_action'] ?? '')),
            'detected_symptoms'      => is_array($assessment['detected_symptoms'] ?? null) ? $assessment['detected_symptoms'] : [],
            'detected_conditions'    => is_array($assessment['possible_conditions'] ?? null) ? $assessment['possible_conditions'] : [],
            'detected_body_parts'    => is_array($triage['detected_body_parts'] ?? null) ? $triage['detected_body_parts'] : [],
            'severity_score'         => (int) ($triage['severity_score'] ?? ($assessment['severity']['severity_score'] ?? 0)),
            'severity'               => (string) ($assessment['severity']['severity'] ?? ($triage['severity'] ?? 'mild')),
            'confidence_score'       => $score,
            'confidence_display'     => $score > 0 ? $score . '%' : '—',
            'confidence_level'       => (string) ($confidence['level'] ?? ''),
            'confidence_level_label' => (string) ($confidence['level_label'] ?? ''),
            'confidence_accepted'    => (bool) ($triage['confidence_accepted'] ?? ($score >= MedicalAssessmentEngine::CONFIDENCE_REVIEW_THRESHOLD)),
            'clinical_reasoning'     => (string) ($triage['clinical_reasoning'] ?? ($triage['reason'] ?? '')),
            'reason'                 => (string) ($triage['reason'] ?? ($triage['clinical_reasoning'] ?? '')),
            'emergency_flags'        => is_array($triage['red_flags'] ?? null) ? $triage['red_flags'] : [],
            'symptom_evidence'       => is_array($assessment['symptom_evidence'] ?? null) ? $assessment['symptom_evidence'] : [],
            'matched_rules'          => is_array($triage['matched_rules'] ?? null) ? $triage['matched_rules'] : [],
            'engine_version'         => (string) ($assessment['engine_version'] ?? MedicalAssessmentEngine::VERSION),
            'engine_chain'           => self::ENGINE_CHAIN,
            'source'                 => 'chief_complaint_nlp_service',
            'emergency_alert'        => $display === 'EMERGENCY',
        ];
    }

    /**
     * Registration Step 3 client payload (stored as nlp_result_json).
     *
     * @return array<string, mixed>
     */
    public static function buildRegistrationPayload(array $assessment, string $originalText): array
    {
        $clinical = self::buildClinicalUrgency($assessment);
        $display = strtoupper(str_replace('_', '-', (string) ($clinical['triage_display'] ?? 'NON-URGENT')));
        if ($display === 'NON URGENT') {
            $display = 'NON-URGENT';
        }

        return [
            'clinical_urgency'    => $clinical,
            'urgency'             => $display,
            'original_complaint'  => trim($originalText),
            'translated_english'  => (string) ($assessment['english_translation'] ?? ''),
            'detected_symptoms'   => $clinical['detected_symptoms'],
            'detected_conditions' => $clinical['detected_conditions'],
            'symptom_evidence'    => $clinical['symptom_evidence'],
            'clinical_reasoning'  => $clinical['clinical_reasoning'],
            'confidence'          => (string) ($clinical['confidence_display'] ?? ''),
            'engine'              => (string) ($assessment['engine'] ?? ''),
            'engine_chain'        => self::ENGINE_CHAIN,
            'assessment'          => $assessment,
            'timestamp'           => date('c'),
        ];
    }

    /**
     * BHW triage assessment shape (replaces profile-pipeline mapping).
     *
     * @return array<string, mixed>
     */
    public static function buildBhwAssessment(array $assessment, string $complaint): array
    {
        $clinical = self::buildClinicalUrgency($assessment);
        $triageMeta = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $confidence = is_array($assessment['confidence'] ?? null) ? $assessment['confidence'] : [];
        $symptoms = array_values(array_filter((array) ($clinical['detected_symptoms'] ?? [])));
        $conditions = array_values(array_filter((array) ($clinical['detected_conditions'] ?? [])));
        $english = (string) ($assessment['english_translation'] ?? '');

        $recommendations = is_array($assessment['recommendations'] ?? null)
            ? $assessment['recommendations']
            : MedicalRecommendationEngine::buildRecommendations(
                $triageMeta,
                $conditions,
                $complaint,
                $english,
                $symptoms
            );

        return [
            'engine_version'      => (string) ($assessment['engine_version'] ?? MedicalAssessmentEngine::VERSION),
            'engine'              => (string) ($assessment['engine'] ?? 'php-medical-assessment'),
            'engine_chain'        => self::ENGINE_CHAIN,
            'service_used'        => (bool) ($assessment['service_used'] ?? false),
            'original_input'      => $complaint,
            'chief_complaint'     => $complaint,
            'english_translation' => $english,
            'detected_symptoms'   => $symptoms,
            'possible_conditions' => $conditions,
            'symptom_evidence'    => $clinical['symptom_evidence'],
            'confidence'          => [
                'score' => (int) ($confidence['score'] ?? 0),
                'level' => (string) ($confidence['level'] ?? 'moderate'),
                'label' => (string) ($confidence['level_label'] ?? ''),
            ],
            'severity'            => is_array($assessment['severity'] ?? null) ? $assessment['severity'] : [],
            'triage'              => $triageMeta,
            'recommendations'     => $recommendations,
            'recommended_action'  => (string) ($assessment['recommended_action'] ?? ''),
            'disclaimer'          => MedicalRecommendationEngine::DISCLAIMER,
            'db_level'            => (string) ($triageMeta['db_level'] ?? '3'),
            'urgency_label'       => (string) ($triageMeta['urgency_label'] ?? 'Routine'),
            'pipeline_summary'    => (string) ($clinical['clinical_reasoning'] ?? ''),
            'clinical_urgency'    => $clinical,
            'clinical_reasoning'  => (string) ($clinical['clinical_reasoning'] ?? ''),
            'matched_rules'       => $clinical['matched_rules'],
            'assessed_at'         => date('c'),
        ];
    }

    /**
     * BHW UI pipeline panel (CDS stages, not profile validation steps).
     *
     * @return array<string, mixed>
     */
    public static function buildBhwPipelineUi(array $assessment, string $complaint): array
    {
        $clinical = self::buildClinicalUrgency($assessment);
        $stages = is_array($assessment['pipeline_stages'] ?? null) ? $assessment['pipeline_stages'] : [];
        $steps = [];
        $id = 1;
        foreach ($stages as $stage => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $steps[] = [
                'id'     => $id++,
                'label'  => ucwords(str_replace('_', ' ', (string) $stage)),
                'status' => 'complete',
            ];
        }
        if ($steps === []) {
            $steps = [
                ['id' => 1, 'label' => 'Normalize & translate', 'status' => 'complete'],
                ['id' => 2, 'label' => 'Extract symptoms', 'status' => 'complete'],
                ['id' => 3, 'label' => 'Clinical triage', 'status' => 'complete'],
            ];
        }

        return [
            'summary'             => (string) ($clinical['clinical_reasoning'] ?? ''),
            'steps'               => $steps,
            'engine'              => (string) ($assessment['engine'] ?? ''),
            'engine_chain'        => self::ENGINE_CHAIN,
            'service_used'        => (bool) ($assessment['service_used'] ?? false),
            'clinical_urgency'    => $clinical,
            'confidence'          => $assessment['confidence'] ?? [],
            'english_translation' => (string) ($assessment['english_translation'] ?? ''),
            'preprocessing'       => [
                'original'        => $complaint,
                'normalized'      => (string) ($assessment['normalized_text'] ?? ''),
                'english_preview' => (string) ($assessment['english_translation'] ?? ''),
                'keywords'        => is_array($assessment['detected_symptoms'] ?? null) ? $assessment['detected_symptoms'] : [],
            ],
            'symptom_evidence'    => $clinical['symptom_evidence'],
            'triggered_rules'     => $clinical['matched_rules'],
            'pipeline_stages'     => $stages,
        ];
    }

    /**
     * Minimal fallback when the full assessment stack throws.
     *
     * @param list<string> $checkboxSymptoms
     * @return array<string, mixed>
     */
    public static function assessWithFallback(string $complaint, array $checkboxSymptoms = []): array
    {
        try {
            return self::assess($complaint, $checkboxSymptoms);
        } catch (Throwable $e) {
            error_log('ChiefComplaintNlpService fallback: ' . $e->getMessage());
            $raw = ClinicalTriageEngine::assess($complaint, $complaint, [], $checkboxSymptoms, 35);
            $display = (string) ($raw['triage_display'] ?? 'NON-URGENT');
            $triageMeta = MedicalRecommendationEngine::classify([
                'nlp_triage_level' => (string) ($raw['triage_level'] ?? 'LOW'),
                'severity'         => (string) ($raw['severity'] ?? 'mild'),
            ]);
            $triageMeta['triage_display'] = $display;

            return [
                'engine_version'      => MedicalAssessmentEngine::VERSION,
                'engine'              => 'clinical-triage-engine-fallback',
                'engine_chain'        => 'ClinicalTriageEngine (fallback)',
                'chief_complaint'     => $complaint,
                'original_chief_complaint' => $complaint,
                'english_translation' => (string) ($raw['english_translation'] ?? ''),
                'detected_symptoms'   => is_array($raw['detected_symptoms'] ?? null) ? $raw['detected_symptoms'] : [],
                'symptom_evidence'    => is_array($raw['symptom_evidence'] ?? null) ? $raw['symptom_evidence'] : [],
                'possible_conditions' => [],
                'confidence'          => [
                    'score' => (int) ($raw['confidence_score'] ?? 35),
                    'level' => 'fallback',
                    'level_label' => 'Engine Fallback',
                ],
                'severity'            => [
                    'severity' => (string) ($raw['severity'] ?? 'mild'),
                    'severity_score' => (int) ($raw['severity_score'] ?? 0),
                ],
                'triage'              => array_merge($triageMeta, $raw),
                'recommended_action'  => (string) ($raw['recommended_action'] ?? ''),
                'recommendations'     => [],
                'assessment_warning'  => 'Full NLP pipeline unavailable; rule-based triage fallback used.',
            ];
        }
    }
}
