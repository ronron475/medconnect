<?php
/**
 * Production patient-answer normalizer for interview / CDS accuracy.
 *
 * Applies existing misspelling CSV + fuzzy clinical lexicon (when available).
 * Does not invent clinical facts. Failures must be caught by callers.
 */
final class ClinicalAnswerNormalizer
{
    /**
     * @return array{
     *   original: string,
     *   corrected: string,
     *   changed: bool,
     *   corrections: list<array<string,mixed>>,
     *   sources: list<string>
     * }
     */
    public static function prepare(string $text, string $awaiting = ''): array
    {
        $original = trim($text);
        if ($original === '') {
            return [
                'original' => '',
                'corrected' => '',
                'changed' => false,
                'corrections' => [],
                'sources' => [],
            ];
        }

        $working = $original;
        $corrections = [];
        $sources = [];

        try {
            if (class_exists('MedicalMisspellingsLoader')) {
                $log = MedicalMisspellingsLoader::applyCorrectionsWithLog($working);
                $next = trim((string) ($log['text'] ?? $working));
                $rows = is_array($log['corrections'] ?? null) ? $log['corrections'] : [];
                if ($next !== '' && ($next !== $working || $rows !== [])) {
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            $corrections[] = $row;
                        }
                    }
                    $working = $next;
                    $sources[] = 'medical_misspellings';
                }
            }
        } catch (Throwable) {
            // keep working text
        }

        try {
            if (class_exists('HiligaynonTextNormalizer')) {
                $hil = trim((string) HiligaynonTextNormalizer::normalize($working));
                if ($hil !== '' && mb_strtolower($hil) !== mb_strtolower($working)) {
                    $working = $hil;
                    $sources[] = 'hiligaynon_normalizer';
                }
            }
        } catch (Throwable) {
            // keep working text
        }

        try {
            if (class_exists('NlpStep3DemoAnswerFuzzy')) {
                $prep = NlpStep3DemoAnswerFuzzy::prepare($working, $awaiting);
                $next = trim((string) ($prep['corrected'] ?? $working));
                $rows = is_array($prep['corrections'] ?? null) ? $prep['corrections'] : [];
                if ($next !== '') {
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            $corrections[] = $row;
                        }
                    }
                    if ($next !== $working || $rows !== []) {
                        $sources[] = 'answer_fuzzy';
                    }
                    $working = $next;
                }
            }
        } catch (Throwable) {
            // keep working text
        }

        // Also keep original tokens available for extractors that match un-normalized slang.
        $corrected = trim($working);
        if ($corrected !== '' && mb_strtolower($corrected) !== mb_strtolower($original)) {
            $corrected = trim($original . ' ' . $corrected);
        } else {
            $corrected = $corrected !== '' ? $corrected : $original;
        }

        return [
            'original' => $original,
            'corrected' => $corrected,
            'changed' => mb_strtolower(trim($working)) !== mb_strtolower($original),
            'corrections' => $corrections,
            'sources' => array_values(array_unique($sources)),
        ];
    }

    public static function correct(string $text, string $awaiting = ''): string
    {
        $prep = self::prepare($text, $awaiting);

        return (string) ($prep['corrected'] ?? $text);
    }
}
