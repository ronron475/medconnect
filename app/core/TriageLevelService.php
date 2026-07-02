<?php
/**
 * Canonical triage severity for GIS and downstream consumers.
 *
 * AI/NLP engines and manual reassessment write `triage_level` on triage_results.
 * GIS reads this field only — it never infers severity from labels or symptoms.
 */
final class TriageLevelService
{
    public const NON_URGENT = 'non_urgent';
    public const URGENT = 'urgent';
    public const EMERGENCY = 'emergency';

    /** @return list<string> */
    public static function validLevels(): array
    {
        return [self::NON_URGENT, self::URGENT, self::EMERGENCY];
    }

    public static function isValid(?string $level): bool
    {
        return in_array(strtolower(trim((string) $level)), self::validLevels(), true);
    }

    public static function fromClassification(?string $classification): string
    {
        return match (strtoupper(trim((string) $classification))) {
            'EMERGENCY' => self::EMERGENCY,
            'URGENT'    => self::URGENT,
            default     => self::NON_URGENT,
        };
    }

    /**
     * Map legacy numeric / string db `level` values to GIS triage_level.
     */
    public static function fromDbLevel(?string $level): string
    {
        $normalized = strtolower(trim((string) $level));

        if (in_array($normalized, ['1', 'high', 'emergency'], true)) {
            return self::EMERGENCY;
        }
        if (in_array($normalized, ['2', 'urgent'], true)) {
            return self::URGENT;
        }

        return self::NON_URGENT;
    }

    /**
     * Resolve the canonical GIS triage_level from persisted or legacy fields.
     * Used only when writing/backfilling — not by the GIS display layer.
     *
     * @param array<string, mixed> $fields
     */
    public static function resolve(array $fields): string
    {
        $explicit = strtolower(trim((string) ($fields['triage_level'] ?? '')));
        if (self::isValid($explicit)) {
            return $explicit;
        }

        if (!empty($fields['triage_classification'])) {
            return self::fromClassification((string) $fields['triage_classification']);
        }

        if (!empty($fields['level'])) {
            return self::fromDbLevel((string) $fields['level']);
        }

        return self::NON_URGENT;
    }

    /**
     * @param array<string, mixed> $assessment MedicalAssessmentEngine::assess() result
     */
    public static function fromAssessment(array $assessment): string
    {
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];

        if (!empty($triage['gis_triage_level']) && self::isValid((string) $triage['gis_triage_level'])) {
            return (string) $triage['gis_triage_level'];
        }

        return self::resolve([
            'triage_classification' => $triage['triage_classification'] ?? '',
            'level'                 => $assessment['db_level'] ?? '',
        ]);
    }

    public static function displayLabel(string $level): string
    {
        return match (self::isValid($level) ? $level : self::NON_URGENT) {
            self::EMERGENCY => 'Emergency',
            self::URGENT    => 'Urgent',
            default         => 'Non-Urgent',
        };
    }
}
