<?php
/**
 * Phrase-level medical entity extraction for Hiligaynon telemedicine.
 */

final class MedicalEntityExtractor
{
    /** @return list<array<string, mixed>> */
    public static function extractEntities(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $normalized = self::normalizeText(MedicalMisspellingsLoader::applyCorrections($text));
        $entities = [];
        $seen = [];

        foreach (SymptomPhrasesLoader::scanPhrases($normalized) as $match) {
            $key = strtolower((string) ($match['matched_phrase'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $entities[] = self::phraseToEntity($match, $text);
        }

        if ($entities !== []) {
            return $entities;
        }

        foreach (HiligaynonNlpDataset::termsByLength() as $term) {
            if (!preg_match('/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/iu', $normalized)) {
                continue;
            }
            $entry = HiligaynonNlpDataset::lookup($term);
            if ($entry === null) {
                continue;
            }
            $key = strtolower($term);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $english = (string) ($entry['english_translation'] ?? '');
            $entities[] = [
                'hiligaynon_term' => (string) ($entry['hiligaynon_term'] ?? $term),
                'english_term'    => $english,
                'symptom'         => self::symptomFromEnglish($english),
                'condition'       => str_contains(strtolower($english), 'infection') ? $english : '',
                'body_part'       => self::bodyPartFromText($term, $english),
                'severity'        => self::normalizeSeverity((string) ($entry['severity'] ?? '')),
                'duration'        => self::extractDuration($text),
                'type'            => str_contains(strtolower($english), 'infection') ? 'condition' : 'symptom',
                'category'        => (string) ($entry['medical_category'] ?? 'symptom'),
                'confidence'      => 92,
                'source'          => 'hiligaynon_nlp_dataset',
            ];
        }

        return $entities;
    }

    /** @return array<string, mixed>|null */
    public static function extractPrimaryEntity(string $text): ?array
    {
        $entities = self::extractEntities($text);

        return $entities[0] ?? null;
    }

    /** @param array<string, mixed> $match */
    private static function phraseToEntity(array $match, string $original): array
    {
        $english = (string) ($match['english_term'] ?? '');
        $cat = strtolower((string) ($match['medical_category'] ?? ''));
        $isInfection = str_contains(strtolower($english), 'infection') || $cat === 'infection';

        return [
            'hiligaynon_term' => (string) ($match['hiligaynon_term'] ?? $match['matched_phrase'] ?? ''),
            'english_term'    => $english,
            'symptom'         => (string) ($match['symptom'] ?? self::symptomFromEnglish($english)),
            'condition'       => $isInfection || in_array($cat, ['injury', 'trauma', 'gynecologic_symptom'], true) ? $english : '',
            'body_part'       => (string) ($match['body_part'] ?? self::bodyPartFromText((string) ($match['hiligaynon_term'] ?? ''), $english)),
            'severity'        => self::normalizeSeverity((string) ($match['severity'] ?? '')),
            'duration'        => self::extractDuration($original),
            'type'            => $isInfection ? 'condition' : 'symptom',
            'category'        => (string) ($match['medical_category'] ?? 'symptom'),
            'triage_level'    => (string) ($match['triage_level'] ?? ''),
            'confidence'      => 95,
            'source'          => (string) ($match['source'] ?? 'symptom_phrases'),
        ];
    }

    private static function normalizeText(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private static function symptomFromEnglish(string $english): string
    {
        $low = strtolower($english);
        foreach (['infection', 'bleeding', 'swelling', 'pain', 'itching', 'lump', 'wound', 'redness', 'retention'] as $token) {
            if (str_contains($low, $token)) {
                return $token;
            }
        }

        return 'symptom';
    }

    private static function bodyPartFromText(string $hil, string $english): string
    {
        $map = [
            'itlog' => 'testicle', 'itlug' => 'testicle', 'bilat' => 'vagina', 'bilad' => 'vagina',
            'ari' => 'penis', 'bayag' => 'scrotum', 'kipay' => 'vulva', 'singit' => 'groin',
        ];
        $low = strtolower($hil);
        foreach ($map as $k => $v) {
            if (preg_match('/\b' . preg_quote($k, '/') . '\b/u', $low)) {
                return $v;
            }
        }
        $eng = strtolower($english);
        foreach (['vagina', 'penis', 'testicle', 'scrotum', 'vulva', 'groin'] as $part) {
            if (str_contains($eng, $part)) {
                return $part;
            }
        }

        return '';
    }

    private static function normalizeSeverity(string $sev): string
    {
        $s = strtolower(trim($sev));
        if (in_array($s, ['critical', 'high', 'severe'], true)) {
            return 'severe';
        }
        if (in_array($s, ['medium', 'moderate'], true)) {
            return 'moderate';
        }
        if (in_array($s, ['low', 'mild'], true)) {
            return 'mild';
        }

        return $s !== '' ? $s : 'moderate';
    }

    private static function extractDuration(string $text): string
    {
        $low = strtolower($text);
        foreach (['/\d+\s*ka\s*adlaw/u', '/dugay\s+na/u', '/bag-o\s+lang/u', '/gahapon/u', '/semana\s+na/u'] as $pat) {
            if (preg_match($pat, $low, $m)) {
                return $m[0];
            }
        }

        return '';
    }
}
