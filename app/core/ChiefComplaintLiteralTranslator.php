<?php
/**
 * Literal English translation — patient meaning only (not clinical interpretation).
 *
 * Translation = what the patient said.
 * Medical concepts are mapped separately via StandardizedConceptMapper.
 */

final class ChiefComplaintLiteralTranslator
{
    /** @var array<string, string>|null normalized phrase → literal English */
    private static ?array $phraseMap = null;

    /**
     * @param array{primary?:string, tags?:list<string>} $language
     * @return array{english:string, source:string, confidence:float}
     */
    public static function translate(string $original, string $correctedText, array $language = []): array
    {
        $original = trim($original);
        $correctedText = trim($correctedText !== '' ? $correctedText : $original);
        if ($original === '' && $correctedText === '') {
            return ['english' => '', 'source' => 'empty', 'confidence' => 0.0];
        }

        self::loadPhraseMap();

        $candidates = array_values(array_unique(array_filter([
            HiligaynonTextNormalizer::normalize($correctedText),
            HiligaynonTextNormalizer::normalize($original),
            $correctedText,
            $original,
            ...HiligaynonTextNormalizer::phraseVariants($correctedText),
            ...HiligaynonTextNormalizer::phraseVariants($original),
        ])));

        foreach ($candidates as $phrase) {
            $key = self::normKey($phrase);
            if ($key !== '' && isset(self::$phraseMap[$key])) {
                return self::result(self::$phraseMap[$key], 'literal_phrase_csv', 0.95);
            }
        }

        foreach ($candidates as $phrase) {
            $pattern = self::matchPattern($phrase);
            if ($pattern !== null) {
                return $pattern;
            }
        }

        $primary = (string) ($language['primary'] ?? '');
        $isLocal = HiligaynonLanguageDetector::isLocalLanguage($original)
            || in_array($primary, ['hiligaynon', 'filipino', 'mixed'], true);

        if ($isLocal) {
            $assembled = self::assembleFromDictionary($correctedText !== '' ? $correctedText : $original);
            if ($assembled !== '') {
                return self::result($assembled, 'dictionary_assembly', 0.72);
            }
        }

        if (!$isLocal || self::looksEnglish($correctedText !== '' ? $correctedText : $original)) {
            return self::result(
                self::polishEnglish($correctedText !== '' ? $correctedText : $original),
                'english_input',
                0.85
            );
        }

        return self::result('', 'untranslated', 0.0);
    }

    /**
     * @return array{english:string, source:string, confidence:float}|null
     */
    private static function matchPattern(string $text): ?array
    {
        $t = self::normKey($text);
        if ($t === '') {
            return null;
        }

        $patterns = [
            '/^(?:nag\s*)?dugo(?:\s+ang)?\s+ulo(?:\s+ko)?$/u' => 'My head is bleeding.',
            '/^(?:nag\s*)?dugo\s+ang\s+ulo\s+ko$/u' => 'My head is bleeding.',
            '/^(?:nag\s*)?duguan\s+ang\s+ulo\s+ko$/u' => 'My head is bleeding.',
            '/^budlay\s+gid\s+ginhawa(?:\s+ko)?$/u' => 'I am having difficulty breathing.',
            '/^budlay\s+ginhawa(?:\s+ko)?$/u' => 'I am having difficulty breathing.',
            '/^indi\s+ko\s+kaginhawa$/u' => 'I cannot breathe well.',
            '/^hirap\s+(?:akong\s+)?huminga$/u' => 'I am having difficulty breathing.',
            '/^may\s+lagnat\s+ako$/u' => 'I have a fever.',
            '/^may\s+hilanat\s+ako$/u' => 'I have a fever.',
            '/^putol\s+ang\s+kamot(?:\s+ko)?$/u' => 'My hand was cut off.',
            '/^naputol\s+(?:ang\s+)?kamot(?:\s+ko)?$/u' => 'My hand was cut off.',
            '/^nautod\s+(?:ang\s+)?kamot(?:\s+ko)?$/u' => 'My hand was cut off.',
        ];

        foreach ($patterns as $regex => $literal) {
            if (preg_match($regex, $t)) {
                return self::result($literal, 'builtin_pattern', 0.92);
            }
        }

        if (preg_match('/\b(?:nag\s*)?dugo(?:\s+ang)?\s+ulo\b/u', $t)) {
            return self::result('My head is bleeding.', 'contextual_head_bleeding', 0.88);
        }

        if (preg_match('/\bbudlay\b/u', $t) && preg_match('/\bginhawa\b/u', $t)) {
            return self::result('I am having difficulty breathing.', 'contextual_breathing', 0.85);
        }

        return null;
    }

