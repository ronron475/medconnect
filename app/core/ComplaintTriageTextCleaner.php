<?php
/**
 * Keep only complaint tokens that can contribute to triage NLP.
 *
 * Minor misspellings are corrected via existing fuzzy/misspelling lexicons
 * BEFORE a token is treated as junk. Leftover smash/greetings are dropped
 * when a real clinical term remains (e.g. "feve fever" → "fever").
 *
 * Corrected text is an internal NLP interpretation. Original patient wording
 * is preserved by callers.
 */
final class ComplaintTriageTextCleaner
{
    /**
     * @return array{
     *   original: string,
     *   cleaned: string,
     *   discarded: list<string>,
     *   kept: list<string>,
     *   corrections: list<array{from:string,to:string,score:float}>,
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
                'corrections' => [],
                'has_usable_clinical_text' => false,
            ];
        }

        $working = $original;
        $scoreHold = [];
        $working = preg_replace_callback('/\b(\d{1,2})\s*\/\s*(10)\b/iu', static function (array $m) use (&$scoreHold): string {
            $key = 'mcscore' . count($scoreHold);
            $scoreHold[$key] = $m[1] . '/' . $m[2];

            return ' ' . $key . ' ';
        }, $working) ?? $working;
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
        foreach ($scoreHold as $key => $score) {
            $working = str_ireplace($key, $score, $working);
        }
        $working = preg_replace('/(\d{1,2})\s*\/\s*(10)\b/u', '$1/$2', $working) ?? $working;
        $tokens = preg_split('/[^\p{L}\p{N}\/\-]+/u', $working, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        $discarded = [];
        $corrections = [];
        $resolved = [];
        foreach ($tokens as $i => $token) {
            $pack = self::resolveToken($token);
            $resolved[$i] = $pack;
        }
        foreach ($tokens as $i => $token) {
            $pack = $resolved[$i];
            $low = mb_strtolower(trim($token));
            if (self::isNoiseToken($low)) {
                $discarded[] = $token;
                continue;
            }
            if (self::isDuplicatePartialOfNeighbor($low, $tokens, $i, $resolved)) {
                $discarded[] = $token;
                continue;
            }
            if ($pack['keep']) {
                $kept[] = $pack['value'];
                if ($pack['correction'] !== null) {
                    $corrections[] = $pack['correction'];
                }
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
            'corrections' => $corrections,
            'has_usable_clinical_text' => $usable,
        ];
    }

    /**
     * @return array{keep:bool,value:string,correction:?array{from:string,to:string,score:float}}
     */
    private static function resolveToken(string $token): array
    {
        $low = mb_strtolower(trim($token));
        if ($low === '' || (mb_strlen($low) === 1 && !ctype_digit($low))) {
            return ['keep' => false, 'value' => $low, 'correction' => null];
        }
        if (preg_match('/^\d+([.\/]\d+)?$/', $low) || self::isClinicalFunctionWord($low)) {
            return ['keep' => true, 'value' => $low, 'correction' => null];
        }
        if (self::looksExactClinicalToken($low)) {
            return ['keep' => true, 'value' => $low, 'correction' => null];
        }

        $fuzzy = self::fuzzyCorrect($low);
        if ($fuzzy !== null) {
            return [
                'keep' => true,
                'value' => $fuzzy['to'],
                'correction' => $fuzzy,
            ];
        }

        return ['keep' => false, 'value' => $low, 'correction' => null];
    }

