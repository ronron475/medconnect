<?php
/**
 * Canonical medical condition → NON_URGENT | URGENT | EMERGENCY from CSV.
 * Used by ClinicalTriageEngine so final triage is CSV-driven, not LLM-driven.
 */

final class ConditionSeverityLoader
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $index = null;

    public static function clearCache(): void
    {
        self::$index = null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        self::$index = [];
        // Curated runtime registry only (ICD overlay is for validation / offline tooling).
        self::loadFile(BASE_PATH . '/data/nlp/condition_triage_severity.csv', true);

        return self::$index;
    }

    /**
     * @param list<string> $terms
     * @return array<string, mixed>|null
     */
    public static function lookup(array $terms): ?array
    {
        $index = self::index();
        $rank = ['NON_URGENT' => 0, 'URGENT' => 1, 'EMERGENCY' => 2];
        $best = null;

        foreach ($terms as $term) {
            $key = self::normalize((string) $term);
            if ($key === '') {
                continue;
            }
            $meta = $index[$key] ?? null;
            if ($meta === null) {
                continue;
            }
            if ($best === null || $rank[$meta['severity_level']] > $rank[$best['severity_level']]) {
                $best = $meta;
            }
        }

        return $best;
    }

    private static function loadFile(string $path, bool $prefer): void
    {
        if (!is_readable($path)) {
            return;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return;
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        $source = basename($path);

        while (($row = fgetcsv($handle)) !== false) {
            $data = [];
            foreach ($header as $i => $col) {
                $data[$col] = trim((string) ($row[$i] ?? ''));
            }
            $level = self::canonicalize($data['severity_level'] ?? '');
            $name = (string) ($data['medical_condition'] ?? $data['condition_name'] ?? '');
            if ($level === '' || $name === '') {
                continue;
            }
            $meta = [
                'medical_condition'   => $name,
                'severity_level'       => $level,
                'urgency_score'       => (int) ($data['urgency_score'] ?? 0) ?: self::defaultScore($level),
                'emergency_flag'      => in_array(strtolower((string) ($data['emergency_flag'] ?? '0')), ['1', 'true', 'yes'], true),
                'recommended_action'  => (string) ($data['recommended_action'] ?? ''),
                'provider_required'   => in_array(strtolower((string) ($data['provider_required'] ?? '0')), ['1', 'true', 'yes'], true),
                'hospital_referral'   => in_array(strtolower((string) ($data['hospital_referral'] ?? '0')), ['1', 'true', 'yes'], true),
                'source'              => $source,
            ];
            self::add($name, $meta, $prefer);
            foreach (explode(';', (string) ($data['synonyms'] ?? '')) as $syn) {
                self::add($syn, $meta, false);
            }
            self::add((string) ($data['hiligaynon_term'] ?? ''), $meta, false);
            foreach (explode(';', (string) ($data['keywords'] ?? '')) as $kw) {
                self::add($kw, $meta, false);
            }
        }
        fclose($handle);
    }

    /** @param array<string, mixed> $meta */
    private static function add(string $key, array $meta, bool $prefer): void
    {
        $k = self::normalize($key);
        if ($k === '') {
            return;
        }
        if (isset(self::$index[$k]) && !$prefer) {
            return;
        }
        self::$index[$k] = $meta;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function canonicalize(string $raw): string
    {
        $v = strtoupper(str_replace(['-', ' '], '_', trim($raw)));
        if (in_array($v, ['NON_URGENT', 'URGENT', 'EMERGENCY'], true)) {
            return $v;
        }
        $map = [
            'non_urgent' => 'NON_URGENT',
            'routine'    => 'NON_URGENT',
            'low'        => 'NON_URGENT',
            'urgent'     => 'URGENT',
            'high'       => 'URGENT',
            'emergency'  => 'EMERGENCY',
            'critical'   => 'EMERGENCY',
        ];
        $low = strtolower(str_replace(['-', ' '], '_', trim($raw)));

        return $map[$low] ?? '';
    }

    private static function defaultScore(string $level): int
    {
        return match ($level) {
            'EMERGENCY' => 90,
            'URGENT'    => 55,
            default     => 20,
        };
    }
}
