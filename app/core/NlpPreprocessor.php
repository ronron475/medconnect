<?php
/**
 * Normalize Hiligaynon/Ilonggo/Taglish patient text before translation and validation.
 */

final class NlpPreprocessor
{
    /** @var list<string> */
    private const FILLER_WORDS = [
        'may', 'ako', 'ko', 'ikaw', 'siya', 'kami', 'tayo', 'nila', 'sila',
        'kag', 'ka', 'og', 'ug', 'ang', 'nga', 'sa', 'si', 'ni', 'kay',
        'na', 'pa', 'ba', 'ho', 'po', 'din', 'rin', 'lang', 'gid', 'man',
        'gyud', 'gud', ' gihapon', 'wala', 'walay', 'dili', 'hindi',
        'oo', 'yes', 'no', 'none', 'walang', 'mayroon', 'meron',
        'sing', 'sang', 'yung', 'yun', 'yan', 'ito', 'mga', 'ng',
        'the', 'a', 'an', 'and', 'or', 'to', 'of', 'in', 'on', 'at', 'for',
        'with', 'have', 'has', 'had', 'am', 'is', 'are', 'was', 'were',
        'my', 'me', 'i', 'we', 'you', 'he', 'she', 'they', 'it',
        'existing', 'known', 'allergy', 'allergies', 'condition', 'conditions',
        'medical', 'history', 'patient', 'mo', 'nimo', 'namon', 'nato', 'nila', 'ila',
        'akon', 'kon',
    ];

    /** @var list<string> */
    private const SKIP_KEYWORDS = [
        'none', 'n/a', 'na', 'wala', 'walay', 'no', 'unknown',
        'none known', 'no known',
    ];

    /** @var list<string> */
    private const GENERIC_TOKENS = [
        'allergy', 'allergies', 'condition', 'conditions', 'sakit', 'gamot',
        'medication', 'medicine', 'may', 'ako', 'sa',
    ];

    public static function normalizeText(string $text): string
    {
        return HiligaynonTextNormalizer::normalize($text);
    }

    public static function normalizeTextLegacy(string $text): string
    {
        $text = HiligaynonSymptomMatcher::collapseRepeatedCharacters(mb_strtolower(trim($text)));
        if ($text === '') {
            return '';
        }
        $cleaned = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text);
        $cleaned = preg_replace('/\s+/u', ' ', (string) $cleaned);

