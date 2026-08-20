<?php
/**
 * Structured clinical feature extractors (duration, pain, temperature, risk factors).
 */

final class ClinicalFeatureExtractors
{
    /** @var array<string, int> */
    private const WORD_NUM = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'isa' => 1, 'duha' => 2, 'tatlo' => 3, 'apat' => 4, 'lima' => 5,
    ];

    private static function toInt(string $token): ?int
    {
        $t = strtolower(trim($token));
        if ($t !== '' && ctype_digit($t)) {
            return (int) $t;
        }

        return self::WORD_NUM[$t] ?? null;
    }

    /** @return array{raw:string,label:string,bucket:string,days:?int,hours:?int} */
    public static function extractDuration(string $text): array
    {
        $low = strtolower(trim($text));
        if ($low === '') {
            return ['raw' => '', 'label' => '', 'bucket' => 'unknown', 'days' => null, 'hours' => null];
        }

        if (preg_match('/(?:for|since|over|about|around)?\s*(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\s*(hours?|hrs?)/u', $low, $m)) {
            $n = self::toInt($m[1]) ?? 0;

            return ['raw' => $m[0], 'label' => $n . ' hour' . ($n === 1 ? '' : 's'), 'bucket' => 'acute_hours', 'days' => null, 'hours' => $n];
        }
        if (preg_match('/(?:for|since|over|nang|durante)?\s*(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\s*(days?|adlaw|araw)/u', $low, $m)
            || preg_match('/(\d+|one|two|three|four|five)\s*ka\s*adlaw/u', $low, $m)
            || preg_match('/(\d+)\s*araw/u', $low, $m)
        ) {
            $n = self::toInt($m[1]) ?? 0;
            $bucket = $n <= 2 ? '1_to_2_days' : ($n <= 4 ? '3_to_4_days' : '5_plus_days');

            return ['raw' => $m[0], 'label' => $n . ' day' . ($n === 1 ? '' : 's'), 'bucket' => $bucket, 'days' => $n, 'hours' => null];
        }
        if (preg_match('/(?:for|since|over)?\s*(\d+|one|two)\s*(weeks?|semana)|one week|1 week|isa ka semana|isang linggo/u', $low, $m)) {
            $n = isset($m[1]) ? (self::toInt($m[1]) ?? 1) : 1;

            return ['raw' => $m[0], 'label' => $n . ' week' . ($n === 1 ? '' : 's'), 'bucket' => 'chronic_weeks', 'days' => $n * 7, 'hours' => null];
        }
        if (preg_match('/\b(today|kanan|subong|ngayon)\b/u', $low, $m)) {
            return ['raw' => $m[0], 'label' => 'Today', 'bucket' => 'same_day', 'days' => 0, 'hours' => null];
        }
        if (preg_match('/\b(yesterday|gahapon|kahapon|since yesterday)\b/u', $low, $m)) {
            return ['raw' => $m[0], 'label' => 'Since yesterday', 'bucket' => '1_to_2_days', 'days' => 1, 'hours' => null];
        }
        if (preg_match('/\b(this morning|kanina|kanina sang aga)\b/u', $low, $m)) {
            return ['raw' => $m[0], 'label' => 'This morning', 'bucket' => 'acute_hours', 'days' => null, 'hours' => 6];
        }
        if (preg_match('/\b(dugay na|matagal na|for a long time)\b/u', $low, $m)) {
            return ['raw' => $m[0], 'label' => 'For a long time', 'bucket' => 'chronic_weeks', 'days' => 14, 'hours' => null];
        }
        if (preg_match('/\b(bag-o lang|just now|just started|gulpi lang|kalit lang|bigla lang)\b/u', $low, $m)) {
            return ['raw' => $m[0], 'label' => 'Just started', 'bucket' => 'acute_hours', 'days' => null, 'hours' => 1];
        }

        foreach (NlpFeaturePatternsLoader::patterns()['duration'] ?? [] as $row) {
            $pattern = (string) ($row['pattern'] ?? '');
            if ($pattern !== '' && str_contains($low, $pattern)) {
                $days = ($row['days'] ?? '') !== '' ? (int) $row['days'] : null;
                $bucket = (string) ($row['bucket'] ?? 'unknown');

                return [
                    'raw'   => $pattern,
                    'label' => ucfirst($pattern),
                    'bucket'=> $bucket,
                    'days'  => $days,
                    'hours' => null,
                ];
            }
        }

        return ['raw' => '', 'label' => '', 'bucket' => 'unknown', 'days' => null, 'hours' => null];
    }

    /** @return array{score:?int,band:string,label:string,modifier_key:string} */
    public static function extractPainScale(string $text): array
    {
        $low = strtolower($text);
        $score = null;

        if (preg_match('/pain\s*(?:scale|level|score)?\s*(?:of|is|=|:)?\s*(\d{1,2})\s*(?:\/\s*10)?/u', $low, $m)
            || preg_match('/(?:rate|rated|rating)\s*(?:my\s*)?pain\s*(?:at|as|=|:)?\s*(\d{1,2})/u', $low, $m)
            || preg_match('/\b(\d{1,2})\s*\/\s*10\b/u', $low, $m)
            || preg_match('/\b(\d{1,2})\s*out of\s*10\b/u', $low, $m)
        ) {
            $val = (int) $m[1];
            if ($val >= 0 && $val <= 10) {
                $score = $val;
            }
        }

        // Numeric pain scores only. Qualitative words (grabe/mild) must not invent a score.
        if ($score === null) {
            foreach (NlpFeaturePatternsLoader::patterns()['pain_scale'] ?? [] as $row) {
                $pattern = (string) ($row['pattern'] ?? '');
                if ($pattern === '' || !str_contains($low, $pattern) || !preg_match('/\d/', $pattern)) {
                    continue;
                }
                $score = (int) ($row['pain_score'] ?? 0);
                break;
            }
        }

        if ($score === null) {
            return ['score' => null, 'band' => '', 'label' => '', 'modifier_key' => ''];
        }
        if ($score <= 3) {
            return ['score' => $score, 'band' => 'mild', 'label' => "Pain {$score}/10 (Mild)", 'modifier_key' => 'mild_1_3'];
        }
        if ($score <= 6) {
            return ['score' => $score, 'band' => 'moderate', 'label' => "Pain {$score}/10 (Moderate)", 'modifier_key' => 'moderate_4_6'];
        }

        return ['score' => $score, 'band' => 'severe', 'label' => "Pain {$score}/10 (Severe)", 'modifier_key' => 'severe_7_10'];
    }

    /** @return array{celsius:?float,band:string,label:string,modifier_key:string} */
    public static function extractTemperature(string $text): array
    {
        $low = strtolower($text);
        $celsius = null;
        if (preg_match('/(\d{2}(?:\.\d)?)\s*°?\s*c\b/u', $low, $m)) {
            $celsius = (float) $m[1];
        } elseif (preg_match('/(\d{2}(?:\.\d)?)\s*degrees?/u', $low, $m)) {
            $val = (float) $m[1];
            $celsius = $val >= 90 ? round(($val - 32) * 5 / 9, 1) : $val;
        } elseif (preg_match('/temp(?:erature)?\s*(?:of|is|=|:)?\s*(\d{2}(?:\.\d)?)/u', $low, $m)) {
            $celsius = (float) $m[1];
        }

        $hasFever = (bool) preg_match('/\b(fever|lagnat|hilanat|nilalagnat|ginahilanat|pyrexia)\b/u', $low);
        if ($celsius === null) {
            if ($hasFever) {
                if (preg_match('/\b(high fever|grabe.*lagnat|mataas.*lagnat|very high)\b/u', $low)) {
                    return ['celsius' => null, 'band' => 'high_fever', 'label' => 'High fever (reported)', 'modifier_key' => 'high_fever'];
                }

                return ['celsius' => null, 'band' => 'fever', 'label' => 'Fever (reported)', 'modifier_key' => 'fever'];
            }

            return ['celsius' => null, 'band' => '', 'label' => '', 'modifier_key' => ''];
        }

        if ($celsius >= 39.0) {
            return ['celsius' => $celsius, 'band' => 'high_fever', 'label' => "Temperature {$celsius}°C (High fever)", 'modifier_key' => 'high_fever'];
        }
        if ($celsius >= 38.0) {
            return ['celsius' => $celsius, 'band' => 'fever', 'label' => "Temperature {$celsius}°C (Fever)", 'modifier_key' => 'fever'];
        }
        if ($celsius >= 37.5) {
            return ['celsius' => $celsius, 'band' => 'low_grade', 'label' => "Temperature {$celsius}°C (Low-grade)", 'modifier_key' => 'low_grade'];
        }

        foreach (NlpFeaturePatternsLoader::patterns()['temperature'] ?? [] as $row) {
            $pattern = (string) ($row['pattern'] ?? '');
            if ($pattern !== '' && str_contains($low, $pattern)) {
                $band = (string) ($row['band'] ?? '');
                $celsiusVal = ($row['celsius'] ?? '') !== '' ? (float) $row['celsius'] : null;

                return [
                    'celsius'      => $celsiusVal,
                    'band'         => $band,
                    'label'        => ucfirst($pattern),
                    'modifier_key' => $band !== '' ? $band : '',
                ];
            }
        }

        return ['celsius' => $celsius, 'band' => 'normal', 'label' => "Temperature {$celsius}°C", 'modifier_key' => ''];
    }

    /** @return list<array{id:string,label:string}> */
    public static function extractRiskFactors(string $text, string $englishText = ''): array
    {
        $hay = strtolower($text . ' ' . $englishText);
        $catalog = [
            ['pregnant', 'Pregnant', ['/\bpregnant\b/u', '/\bpregnancy\b/u', '/\bbuntis\b/u', '/\bnagabusong\b/u']],
            ['infant', 'Infant', ['/\binfant\b/u', '/\bbaby\b/u', '/\bnewborn\b/u', '/\bsanggol\b/u']],
            ['child', 'Child', ['/\b(my child|anak|for my\s+bata|sa akon\s+bata)\b/u']],
            ['senior', 'Senior Citizen', ['/\bsenior\b/u', '/\belderly\b/u', '/\btigulang\b/u', '/\bmatanda\b/u']],
            ['diabetes', 'Diabetes', ['/\bdiabetes\b/u', '/\bdiabetic\b/u', '/\bhigh blood sugar\b/u']],
            ['hypertension', 'Hypertension', ['/\bhypertension\b/u', '/\bhigh blood\b/u', '/\btaas blood\b/u']],
            ['asthma', 'Asthma', ['/\basthma\b/u', '/\bhika\b/u']],
            ['cancer', 'Cancer', ['/\bcancer\b/u', '/\bchemotherapy\b/u']],
            ['heart_disease', 'Heart Disease', ['/\bheart disease\b/u', '/\bheart failure\b/u', '/\bcoronary\b/u', '/\bheart attack history\b/u']],
            ['kidney_disease', 'Kidney Disease', ['/\bkidney disease\b/u', '/\bdialysis\b/u', '/\bckd\b/u']],
            ['immunocompromised', 'Immunocompromised', ['/\bimmunocompromised\b/u', '/\bimmunosuppressed\b/u', '/\btransplant\b/u']],
        ];

        $found = [];
        foreach ($catalog as [$id, $label, $patterns]) {
            foreach ($patterns as $pat) {
                if (preg_match($pat, $hay)) {
                    if ($id === 'senior' && preg_match('/\b(\d{2,3})\s*years? old\b/u', $hay, $m) && (int) $m[1] < 60
                        && !str_contains($hay, 'senior') && !str_contains($hay, 'tigulang')) {
                        continue 2;
                    }
                    $found[] = ['id' => $id, 'label' => $label];
                    break;
                }
            }
        }
        if (preg_match('/\b(\d{2,3})\s*years? old\b/u', $hay, $m) && (int) $m[1] >= 60) {
            $has = false;
            foreach ($found as $f) {
                if ($f['id'] === 'senior') {
                    $has = true;
                    break;
                }
            }
            if (!$has) {
                $found[] = ['id' => 'senior', 'label' => 'Senior Citizen'];
            }
        }

        foreach (NlpFeaturePatternsLoader::patterns()['risk_factors'] ?? [] as $row) {
            $pattern = (string) ($row['pattern'] ?? '');
            $label = (string) ($row['label'] ?? '');
            if ($pattern === '' || $label === '' || !str_contains($hay, $pattern)) {
                continue;
            }
            $id = strtolower(str_replace(' ', '_', $label));
            $exists = false;
            foreach ($found as $f) {
                if (($f['id'] ?? '') === $id) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $found[] = ['id' => $id, 'label' => ucwords($label)];
            }
        }

        return $found;
    }

    /** @param list<array{id:string,label:string}> $riskFactors */
    public static function extractAgeGroup(string $text, array $riskFactors = []): string
    {
        $ids = array_column($riskFactors, 'id');
        if (in_array('infant', $ids, true)) {
            return 'Infant';
        }
        if (in_array('child', $ids, true)) {
            return 'Child';
        }
        if (in_array('senior', $ids, true)) {
            return 'Senior Citizen';
        }
        if (in_array('pregnant', $ids, true)) {
            return 'Pregnant Adult';
        }
        if (preg_match('/\b(\d{1,3})\s*(?:years?|yrs?)\s*old\b/u', strtolower($text), $m)) {
            $age = (int) $m[1];
            if ($age < 1) {
                return 'Infant';
            }
            if ($age < 18) {
                return 'Child';
            }
            if ($age >= 60) {
                return 'Senior Citizen';
            }

            return 'Adult';
        }

        return 'Unknown';
    }

    public static function extractOnset(string $text): string
    {
        $low = strtolower($text);
        if ($low === '') {
            return '';
        }
        if (preg_match('/\b(gulpi|kalit|sudden|suddenly|bigla|all of a sudden)\b/u', $low)) {
            return 'sudden';
        }
        if (preg_match('/\b(hinay-hinay|hinay|gradual|gradually|slowly|unti-unti|paunti-unti)\b/u', $low)) {
            return 'gradual';
        }

        return '';
    }

    public static function extractPainQualifier(string $text): string
    {
        $low = strtolower($text);
        if (preg_match('/\b(grabe|severe|unbearable|worst)\b/u', $low) || preg_match('/\b(sakit|hapdi|masakit).{0,12}\bgid\b/u', $low)) {
            return 'severe';
        }
        if (preg_match('/\b(moderate|medyo|tunga-tunga)\b/u', $low)) {
            return 'moderate';
        }
        if (preg_match('/\b(mild|gamay|slight)\b/u', $low)) {
            return 'mild';
        }

        return '';
    }

    public static function extractStandalonePainScore(string $text, bool $awaitingPainQuestion): ?int
    {
        $low = strtolower(trim($text));
        if ($low === '') {
            return null;
        }
        if (preg_match('/\b(\d{1,2})\s*\/\s*10\b/u', $low, $m) || preg_match('/\b(\d{1,2})\s*out of\s*10\b/u', $low, $m)) {
            $val = (int) $m[1];

            return ($val >= 0 && $val <= 10) ? $val : null;
        }
        if (!$awaitingPainQuestion) {
            return null;
        }
        if (preg_match('/^\s*(\d{1,2})\s*(?:\/\s*10)?(?:\s|$)/u', $low, $m)) {
            $val = (int) $m[1];

            return ($val >= 0 && $val <= 10) ? $val : null;
        }

        return null;
    }

    /** @return list<string> */
    public static function extractBodyLocations(string $text): array
    {
        $low = strtolower($text);
        if ($low === '') {
            return [];
        }
        $map = [
            'ulo' => 'head', 'head' => 'head', 'headache' => 'head',
            'dughan' => 'chest', 'dibdib' => 'chest', 'chest' => 'chest',
            'tiyan' => 'abdomen', 'abdomen' => 'abdomen', 'stomach' => 'abdomen', 'belly' => 'abdomen',
            'ilong' => 'nose', 'nose' => 'nose',
            'kamot' => 'hand', 'kamay' => 'hand', 'hand' => 'hand',
            'tiil' => 'leg', 'paa' => 'leg', 'leg' => 'leg',
            'likod' => 'back', 'back' => 'back',
            'liog' => 'neck', 'leeg' => 'neck', 'neck' => 'neck',
            'mata' => 'eye', 'eye' => 'eye',
        ];
        $found = [];
        foreach ($map as $term => $canonical) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $low) && !in_array($canonical, $found, true)) {
                $found[] = $canonical;
            }
        }

        return $found;
    }

    public static function deniedAssociatedSymptoms(string $text): bool
    {
        $low = strtolower($text);

        return (bool) preg_match(
            '/wala iban nga sintomas|wala na iban|no other symptoms|nothing else|walang ibang sintomas|wala nang iba|no weakness|no numbness|without weakness|without numbness/u',
            $low
        );
    }

    public static function extractYesNo(string $text): ?bool
    {
        $low = strtolower(trim($text));
        if ($low === '') {
            return null;
        }
        if (preg_match('/\b(yes|oo|opo|hoo|hu-o|huo|tama|correct|gid)\b/u', $low)
            && !preg_match('/\b(no|wala|hindi|indi|none)\b/u', $low)
        ) {
            return true;
        }
        if (preg_match('/\b(no|wala|hindi|indi|none|not really)\b/u', $low)) {
            return false;
        }

        return null;
    }

    public static function isVagueComplaint(string $text): bool
    {
        $low = strtolower(trim($text));
        $low = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $low));
        $low = trim((string) preg_replace('/\s+/u', ' ', $low));
        if ($low === '') {
            return true;
        }
        $genericExact = [
            'sakit', 'masakit', 'pain', 'hurts', 'hurt', 'it hurts', 'something hurts',
            'may sakit ako', 'indi ko maayo', 'indi ko maayo ang lawas ko',
            'masama pakiramdam ko', 'i dont feel well', 'i don t feel well',
            'not feeling well', 'lain lawas ko', 'may nararamdaman ako',
            'masakit gid', 'something is wrong',
        ];
        if (in_array($low, $genericExact, true)) {
            return true;
        }
        if (preg_match('/^(sakit|masakit|pain|hurts?|discomfort)( gid| lang| ko| ako)?$/u', $low)) {
            return true;
        }
        if (preg_match('/\bi don\'t feel well\b|\bnot feeling well\b|\bindi maayo\b|\bhindi maganda (ang )?pakiramdam\b|\bsomething is wrong\b|\blain lawas\b/u', $low)
            && !preg_match('/(ulo|dughan|dibdib|tiyan|ilong|chest|head|fever|lagnat|dugo|ginhawa|breath)/u', $low)
        ) {
            return true;
        }
        if (preg_match('/(fever|lagnat|hilanat|cough|ubo|sip-?on|chest|dughan|dibdib|breath|ginhawa|hinga|blood|dugo|suka|vomit|headache|ulo|tiyan|abdomen|ilong|nose|nause)/u', $low)) {
            return false;
        }
        $words = preg_split('/\s+/u', $low) ?: [];

        return count($words) <= 3;
    }

    /** Complaint text NLP cannot parse into a safe clinical picture. */
    public static function isUnintelligibleComplaint(string $text): bool
    {
        $low = strtolower(trim($text));
        $low = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $low));
        $low = trim((string) preg_replace('/\s+/u', ' ', $low));
        if ($low === '') {
            return true;
        }
        if (preg_match('/\b(asdf|qwerty|zxcv|hjkl|xxxx|xzy|xyz|qwe|asd)\b/u', $low)) {
            return true;
        }

        $words = preg_split('/\s+/u', $low) ?: [];
        $realWords = 0;
        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/u', '', $word) ?? '';
            if ($word === '' || strlen($word) < 3) {
                continue;
            }
            if (preg_match('/[aeiouy]/u', $word)) {
                $realWords++;
            }
        }

        return count($words) >= 2 && $realWords <= 1 && preg_match('/\b(sakit|masakit|pain|hurt)\b/u', $low);
    }

    /** Patient reply that does not answer the pending clinical question. */
    public static function isUnclearAnswer(string $text): bool
    {
        $low = strtolower(trim($text));
        $low = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $low));
        $low = trim((string) preg_replace('/\s+/u', ' ', $low));
        if ($low === '') {
            return true;
        }
        if (preg_match('/^(ambot|ewan|dunno|don t know|dont know|maybe|basta|hindi ko alam|indi ko mahibaluan|uh+h|um+)$/u', $low)) {
            return true;
        }
        if (preg_match('/^(yes|no|oo|hindi|indi|wala|meron|oo po|hindi po)$/u', $low)) {
            return false;
        }

        return mb_strlen($low) <= 2;
    }

    /**
     * @param list<string> $negatedConcepts
     * @return array<string, mixed>
     */
    public static function extractAll(string $original, string $english = '', array $negatedConcepts = []): array
    {
        $combined = trim($original . ' ' . $english);
        $risks = self::extractRiskFactors($original, $english);
        $temperature = self::extractTemperature($combined);
        $neg = array_map('strtolower', $negatedConcepts);
        // Do not score fever/temperature when fever is explicitly negated
        if (array_intersect($neg, ['fever', 'lagnat', 'hilanat']) !== []) {
            $temperature = ['celsius' => null, 'band' => '', 'label' => '', 'modifier_key' => ''];
        }

        return [
            'duration'          => self::extractDuration($combined),
            'pain_scale'        => self::extractPainScale($combined),
            'pain_qualifier'    => self::extractPainQualifier($combined),
            'onset'             => self::extractOnset($combined),
            'body_locations'    => self::extractBodyLocations($combined),
            'temperature'       => $temperature,
            'risk_factors'      => $risks,
            'age_group'         => self::extractAgeGroup($combined, $risks),
            'vague_complaint'   => self::isVagueComplaint($original !== '' ? $original : $english),
            'negated_concepts'  => array_values($neg),
            'denied_associated' => self::deniedAssociatedSymptoms($combined),
        ];
    }
}
