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
            ['wala ko ubo', 'cough'],
            ['wala ko difficulty breathing', 'difficulty breathing'],
            ['wala ko budlay ginhawa', 'difficulty breathing'],
            ['wala budlay ginhawa', 'difficulty breathing'],
            ['indi ko budlay ginhawa', 'difficulty breathing'],
            ['wala ko suka', 'vomiting'],
            ['wala ga suka', 'vomiting'],
            ['wala gasuka', 'vomiting'],
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

        // Generic window: longer Hiligaynon forms first so "wala ko X" is not captured as "ko X".
        // Do NOT treat bare "wala" as negation — in Hiligaynon "wala nga …" often means LEFT side
        // ("wala nga kamot" = left hand), not "no hand".
        // Do NOT treat "indi ko maka..." ability denials as symptom negation —
        // "indi ko makaginhawa" means cannot breathe (POSITIVE emergency finding).
        $genericHay = preg_replace(
            '/\bindi(?:\s+ko)?\s+maka[a-z\-]*(?:\s+[a-z\-]+){0,3}/u',
            ' ',
            $hay
        ) ?? $hay;
        // Strip laterality phrases so "wala nga kamot/tiil/mata" never become negations.
        $genericHay = preg_replace(
            '/\b(wala|tuo|left|right)\s+nga\s+(kamot|tiil|paa|mata|bahin|dughan|ulo|ilong)\b/u',
            ' ',
            $genericHay
        ) ?? $genericHay;
        if (preg_match_all(
            '/\b(?:no|not|without|denies|wala\s+ko|wala\s+akong|wala\s+ako|walang|walay|indi|hindi(?:\s+ako)?)\s+([a-z0-9\-\s]{2,40})/u',
            $genericHay,
            $m
        )) {
            foreach ($m[1] as $span) {
                $span = trim(preg_replace('/\s+/u', ' ', (string) $span) ?? '');
                if ($span === '') {
                    continue;
                }
                if (self::isPositiveInabilitySpan($span)) {
                    continue;
                }
                $negated[] = $span;
                // Normalize leading person markers accidentally left in the span.
                $stripped = trim(preg_replace('/^(ko|ako|akong|sang|man)\s+/u', '', $span) ?? $span);
                if ($stripped !== '' && $stripped !== $span && !self::isPositiveInabilitySpan($stripped)) {
                    $negated[] = $stripped;
                }
            }
        }

        $negated = array_values(array_unique(array_filter($negated)));

        return array_values(array_filter(
            $negated,
            static fn (string $neg): bool => !self::isPositiveInabilitySpan($neg)
        ));
    }

    private static function isPositiveInabilitySpan(string $span): bool
    {
        $span = strtolower(trim($span));
        if ($span === '') {
            return false;
        }

        // Ability / incapacity constructions are clinical POSITIVES, not negations.
        return (bool) preg_match(
            '/\b(maka(?:ginhawa|hinga)|makaginhawa|makahinga|kaginhawa|ginhawa|hinga|breathe|breathing)\b/u',
            $span
        ) && !preg_match('/\b(budlay|lisod|kapos|difficulty|shortness)\b/u', $span);
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

        $negatedConcepts = self::detectNegatedConcepts($hay);
        $kept = [];
        foreach ($flags as $flag) {
            $pat = strtolower((string) (($flag['matched_pattern'] ?? '') ?: ($flag['english_pattern'] ?? '') ?: ($flag['flag_name'] ?? '')));
            $name = strtolower((string) ($flag['flag_name'] ?? ''));
            $negated = false;

            // "wala ko difficulty breathing" (person marker between negator and finding)
            foreach (['no ', 'not ', 'without ', 'denies ', 'wala ', 'wala ko ', 'wala akong ', 'wala ako ', 'walang ', 'indi ', 'indi ko ', 'hindi ', 'hindi ako '] as $neg) {
                if ($pat !== '' && str_contains($hay, $neg . $pat)) {
                    $negated = true;
                    break;
                }
                if ($name !== '' && str_contains($hay, $neg . $name)) {
                    $negated = true;
                    break;
                }
            }

            if (!$negated) {
                foreach ($negatedConcepts as $neg) {
                    $neg = strtolower(trim((string) $neg));
                    if ($neg === '') {
                        continue;
                    }
                    if (
                        ($pat !== '' && ($pat === $neg || str_contains($pat, $neg) || str_contains($neg, $pat)))
                        || ($name !== '' && ($name === $neg || str_contains($name, $neg) || str_contains($neg, $name)))
                    ) {
                        $negated = true;
                        break;
                    }
                    $aliases = [
                        'difficulty breathing' => ['difficulty breathing', 'shortness of breath', 'budlay ginhawa', 'ginhawa', 'dyspnea', 'breathing'],
                        'chest pain' => ['chest pain', 'dughan', 'dibdib'],
                        'cough' => ['cough', 'ubo'],
                        'vomiting' => ['vomiting', 'suka'],
                        'seizure' => ['seizure', 'convulsion', 'naguyam'],
                        'unconscious' => ['unconscious', 'unresponsive', 'malay'],
                    ];
                    foreach ($aliases as $concept => $words) {
                        $negHits = ($neg === $concept);
                        $flagHits = false;
                        foreach ($words as $w) {
                            if ($neg === $w || str_contains($neg, $w)) {
                                $negHits = true;
                            }
                            if (($pat !== '' && str_contains($pat, $w)) || ($name !== '' && str_contains($name, $w))) {
                                $flagHits = true;
                            }
                        }
                        if ($negHits && $flagHits) {
                            $negated = true;
                            break 2;
                        }
                    }
                }
            }

            // Explicit Hiligaynon negation of breathing/chest
            if (str_contains($hay, 'indi budlay ginhawa') && (str_contains($pat, 'breath') || str_contains($name, 'breath'))) {
                $negated = true;
            }
            if (str_contains($hay, 'indi masakit dughan') && (str_contains($pat, 'chest') || str_contains($name, 'chest'))) {
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
