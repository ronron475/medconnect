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
    private const MIN_TOKEN_LEN = 3;

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
            'kasakit' => 'kasakit',
            'gasakit' => 'gasakit',
            'pain' => 'pain',
            'ache' => 'ache',
            'headache' => 'headache',
            'headech' => 'headache',
            'hedache' => 'headache',
            'stomach' => 'stomach',
            'stomac' => 'stomach',
            'stomache' => 'stomach',
            'hilanat' => 'hilanat',
            'lagnat' => 'lagnat',
            'fever' => 'fever',
            'suka' => 'suka',
            'nagsuka' => 'nagsuka',
            'nagasuka' => 'nagasuka',
            'ginasuka' => 'nagsuka',
            'vomit' => 'vomit',
            'vomiting' => 'vomiting',
            'nausea' => 'nausea',
            'diarrhea' => 'diarrhea',
            'diarrhoea' => 'diarrhea',
            'hilo' => 'hilo',
            'nahilo' => 'nahilo',
            'nahihilo' => 'nahilo',
            'dizzy' => 'dizzy',
            'dizziness' => 'dizziness',
            'malipong' => 'malipong',
            'ginhawa' => 'ginhawa',
            'budlay' => 'budlay',
            'breath' => 'breath',
            'dyspnea' => 'dyspnea',
            'ubo' => 'ubo',
            'cough' => 'cough',
            'cought' => 'cough',
            'couph' => 'cough',
            'swollen' => 'swollen',
            'swelling' => 'swelling',
            'hubag' => 'hubag',
            'gahabok' => 'gahabok',
            'gahubag' => 'gahubag',
            'itchy' => 'itchy',
            'itching' => 'itching',
            'katol' => 'katol',
            'gakatol' => 'gakatol',
            'kapoy' => 'kapoy',
            'weak' => 'weak',
            'dugo' => 'dugo',
            'bleeding' => 'bleeding',
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
            // Extra slang / Tagalog / English / light misspellings (accuracy expansion)
            'saket' => 'sakit',
            'sakittt' => 'sakit',
            'masaket' => 'masakit',
            'kasaket' => 'kasakit',
            'gasaket' => 'gasakit',
            'headech' => 'headache',
            'hedache' => 'headache',
            'headace' => 'headache',
            'ulo ko' => 'ulo',
            'tyan' => 'tiyan',
            'tian' => 'tiyan',
            'tiyanan' => 'tiyan',
            'dughann' => 'dughan',
            'dibdiib' => 'dughan',
            'matta' => 'mata',
            'eyepain' => 'eye',
            'hilanattt' => 'hilanat',
            'lagnatt' => 'lagnat',
            'nilalagnat' => 'lagnat',
            'nagtatae' => 'diarrhea',
            'nagdudugo' => 'bleeding',
            'hirap' => 'hirap',
            'huminga' => 'huminga',
            'makahinga' => 'makaginhawa',
            'makaginhawa' => 'makaginhawa',
            'lipong' => 'malipong',
            'nahihilo' => 'nahilo',
            'sumasakit' => 'masakit',
            'nasaktan' => 'masakit',
            'grabe' => 'grabe',
            'grabee' => 'grabe',
            'gid' => 'gid',
            'kagapong' => 'gahapon',
            'kagahapon' => 'gahapon',
            'kangina' => 'kanina',
            'bigla' => 'sudden',
            'gulpi' => 'sudden',
            'unti-unti' => 'gradual',
            'hinay-hinay' => 'gradual',
            'wala sang' => 'wala',
            'wla' => 'wala',
            'nd' => 'indi',
            'dli' => 'indi',
            'di' => 'indi',
            'opo' => 'oo',
            'oho' => 'oo',
            'yespo' => 'yes',
            'nope' => 'no',
            'libang' => 'diarrhea',
            'tae' => 'diarrhea',
            'pagsuka' => 'vomiting',
            'nagsusuka' => 'vomiting',
            'nabubulahaw' => 'cyanosis',
            'asul' => 'cyanosis',
            'nguyam' => 'naguyam',
            'kombulsiyon' => 'seizure',
            'convulsion' => 'seizure',
            'stroke' => 'stroke',
            'buntis' => 'pregnant',
            'pregnant' => 'pregnant',
            // Round-2 patient typing / SMS training
            'msk' => 'masakit',
            'tyann' => 'tiyan',
            'oloo' => 'ulo',
            'dughanh' => 'dughan',
            'dibdibb' => 'dibdib',
            'makahinga' => 'makaginhawa',
            'mkahinga' => 'makaginhawa',
            'mkaginhawa' => 'makaginhawa',
            'hminga' => 'huminga',
            'huminga' => 'huminga',
            'nahimatay' => 'nahimatay',
            'nmatay' => 'nahimatay',
            'nawalan malay' => 'unresponsive',
            'walang malay' => 'unresponsive',
            'nanlalata' => 'weakness',
            'pamamanhid' => 'numbness',
            'kombulsyon' => 'seizure',
            'ahas' => 'snake',
            'man-og' => 'snake',
            'napaso' => 'nasunog',
            'nasunugan' => 'nasunog',
            'nagtatae' => 'diarrhea',
            'nagdudumi' => 'diarrhea',
            'lgnt' => 'lagnat',
            'hlnt' => 'hilanat',
            'cought' => 'cough',
            'couph' => 'cough',
            'ubo' => 'cough',
            'grbe' => 'grabe',
            'sobrang' => 'grabe',
            'di ako' => 'indi ako',
            'hindi ako' => 'indi ako',
            'aq' => 'ako',
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
     * Exact lexicon synonym expansion only (no typo distance).
     * e.g. kagapon → gahapon so existing extractors can match.
     */
    public static function synonymNormalize(string $text): string
    {
        $normalized = self::normalize($text);
        $lex = self::lexicon();
        $tokens = preg_split('/(\s+)/u', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = [];
        foreach ($tokens as $tok) {
            if ($tok === '' || preg_match('/^\s+$/u', $tok)) {
                $out[] = $tok;
                continue;
            }
            $clean = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $tok);
            $key = mb_strtolower($clean);
            if ($clean !== '' && isset($lex[$key]) && $lex[$key] !== $key) {
                $out[] = str_replace($clean, $lex[$key], $tok);
            } else {
                $out[] = $tok;
            }
        }

        return trim(implode('', $out));
    }

    /**
     * @return array{
     *   original: string,
     *   normalized: string,
     *   synonym: string,
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
        $synonym = self::synonymNormalize($original);
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
            if ($clean === '' || mb_strlen($clean) < self::MIN_TOKEN_LEN) {
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
            $corrected = $synonym !== '' ? $synonym : ($normalized !== '' ? $normalized : $original);
        }

        $status = 'NONE';
        if ($any && $corrected !== $normalized) {
            $status = 'SUCCESS';
        } elseif ($any) {
            $status = 'SUCCESS';
        } elseif ($synonym !== $normalized && $synonym !== $original) {
            $status = 'SYNONYM';
        } else {
            $status = 'NO_CORRECTION';
        }

        return [
            'original' => $original,
            'normalized' => $normalized,
            'synonym' => $synonym,
            'corrected' => $corrected,
            'changed' => ($corrected !== $original && $corrected !== $normalized)
                || ($corrections !== [] && $corrected !== $original)
                || ($synonym !== $original && $synonym !== $normalized),
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
        $tLen = mb_strlen($t);
        if ($t === '' || $tLen < self::MIN_TOKEN_LEN) {
            return null;
        }
        $lex = self::lexicon();
        if (isset($lex[$t])) {
            $to = $lex[$t];

            return $to === $t
                ? null
                : ['from' => $t, 'to' => $to, 'score' => 100.0];
        }

        $maxLev = self::maxLevForLength($tLen);
        $minScore = self::minScoreForLength($tLen);
        $best = null;
        $bestScore = 0.0;
        foreach ($lex as $candidate => $engineForm) {
            $cLen = mb_strlen($candidate);
            if (abs($cLen - $tLen) > $maxLev) {
                continue;
            }
            // Refuse matching a stub onto a much longer clinical word (hh ↛ headache).
            if ($cLen >= ($tLen * 2) && $tLen <= 4) {
                continue;
            }
            $a = self::toAsciiFold($t);
            $b = self::toAsciiFold($candidate);
            if ($a === '' || $b === '') {
                continue;
            }
            $lev = levenshtein($a, $b);
            if ($lev < 0 || $lev > $maxLev) {
                continue;
            }
            similar_text($a, $b, $pct);
            $score = $pct - ($lev * 8);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['from' => $t, 'to' => $engineForm, 'score' => $score];
            }
        }

        if ($best === null || $bestScore < $minScore) {
            return null;
        }

        return $best;
    }

    private static function maxLevForLength(int $len): int
    {
        if ($len <= 5) {
            return 1;
        }

        return self::MAX_LEV;
    }

    private static function minScoreForLength(int $len): float
    {
        if ($len <= 3) {
            return 88.0;
        }
        if ($len === 4) {
            return 76.0;
        }

        return self::MIN_SCORE;
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
