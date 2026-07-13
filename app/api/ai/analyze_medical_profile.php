<?php
/**
 * API: NLP demo for registration step 3 (allergies + current medications).
 * Workflow: preprocess → translate to English → fuzzy match (English only) → validate.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePost();

set_time_limit(210);

$allergies = trim((string) ($_POST['allergies'] ?? ''));
$existing_conditions = trim((string) (
    $_POST['existing_conditions']
    ?? $_POST['current_medications']
    ?? $_POST['chief_complaint']
    ?? $_POST['text']
    ?? ''
));

if ($allergies === '' && $existing_conditions === '') {
    Api::error('Enter a chief complaint, medical conditions, and/or known allergies.');
}

$current_medications = $existing_conditions;

/**
 * @param array<string, mixed> $pipeline
 * @param array<string, mixed> $invalidDetection
 */
function nlp_pipeline_summary(array $pipeline, array $invalidDetection): string
{
    $summary = trim((string) ($pipeline['summary'] ?? ''));
    if ($summary !== '') {
        return $summary;
    }

    $terms = $pipeline['term_results'] ?? [];
    if (is_array($terms) && $terms !== []) {
        $parts = [];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $type = (string) ($term['term_type'] ?? (($term['field'] ?? '') === 'allergies' ? 'allergy' : 'condition'));
            $label = ucfirst($type);
            $input = (string) ($term['original_local'] ?? $term['english_term'] ?? '');
            if (($term['display_status'] ?? '') === 'valid') {
                $standard = (string) ($term['standardized_term'] ?? $term['english_term'] ?? '');
                $parts[] = "{$label}: {$input} → {$standard} (verified)";
            } else {
                $parts[] = "{$label}: {$input} (blocked)";
            }
        }
        if ($parts !== []) {
            return implode('. ', $parts) . '.';
        }
    }

    return trim((string) ($invalidDetection['user_message'] ?? ''));
}

