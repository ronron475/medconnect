<?php
/**
 * Symptom phrase index for phrase-first NLP matching.
 */

final class SymptomPhrasesLoader
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $index = null;

    /** @var list<string>|null */
    private static ?array $byLength = null;

    /** @var list<string> */
    private static array $sources = [
        '/data/nlp/symptom_phrases.csv',
        '/data/nlp/hiligaynon_wv_expansion.csv',
        '/data/nlp/hiligaynon_reproductive_expansion.csv',
        '/data/nlp/hiligaynon_combinatorial_phrases.csv',
        '/data/nlp/hiligaynon_conditions_combinatorial.csv',
        '/data/nlp/hiligaynon_conditions.csv',
        '/data/nlp/hiligaynon_symptoms.csv',
        '/data/nlp/step6_triage_exemplars.csv',
        '/data/nlp/symptom_phrases_seed.csv',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function phraseIndex(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        self::$index = [];
        foreach (self::$sources as $rel) {
            $path = BASE_PATH . $rel;
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
                $hil = (string) ($data['hiligaynon_term'] ?? '');
                $eng = (string) ($data['english_term'] ?? $data['english_translation'] ?? '');
                if ($hil === '' || $eng === '') {
                    continue;
                }
                $key = self::normalize($hil);
                if (isset(self::$index[$key])) {
                    continue;
                }
                self::$index[$key] = [
                    'hiligaynon_term'  => $hil,
                    'english_term'     => $eng,
                    'medical_category' => (string) ($data['medical_category'] ?? 'general'),
                    'severity'         => (string) ($data['severity'] ?? 'Low'),
                    'triage_level'     => (string) ($data['triage_level'] ?? 'routine'),
                    'body_part'        => self::inferBodyPart($hil, $eng),
                    'symptom'          => self::inferSymptom($eng),
                    'source'           => basename($path),
                ];
            }
            fclose($handle);
        }

        return self::$index;
    }

    /** @return list<string> */
    public static function phrasesByLength(): array
    {
        if (self::$byLength !== null) {
            return self::$byLength;
        }
        $keys = array_keys(self::phraseIndex());
        usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        self::$byLength = $keys;

        return self::$byLength;
    }

    /** @return array<string, mixed>|null */
    public static function lookupPhrase(string $text): ?array
    {
        return self::phraseIndex()[self::normalize($text)] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function scanPhrases(string $text): array
    {
        $working = self::normalize($text);
        if ($working === '') {
            return [];
        }

        $matches = self::scanStaticPhrases($working);
        $comb = PhraseCombinatorialEngine::matchPhrases($text);
        foreach ($comb as $entry) {
            $phrase = (string) ($entry['matched_phrase'] ?? '');
            if ($phrase === '') {
                continue;
            }
            $dup = false;
            foreach ($matches as $existing) {
                if (($existing['matched_phrase'] ?? '') === $phrase) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $matches[] = $entry;
            }
        }
        usort($matches, static fn (array $a, array $b): int => ($a['span'][0] ?? 0) <=> ($b['span'][0] ?? 0));

        return $matches;
    }

    /** @return list<array<string, mixed>> */
    private static function scanStaticPhrases(string $working): array
    {
        $occupied = array_fill(0, strlen($working), false);
        $matches = [];
        foreach (self::phrasesByLength() as $phrase) {
            if (!preg_match_all('/(?<!\w)' . preg_quote($phrase, '/') . '(?!\w)/iu', $working, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $entry = self::phraseIndex()[$phrase] ?? null;
            if ($entry === null) {
                continue;
            }
            foreach ($m[0] as [$_, $start]) {
                $end = $start + strlen($phrase);
                $overlap = false;
                for ($i = $start; $i < $end; $i++) {
                    if ($occupied[$i] ?? false) {
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
                $matches[] = array_merge($entry, ['matched_phrase' => $phrase, 'span' => [$start, $end]]);
            }
        }
        usort($matches, static fn (array $a, array $b): int => ($a['span'][0] ?? 0) <=> ($b['span'][0] ?? 0));

        return $matches;
    }

    private static function normalize(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private static function inferBodyPart(string $hil, string $eng): string
    {
        $map = ['itlog' => 'testicle', 'bilat' => 'vagina', 'ari' => 'penis', 'bayag' => 'scrotum'];
        $low = strtolower($hil);
        foreach ($map as $k => $v) {
            if (str_contains($low, $k)) {
                return $v;
            }
        }
        $engLow = strtolower($eng);
        foreach (['vagina', 'penis', 'testicle', 'scrotum', 'vulva', 'groin'] as $part) {
            if (str_contains($engLow, $part)) {
                return $part;
            }
        }

        return '';
    }

    private static function inferSymptom(string $eng): string
    {
        $low = strtolower($eng);
        foreach (['infection', 'bleeding', 'swelling', 'pain', 'itching', 'lump', 'wound'] as $t) {
            if (str_contains($low, $t)) {
                return $t;
            }
        }

        return 'symptom';
    }
}
