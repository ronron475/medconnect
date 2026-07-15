<?php
/**
 * BHW chief complaint — full medical NLP pipeline (same engine as nlp_step3_demo).
 */

require_once __DIR__ . '/../core/AiServiceClient.php';
require_once __DIR__ . '/../core/AiServiceLauncher.php';
require_once __DIR__ . '/../core/MedicalValidationWorkflow.php';
require_once __DIR__ . '/../core/MedicalProfilePipelineSteps.php';
require_once __DIR__ . '/../core/MedicalRecommendationEngine.php';
require_once __DIR__ . '/../core/NlpPipelineDiagnostics.php';
require_once __DIR__ . '/../core/MedicalDictionary.php';
require_once __DIR__ . '/../core/NlpPreprocessor.php';
require_once __DIR__ . '/../core/BodyPartsDataset.php';

/**
 * Remove anatomy-only labels and other non-symptom tokens from UI lists.
 *
 * @param list<mixed> $terms
 * @return list<string>
 */
function bhw_triage_filter_display_terms(array $terms): array
{
    $out = [];
    foreach ($terms as $term) {
        $label = trim((string) $term);
        if ($label === '') {
            continue;
        }
        if (BodyPartsDataset::isBodyPartOrEnglish($label)) {
            continue;
        }
        $out[] = $label;
    }

    return array_values(array_unique($out));
}

/**
 * @param array<string, mixed> $term
 */
function bhw_triage_term_is_body_part(array $term): bool
{
    $local = trim((string) ($term['original_local'] ?? $term['local_term'] ?? ''));
    $english = trim((string) ($term['english_term'] ?? $term['match_term'] ?? ''));
    $type = strtolower((string) ($term['term_type'] ?? $term['category'] ?? ''));

    if ($type === 'body_part') {
        return true;
    }
    if ($local !== '' && BodyPartsDataset::isBodyPart($local)) {
        return true;
    }

    return $english !== '' && BodyPartsDataset::isEnglishBodyPart($english);
}

/**
 * Run the 7-step NLP pipeline for a chief complaint (no allergies field).
 *
 * @return array<string, mixed>
 */
function bhw_run_chief_complaint_nlp(string $complaint): array
{
    $complaint = trim($complaint);
    $allergies = '';
    /** BHW walk-in triage must stay responsive — cap Python wait, always allow PHP fallback. */
    $bhwAiTimeout = max(8, min(25, (int) (getenv('MEDCONNECT_BHW_TRIAGE_AI_TIMEOUT') ?: 20)));

    $serviceOnline = AI_SERVICE_ENABLED && AiServiceClient::isHealthy(2);
    $serviceStatus = AiServiceClient::connectionStatus();

    if (!$serviceOnline && AI_SERVICE_ENABLED && AI_SERVICE_AUTO_START) {
        AiServiceLauncher::log('bhw_triage_nlp: Python offline — background start (no health wait)');
        AiServiceLauncher::ensureRunning(false);
        $serviceOnline = AiServiceClient::isHealthy(2);
        $serviceStatus = AiServiceClient::connectionStatus();
    }

    $service_data = null;
    if ($serviceOnline) {
        $service_data = AiServiceClient::analyzeMedicalProfile($allergies, $complaint, $bhwAiTimeout);
        $serviceOnline = $service_data !== null && AiServiceClient::isHealthy(1);
        $serviceStatus = AiServiceClient::connectionStatus();
    }

    if ($service_data) {
        $datasetValidation = $service_data['dataset_validation'] ?? [];
        $invalidDetection = $service_data['invalid_entry_detection'] ?? [];
        $pipeline = array_merge($service_data, [
            'registration'          => $service_data['registration'] ?? ($datasetValidation['registration'] ?? []),
            'registration_eligible' => (bool) (
                $service_data['registration_eligible'] ?? ($datasetValidation['registration_eligible'] ?? false)
            ),
            'submission_rejected'   => (bool) ($service_data['submission_rejected'] ?? ($invalidDetection['submission_rejected'] ?? false)),
            'submission_accepted'   => (bool) ($service_data['submission_accepted'] ?? ($invalidDetection['submission_accepted'] ?? false)),
            'save_allowed'          => (bool) ($service_data['save_allowed'] ?? ($invalidDetection['save_allowed'] ?? false)),
            'engine'                => (string) ($service_data['engine'] ?? 'python-medical-profile-nlp'),
            'service_used'          => true,
            'allergies_text'        => $allergies,
            'medications_text'      => $complaint,
        ]);
        $pipeline = MedicalProfilePipelineSteps::enrich($pipeline);
    } else {
        if (AI_SERVICE_ENABLED && !$serviceOnline) {
            AiServiceLauncher::log('bhw_triage_nlp: using PHP validation workflow (Python unavailable or timed out)');
        }
        $pipeline = MedicalValidationWorkflow::run($allergies, $complaint);
        $pipeline['engine'] = 'php-validation-workflow';
        $pipeline['service_used'] = false;
    }

    $pipeline['service_online'] = $serviceOnline;
    $pipeline['ai_service'] = $serviceStatus;
    $pipeline['summary'] = bhw_triage_nlp_summary($pipeline);

    return $pipeline;
}

