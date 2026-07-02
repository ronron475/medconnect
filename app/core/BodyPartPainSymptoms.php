<?php
/**
 * Maps body-part-specific pain phrases to official symptoms.csv names.
 * Source: data/nlp/body_part_pain_symptoms.csv
 */

final class BodyPartPainSymptoms
{
    /** @var list<array<string, string>>|null */
    private static ?array $rows = null;

    /** @var array<string, array<string, string>>|null */
    private static ?array $aliasIndex = null;

    public static function csvPath(): string
    {
        return BASE_PATH . '/data/nlp/body_part_pain_symptoms.csv';
    }

    /** @return list<array<string, string>> */
    public static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];
        $path = self::csvPath();
        if (!is_readable($path)) {
            return self::$rows;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return self::$rows;
        }

        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 4) {
                continue;
            }
            $mapped = self::mapRow($header ?: [], $row);
            if ($mapped['english_alias'] !== '') {
                self::$rows[] = $mapped;
            }
        }
        fclose($handle);

        return self::$rows;
    }

    /**
     * @param list<string|null> $header
     * @param list<string|null> $row
     * @return array<string, string>
     */
    private static function mapRow(array $header, array $row): array
    {
        $data = array_combine(
            array_map(static fn ($h) => strtolower(trim((string) $h)), $header),
            array_map(static fn ($v) => trim((string) $v), $row)
        ) ?: [];

        return [
            'id'                => (string) ($data['id'] ?? ''),
            'english_alias'     => (string) ($data['english_alias'] ?? ''),
            'canonical_english' => (string) ($data['canonical_english'] ?? ''),
            'official_symptom'  => (string) ($data['official_symptom'] ?? ''),
            'body_part'         => (string) ($data['body_part'] ?? ''),
            'notes'             => (string) ($data['notes'] ?? ''),
        ];
    }

    /** @return array<string, array<string, string>> */
    public static function aliasIndex(): array
    {
        if (self::$aliasIndex !== null) {
            return self::$aliasIndex;
        }

        self::$aliasIndex = [];
        foreach (self::rows() as $row) {
            $key = mb_strtolower(trim($row['english_alias']));
            if ($key !== '') {
                self::$aliasIndex[$key] = $row;
            }
        }

        return self::$aliasIndex;
    }

    public static function normalizeKey(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return array<string, string>|null */
    public static function lookup(string $english): ?array
    {
        $key = self::normalizeKey($english);
        if ($key === '') {
            return null;
        }

        return self::aliasIndex()[$key] ?? null;
    }

    public static function canonicalEnglish(string $english): string
    {
        $entry = self::lookup($english);

        return $entry !== null && $entry['canonical_english'] !== ''
            ? $entry['canonical_english']
            : trim($english);
    }

    public static function officialSymptomName(string $english): ?string
    {
        $entry = self::lookup($english);
        if ($entry === null || $entry['official_symptom'] === '') {
            return null;
        }

        return $entry['official_symptom'];
    }
}
