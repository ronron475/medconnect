<?php
/**
 * Loads data/nlp/hiligaynon_pain_recognition.csv — dedicated Hiligaynon pain NLP dataset.
 */

final class HiligaynonPainRecognition
{
    private static ?array $rows = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $complaintIndex = null;

    /** @var list<string>|null */
    private static ?array $complaintsByLength = null;

    public static function csvPath(): string
    {
        return BASE_PATH . '/data/nlp/hiligaynon_pain_recognition.csv';
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
            if ($mapped['hiligaynon_complaint'] !== '') {
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
            'id'                    => (string) ($data['id'] ?? ''),
            'hiligaynon_complaint'  => (string) ($data['hiligaynon_complaint'] ?? ''),
            'normalized_symptom'    => (string) ($data['normalized_symptom'] ?? ''),
            'english_translation'   => (string) ($data['english_translation'] ?? ''),
            'medical_term'          => (string) ($data['medical_term'] ?? ''),
            'body_part'             => (string) ($data['body_part'] ?? ''),
            'pain_category'         => (string) ($data['pain_category'] ?? ''),
            'severity_level'        => (string) ($data['severity_level'] ?? 'medium'),
            'alternative_spellings' => (string) ($data['alternative_spellings'] ?? ''),
        ];
    }

    public static function normalizeComplaint(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return array<string, array<string, mixed>> */
    public static function complaintIndex(): array
    {
        if (self::$complaintIndex !== null) {
            return self::$complaintIndex;
        }

        self::$complaintIndex = [];
        foreach (self::rows() as $row) {
            $meta = [
                'id'                  => $row['id'],
                'english'             => $row['english_translation'],
                'normalized_symptom'  => $row['normalized_symptom'],
                'medical_term'        => $row['medical_term'],
                'category'            => $row['pain_category'],
                'body_part'           => $row['body_part'],
                'pain_category'       => $row['pain_category'],
                'severity_level'      => $row['severity_level'],
                'canonical_complaint' => $row['hiligaynon_complaint'],
            ];

            $terms = [$row['hiligaynon_complaint'], $row['normalized_symptom']];
            if ($row['alternative_spellings'] !== '') {
                foreach (explode(';', $row['alternative_spellings']) as $alt) {
                    $alt = trim($alt);
                    if ($alt !== '') {
                        $terms[] = $alt;
                    }
                }
            }

            foreach ($terms as $term) {
                $key = self::normalizeComplaint($term);
                if ($key === '' || isset(self::$complaintIndex[$key])) {
                    continue;
                }
                self::$complaintIndex[$key] = $meta + ['matched_term' => $term];
            }
        }

        return self::$complaintIndex;
    }

    /** @return list<string> */
    public static function complaintsByLength(): array
    {
        if (self::$complaintsByLength !== null) {
            return self::$complaintsByLength;
        }

        $terms = array_keys(self::complaintIndex());
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        self::$complaintsByLength = $terms;

        return self::$complaintsByLength;
    }

    /** @return array<string, mixed>|null */
    public static function lookup(string $complaint): ?array
    {
        return self::complaintIndex()[self::normalizeComplaint($complaint)] ?? null;
    }

    public static function translateComplaint(string $text): string
    {
        $entry = self::lookup($text);

        return $entry !== null ? (string) ($entry['english'] ?? '') : '';
    }

    public static function translateText(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $working = self::normalizeComplaint($text);
        $occupied = array_fill(0, max(mb_strlen($working), 1), false);
        $englishParts = [];

        foreach (self::complaintsByLength() as $term) {
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
                $english = self::complaintIndex()[$term]['english'] ?? '';
                if ($english !== '') {
                    $englishParts[] = $english;
                }
            }
        }

        if ($englishParts === []) {
            return self::translateComplaint($text) ?: $text;
        }

        return implode(', ', array_unique($englishParts));
    }

    /** @return array<string, mixed> */
    public static function stats(): array
    {
        $rows = self::rows();
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row['body_part'];
        }

        return [
            'path'          => self::csvPath(),
            'row_count'     => count($rows),
            'variant_count' => count(self::complaintIndex()),
            'body_parts'    => array_values(array_unique($parts)),
        ];
    }
}
