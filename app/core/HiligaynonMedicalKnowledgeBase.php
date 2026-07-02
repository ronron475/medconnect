<?php
/**
 * Loads data/nlp/hiligaynon_medical_knowledge_base.csv — master Hiligaynon medical NLP KB.
 */

final class HiligaynonMedicalKnowledgeBase
{
    private static ?array $rows = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $statementIndex = null;

    /** @var list<string>|null */
    private static ?array $statementsByLength = null;

    public static function csvPath(): string
    {
        return BASE_PATH . '/data/nlp/hiligaynon_medical_knowledge_base.csv';
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
            if (count($row) < 5) {
                continue;
            }
            $mapped = self::mapRow($header ?: [], $row);
            if ($mapped['patient_statement'] !== '') {
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
            'id'                  => (string) ($data['id'] ?? ''),
            'patient_statement'   => (string) ($data['patient_statement'] ?? ''),
            'normalized_symptom'    => (string) ($data['normalized_symptom'] ?? ''),
            'english_translation'   => (string) ($data['english_translation'] ?? ''),
            'medical_term'          => (string) ($data['medical_term'] ?? ''),
            'icd_category'          => (string) ($data['icd_category'] ?? ''),
            'body_system'           => (string) ($data['body_system'] ?? 'general'),
            'urgency_level'         => (string) ($data['urgency_level'] ?? 'Low'),
            'possible_conditions'   => (string) ($data['possible_conditions'] ?? ''),
            'alternative_spellings' => (string) ($data['alternative_spellings'] ?? ''),
            'related_symptoms'      => (string) ($data['related_symptoms'] ?? ''),
            'confidence_keywords'   => (string) ($data['confidence_keywords'] ?? ''),
        ];
    }

    public static function normalizeStatement(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return array<string, array<string, mixed>> */
    public static function statementIndex(): array
    {
        if (self::$statementIndex !== null) {
            return self::$statementIndex;
        }

        self::$statementIndex = [];
        foreach (self::rows() as $row) {
            $meta = [
                'id'                  => $row['id'],
                'english'             => $row['english_translation'],
                'normalized_symptom'  => $row['normalized_symptom'],
                'medical_term'        => $row['medical_term'],
                'category'            => $row['body_system'],
                'body_system'         => $row['body_system'],
                'icd_category'        => $row['icd_category'],
                'urgency_level'       => $row['urgency_level'],
                'possible_conditions' => $row['possible_conditions'],
                'related_symptoms'    => $row['related_symptoms'],
                'confidence_keywords' => $row['confidence_keywords'],
                'canonical_statement' => $row['patient_statement'],
            ];

            $terms = [$row['patient_statement'], $row['normalized_symptom']];
            if ($row['alternative_spellings'] !== '') {
                foreach (explode(';', $row['alternative_spellings']) as $alt) {
                    $alt = trim($alt);
                    if ($alt !== '') {
                        $terms[] = $alt;
                    }
                }
            }

            foreach ($terms as $term) {
                $key = self::normalizeStatement($term);
                if ($key === '' || isset(self::$statementIndex[$key])) {
                    continue;
                }
                self::$statementIndex[$key] = $meta + ['matched_term' => $term];
            }
        }

        return self::$statementIndex;
    }

    /** @return list<string> */
    public static function statementsByLength(): array
    {
        if (self::$statementsByLength !== null) {
            return self::$statementsByLength;
        }

        $terms = array_keys(self::statementIndex());
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        self::$statementsByLength = $terms;

        return self::$statementsByLength;
    }

    /** @return array<string, mixed>|null */
    public static function lookup(string $statement): ?array
    {
        return self::statementIndex()[self::normalizeStatement($statement)] ?? null;
    }

    public static function translateStatement(string $text): string
    {
        $entry = self::lookup($text);

        return $entry !== null ? (string) ($entry['english'] ?? '') : '';
    }

    public static function translateText(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $working = self::normalizeStatement($text);
        $occupied = array_fill(0, max(mb_strlen($working), 1), false);
        $englishParts = [];

        foreach (self::statementsByLength() as $term) {
            $pattern = '/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/iu';
            if (!preg_match_all($pattern, $working, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $start = $match[1];
                $end = $start + strlen($match[0]);
                $overlap = false;
                for ($i = $start; $i < $end; $i++) {
                    if (!empty($occupied[$i])) {
                        $overlap = true;
                        break;
                    }
                }
                if ($overlap) {
                    continue;
                }
                for ($i = $start; $i < $end; $i++) {
                    $occupied[$i] = true;
                }
                $english = self::statementIndex()[$term]['english'] ?? '';
                if ($english !== '') {
                    $englishParts[] = $english;
                }
            }
        }

        if ($englishParts === []) {
            return self::translateStatement($text) ?: $text;
        }

        return implode(', ', array_unique($englishParts));
    }

    /** @return array<string, mixed> */
    public static function stats(): array
    {
        $rows = self::rows();
        $systems = [];
        foreach ($rows as $row) {
            $systems[] = $row['body_system'];
        }

        return [
            'path'           => self::csvPath(),
            'row_count'      => count($rows),
            'variant_count'  => count(self::statementIndex()),
            'body_systems'   => array_values(array_unique($systems)),
        ];
    }
}
