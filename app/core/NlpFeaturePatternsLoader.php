<?php
/**
 * Loads duration, temperature, pain scale, and risk-factor pattern CSVs for feature extractors.
 */

final class NlpFeaturePatternsLoader
{
    /** @var array<string, list<array<string, string>>>|null */
    private static ?array $cache = null;

    /** @return array<string, list<array<string, string>>> */
    public static function patterns(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [
            'duration'     => self::loadCsv('duration_patterns.csv', ['pattern', 'bucket', 'days']),
            'temperature'  => self::loadCsv('temperature_patterns.csv', ['pattern', 'celsius', 'band']),
            'pain_scale'   => self::loadCsv('pain_scale.csv', ['pattern', 'pain_score', 'band']),
            'risk_factors' => self::loadCsv('risk_factors.csv', ['pattern', 'label', 'category']),
        ];

        return self::$cache;
    }

    /**
     * @param list<string> $keys
     * @return list<array<string, string>>
     */
    private static function loadCsv(string $filename, array $keys): array
    {
        $path = BASE_PATH . '/data/nlp/' . $filename;
        $rows = [];
        if (!is_readable($path)) {
            return $rows;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $rows;
        }
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            if (($data['status'] ?? 'active') !== 'active') {
                continue;
            }
            $entry = [];
            foreach ($keys as $key) {
                $entry[$key] = strtolower((string) ($data[$key] ?? ''));
            }
            if (($entry[$keys[0]] ?? '') === '') {
                continue;
            }
            $rows[] = $entry;
        }
        fclose($handle);
        usort($rows, static fn (array $a, array $b): int => strlen($b[$keys[0]]) <=> strlen($a[$keys[0]]));

        return $rows;
    }
}
