<?php
/**
 * DEMO ONLY — universal patient-answer misspelling / typo tolerance.
 *
 * Corrects tokens against a vocabulary of canonical clinical spellings
 * (Levenshtein + similar_text). Does NOT hard-code each misspelling.
 * Does NOT invent clinical facts beyond mapping to known vocabulary.
 */
final class NlpStep3DemoAnswerFuzzy
{
    private const MIN_SCORE = 72.0;
    private const MAX_LEV = 2;

    /**
     * Canonical vocabulary. Values are engine-ready forms understood by
     * existing extractors / demo clinical state (synonym normalization only).
     *
     * @return array<string, string>
     */
    public static function lexicon(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        // key = correct spelling / accepted variant; value = form existing NLP prefers
        $pairs = [
            // onset / duration
            'gahapon' => 'gahapon',
            'kahapon' => 'gahapon',
            'kagapon' => 'gahapon',
            'yesterday' => 'yesterday',
            'kagab-i' => 'kagab-i',
            'kagabi' => 'kagab-i',
            'kanina' => 'kanina',
            'subong' => 'subong',
            'dugay' => 'dugay',
            'ligad' => 'ligad',
            'halin' => 'halin',
            // locations
            'ulo' => 'ulo',
            'olo' => 'ulo',
            'head' => 'head',
            'headache' => 'headache',
            'tiyan' => 'tiyan',
            'abdomen' => 'abdomen',
            'stomach' => 'stomach',
            'dughan' => 'dughan',
            'dibdib' => 'dughan',
            'chest' => 'chest',
            'mata' => 'mata',
            'eye' => 'eye',
            'likod' => 'likod',
            'back' => 'back',
            'liog' => 'liog',
            'neck' => 'neck',
            // severity words
            'pito' => 'pito',
            'seven' => 'seven',
            'lima' => 'lima',
            'five' => 'five',
            'apat' => 'apat',
            'four' => 'four',
            'tatlo' => 'tatlo',
            'three' => 'three',
            'duha' => 'duha',
            'two' => 'two',
            'isa' => 'isa',
            'one' => 'one',
            'walo' => 'walo',
            'eight' => 'eight',
            'siyam' => 'siyam',
            'nine' => 'nine',
            'napulo' => 'napulo',
            'ten' => 'ten',
            // symptoms
            'sakit' => 'sakit',
            'masakit' => 'masakit',
            'pain' => 'pain',
            'hilanat' => 'hilanat',
            'lagnat' => 'lagnat',
            'fever' => 'fever',
            'suka' => 'suka',
            'nagsuka' => 'nagsuka',
            'nagasuka' => 'nagasuka',
            'ginasuka' => 'nagsuka',
            'vomit' => 'vomit',
            'vomiting' => 'vomiting',
            'hilo' => 'hilo',
            'nahilo' => 'nahilo',
            'dizzy' => 'dizzy',
            'dizziness' => 'dizziness',
            'malipong' => 'malipong',
            'ginhawa' => 'ginhawa',
            'budlay' => 'budlay',
            'breath' => 'breath',
            'dyspnea' => 'dyspnea',
            'ubo' => 'ubo',
            'cough' => 'cough',
            'pulsing' => 'pulsing',
            'pulsating' => 'pulsating',
            'nagapulsar' => 'pulsing',
            'tusok' => 'tusok',
            // yes/no
            'oo' => 'oo',
            'opo' => 'oo',
            'yes' => 'yes',
            'indi' => 'indi',
            'hindi' => 'indi',
            'wala' => 'wala',
            'no' => 'no',
            'tuo' => 'tuo',
            'right' => 'right',
            'left' => 'left',
            'kaliwa' => 'left',
        ];

        $map = [];
        foreach ($pairs as $k => $v) {
            $map[mb_strtolower($k)] = mb_strtolower($v);
        }

        return $map;
    }

