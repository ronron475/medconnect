<?php
/**
 * Anatomy-only body parts — must not be validated as standalone symptoms.
 */

final class BodyPartsDataset
{
    private static ?array $rows = null;

    /** @var array<string, true>|null */
    private static ?array $termSet = null;

    /** @var array<string, true>|null */
    private static ?array $englishTermSet = null;

    public static function csvPath(): string
    {
        return BASE_PATH . '/data/nlp/body_parts.csv';
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
            if (count($row) < 2) {
                continue;
            }
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $hil = (string) ($data['hiligaynon_term'] ?? '');
            if ($hil === '') {
                continue;
            }
            self::$rows[] = [
                'hiligaynon_term'   => $hil,
                'english_term'      => (string) ($data['english_term'] ?? ''),
                'body_system'       => (string) ($data['body_system'] ?? $data['anatomy_category'] ?? 'general'),
                'anatomy_category'  => (string) ($data['anatomy_category'] ?? $data['body_system'] ?? 'general'),
                'status'            => (string) ($data['status'] ?? 'active'),
            ];
        }
        fclose($handle);

        return self::$rows;
    }

    public static function normalizeTerm(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    public static function isBodyPart(string $term): bool
    {
        $key = self::normalizeTerm($term);
        if ($key === '') {
            return false;
        }
        if (self::$termSet === null) {
            self::$termSet = [];
            foreach (self::rows() as $row) {
                self::$termSet[self::normalizeTerm($row['hiligaynon_term'])] = true;
                $base = explode(' ', $row['hiligaynon_term'])[0] ?? '';
                if ($base !== '') {
                    self::$termSet[self::normalizeTerm($base)] = true;
                }
            }
        }

        return isset(self::$termSet[$key]);
    }

    public static function isEnglishBodyPart(string $term): bool
    {
        $key = self::normalizeTerm($term);
        if ($key === '') {
            return false;
        }
        if (self::$englishTermSet === null) {
            self::$englishTermSet = [];
            foreach (self::rows() as $row) {
                $english = self::normalizeTerm($row['english_term']);
                if ($english !== '') {
                    self::$englishTermSet[$english] = true;
                }
            }
        }

        return isset(self::$englishTermSet[$key]);
    }

    public static function isBodyPartOrEnglish(string $term): bool
    {
        return self::isBodyPart($term) || self::isEnglishBodyPart($term);
    }
}
