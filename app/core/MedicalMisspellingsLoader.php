<?php
/**
 * Hiligaynon misspelling normalization before phrase matching.
 */

final class MedicalMisspellingsLoader
{
    /** @var array<string, string>|null */
    private static ?array $map = null;

    /** @return array<string, string> */
    public static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        self::$map = [];
        foreach ([
            BASE_PATH . '/data/nlp/medical_misspellings.csv',
            BASE_PATH . '/data/nlp/misspellings.csv',
        ] as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $handle = fopen($path, 'r');
            if ($handle === false) {
                continue;
            }
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $data = array_combine(
                    array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                    array_map(static fn ($v) => trim((string) $v), $row)
                ) ?: [];
                $correct = strtolower((string) ($data['correct_term'] ?? ''));
                $wrong = strtolower((string) ($data['misspelling'] ?? ''));
                // Skip padded generator artifacts
                if ($wrong !== '' && preg_match('/\d{3,}$/', $wrong)) {
                    continue;
                }
                if ($correct !== '' && $wrong !== '' && !isset(self::$map[$wrong])) {
                    self::$map[$wrong] = $correct;
                }
            }
            fclose($handle);
        }

        // Medical abbreviations expansion (CSV-driven)
        $abbrPath = BASE_PATH . '/data/nlp/medical_abbreviations.csv';
        if (is_readable($abbrPath)) {
            $handle = fopen($abbrPath, 'r');
            if ($handle !== false) {
                $header = fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    $data = array_combine(
                        array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                        array_map(static fn ($v) => trim((string) $v), $row)
                    ) ?: [];
                    $abbr = strtolower((string) ($data['abbreviation'] ?? ''));
                    $exp = strtolower((string) ($data['expansion'] ?? ''));
                    if ($abbr !== '' && $exp !== '' && !isset(self::$map[$abbr])) {
                        self::$map[$abbr] = $exp;
                    }
                }
                fclose($handle);
            }
        }

        $chatPath = BASE_PATH . '/data/nlp/hiligaynon_chat_shorthand.csv';
        if (is_readable($chatPath)) {
            $handle = fopen($chatPath, 'r');
            if ($handle !== false) {
                $header = fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    $data = array_combine(
                        array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                        array_map(static fn ($v) => trim((string) $v), $row)
                    ) ?: [];
                    $wrong = strtolower((string) ($data['shorthand'] ?? ''));
                    $correct = strtolower((string) ($data['expansion'] ?? ''));
                    if ($wrong !== '' && $correct !== '' && !isset(self::$map[$wrong])) {
                        self::$map[$wrong] = $correct;
                    }
                }
                fclose($handle);
            }
        }

        $enginePath = BASE_PATH . '/data/nlp/phrase_engine/misspelling_rules.json';
        if (is_readable($enginePath)) {
            $rules = json_decode((string) file_get_contents($enginePath), true);
            if (is_array($rules)) {
                foreach (($rules['known_variants'] ?? []) as $correct => $variants) {
                    $c = strtolower(trim((string) $correct));
                    if (!is_array($variants)) {
                        continue;
                    }
                    foreach ($variants as $v) {
                        $w = strtolower(trim((string) $v));
                        if ($w !== '' && $w !== $c && !isset(self::$map[$w])) {
                            self::$map[$w] = $c;
                        }
                    }
                }
            }
        }

        return self::$map;
    }

    /** @var array<string, string>|null */
    private static ?array $sortedMap = null;

    /** @return array<string, string> */
    public static function sortedMap(): array
    {
        if (self::$sortedMap !== null) {
            return self::$sortedMap;
        }
        $map = self::map();
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        self::$sortedMap = $map;

        return self::$sortedMap;
    }

    public static function applyCorrections(string $text): string
    {
        return self::applyCorrectionsWithLog($text)['text'];
    }

    /**
     * @return array{text:string, corrections:list<array{from:string,to:string}>}
     */
    public static function applyCorrectionsWithLog(string $text): array
    {
        $working = strtolower(trim($text));
        if ($working === '') {
            return ['text' => '', 'corrections' => []];
        }

        $corrections = [];
        $tokens = preg_split('/\s+/u', $working, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokenSet = array_fill_keys($tokens, true);
        foreach (self::sortedMap() as $wrong => $correct) {
            $wrong = (string) $wrong;
            $correct = (string) $correct;
            if (strlen($wrong) < 4 || strlen($wrong) > strlen($working)) {
                continue;
            }
            if (str_contains($wrong, ' ')) {
                if (!str_contains($working, $wrong)) {
                    continue;
                }
            } elseif (!isset($tokenSet[$wrong])) {
                continue;
            }
            $pattern = '/(?<!\w)' . preg_quote($wrong, '/') . '(?!\w)/u';
            if (!preg_match($pattern, $working)) {
                continue;
            }
            $corrections[] = ['from' => $wrong, 'to' => $correct];
            $working = preg_replace($pattern, $correct, $working) ?? $working;
            $tokens = preg_split('/\s+/u', $working, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokenSet = array_fill_keys($tokens, true);
        }

        return [
            'text'        => trim(preg_replace('/\s+/', ' ', $working) ?? $working),
            'corrections' => $corrections,
        ];
    }
}
