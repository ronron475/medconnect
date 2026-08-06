<?php
/**
 * BHW chief complaint — canonical CDS NLP pipeline (same engine as CDS demo + registration).
 */

/**
 * Run centralized chief-complaint NLP for BHW walk-in triage.
 *
 * @return array<string, mixed>
 */
function bhw_run_chief_complaint_nlp(string $complaint): array
{
    $complaint = trim($complaint);
    $assessment = ChiefComplaintNlpService::assess($complaint, []);

    return [
        'engine'                => (string) ($assessment['engine'] ?? 'php-medical-assessment'),
        'engine_chain'          => ChiefComplaintNlpService::ENGINE_CHAIN,
        'service_used'          => (bool) ($assessment['service_used'] ?? false),
        'service_online'        => AI_SERVICE_ENABLED && AiServiceClient::isHealthy(2),
        'ai_service'            => AiServiceClient::connectionStatus(),
        'clinical_urgency'      => ChiefComplaintNlpService::buildClinicalUrgency($assessment),
        'confidence_assessment' => $assessment['confidence'] ?? [],
        'translation'           => [
            'combined_english' => (string) ($assessment['english_translation'] ?? ''),
            'conditions'       => ['english_text' => (string) ($assessment['english_translation'] ?? '')],
        ],
        'preprocessing'         => [
            'conditions' => [
                'original'        => $complaint,
                'normalized'      => (string) ($assessment['normalized_text'] ?? ''),
                'english_preview' => (string) ($assessment['english_translation'] ?? ''),
                'keywords'        => is_array($assessment['detected_symptoms'] ?? null) ? $assessment['detected_symptoms'] : [],
            ],
        ],
        'pipeline_stages'       => $assessment['pipeline_stages'] ?? [],
        'symptom_evidence'      => $assessment['symptom_evidence'] ?? [],
        'matched_rules'         => $assessment['triage']['matched_rules'] ?? [],
        'assessment'            => $assessment,
        'summary'               => ChiefComplaintNlpService::buildCdsSummary($assessment, $complaint),
        'medications_text'      => $complaint,
        'allergies_text'        => '',
    ];
}

/**
 * Map pipeline output to the BHW assessment shape used by triage booking.
 *
 * @param array<string, mixed> $pipeline
 * @return array<string, mixed>
 */
function bhw_map_nlp_pipeline_to_assessment(array $pipeline, string $complaint): array
{
    if (is_array($pipeline['assessment'] ?? null) && $pipeline['assessment'] !== []) {
        return ChiefComplaintNlpService::buildBhwAssessment($pipeline['assessment'], $complaint);
    }

    return ChiefComplaintNlpService::buildBhwAssessment(
        ChiefComplaintNlpService::assess($complaint, []),
        $complaint
    );
}

/**
 * Compact pipeline payload for the BHW triage UI.
 *
 * @param array<string, mixed> $pipeline
 * @return array<string, mixed>
 */
function bhw_format_pipeline_for_ui(array $pipeline): array
{
    $assessment = is_array($pipeline['assessment'] ?? null) ? $pipeline['assessment'] : [];
    $complaint = (string) ($pipeline['medications_text'] ?? $pipeline['preprocessing']['conditions']['original'] ?? '');

    if ($assessment === [] && $complaint !== '') {
        $assessment = ChiefComplaintNlpService::assess($complaint, []);
    }

    return ChiefComplaintNlpService::buildBhwPipelineUi($assessment, $complaint);
}

/**
 * @param array<string, mixed> $pipeline
 */
function bhw_triage_nlp_summary(array $pipeline): string
{
    $summary = trim((string) ($pipeline['summary']['clinical_reasoning'] ?? $pipeline['summary']['reason'] ?? ''));
    if ($summary !== '') {
        return $summary;
    }

    $clinical = is_array($pipeline['clinical_urgency'] ?? null) ? $pipeline['clinical_urgency'] : [];

    return trim((string) ($clinical['clinical_reasoning'] ?? $clinical['reason'] ?? 'No medical terms were extracted from the concern.'));
}
