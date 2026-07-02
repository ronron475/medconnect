<?php
/**
 * Loads data/nlp/hiligaynon_medical_nlp_dataset.csv — 10,000+ Hiligaynon medical NLP rows.
 */

final class HiligaynonNlpDataset
{
    private static ?array $rows = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $termIndex = null;

    /** @var list<string>|null */
    private static ?array $termsByLength = null;

    public static function csvPath(): string
    {
        return BASE_PATH . '/data/nlp/hiligaynon_medical_nlp_dataset.csv';
    }

    /** @var list<string> */
    private static array $supplementaryPaths = [
        '/data/nlp/symptom_phrases.csv',
        '/data/nlp/hiligaynon_symptoms.csv',
        '/data/nlp/hiligaynon_wv_expansion.csv',
        '/data/nlp/hiligaynon_reproductive_expansion.csv',
    ];

    /** @return list<array<string, string>> */
    public static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];
        $seen = [];
        foreach (array_merge([self::csvPath()], array_map(static fn ($p) => BASE_PATH . $p, self::$supplementaryPaths)) as $path) {
            if (!is_readable($path)) {
                continue;
            }
            self::loadCsvIntoRows($path, $seen);
        }

        return self::$rows;
    }

    /** @param array<string, true> $seen */
    private static function loadCsvIntoRows(string $path, array &$seen): void
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        $header = fgetcsv($handle);
        $headerLower = array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []);
        $isWv = in_array('english_term', $headerLower, true);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) {
                continue;
            }
            $mapped = $isWv ? self::mapWvRow($header ?: [], $row) : self::mapRow($header ?: [], $row);
            if ($mapped['hiligaynon_term'] === '') {
                continue;
            }
            $key = self::normalizeTerm($mapped['hiligaynon_term']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            self::$rows[] = $mapped;
        }
        fclose($handle);
    }

    /**
     * @param list<string|null> $header
     * @param list<string|null> $row
     * @return array<string, string>
     */
    private static function mapWvRow(array $header, array $row): array
    {
        $data = array_combine(
            array_map(static fn ($h) => strtolower(trim((string) $h)), $header),
            array_map(static fn ($v) => trim((string) $v), $row)
        ) ?: [];

        $english = (string) ($data['english_term'] ?? '');
        $kw = str_replace(' ', ';', strtolower($english));

        return [
            'id'                    => '',
            'hiligaynon_term'       => (string) ($data['hiligaynon_term'] ?? ''),
            'alternative_spellings' => '',
            'english_translation'   => $english,
            'medical_term'          => $english,
            'medical_category'      => (string) ($data['medical_category'] ?? 'General'),
            'body_system'           => 'general',
            'severity'              => (string) ($data['severity'] ?? 'Low'),
            'symptom_keywords'      => $kw,
            'confidence_keywords'   => $kw,
        ];
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
            'id'                    => (string) ($data['id'] ?? ''),
            'hiligaynon_term'       => (string) ($data['hiligaynon_term'] ?? ''),
            'alternative_spellings' => (string) ($data['alternative_spellings'] ?? ''),
            'english_translation'   => (string) ($data['english_translation'] ?? ''),
            'medical_term'          => (string) ($data['medical_term'] ?? ''),
            'medical_category'      => (string) ($data['medical_category'] ?? $data['category'] ?? ''),
            'body_system'           => (string) ($data['body_system'] ?? 'general'),
            'severity'              => (string) ($data['severity'] ?? $data['severity_level'] ?? 'Low'),
            'symptom_keywords'      => (string) ($data['symptom_keywords'] ?? ''),
            'confidence_keywords'   => (string) ($data['confidence_keywords'] ?? ''),
        ];
    }

    public static function normalizeTerm(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return array<string, array<string, mixed>> */
    public static function termIndex(): array
    {
        if (self::$termIndex !== null) {
            return self::$termIndex;
        }

        self::$termIndex = [];
        foreach (self::rows() as $row) {
            $meta = [
                'id'                  => $row['id'],
                'english'             => $row['english_translation'],
                'medical_term'        => $row['medical_term'],
                'category'            => strtolower($row['medical_category']),
                'body_system'         => $row['body_system'],
                'severity'            => $row['severity'],
                'symptom_keywords'    => $row['symptom_keywords'],
                'confidence_keywords' => $row['confidence_keywords'],
                'canonical_variant'   => $row['hiligaynon_term'],
            ];

            $terms = [$row['hiligaynon_term']];
            if ($row['alternative_spellings'] !== '') {
                foreach (explode(';', $row['alternative_spellings']) as $alt) {
                    $alt = trim($alt);
                    if ($alt !== '') {
                        $terms[] = $alt;
                    }
                }
            }

            foreach ($terms as $term) {
                $key = self::normalizeTerm($term);
                if ($key === '' || isset(self::$termIndex[$key])) {
                    continue;
                }
                self::$termIndex[$key] = $meta + ['matched_term' => $term];
            }
        }

        return self::$termIndex;
    }

    /** @return list<string> */
    public static function termsByLength(): array
    {
        if (self::$termsByLength !== null) {
            return self::$termsByLength;
        }

        $terms = array_keys(self::termIndex());
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        self::$termsByLength = $terms;

        return self::$termsByLength;
    }

    /** @return array<string, mixed>|null */
    public static function lookup(string $term): ?array
    {
        $key = self::normalizeTerm($term);

        return self::termIndex()[$key] ?? null;
    }

    public static function translateTerm(string $text): string
    {
        $key = self::normalizeTerm($text);
        $entry = self::termIndex()[$key] ?? null;

        return $entry !== null ? (string) ($entry['english'] ?? '') : '';
    }

    public static function translateText(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $working = self::normalizeTerm($text);
        $occupied = array_fill(0, max(mb_strlen($working), 1), false);
        $replacements = [];

        foreach (self::termsByLength() as $term) {
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
                $english = self::termIndex()[$term]['english'] ?? '';
                if ($english !== '') {
                    $replacements[] = [$start, $end, $english];
                }
            }
        }

        if ($replacements === []) {
            return self::translateTerm($text) ?: $text;
        }

        usort($replacements, static fn ($a, $b) => $a[0] <=> $b[0]);
        $parts = [];
        foreach ($replacements as [, , $english]) {
            $parts[] = $english;
        }

        return implode(', ', array_unique($parts));
    }

    /** @return array<string, mixed> */
    public static function stats(): array
    {
        $rows = self::rows();
        $categories = [];
        foreach ($rows as $row) {
            $categories[] = $row['medical_category'];
        }

        return [
            'path'           => self::csvPath(),
            'row_count'      => count($rows),
            'variant_count'  => count(self::termIndex()),
            'categories'     => array_values(array_unique($categories)),
        ];
    }
}