/**
 * @param array<string, mixed> $pipeline
 */
function bhw_triage_nlp_summary(array $pipeline): string
{
    $summary = trim((string) ($pipeline['summary'] ?? ''));
    if ($summary !== '') {
        return $summary;
    }

    $invalidDet = is_array($pipeline['invalid_entry_detection'] ?? null)
        ? $pipeline['invalid_entry_detection']
        : [];
    $terms = is_array($pipeline['term_results'] ?? null) ? $pipeline['term_results'] : [];

    if ($terms === []) {
        return trim((string) ($invalidDet['user_message'] ?? 'No medical terms were extracted from the concern.'));
    }

    $parts = [];
    foreach ($terms as $term) {
        if (!is_array($term)) {
            continue;
        }
        $type = (string) ($term['term_type'] ?? (($term['field'] ?? '') === 'allergies' ? 'allergy' : 'condition'));
        $label = ucfirst($type);
        $input = (string) ($term['original_local'] ?? $term['english_term'] ?? '');
        if (bhw_triage_term_is_body_part($term)) {
            $english = trim((string) ($term['english_term'] ?? ''));
            if ($english === '' && $input !== '') {
                $dict = MedicalDictionary::lookup($input);
                $english = trim((string) ($dict['english_term'] ?? $input));
            }
            $parts[] = 'Body part: ' . $input . ($english !== '' && strcasecmp($english, $input) !== 0 ? ' → ' . $english : '');
            continue;
        }
        if (($term['display_status'] ?? '') === 'valid') {
            $standard = (string) ($term['standardized_term'] ?? $term['english_term'] ?? '');
            $parts[] = "{$label}: {$input} → {$standard} (verified)";
        } else {
            $parts[] = "{$label}: {$input} (not in official dataset)";
        }
    }

    return $parts !== [] ? implode('. ', $parts) . '.' : '';
}

/**
 * Map full pipeline output to the BHW assessment shape used by triage booking.
 *
 * @param array<string, mixed> $pipeline
 * @return array<string, mixed>
 */
function bhw_map_nlp_pipeline_to_assessment(array $pipeline, string $complaint): array
{
    $clinical = is_array($pipeline['clinical_urgency'] ?? null) ? $pipeline['clinical_urgency'] : [];
    $confidence = is_array($pipeline['confidence_assessment'] ?? null) ? $pipeline['confidence_assessment'] : [];
    $translation = is_array($pipeline['translation'] ?? null) ? $pipeline['translation'] : [];

    $triageMeta = MedicalRecommendationEngine::classify([
        'nlp_triage_level' => (string) ($clinical['triage_level'] ?? 'LOW'),
        'severity'         => (string) ($clinical['severity'] ?? 'mild'),
    ]);

    $display = (string) ($clinical['triage_display'] ?? $triageMeta['triage_display'] ?? 'NON-URGENT');
    if ($display === 'EMERGENCY') {
        $triageMeta = array_merge($triageMeta, [
            'triage_level'       => 'EMERGENCY',
            'triage_display'     => 'EMERGENCY',
            'triage_icon'        => (string) ($clinical['triage_icon'] ?? '🔴'),
            'db_level'           => '1',
            'urgency_label'      => 'Emergency (Immediate)',
            'recommended_action' => (string) ($clinical['recommendation'] ?? $clinical['recommended_action'] ?? $triageMeta['recommended_action']),
        ]);
    } elseif ($display === 'URGENT') {
        $triageMeta = array_merge($triageMeta, [
            'triage_level'       => 'HIGH',
            'triage_display'     => 'URGENT',
            'triage_icon'        => (string) ($clinical['triage_icon'] ?? '🟡'),
            'db_level'           => '2',
            'urgency_label'      => 'Urgent (Priority)',
            'recommended_action' => (string) ($clinical['recommendation'] ?? $clinical['recommended_action'] ?? $triageMeta['recommended_action']),
        ]);
    } else {
        $triageMeta['recommended_action'] = (string) (
            $clinical['recommendation'] ?? $clinical['recommended_action'] ?? $triageMeta['recommended_action']
        );
    }
    $triageMeta['triage_classification'] = (string) (
        $clinical['triage_classification'] ?? $triageMeta['triage_classification'] ?? 'NON_URGENT'
    );

    $overallScore = (int) ($confidence['overall_score'] ?? $clinical['confidence_score'] ?? 0);
    $symptoms = bhw_triage_filter_display_terms(array_values(array_filter((array) ($clinical['detected_symptoms'] ?? []))));
    $conditions = array_values(array_filter((array) ($clinical['detected_conditions'] ?? [])));

    $english = trim((string) (
        $translation['combined_english']
        ?? ($pipeline['english_medications'] ?? '')
        ?? ($translation['conditions']['english_text'] ?? '')
    ));

    $recommendations = MedicalRecommendationEngine::buildRecommendations(
        $triageMeta,
        $conditions,
        (string) ($clinical['chief_complaint'] ?? $clinical['original_complaint'] ?? $complaint ?? ''),
        $english,
        $symptoms
    );
    $reasoning = trim((string) ($clinical['clinical_reasoning'] ?? $clinical['reason'] ?? ''));
    if ($reasoning !== '' && !in_array($reasoning, $recommendations, true)) {
        array_unshift($recommendations, $reasoning);
    }

    return [
        'engine_version'      => '2.0',
        'engine'              => (string) ($pipeline['engine'] ?? 'python-medical-profile-nlp'),
        'service_used'        => (bool) ($pipeline['service_used'] ?? false),
        'original_input'      => $complaint,
        'chief_complaint'     => $complaint,
        'english_translation' => $english,
        'detected_symptoms'   => $symptoms,
        'possible_conditions' => $conditions,
        'confidence'          => [
            'score' => $overallScore,
            'level' => (string) ($confidence['overall_level'] ?? ($overallScore >= 85 ? 'high' : 'moderate')),
            'label' => (string) ($confidence['overall_level_label'] ?? ''),
        ],
        'severity'            => [
            'severity' => (string) ($clinical['severity'] ?? 'mild'),
            'score'    => (int) ($clinical['severity_score'] ?? 0),
        ],
        'triage'              => $triageMeta,
        'recommendations'     => $recommendations,
        'recommended_action'  => (string) ($triageMeta['recommended_action'] ?? ''),
        'disclaimer'          => MedicalRecommendationEngine::DISCLAIMER,
        'db_level'            => (string) ($triageMeta['db_level'] ?? '3'),
        'urgency_label'       => (string) ($triageMeta['urgency_label'] ?? 'Routine'),
        'pipeline_summary'    => bhw_triage_nlp_summary($pipeline),
        'clinical_urgency'    => $clinical,
        'assessed_at'         => date('c'),
    ];
}