    private static function assembleFromDictionary(string $text): string
    {
        $normalized = HiligaynonTextNormalizer::normalize($text);
        if ($normalized === '') {
            return '';
        }

        $phrase = SymptomPhrasesLoader::lookupPhrase($normalized);
        if ($phrase !== null) {
            $clinical = trim((string) ($phrase['english_term'] ?? ''));
            $literal = self::clinicalToLiteral($clinical, $normalized);
            if ($literal !== '') {
                return $literal;
            }
        }

        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $translated = [];
        foreach ($tokens as $token) {
            $en = MedicalDictionary::translateText($token);
            if ($en !== '' && mb_strtolower($en) !== mb_strtolower($token)) {
                $translated[] = $en;
                continue;
            }
            if (!in_array($token, ['ko', 'ko', 'akon', 'ang', 'nga', 'sa', 'gid', 'man', 'ka', 'sang'], true)) {
                $translated[] = $token;
            }
        }

        if ($translated === []) {
            return '';
        }

        $sentence = ucfirst(trim(implode(' ', $translated)));
        if (!str_ends_with($sentence, '.')) {
            $sentence .= '.';
        }

        return $sentence;
    }

    private static function clinicalToLiteral(string $clinical, string $sourcePhrase): string
    {
        $key = self::normKey($clinical);
        $map = [
            'head bleeding' => 'My head is bleeding.',
            'head bleeding with dizziness' => 'My head is bleeding and I feel dizzy.',
            'head bleeding after fall' => 'My head started bleeding after I fell.',
            'difficulty breathing' => 'I am having difficulty breathing.',
            'chest pain' => 'My chest hurts.',
            'hand cut off' => 'My hand was cut off.',
            'finger amputation' => 'My finger was cut off.',
            'fever' => 'I have a fever.',
            'cough' => 'I have a cough.',
        ];

        if (isset($map[$key])) {
            return $map[$key];
        }

        if (preg_match('/\bhead bleeding\b/u', $key)) {
            return 'My head is bleeding.';
        }

        return '';
    }

    private static function polishEnglish(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = ucfirst($text);
        if (!preg_match('/[.!?]$/u', $text)) {
            $text .= '.';
        }

        return $text;
    }

    private static function looksEnglish(string $text): bool
    {
        return !HiligaynonLanguageDetector::isLocalLanguage($text);
    }

    private static function loadPhraseMap(): void
    {
        if (self::$phraseMap !== null) {
            return;
        }

        self::$phraseMap = [];
        $path = BASE_PATH . '/data/nlp/literal_translation_phrases.csv';
        if (!is_readable($path)) {
            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $phrase = self::normKey((string) ($data['phrase'] ?? ''));
            $literal = trim((string) ($data['literal_english'] ?? ''));
            $status = strtolower((string) ($data['status'] ?? 'active'));
            if ($phrase !== '' && $literal !== '' && $status !== 'inactive') {
                self::$phraseMap[$phrase] = $literal;
            }
        }
        fclose($handle);
    }

    private static function normKey(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? ''));
    }

    /**
     * @return array{english:string, source:string, confidence:float}
     */
    private static function result(string $english, string $source, float $confidence): array
    {
        return [
            'english'    => trim($english),
            'source'     => $source,
            'confidence' => round($confidence, 2),
        ];
    }
}
