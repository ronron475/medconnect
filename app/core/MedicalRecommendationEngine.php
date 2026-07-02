<?php
/**
 * Triage classification and patient-facing care recommendations.
 */

final class MedicalRecommendationEngine
{
    public const DISCLAIMER = 'This assessment is AI-generated for informational purposes only and is not a medical diagnosis. Please consult a licensed healthcare professional for proper evaluation and treatment.';

    /**
     * @param array<string, mixed> $triageInput
     * @return array{
     *   triage_level:string,
     *   triage_classification:string,
     *   triage_display:string,
     *   triage_badge_class:string,
     *   triage_icon:string,
     *   recommended_action:string,
     *   db_level:string,
     *   urgency_label:string
     * }
     */
    public static function classify(array $triageInput): array
    {
        $nlpLevel = mb_strtoupper((string) ($triageInput['nlp_triage_level'] ?? 'LOW'));
        $severity = mb_strtolower((string) ($triageInput['severity'] ?? 'mild'));
        $mlLevel = mb_strtolower((string) ($triageInput['ml_triage_level'] ?? ''));

        $classification = 'NON_URGENT';
        if ($nlpLevel === 'EMERGENCY' || $mlLevel === 'critical') {
            $classification = 'EMERGENCY';
        } elseif (
            $nlpLevel === 'HIGH'
            || $nlpLevel === 'MEDIUM'
            || in_array($mlLevel, ['high', 'moderate'], true)
            || $severity === 'severe'
        ) {
            $classification = 'URGENT';
        }

        $map = [
            'NON_URGENT' => [
                'triage_level'       => 'LOW',
                'triage_display'     => 'NON-URGENT',
                'triage_icon'        => '🟢',
                'triage_badge_class' => 'triage-badge--green',
                'recommended_action' => 'Monitor symptoms and schedule a routine consultation if symptoms persist.',
                'db_level'           => '3',
                'urgency_label'      => 'Non-Urgent (Routine)',
            ],
            'URGENT' => [
                'triage_level'       => 'HIGH',
                'triage_display'     => 'URGENT',
                'triage_icon'        => '🟡',
                'triage_badge_class' => 'triage-badge--yellow',
                'recommended_action' => 'Consult a healthcare provider within 24 hours.',
                'db_level'           => '2',
                'urgency_label'      => 'Urgent (Priority)',
            ],
            'EMERGENCY' => [
                'triage_level'       => 'EMERGENCY',
                'triage_display'     => 'EMERGENCY',
                'triage_icon'        => '🔴',
                'triage_badge_class' => 'triage-badge--red',
                'recommended_action' => 'Seek emergency medical care immediately.',
                'db_level'           => '1',
                'urgency_label'      => 'Emergency (Immediate)',
            ],
        ];

        $result = $map[$classification];
        $result['triage_classification'] = $classification;
        $result['gis_triage_level'] = match ($classification) {
            'EMERGENCY' => 'emergency',
            'URGENT'    => 'urgent',
            default     => 'non_urgent',
        };

        return $result;
    }

    /**
     * @param array<string, mixed> $classification
     * @param list<string> $possibleConditions
     * @return list<string>
     */
    public static function buildRecommendations(array $classification, array $possibleConditions): array
    {
        $items = [
            (string) ($classification['recommended_action'] ?? ''),
        ];

        if ($possibleConditions !== []) {
            $items[] = 'Possible related conditions were identified for discussion with your provider — not a confirmed diagnosis.';
        }

        $class = (string) ($classification['triage_classification'] ?? 'NON_URGENT');
        if ($class === 'NON_URGENT') {
            $items[] = 'Rest, stay hydrated, and track symptom changes over the next 24–48 hours.';
        } elseif ($class === 'URGENT') {
            $items[] = 'Avoid self-medicating with prescription drugs without professional guidance.';
            $items[] = 'Prepare a brief symptom timeline before your consultation.';
        } else {
            $items[] = 'Call local emergency services or go to the nearest emergency facility without delay.';
            $items[] = 'Do not drive yourself if you feel faint, confused, or severely unwell.';
        }

        $items[] = self::DISCLAIMER;

        return array_values(array_filter(array_unique($items)));
    }
}