/**
 * Compact pipeline payload for the BHW triage UI (mirrors nlp_step3_demo highlights).
 *
 * @param array<string, mixed> $pipeline
 * @return array<string, mixed>
 */
function bhw_format_pipeline_for_ui(array $pipeline): array
{
    $clinical = is_array($pipeline['clinical_urgency'] ?? null) ? $pipeline['clinical_urgency'] : [];
    $confidence = is_array($pipeline['confidence_assessment'] ?? null) ? $pipeline['confidence_assessment'] : [];
    $preprocessing = is_array($pipeline['preprocessing'] ?? null) ? $pipeline['preprocessing'] : [];
    $translation = is_array($pipeline['translation'] ?? null) ? $pipeline['translation'] : [];
    $prepConditions = is_array($preprocessing['conditions'] ?? null) ? $preprocessing['conditions'] : [];

    $steps = [
        ['id' => 1, 'label' => 'Preprocessing', 'status' => !empty($prepConditions) ? 'complete' : 'pending'],
        ['id' => 2, 'label' => 'Medical translation', 'status' => !empty($translation) ? 'complete' : 'pending'],
        ['id' => 3, 'label' => 'Fuzzy matching', 'status' => !empty($pipeline['fuzzy_matching']) ? 'complete' : 'pending'],
        ['id' => 4, 'label' => 'Dataset validation', 'status' => !empty($pipeline['dataset_validation']) ? 'complete' : 'pending'],
        ['id' => 5, 'label' => 'Confidence assessment', 'status' => !empty($confidence) ? 'complete' : 'pending'],
        ['id' => 6, 'label' => 'Clinical urgency', 'status' => !empty($clinical) ? 'complete' : 'pending'],
        ['id' => 7, 'label' => 'Triage decision', 'status' => !empty($pipeline['registration_decision']) ? 'complete' : 'pending'],
    ];

    return [
        'summary'            => bhw_triage_nlp_summary($pipeline),
        'steps'              => $steps,
        'engine'             => (string) ($pipeline['engine'] ?? ''),
        'service_used'       => (bool) ($pipeline['service_used'] ?? false),
        'clinical_urgency'   => $clinical,
        'confidence'         => $confidence,
        'english_translation'=> trim((string) (
            $translation['combined_english']
            ?? ($pipeline['english_medications'] ?? '')
            ?? ($translation['conditions']['english_text'] ?? '')
        )),
        'preprocessing'      => [
            'original'         => (string) ($prepConditions['original'] ?? ''),
            'normalized'       => (string) ($prepConditions['normalized'] ?? ''),
            'english_preview'  => (string) ($prepConditions['english_preview'] ?? ''),
            'keywords'         => array_values((array) ($prepConditions['keywords'] ?? [])),
        ],
        'registration_decision' => is_array($pipeline['registration_decision'] ?? null)
            ? $pipeline['registration_decision']
            : [],
        'warnings'           => is_array($pipeline['invalid_entry_detection']['invalid_entries'] ?? null)
            ? $pipeline['invalid_entry_detection']['invalid_entries']
            : [],
    ];
}
