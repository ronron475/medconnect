<?php
/**
 * Loads data/nlp/hiligaynon_medical_training_batch_*.csv for phrase-level lookups.
 */

final class HiligaynonMedicalTraining
{
    /** @var array<string, array<string, string>>|null */
    private static ?array $phraseIndex = null;

    /** @return list<string> */
    private static function csvPaths(): array
    {
        $dir = BASE_PATH . '/data/nlp';
        $paths = glob($dir . '/hiligaynon_medical_training_batch_*.csv') ?: [];
        sort($paths);

        return $paths;
    }

    /** @return array<string, array<string, string>> */
    public static function phraseIndex(): array
    {
        if (self::$phraseIndex !== null) {
            return self::$phraseIndex;
        }

        self::$phraseIndex = [];
        foreach (self::csvPaths() as $path) {
            $handle = fopen($path, 'r');
            if ($handle === false) {
                continue;
            }
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 4) {
                    continue;
                }
                $data = array_combine(
                    array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                    array_map(static fn ($v) => trim((string) $v), $row)
                ) ?: [];
                $local = self::normalizeKey((string) ($data['local_term'] ?? ''));
                if ($local === '') {
                    continue;
                }
                self::$phraseIndex[$local] = [
                    'english_translation' => (string) ($data['english_translation'] ?? ''),
                    'medical_keyword'     => (string) ($data['medical_keyword'] ?? ''),
                    'category'            => (string) ($data['category'] ?? 'symptom'),
                    'severity'            => (string) ($data['severity'] ?? ''),
                    'body_system'         => (string) ($data['body_system'] ?? ''),
                ];
            }
            fclose($handle);
        }

        return self::$phraseIndex;
    }

    /** @return array<string, string>|null */
    public static function lookup(string $text): ?array
    {
        $key = self::normalizeKey($text);

        return self::phraseIndex()[$key] ?? null;
    }

    public static function normalizeKey(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
