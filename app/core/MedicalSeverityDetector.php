<?php
/**
 * Symptom severity analysis from Hiligaynon, English, and Tagalog intensity markers.
 */

final class MedicalSeverityDetector
{
    /** @var list<string> */
    private const MILD_MARKERS = [
        'mild', 'slight', 'minor', 'little', 'medyo', ' gamay', 'gamay lang',
        'light', 'low grade', ' konti',
    ];

    /** @var list<string> */
    private const MODERATE_MARKERS = [
        'moderate', 'medium', 'fairly', 'somewhat', ' medyo grabe', 'persistent',
        'ongoing', 'for days', 'for a week',
    ];

    /** @var list<string> */
    private const SEVERE_MARKERS = [
        'severe', 'extreme', 'intense', 'unbearable', 'grabe', 'malala', 'grabe gid',
        'very bad', 'worst', 'cannot', "can't", 'indi ko kaginhawa', 'indi ko makaginhawa',
        'daw mapatay', 'grabe pagdugo', 'loss of consciousness', 'unconscious',
    ];

    /**
     * @param list<array<string, mixed>> $concepts
     * @return array{severity:string, severity_label:string, source:string}
     */
    public static function detect(string $original, string $english, array $concepts, string $nlpSeverity = ''): array
    {
        $haystack = mb_strtolower(trim($original . ' ' . $english));

        foreach (self::SEVERE_MARKERS as $marker) {
            if (mb_strpos($haystack, mb_strtolower($marker)) !== false) {
                return self::result('severe', 'phrase_marker');
            }
        }

        $mildHits = 0;
        $moderateHits = 0;
        foreach (self::MILD_MARKERS as $marker) {
            if (mb_strpos($haystack, mb_strtolower($marker)) !== false) {
                $mildHits++;
            }
        }
        foreach (self::MODERATE_MARKERS as $marker) {
            if (mb_strpos($haystack, mb_strtolower($marker)) !== false) {
                $moderateHits++;
            }
        }

        if ($mildHits > 0 && $moderateHits === 0) {
            return self::result('mild', 'intensity_marker');
        }
        if ($moderateHits > 0) {
            return self::result('moderate', 'intensity_marker');
        }

        if ($nlpSeverity !== '') {
            return self::result(self::normalize($nlpSeverity), 'nlp_triage');
        }

        $conceptCount = count($concepts);
        if ($conceptCount >= 3) {
            return self::result('moderate', 'symptom_burden');
        }
        if ($conceptCount >= 1) {
            return self::result('mild', 'default');
        }

        return self::result('mild', 'default');
    }

    private static function normalize(string $severity): string
    {
        $severity = mb_strtolower(trim($severity));
        return match ($severity) {
            'emergency' => 'severe',
            'severe', 'high' => 'severe',
            'moderate', 'medium' => 'moderate',
            default => 'mild',
        };
    }

    /**
     * @return array{severity:string, severity_label:string, source:string}
     */
    private static function result(string $severity, string $source): array
    {
        $labels = [
            'mild'     => 'Mild',
            'moderate' => 'Moderate',
            'severe'   => 'Severe',
        ];

        return [
            'severity'       => $severity,
            'severity_label' => $labels[$severity] ?? 'Mild',
            'source'         => $source,
        ];
    }
}
