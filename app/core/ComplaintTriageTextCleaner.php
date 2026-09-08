<?php
/**
 * Keep only complaint tokens that can contribute to triage NLP.
 *
 * Leftover typos, greetings, and keyboard smash are dropped when a real
 * clinical term remains (e.g. "feve fever" → "fever"). If nothing usable
 * remains, the opening gate asks the patient to re-enter.
 *
 * Does not diagnose or assign EMERGENCY / URGENT / NON-URGENT.
 */
final class ComplaintTriageTextCleaner
{
    /**
     * @return array{
     *   original: string,
     *   cleaned: string,
     *   discarded: list<string>,
     *   kept: list<string>,
     *   has_usable_clinical_text: bool
     * }
     */
    public static function prepare(string $text): array
    {
        $original = trim($text);
        if ($original === '') {
            return [
                'original' => '',
                'cleaned' => '',
                'discarded' => [],
                'kept' => [],
                'has_usable_clinical_text' => false,
            ];
        }

        $working = $original;
        if (class_exists('HiligaynonTextNormalizer')) {
            try {
                $norm = trim((string) HiligaynonTextNormalizer::normalize($working));
                if ($norm !== '') {
                    $working = $norm;
                }
            } catch (Throwable) {
                // keep original working text
            }
        }

        $tokens = preg_split('/[^\p{L}\p{N}\-]+/u', $working, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        $discarded = [];
        foreach ($tokens as $i => $token) {
            if (self::isEssentialToken($token, $tokens, $i)) {
                $kept[] = $token;
            } else {
                $discarded[] = $token;
            }
        }

        $cleaned = trim(implode(' ', $kept));
        $usable = $cleaned !== '' && self::hasClinicalMeaning($cleaned);

        return [
            'original' => $original,
            'cleaned' => $usable ? $cleaned : '',
            'discarded' => array_values(array_unique($discarded)),
            'kept' => $kept,
            'has_usable_clinical_text' => $usable,
        ];
    }

    /**
     * @param list<string> $tokens
     */
    private static function isEssentialToken(string $token, array $tokens, int $index): bool
    {
        $low = mb_strtolower(trim($token));
        if ($low === '' || (mb_strlen($low) === 1 && !ctype_digit($low))) {
            return false;
        }

        if (self::isNoiseToken($low) || self::isDuplicatePartialOfNeighbor($low, $tokens, $index)) {
            return false;
        }

        if (preg_match('/^\d+([.\/]\d+)?$/', $low) || self::isClinicalFunctionWord($low)) {
            return true;
        }

        if (self::looksExactClinicalToken($low) || self::isRecognizedMisspelling($low)) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $tokens
     */
    private static function isDuplicatePartialOfNeighbor(string $low, array $tokens, int $index): bool
    {
        foreach ([-1, 1] as $delta) {
            $neighbor = mb_strtolower(trim((string) ($tokens[$index + $delta] ?? '')));
            if ($neighbor === '' || $neighbor === $low) {
                continue;
            }
            if (mb_strlen($low) < 3 || mb_strlen($low) >= mb_strlen($neighbor)) {
                continue;
            }
            if (!self::looksExactClinicalToken($neighbor) && !self::isRecognizedMisspelling($neighbor)) {
                continue;
            }
            if (str_starts_with($neighbor, $low) || levenshtein($low, $neighbor) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isNoiseToken(string $low): bool
    {
        if (preg_match('/^(asdf+|qwer+|zxcv+|qwerty+|hhhh+|xxxx+|zzz+|test+|testing+|hello|hi|hey|yo|haha|lol|lmao|ok|okay|thanks|thank)$/u', $low)) {
            return true;
        }

        return false;
    }

    private static function isClinicalFunctionWord(string $low): bool
    {
        return (bool) preg_match(
            '/^(hurts?|hurt|aching|ache|painful|masakit|sakit|gasakit|ginasakit|nagasakit|sumasakit|kirot|hapdi|grabe|gid|na|ka|adlaw|semana|oras|bulan|days?|weeks?|months?|hours?|since|for|my|ko|akon|ang|ulo|head|tiyan|chest|dughan|mata|eye)$/u',
            $low
        );
    }

    private static function looksExactClinicalToken(string $low): bool
    {
        return (bool) preg_match(
            '/^(fever|hilanat|lagnat|cough|cought|ubo|sipon|headache|pain|sakit|masakit|dizzy|dizziness|hilo|nahilo|lingin|vomit|vomiting|suka|nausea|breath|breathing|ginhawa|dyspnea|chest|dughan|dibdib|tiyan|stomach|abdomen|ulo|head|rash|bleed|bleeding|dugo|swell|swollen|hubag|weak|weakness|sick|unwell)$/u',
            $low
        );
    }

    private static function isRecognizedMisspelling(string $low): bool
    {
        static $known = [
            'saket' => true,
            'olo' => true,
            'tyan' => true,
            'hedache' => true,
            'headech' => true,
            'cought' => true,
            'couph' => true,
            'hlnt' => true,
            'lgnt' => true,
        ];

        return isset($known[$low]);
    }

    private static function hasClinicalMeaning(string $cleaned): bool
    {
        return (bool) preg_match(
            '/\b(fever|hilanat|lagnat|cough|ubo|sipon|headache|pain|sakit|masakit|dizzy|hilo|hurts?|hurt|aching|ache|chest|dughan|tiyan|ulo|head|vomit|suka|breath|ginhawa|rash|bleed|dugo|hubag|sick|unwell|saket|olo|tyan|hedache)\b/u',
            mb_strtolower($cleaned)
        );
    }
}
