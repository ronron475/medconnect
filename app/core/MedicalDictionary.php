<?php
/**
 * Loads data/nlp/medical_dictionary.csv for local → English mapping.
 */

final class MedicalDictionary
{
    private static ?array $rows = null;

    /** @var array<string, string>|null */
    private static ?array $localToEnglish = null;

    /** @var array<string, array{dictionary_id:int, local_term:string, english_term:string, category:string}>|null */
    private static ?array $localIndex = null;

    /** @var list<string>|null */
    private static ?array $termsByLength = null;

    /** @var array<string, array{dictionary_id:int, local_term:string, english_term:string, category:string}>|null */
    private static ?array $englishIndex = null;

    public static function csvPath(): string
    {
        return BASE_PATH . '/data/nlp/medical_dictionary.csv';
    }

    /** @return list<array{local_term:string, english_term:string, category:string}> */
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
            $local = trim($row[1] ?? '');
            $english = trim($row[2] ?? '');
            $category = strtolower(trim($row[3] ?? ''));
            if ($local !== '' && $english !== '') {
                self::$rows[] = [
                    'dictionary_id' => (int) ($row[0] ?? 0),
                    'local_term'    => $local,
                    'english_term'  => $english,
                    'category'      => $category,
                ];
            }
        }
        fclose($handle);

        return self::$rows;
    }

    /** @return array<string, string> */
    public static function localToEnglish(): array
    {
        if (self::$localToEnglish !== null) {
            return self::$localToEnglish;
        }

        self::$localToEnglish = [];
        foreach (self::rows() as $row) {
            $key = mb_strtolower($row['local_term']);
            if (!isset(self::$localToEnglish[$key])) {
                self::$localToEnglish[$key] = $row['english_term'];
            }
        }

        return self::$localToEnglish;
    }

    /** @return list<string> */
    public static function termsByLength(): array
    {
        if (self::$termsByLength !== null) {
            return self::$termsByLength;
        }

        $terms = array_keys(self::localToEnglish());
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        self::$termsByLength = $terms;

        return self::$termsByLength;
    }

    /** @return array{loaded:int, conditions:int, allergies:int, path:string} */
    public static function stats(): array
    {
        $rows = self::rows();
        $conditions = 0;
        $allergies = 0;
        foreach ($rows as $row) {
            if ($row['category'] === 'condition') {
                $conditions++;
            } elseif ($row['category'] === 'allergy') {
                $allergies++;
            }
        }

        return [
            'loaded'     => count($rows),
            'conditions' => $conditions,
            'allergies'  => $allergies,
            'path'       => self::csvPath(),
        ];
    }

    public static function translateLocal(string $local): string
    {
        $entry = self::lookup($local);

        return $entry['english_term'] ?? trim($local);
    }

    /**
     * @return array{dictionary_id:int, local_term:string, english_term:string, category:string}|null
     */
    public static function lookup(string $local): ?array
    {
        self::buildIndex();
        $key = mb_strtolower(trim($local));

        return self::$localIndex[$key] ?? null;
    }

    /**
     * @return array{dictionary_id:int, local_term:string, english_term:string, category:string}|null
     */
    public static function lookupByEnglish(string $english): ?array
    {
        self::buildEnglishIndex();
        $key = mb_strtolower(trim($english));

        return self::$englishIndex[$key] ?? null;
    }

    public static function isLikelyEnglish(string $term): bool
    {
        $term = trim($term);
        if ($term === '') {
            return false;
        }
        if (self::lookup($term) !== null) {
            return false;
        }

        return self::lookupByEnglish($term) !== null
            || preg_match('/^[a-z0-9\s\-]+$/i', $term) === 1;
    }

    private static function buildEnglishIndex(): void
    {
        if (self::$englishIndex !== null) {
            return;
        }
        self::$englishIndex = [];
        foreach (self::rows() as $row) {
            $key = mb_strtolower($row['english_term']);
            if (!isset(self::$englishIndex[$key])) {
                self::$englishIndex[$key] = $row;
            }
        }
    }

    private static function buildIndex(): void
    {
        if (self::$localIndex !== null) {
            return;
        }
        self::$localIndex = [];
        foreach (self::rows() as $row) {
            $key = mb_strtolower($row['local_term']);
            if (!isset(self::$localIndex[$key])) {
                self::$localIndex[$key] = $row;
            }
        }
    }

    /** Apply dictionary phrase replacement to cleaned text (longest match first). */
    public static function translateText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $working = mb_strtolower($text);
        foreach (self::termsByLength() as $term) {
            $entry = self::lookup($term);
            if (!$entry) {
                continue;
            }
            $pattern = '/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/iu';
            $working = preg_replace($pattern, $entry['english_term'], $working) ?? $working;
        }

        return trim(preg_replace('/\s+/u', ' ', $working) ?? $working);
    }
}