    /**
     * @return array{from:string,to:string,score:float}|null
     */
    private static function fuzzyCorrect(string $low): ?array
    {
        if (class_exists('NlpStep3DemoAnswerFuzzy')) {
            try {
                $match = NlpStep3DemoAnswerFuzzy::bestMatch($low);
                $to = mb_strtolower(trim((string) ($match['to'] ?? '')));
                $score = (float) ($match['score'] ?? 0);
                if ($to !== '' && $to !== $low && (self::looksExactClinicalToken($to) || self::isClinicalFunctionWord($to))) {
                    return ['from' => $low, 'to' => $to, 'score' => $score];
                }
                if ($to !== '' && $to !== $low && $score >= 88.0) {
                    return ['from' => $low, 'to' => $to, 'score' => $score];
                }
            } catch (Throwable) {
                // fall through
            }
        }
        if (class_exists('MedicalMisspellingsLoader')) {
            try {
                $mapped = mb_strtolower(trim((string) MedicalMisspellingsLoader::applyCorrections($low)));
                if ($mapped !== '' && $mapped !== $low && self::looksExactClinicalToken($mapped)) {
                    return ['from' => $low, 'to' => $mapped, 'score' => 100.0];
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     * @param array<int, array{keep:bool,value:string,correction:?array}> $resolved
     */
    private static function isDuplicatePartialOfNeighbor(string $low, array $tokens, int $index, array $resolved): bool
    {
        foreach ([-1, 1] as $delta) {
            $ni = $index + $delta;
            $neighborOrig = mb_strtolower(trim((string) ($tokens[$ni] ?? '')));
            $neighbor = mb_strtolower(trim((string) (($resolved[$ni]['value'] ?? '') ?: $neighborOrig)));
            if ($neighbor === '' || $neighbor === $low) {
                continue;
            }
            if (mb_strlen($low) < 3 || mb_strlen($low) >= mb_strlen($neighbor)) {
                continue;
            }
            if (!self::looksExactClinicalToken($neighbor) && !self::isClinicalFunctionWord($neighbor)) {
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
        return (bool) preg_match(
            '/^(asdf+|qwer+|zxcv+|qwerty+|hhhh+|xxxx+|zzz+|test+|testing+|hello|hi|hey|yo|haha|lol|lmao|ok|okay|thanks|thank)$/u',
            $low
        );
    }

    private static function isClinicalFunctionWord(string $low): bool
    {
        return (bool) preg_match(
            '/^(hurts?|hurt|aching|ache|painful|masakit|sakit|gasakit|ginasakit|nagasakit|sumasakit|kirot|hapdi|'
            . 'grabe|gid|na|ka|pa|ya|lang|man|kay|nga|sang|'
            . 'adlaw|araw|semana|linggo|oras|bulan|buwan|days?|weeks?|months?|hours?|'
            . 'since|for|about|almost|ago|earlier|while|last|night|morning|today|yesterday|tonight|'
            . 'ligad|dugay|matagal|gahapon|kahapon|kagapon|kanina|subong|ngayon|bag-o|halin|una|'
            . 'monday|tuesday|wednesday|thursday|friday|saturday|sunday|'
            . 'my|ko|akon|ang|ulo|olo|head|tiyan|chest|dughan|mata|eye|left|right|wala|tuo)$/u',
            $low
        );
    }

    private static function looksExactClinicalToken(string $low): bool
    {
        return (bool) preg_match(
            '/^(fever|hilanat|lagnat|cough|cought|ubo|sipon|headache|pain|sakit|masakit|dizzy|dizziness|hilo|nahilo|nahihilo|lingin|'
            . 'vomit|vomiting|suka|nausea|diarrhea|diarrhoea|breath|breathing|ginhawa|dyspnea|chest|dughan|dibdib|tiyan|'
            . 'stomach|abdomen|ulo|olo|head|rash|bleed|bleeding|dugo|swell|swollen|hubag|weak|weakness|sick|unwell|'
            . 'ligad|dugay|gahapon|kahapon|kagapon|kanina|subong)$/u',
            $low
        );
    }

    private static function hasClinicalMeaning(string $cleaned): bool
    {
        return (bool) preg_match(
            '/\b(fever|hilanat|lagnat|cough|ubo|sipon|headache|pain|sakit|masakit|dizzy|hilo|nahilo|hurts?|hurt|aching|ache|chest|dughan|tiyan|ulo|head|vomit|vomiting|suka|nausea|diarrhea|breath|ginhawa|rash|bleed|dugo|hubag|sick|unwell|weak)\b/u',
            mb_strtolower($cleaned)
        );
    }
}
