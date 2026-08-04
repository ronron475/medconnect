<?php
/**
 * Negation detection for clinical NLP — never extract negated symptoms.
 * Loads patterns from data/nlp/negation_words.csv (CSV-expandable).
 */

final class NegationDetector
{
    /** @var list<array{pattern:string,negated_concept:string}>|null */
    private static ?array $patterns = null;

    /** @return list<array{pattern:string,negated_concept:string}> */
    public static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }

        self::$patterns = [];
        $path = BASE_PATH . '/data/nlp/negation_words.csv';
        if (is_readable($path)) {
            $handle = fopen($path, 'r');
            if ($handle !== false) {
                $header = fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    $data = array_combine(
                        array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                        array_map(static fn ($v) => trim((string) $v), $row)
                    ) ?: [];
                    $pattern = strtolower((string) ($data['pattern'] ?? ''));
                    $concept = strtolower((string) ($data['negated_concept'] ?? ''));
                    // Skip generator padding ids in pattern
                    $pattern = trim(preg_replace('/\s*#\d+\s*$/', '', $pattern) ?? $pattern);
                    if ($pattern === '' || str_contains($pattern, 'case')) {
                        continue;
                    }
                    if ($pattern !== '' && $concept !== '') {
                        self::$patterns[] = [
                            'pattern' => $pattern,
                            'negated_concept' => $concept,
                        ];
                    }
                }
                fclose($handle);
            }
        }

        // Built-in safety net (always available)
        foreach ([
            ['no fever', 'fever'],
            ['no cough', 'cough'],
            ['no chest pain', 'chest pain'],
            ['no vomiting', 'vomiting'],
            ['not dizzy', 'dizziness'],
            ['wala akong lagnat', 'fever'],
            ['wala akong ubo', 'cough'],
            ['wala ko ginaubo', 'cough'],
            ['wala ko lagnat', 'fever'],
            ['indi budlay ginhawa', 'difficulty breathing'],
            ['indi masakit dughan', 'chest pain'],
            ['indi gasuka', 'vomiting'],
            ['hindi ako nilalagnat', 'fever'],
            ['walang sakit sa dibdib', 'chest pain'],
            ['no shortness of breath', 'difficulty breathing'],
        ] as [$p, $c]) {
            self::$patterns[] = ['pattern' => $p, 'negated_concept' => $c];
        }

        usort(self::$patterns, static fn (array $a, array $b): int => strlen($b['pattern']) <=> strlen($a['pattern']));

        return self::$patterns;
    }

    /**
     * @return list<string> Negated concept labels (lowercase)
     */
    public static function detectNegatedConcepts(string $text): array
    {
        $hay = strtolower(trim($text));
        if ($hay === '') {
            return [];
        }

        $negated = [];
        foreach (self::patterns() as $row) {
            if ($row['pattern'] !== '' && str_contains($hay, $row['pattern'])) {
                $negated[] = $row['negated_concept'];
            }
        }

        // Generic window: "no/wala/indi/hindi + term"
        if (preg_match_all('/\b(?:no|not|without|denies|wala(?:\s+ako(?:ng)?)?|wala\s+ko|indi|hindi(?:\s+ako)?)\s+([a-z\-\s]{3,40})/u', $hay, $m)) {
            foreach ($m[1] as $span) {
                $negated[] = trim($span);
            }
        }

        return array_values(array_unique(array_filter($negated)));
    }

    /**
     * Filter out symptom rows whose name/matched term is negated.
     *
     * @param list<array<string, mixed>> $symptoms
     * @return list<array<string, mixed>>
     */
    public static function filterSymptoms(array $symptoms, string $original, string $english = ''): array
    {
        $negated = self::detectNegatedConcepts($original . ' ' . $english);
        if ($negated === [] || $symptoms === []) {
            return $symptoms;
        }

        $kept = [];
        foreach ($symptoms as $sym) {
            $name = strtolower((string) ($sym['symptom_name'] ?? $sym['english_term'] ?? ''));
            $matched = strtolower((string) ($sym['matched_term'] ?? ''));
            $id = strtolower(str_replace('_', ' ', (string) ($sym['id'] ?? '')));
            $drop = false;
            foreach ($negated as $neg) {
                if ($neg === '') {
                    continue;
                }
                if (
                    ($name !== '' && (str_contains($name, $neg) || str_contains($neg, $name)))
                    || ($matched !== '' && (str_contains($matched, $neg) || str_contains($neg, $matched)))
                    || ($id !== '' && (str_contains($id, $neg) || str_contains($neg, $id)))
                ) {
                    $drop = true;
                    break;
                }
                // Map common concept aliases
                $aliases = [
                    'fever' => ['fever', 'lagnat', 'hilanat'],
                    'cough' => ['cough', 'ubo'],
                    'chest pain' => ['chest pain', 'dughan', 'dibdib'],
                    'difficulty breathing' => ['difficulty breathing', 'shortness of breath', 'ginhawa', 'dyspnea'],
                    'vomiting' => ['vomiting', 'suka'],
                    'dizziness' => ['dizziness', 'dizzy', 'lipong'],
                ];
                foreach ($aliases as $concept => $words) {
                    if ($neg === $concept || in_array($neg, $words, true)) {
                        foreach ($words as $w) {
                            if (str_contains($name, $w) || str_contains($matched, $w) || str_contains($id, str_replace(' ', '_', $w))) {
                                $drop = true;
                                break 3;
                            }
                        }
                    }
                }
            }
            if (!$drop) {
                $kept[] = $sym;
            }
        }

        return $kept;
    }

    /**
     * Filter red-flag matches that are clearly negated.
     *
     * @param list<array<string, mixed>> $flags
     * @return list<array<string, mixed>>
     */
    public static function filterRedFlags(array $flags, string $original, string $english = ''): array
    {
        $hay = strtolower(trim($original . ' ' . $english));
        if ($hay === '' || $flags === []) {
            return $flags;
        }

        $kept = [];
        foreach ($flags as $flag) {
            $pat = strtolower((string) (($flag['matched_pattern'] ?? '') ?: ($flag['english_pattern'] ?? '') ?: ($flag['flag_name'] ?? '')));
            $negated = false;
            foreach (['no ', 'not ', 'wala ', 'indi ', 'hindi ', 'without ', 'denies '] as $neg) {
                if ($pat !== '' && str_contains($hay, $neg . $pat)) {
                    $negated = true;
                    break;
                }
            }
            // Explicit Hiligaynon negation of breathing/chest
            if (str_contains($hay, 'indi budlay ginhawa') && str_contains($pat, 'breath')) {
                $negated = true;
            }
            if (str_contains($hay, 'indi masakit dughan') && str_contains($pat, 'chest')) {
                $negated = true;
            }
            if (!$negated) {
                $kept[] = $flag;
            }
        }

        return $kept;
    }

    public static function clearCache(): void
    {
        self::$patterns = null;
    }
}
