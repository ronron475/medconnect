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
        $path = BASE_PATH . '/data/nlp/medical_misspellings.csv';
        if (is_readable($path)) {
            $handle = fopen($path, 'r');
            if ($handle !== false) {
                $header = fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    $data = array_combine(
                        array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                        array_map(static fn ($v) => trim((string) $v), $row)
                    ) ?: [];
                    $correct = strtolower((string) ($data['correct_term'] ?? ''));
                    $wrong = strtolower((string) ($data['misspelling'] ?? ''));
                    if ($correct !== '' && $wrong !== '' && !isset(self::$map[$wrong])) {
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

    public static function applyCorrections(string $text): string
    {
        $working = strtolower(trim($text));
        if ($working === '') {
            return '';
        }
        $map = self::map();
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($map as $wrong => $correct) {
            $working = preg_replace('/(?<!\w)' . preg_quote($wrong, '/') . '(?!\w)/u', $correct, $working) ?? $working;
        }

        return trim(preg_replace('/\s+/', ' ', $working) ?? $working);
    }
}
