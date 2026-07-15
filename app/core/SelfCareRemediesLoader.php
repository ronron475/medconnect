<?php
/**
 * Loads curated non-urgent self-care tips from data/nlp/self_care_remedies.csv.
 */

final class SelfCareRemediesLoader
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $rows = null;

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];
        $path = BASE_PATH . '/data/nlp/self_care_remedies.csv';
        if (!is_readable($path)) {
            return self::$rows;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return self::$rows;
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            return self::$rows;
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $data = [];
            foreach ($header as $i => $key) {
                $data[$key] = trim((string) ($row[$i] ?? ''));
            }
            if (($data['active'] ?? '1') === '0') {
                continue;
            }
            $key = strtolower(trim((string) ($data['symptom_key'] ?? '')));
            if ($key === '') {
                continue;
            }

            $aliases = preg_split('/\|/', (string) ($data['aliases'] ?? '')) ?: [];
            $aliasList = [];
            foreach ($aliases as $alias) {
                $alias = self::normalize((string) $alias);
                if ($alias !== '') {
                    $aliasList[] = $alias;
                }
            }
            $aliasList[] = self::normalize($key);
            $aliasList[] = self::normalize((string) ($data['display_name'] ?? ''));
            $aliasList = array_values(array_unique(array_filter($aliasList)));

            $tips = [];
            foreach (['tip_1', 'tip_2', 'tip_3', 'tip_4'] as $tipKey) {
                $tip = trim((string) ($data[$tipKey] ?? ''));
                if ($tip !== '') {
                    $tips[] = $tip;
                }
            }

            self::$rows[] = [
                'symptom_key' => $key,
                'display_name' => (string) ($data['display_name'] ?? $key),
                'category' => (string) ($data['category'] ?? 'general'),
                'aliases' => $aliasList,
                'tips' => $tips,
                'when_to_seek_care' => trim((string) ($data['when_to_seek_care'] ?? '')),
            ];
        }
        fclose($handle);

        return self::$rows;
    }

    /**
     * @param list<string> $detectedSymptoms
     * @return array{matched: bool, symptom_key: string, display_name: string, tips: list<string>, when_to_seek_care: string}
     */
    public static function match(string $chiefComplaint, string $englishText = '', array $detectedSymptoms = []): array
    {
        $complaintOnly = self::normalize($chiefComplaint);
        $primary = self::bestMatch($complaintOnly, true);
        if ($primary !== null && ($primary['_score'] ?? 0) >= 70) {
            return self::formatMatch($primary);
        }

        $haystackParts = [$chiefComplaint, $englishText];
        foreach ($detectedSymptoms as $symptom) {
            if (is_string($symptom)) {
                $haystackParts[] = $symptom;
            } elseif (is_array($symptom)) {
                $haystackParts[] = (string) ($symptom['term'] ?? $symptom['symptom'] ?? $symptom['english'] ?? $symptom['name'] ?? '');
            }
        }
        $haystack = self::normalize(implode(' ', $haystackParts));
        $best = self::bestMatch($haystack, false);

        if ($best === null) {
            return [
                'matched' => false,
                'symptom_key' => '',
                'display_name' => '',
                'tips' => [],
                'when_to_seek_care' => '',
            ];
        }

        return self::formatMatch($best);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function bestMatch(string $haystack, bool $preferExact): ?array
    {
        if ($haystack === '') {
            return null;
        }

        $fallback = null;
        $best = null;
        $bestScore = 0;

        foreach (self::all() as $row) {
            if (($row['symptom_key'] ?? '') === 'default_non_urgent') {
                $fallback = $row;
                continue;
            }
            $score = 0;
            foreach ($row['aliases'] as $alias) {
                if ($alias === '' || mb_strlen($alias) < 3) {
                    continue;
                }
                if ($haystack === $alias) {
                    $score = max($score, 100);
                } elseif (str_contains($haystack, $alias)) {
                    $score = max($score, 40 + min(40, mb_strlen($alias)));
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
                $best['_score'] = $score;
            }
        }

        if ($preferExact && $bestScore >= 70) {
            return $best;
        }

        if ($best === null || $bestScore < 40) {
            if ($fallback !== null) {
                $fallback['_score'] = 10;
            }
            return $fallback;
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $best
     * @return array{matched: bool, symptom_key: string, display_name: string, tips: list<string>, when_to_seek_care: string}
     */
    private static function formatMatch(array $best): array
    {
        $score = (int) ($best['_score'] ?? 0);
        return [
            'matched' => $score >= 40 || ($best['symptom_key'] ?? '') === 'default_non_urgent',
            'symptom_key' => (string) ($best['symptom_key'] ?? ''),
            'display_name' => (string) ($best['display_name'] ?? ''),
            'tips' => array_values($best['tips'] ?? []),
            'when_to_seek_care' => (string) ($best['when_to_seek_care'] ?? ''),
        ];
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $text);
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