    /**
     * Light text normalization (spacing / punctuation), not clinical invention.
     */
    public static function normalize(string $text): string
    {
        $text = trim(mb_strtolower($text));
        $text = str_replace(['´', '`', '’', "'"], '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        // Collapse extreme repeated letters: olooo -> oloo (keep mild repeats for fuzzy)
        $text = (string) preg_replace('/(.)\1{3,}/u', '$1$1', $text);

        return trim($text);
    }

    /**
     * @return array{
     *   original: string,
     *   normalized: string,
     *   corrected: string,
     *   changed: bool,
     *   corrections: list<array{from:string,to:string,score:float}>,
     *   fuzzy_status: string,
     *   confidence: float
     * }
     */
    public static function prepare(string $text, string $awaiting = ''): array
    {
        unset($awaiting);
        $original = trim($text);
        $normalized = self::normalize($original);
        $corrections = [];
        $tokens = preg_split('/(\s+)/u', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = [];
        $minConf = 1.0;
        $any = false;

        foreach ($tokens as $tok) {
            if ($tok === '' || preg_match('/^\s+$/u', $tok)) {
                $out[] = $tok;
                continue;
            }
            // Keep numeric / slash scores intact.
            if (preg_match('/^\d{1,2}(?:\/10)?$/u', $tok) || preg_match('/^\d+(?:\.\d+)?$/u', $tok)) {
                $out[] = $tok;
                continue;
            }
            $clean = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $tok);
            if ($clean === '' || mb_strlen($clean) < 3) {
                $out[] = $tok;
                continue;
            }
            $match = self::bestMatch($clean);
            if ($match !== null) {
                $any = true;
                $corrections[] = $match;
                $minConf = min($minConf, (float) $match['score'] / 100.0);
                $out[] = str_replace($clean, $match['to'], $tok);
            } else {
                $out[] = $tok;
            }
        }

        $corrected = trim(implode('', $out));
        if ($corrected === '') {
            $corrected = $normalized !== '' ? $normalized : $original;
        }

        $status = 'NONE';
        if ($any && $corrected !== $normalized) {
            $status = 'SUCCESS';
        } elseif ($any) {
            $status = 'SUCCESS';
        } else {
            $status = 'NO_CORRECTION';
        }

        return [
            'original' => $original,
            'normalized' => $normalized,
            'corrected' => $corrected,
            'changed' => $corrected !== $original && $corrected !== $normalized
                || ($corrections !== [] && $corrected !== $original),
            'corrections' => $corrections,
            'fuzzy_status' => $status,
            'confidence' => $corrections === [] ? 1.0 : $minConf,
        ];
    }

    /**
     * @return array{from:string,to:string,score:float}|null
     */
    public static function bestMatch(string $token): ?array
    {
        $t = mb_strtolower(trim($token));
        if ($t === '' || mb_strlen($t) < 3) {
            return null;
        }
        $lex = self::lexicon();
        if (isset($lex[$t])) {
            $to = $lex[$t];

            return $to === $t
                ? null
                : ['from' => $t, 'to' => $to, 'score' => 100.0];
        }

        $best = null;
        $bestScore = 0.0;
        $tLen = mb_strlen($t);
        foreach ($lex as $candidate => $engineForm) {
            $cLen = mb_strlen($candidate);
            if (abs($cLen - $tLen) > self::MAX_LEV) {
                continue;
            }
            // ASCII levenshtein for speed; skip if non-ascii length mismatch already handled
            $a = self::toAsciiFold($t);
            $b = self::toAsciiFold($candidate);
            if ($a === '' || $b === '') {
                continue;
            }
            $lev = levenshtein($a, $b);
            if ($lev > self::MAX_LEV) {
                continue;
            }
            similar_text($a, $b, $pct);
            $score = $pct - ($lev * 8);
            // Prefer slightly longer clinical roots when tied.
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['from' => $t, 'to' => $engineForm, 'score' => $score];
            }
        }

        if ($best === null || $bestScore < self::MIN_SCORE) {
            return null;
        }
        // Avoid over-correction: very short tokens need higher similarity.
        if ($tLen <= 3 && $bestScore < 85) {
            return null;
        }

        return $best;
    }

    private static function toAsciiFold(string $s): string
    {
        $s = mb_strtolower($s);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($converted) && $converted !== '') {
                $s = $converted;
            }
        }

        return (string) preg_replace('/[^a-z0-9\-]/', '', $s);
    }
}