try {
    $serviceOnline = AI_SERVICE_ENABLED && AiServiceClient::isHealthy(3);
    $serviceStatus = AiServiceClient::connectionStatus();

    if (!$serviceOnline && AI_SERVICE_ENABLED && AI_SERVICE_AUTO_START) {
        AiServiceLauncher::log('analyze_medical_profile: Python offline — auto-start with health wait');
        AiServiceLauncher::ensureRunning(true);
        $serviceOnline = AiServiceClient::isHealthy(3);
        $serviceStatus = AiServiceClient::connectionStatus();
    }

    $service_data = null;
    if ($serviceOnline) {
        $analyzeStarted = microtime(true);
        $service_data = AiServiceClient::analyzeMedicalProfile($allergies, $current_medications);
        if ($service_data === null && (microtime(true) - $analyzeStarted) < 8) {
            AiServiceLauncher::log('analyze_medical_profile: Python analyze failed quickly — retry once');
            usleep(750_000);
            $service_data = AiServiceClient::analyzeMedicalProfile($allergies, $current_medications);
        } elseif ($service_data === null) {
            AiServiceLauncher::log('analyze_medical_profile: Python analyze timed out or returned empty');
        }
        $serviceOnline = AiServiceClient::isHealthy(2);
        $serviceStatus = AiServiceClient::connectionStatus();
    }

    if ($service_data) {
        $datasetValidation = $service_data['dataset_validation'] ?? [];
        $invalidDetection = $service_data['invalid_entry_detection'] ?? [];
        $pipeline = array_merge($service_data, [
            'registration'            => $service_data['registration'] ?? ($datasetValidation['registration'] ?? []),
            'registration_eligible'   => (bool) (
                $service_data['registration_eligible'] ?? ($datasetValidation['registration_eligible'] ?? false)
            ),
            'submission_rejected'     => (bool) ($service_data['submission_rejected'] ?? ($invalidDetection['submission_rejected'] ?? false)),
            'submission_accepted'     => (bool) ($service_data['submission_accepted'] ?? ($invalidDetection['submission_accepted'] ?? false)),
            'save_allowed'            => (bool) ($service_data['save_allowed'] ?? ($invalidDetection['save_allowed'] ?? false)),
            'engine'                  => (string) ($service_data['engine'] ?? 'python-medical-profile-nlp'),
            'service_used'            => true,
            'allergies_text'          => $allergies,
            'medications_text'        => $current_medications,
        ]);
        $pipeline = MedicalProfilePipelineSteps::enrich($pipeline);
        if (trim((string) ($pipeline['summary'] ?? '')) === '') {
            $pipeline['summary'] = nlp_pipeline_summary($pipeline, $invalidDetection);
        }
    } elseif (AI_SERVICE_ENABLED && AI_SERVICE_REQUIRE_PYTHON) {
        $reason = (string) ($serviceStatus['reason'] ?? 'Python AI service unavailable or analyze timed out.');
        Api::error(
            'Python AI service with Groq is required but was not used. '
            . $reason
            . ' Run ai_service\\restart_ai_service.bat and ensure GROQ_API_KEY is set in .env.',
            503,
            [
                'data' => [
                    'engine'                  => 'python-medical-profile-nlp',
                    'service_used'            => false,
                    'service_online'          => $serviceOnline,
                    'service_required'        => true,
                    'ai_service'              => $serviceStatus,
                    'groq_configured'         => (bool) (MedicalAiInterpreter::providerStatus()['groq_configured'] ?? false),
                    'analyze_timeout_seconds' => AI_SERVICE_TIMEOUT_ANALYZE,
                ],
            ]
        );
    } else {
        $pipeline = MedicalValidationWorkflow::run($allergies, $existing_conditions);
        $pipeline['engine'] = 'php-validation-workflow';
        $pipeline['service_used'] = false;
    }

    $invalidDetection = $pipeline['invalid_entry_detection'] ?? [];

    $responseData = [
        'workflow'                => $pipeline['workflow'] ?? null,
        'preprocessing'           => $pipeline['preprocessing'] ?? [],
        'translation'             => $pipeline['translation'] ?? [],
        'fuzzy_matching'          => $pipeline['fuzzy_matching'] ?? [],
        'dataset_validation'      => $pipeline['dataset_validation'] ?? [],
        'invalid_entry_detection' => $invalidDetection,
        'registration'            => $pipeline['registration'] ?? [],
        'registration_eligible'   => (bool) ($pipeline['registration_eligible'] ?? false),
        'submission_rejected'     => (bool) ($pipeline['submission_rejected'] ?? false),
        'submission_accepted'     => (bool) ($pipeline['submission_accepted'] ?? false),
        'save_allowed'            => (bool) ($pipeline['save_allowed'] ?? false),
        'term_results'            => $pipeline['term_results'] ?? [],
        'matched_records'         => $pipeline['matched_records'] ?? ($pipeline['dataset_validation']['matched_records'] ?? []),
        'conditions_recognition'  => $pipeline['conditions_recognition'] ?? [],
        'allergies_recognition'   => $pipeline['allergies_recognition'] ?? [],
        'translated_keywords'     => [
            'allergies'  => NlpPreprocessor::translateKeywords(
                $pipeline['preprocessing']['allergies']['keywords'] ?? []
            ),
            'conditions' => NlpPreprocessor::translateKeywords(
                $pipeline['preprocessing']['conditions']['keywords'] ?? []
            ),
        ],
        'allergies_text'          => $allergies,
        'medications_text'        => $current_medications,
        'english_allergies'       => (string) ($pipeline['translation']['allergies']['english_text'] ?? ''),
        'english_medications'     => (string) ($pipeline['translation']['conditions']['english_text'] ?? ''),
        'confidence_assessment'   => $pipeline['confidence_assessment'] ?? [],
        'clinical_urgency'        => $pipeline['clinical_urgency'] ?? [],
        'registration_decision'   => $pipeline['registration_decision'] ?? [],
        'summary'                 => nlp_pipeline_summary($pipeline, $invalidDetection),
        'engine'                  => (string) ($pipeline['engine'] ?? 'php-validation-workflow'),
        'service_used'            => (bool) ($pipeline['service_used'] ?? false),
        'service_online'          => $serviceOnline,
        'ai_service'              => $serviceStatus,
        'dictionary'              => MedicalDictionary::stats(),
        'pipeline_diagnostics'    => NlpPipelineDiagnostics::collect(
            $pipeline['preprocessing'] ?? [],
            $pipeline['translation'] ?? [],
            (bool) ($pipeline['service_used'] ?? false)
        ),
    ];

    $responseData['submission_rejected'] = (bool) ($pipeline['submission_rejected'] ?? false);
    $responseData['submission_accepted'] = (bool) ($pipeline['submission_accepted'] ?? false);
    $responseData['save_allowed'] = (bool) ($pipeline['save_allowed'] ?? false);

    if (!empty($pipeline['submission_rejected'])) {
        Api::error(
            (string) ($invalidDetection['user_message']
                ?? ($pipeline['registration_decision']['message'] ?? 'Submission rejected.')),
            422,
            ['data' => $responseData, 'invalid_entry_detection' => $invalidDetection]
        );
    }

    $message = (string) (
        $pipeline['registration_decision']['message']
        ?? $invalidDetection['user_message']
        ?? 'All terms translated and verified.'
    );
    Api::success(['data' => $responseData], $message);
} catch (Throwable $e) {
    AiServiceLauncher::log('analyze_medical_profile fatal: ' . $e->getMessage());
    Api::error(
        'Medical NLP pipeline error. Please try again or contact support.',
        500,
        ['error_detail' => $e->getMessage()]
    );
}