        return trim((string) $cleaned);
    }

    public static function removeFillers(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return '';
        }
        $fillers = array_flip(self::FILLER_WORDS);
        $kept = [];
        foreach ($tokens as $token) {
            if (!isset($fillers[$token]) && !MedicalTermFilter::isStopWord($token)) {
                $kept[] = $token;
            }
        }

        return implode(' ', $kept);
    }

    /** @return list<string> */
    public static function extractKeywords(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $terms = array_merge(
            MedicalDictionary::termsByLength(),
            HiligaynonPainRecognition::complaintsByLength(),
            HiligaynonMedicalKnowledgeBase::statementsByLength(),
            HiligaynonNlpDataset::termsByLength(),
            HiligaynonPatientComplaints::complaintsByLength()
        );
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $terms = array_values(array_unique($terms));
        $len = strlen($text);
        $occupied = array_fill(0, $len, false);

        $candidates = [];
        foreach ($terms as $term) {
            $pattern = '/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/iu';
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $start = $match[1];
                    $end = $start + strlen($match[0]);
                    $candidates[] = [$start, $end, $term, $end - $start];
                }
            }
        }

        usort($candidates, static function ($a, $b) {
            if ($a[3] !== $b[3]) {
                return $b[3] <=> $a[3];
            }
            return $a[0] <=> $b[0];
        });

        $matched = [];
        foreach ($candidates as [$start, $end, $term]) {
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
            $matched[] = [$start, $end, $term];
        }

        usort($matched, static fn ($a, $b) => $a[0] <=> $b[0]);

        $ordered = [];
        $seen = [];
        $add = static function (string $item) use (&$ordered, &$seen): void {
            $key = mb_strtolower(trim($item));
            if ($key === '' || isset($seen[$key]) || in_array($key, self::SKIP_KEYWORDS, true)) {
                return;
            }
            $seen[$key] = true;
            $ordered[] = $item;
        };

        foreach ($matched as [, , $term]) {
            $add($term);
        }

        if (preg_match_all('/[a-z0-9]+/i', $text, $tokens, PREG_OFFSET_CAPTURE)) {
            foreach ($tokens[0] as $match) {
                $token = $match[0];
                $start = $match[1];
                $end = $start + strlen($token);
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
                if (in_array($token, self::FILLER_WORDS, true) || MedicalTermFilter::isStopWord($token)) {
                    continue;
                }
                if (strlen($token) < 2 && !in_array($token, ['tb', 'dm'], true)) {
                    continue;
                }
                if (!MedicalTermFilter::isMedicalTerm($token)) {
                    continue;
                }
                $add($token);
            }
        }

        return $ordered;
    }

    /** @return list<string> */
    public static function extractTokenDictionaryKeywords(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $keywords = [];
        $seen = [];
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || in_array(mb_strtolower($token), self::FILLER_WORDS, true)) {
                continue;
            }
            if (MedicalTermFilter::isStopWord($token)) {
                continue;
            }

            $key = mb_strtolower($token);
            if (isset($seen[$key])) {
                continue;
            }

            $found = MedicalDictionary::lookup($token) !== null
                || HiligaynonNlpDataset::lookup($token) !== null
                || HiligaynonPainRecognition::lookup($token) !== null
                || MedicalTermFilter::isMedicalTerm($token);

            if ($found) {
                $seen[$key] = true;
                $keywords[] = $token;
            }
        }

        return $keywords;
    }

    /**
     * @return array{
     *   original: string,
     *   normalized: string,
     *   cleaned: string,
     *   keywords: list<string>,
     *   keywords_text: string,
     *   field: string
     * }
     */
    public static function preprocessField(string $text, string $field = 'conditions'): array
    {
        $original = $text;
        $normalized = self::normalizeText($original);
        $cleaned = self::removeFillers($normalized);

        // Extract dictionary phrases from full normalized text before fillers remove Hiligaynon particles.
        $phraseKeywords = self::extractKeywords($normalized);
        $tokenKeywords = self::extractKeywords($cleaned);
        $keywords = self::pruneSubsumedKeywords(self::mergeKeywords($phraseKeywords, $tokenKeywords));
        $keywords = self::mergeKeywords($keywords, self::extractTokenDictionaryKeywords($normalized));
        $keywords = self::mergeKeywords($keywords, self::extractTokenDictionaryKeywords($cleaned));
        $keywords = self::dropGenericIfSpecificPresent($keywords);
        $keywords = self::dropUnmappedFragments($keywords);
        $keywords = self::refineAllergyKeywords($keywords, $normalized, $field);

        if ($keywords === [] && $cleaned !== '') {
            $keywords = self::fallbackSegments($normalized, $cleaned);
        }

        $englishPreview = HiligaynonPainRecognition::translateText($normalized);
        if ($englishPreview === '' || mb_strtolower($englishPreview) === mb_strtolower($normalized)) {
            $englishPreview = HiligaynonMedicalKnowledgeBase::translateText($normalized);
        }
        if ($englishPreview === '' || mb_strtolower($englishPreview) === mb_strtolower($normalized)) {
            $englishPreview = HiligaynonPatientComplaints::translateText($normalized);
        }
        if ($englishPreview === '' || mb_strtolower($englishPreview) === mb_strtolower($normalized)) {
            $englishPreview = HiligaynonNlpDataset::translateText($normalized);
        }
        if ($englishPreview === '' || mb_strtolower($englishPreview) === mb_strtolower($normalized)) {
            $englishPreview = MedicalDictionary::translateText($normalized);
        }

        $result = [
            'original'        => $original,
            'normalized'      => $normalized,
            'cleaned'         => $cleaned,
            'english_preview' => $englishPreview,
            'keywords'        => $keywords,
            'keywords_text'   => implode(' ', $keywords),
            'field'           => $field,
        ];

        return MedicalTermFilter::applyToField($result);
    }

    /**
     * @param list<string> $keywords
     * @return list<string>
     */
    private static function pruneSubsumedKeywords(array $keywords): array
    {
        if (count($keywords) <= 1) {
            return $keywords;
        }
        usort($keywords, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $kept = [];
        foreach ($keywords as $kw) {
            $kwLower = mb_strtolower($kw);
            $subsumed = false;
            foreach ($kept as $longer) {
                if (preg_match('/(?<!\w)' . preg_quote($kwLower, '/') . '(?!\w)/iu', mb_strtolower($longer))) {
                    $subsumed = true;
                    break;
                }
            }
            if (!$subsumed) {
                $kept[] = $kw;
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $keywords
     * @return list<string>
     */
    private static function dropGenericIfSpecificPresent(array $keywords): array
    {
        if (count($keywords) <= 1) {
            return $keywords;
        }
        $generic = array_flip(self::GENERIC_TOKENS);
        $specific = array_filter(
            $keywords,
            static fn ($kw) => !isset($generic[mb_strtolower($kw)])
        );

        return $specific !== [] ? array_values($specific) : $keywords;
    }

    /**
     * Drop filler fragments when a reliable medical keyword is also present.
     *
     * @param list<string> $keywords
     * @return list<string>
     */
    private static function dropUnmappedFragments(array $keywords): array
    {
        if (count($keywords) <= 1) {
            return $keywords;
        }

        $trusted = [];
        foreach ($keywords as $kw) {
            $lower = mb_strtolower($kw);
            $translated = MedicalDictionary::translateText($kw);
            $generic = array_flip(self::GENERIC_TOKENS);
            $translatedLower = mb_strtolower($translated);
            $translatedIsGeneric = isset($generic[$translatedLower]);
            $isTrusted = MedicalDictionary::lookup($kw) !== null
                || MedicalDictionary::lookupByEnglish($kw) !== null
                || MedicalDictionary::isLikelyEnglish($kw)
                || (
                    $translated !== ''
                    && $translatedLower !== $lower
                    && !$translatedIsGeneric
                    && mb_strlen($translatedLower) > 3
                );

            if ($isTrusted) {
                $trusted[] = $kw;
            }
        }

        return $trusted !== [] ? $trusted : $keywords;
    }

    /**
     * @param list<string> $keywords
     * @return list<string>
     */
    private static function refineAllergyKeywords(array $keywords, string $normalized, string $field): array
    {
        if ($field !== 'allergies') {
            return $keywords;
        }

        $knownSubstances = [
            'penicillin', 'shellfish', 'peanut', 'seafood', 'latex', 'aspirin',
            'sulfa', 'egg', 'milk', 'dairy', 'fish', 'shrimp', 'crab',
        ];

        foreach ($knownSubstances as $substance) {
            if (preg_match('/(?<!\w)' . preg_quote($substance, '/') . '(?!\w)/i', $normalized)) {
                $has = false;
                foreach ($keywords as $kw) {
                    if (stripos($kw, $substance) !== false) {
                        $has = true;
                        break;
                    }
                }
                if (!$has) {
                    $keywords[] = $substance;
                }
            }
        }

        return array_values(array_filter(
            $keywords,
            static function (string $kw) use ($knownSubstances): bool {
                $lower = mb_strtolower($kw);
                foreach ($knownSubstances as $substance) {
                    if (str_contains($lower, $substance)) {
                        return true;
                    }
                }
                if (preg_match('/^(may|allergic|allergy)(\s|$)/', $lower)) {
                    return false;
                }
                if (preg_match('/\b(sa|ako)\b/', $lower) && mb_strlen($lower) < 20) {
                    return false;
                }

                return MedicalDictionary::lookup($kw) !== null
                    || MedicalDictionary::translateText($kw) !== $lower;
            }
        ));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private static function mergeKeywords(array $a, array $b): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($a, $b) as $kw) {
            $key = mb_strtolower(trim($kw));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $kw;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function fallbackSegments(string $normalized, string $cleaned): array
    {
        $tokenKeywords = self::extractTokenDictionaryKeywords($cleaned);
        if ($tokenKeywords !== []) {
            return $tokenKeywords;
        }

        $english = MedicalDictionary::translateText($normalized);
        $source = $english !== '' && $english !== $cleaned ? $english : $cleaned;
        $parts = preg_split('/\s*(?:,|;| and | og | kag | ka | ug )\s*/i', $source);
        $items = [];
        foreach ($parts ?: [] as $part) {
            $part = trim((string) $part);
            if (
                $part !== ''
                && !in_array(mb_strtolower($part), self::SKIP_KEYWORDS, true)
                && MedicalTermFilter::isMedicalTerm($part)
            ) {
                $items[] = $part;
            }
        }

        return $items;
    }

    /**
     * @param list<string> $keywords
     * @return list<array{local:string, english:string}>
     */
    public static function translateKeywords(array $keywords): array
    {
        $out = [];
        foreach ($keywords as $kw) {
            $out[] = [
                'local'   => $kw,
                'english' => MedicalDictionary::translateLocal($kw),
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   allergies: array,
     *   conditions: array,
     *   dictionary: array
     * }
     */
    public static function preprocessProfile(string $allergies, string $conditions): array
    {
        $allergyBlock = self::preprocessField($allergies, 'allergies');
        $conditionBlock = self::preprocessField($conditions, 'conditions');

        return [
            'allergies'  => $allergyBlock,
            'conditions' => $conditionBlock,
            'dictionary' => MedicalDictionary::stats(),
        ];
    }
}
