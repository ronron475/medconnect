<?php
/**
 * Data-driven symptom knowledge base + red-flag library for clinical triage CDS.
 */

final class SymptomKnowledgeBase
{
    /** @var array<string, mixed>|null */
    private static ?array $kb = null;

    /** @var array<string, mixed>|null */
    private static ?array $redFlags = null;

    /** @var list<array<string, mixed>>|null */
    private static ?array $symptomIndex = null;

    /** @var array<string, list<string>>|null */
    private static ?array $csvBoosts = null;

    /** @return array<string, mixed> */
    public static function load(): array
    {
        if (self::$kb !== null) {
            return self::$kb;
        }
        $path = BASE_PATH . '/data/nlp/symptom_knowledge_base.json';
        if (!is_readable($path)) {
            self::$kb = ['symptoms' => [], 'scoring' => []];

            return self::$kb;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        self::$kb = is_array($raw) ? $raw : ['symptoms' => [], 'scoring' => []];

        return self::$kb;
    }

    /** @return array<string, mixed> */
    public static function loadRedFlags(): array
    {
        if (self::$redFlags !== null) {
            return self::$redFlags;
        }
        $path = BASE_PATH . '/data/nlp/red_flags_library.json';
        if (!is_readable($path)) {
            self::$redFlags = ['red_flags' => [], 'policy' => []];

            return self::$redFlags;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        self::$redFlags = is_array($raw) ? $raw : ['red_flags' => [], 'policy' => []];

        return self::$redFlags;
    }

    /** @return array<string, mixed> */
    public static function scoringConfig(): array
    {
        $kb = self::load();

        return is_array($kb['scoring'] ?? null) ? $kb['scoring'] : [];
    }

    /** @return list<array<string, mixed>> */
    private static function symptomIndex(): array
    {
        if (self::$symptomIndex !== null) {
            return self::$symptomIndex;
        }
        $index = [];
        foreach ((self::load()['symptoms'] ?? []) as $symptom) {
            if (!is_array($symptom)) {
                continue;
            }
            $terms = [];
            foreach (['keywords', 'synonyms', 'hiligaynon_terms', 'filipino_terms'] as $key) {
                foreach (($symptom[$key] ?? []) as $term) {
                    $t = strtolower(trim((string) $term));
                    if ($t !== '') {
                        $terms[] = $t;
                    }
                }
            }
            $name = strtolower(trim((string) ($symptom['symptom_name'] ?? '')));
            if ($name !== '') {
                $terms[] = $name;
            }
            $terms = array_values(array_unique($terms));
            usort($terms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            if ($terms === []) {
                continue;
            }
            $symptom['_match_terms'] = $terms;
            $index[] = $symptom;
        }

        // Expand match terms from CSV synonym / Hiligaynon / Filipino term banks (no code change needed to add rows)
        $csvBoost = self::loadCsvTermBoosts();
        foreach ($index as &$symptom) {
            $eng = strtolower((string) ($symptom['symptom_name'] ?? ''));
            $sid = strtolower((string) ($symptom['id'] ?? ''));
            $extra = array_merge($csvBoost[$eng] ?? [], $csvBoost[$sid] ?? []);
            if ($extra !== []) {
                $merged = array_values(array_unique(array_merge($symptom['_match_terms'], $extra)));
                usort($merged, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
                $symptom['_match_terms'] = $merged;
            }
        }
        unset($symptom);

        self::$symptomIndex = $index;

        return self::$symptomIndex;
    }

    /**
     * Map english/concept → local terms from expandable CSVs.
     *
     * @return array<string, list<string>>
     */
    private static function loadCsvTermBoosts(): array
    {
        if (self::$csvBoosts !== null) {
            return self::$csvBoosts;
        }

        $boost = [];
        // Prefer compact language banks first (full corpora remain available for training/admin)
        $files = [
            BASE_PATH . '/data/nlp/hiligaynon_medical_terms.csv',
            BASE_PATH . '/data/nlp/filipino_medical_terms.csv',
            BASE_PATH . '/data/nlp/medical_phrases.csv',
        ];
        foreach ($files as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $handle = fopen($path, 'r');
            if ($handle === false) {
                continue;
            }
            $header = fgetcsv($handle);
            $count = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $data = array_combine(
                    array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                    array_map(static fn ($v) => trim((string) $v), $row)
                ) ?: [];
                $local = strtolower((string) (($data['term'] ?? '') ?: ($data['local_term'] ?? '') ?: ($data['phrase'] ?? '')));
                $eng = strtolower((string) (($data['english'] ?? '') ?: ($data['english_term'] ?? '')));
                $concept = strtolower((string) ($data['concept'] ?? ''));
                if ($local === '' || strlen($local) > 60 || ($eng === '' && $concept === '')) {
                    continue;
                }
                foreach (array_filter([$eng, $concept, str_replace('_', ' ', $concept)]) as $key) {
                    if ($key === '') {
                        continue;
                    }
                    $boost[$key][] = $local;
                }
                $count++;
                if ($count >= 4000) {
                    break;
                }
            }
            fclose($handle);
        }
        foreach ($boost as $k => $list) {
            $boost[$k] = array_values(array_unique($list));
        }
        self::$csvBoosts = $boost;

        return self::$csvBoosts;
    }

    /**
     * @param list<string> $extraTerms
     * @return list<array<string, mixed>>
     */
    public static function matchSymptoms(string $text, string $englishText = '', array $extraTerms = []): array
    {
        $hay = strtolower(trim(implode(' | ', array_filter([
            $text,
            $englishText,
            implode(' ', $extraTerms),
        ]))));
        if ($hay === '') {
            return [];
        }

        $matched = [];
        $seen = [];
        foreach (self::symptomIndex() as $symptom) {
            $sid = (string) ($symptom['id'] ?? $symptom['symptom_name'] ?? '');
            if ($sid === '' || isset($seen[$sid])) {
                continue;
            }
            foreach ($symptom['_match_terms'] as $term) {
                if ($term === '' || (strlen($term) < 5 && !str_contains($term, ' '))) {
                    $allowShort = ['ubo', 'sipon', 'lagnat', 'hilo', 'tae', 'dugo', 'hapdi', 'kapoy', 'luya', 'ulon', 'mata', 'dughan'];
                    if (!in_array($term, $allowShort, true)) {
                        continue;
                    }
                }
                if (!self::termMatchesWithContext($hay, $term, $symptom)) {
                    continue;
                }
                $matched[] = [
                    'id'                  => $symptom['id'] ?? '',
                    'symptom_name'        => $symptom['symptom_name'] ?? '',
                    'medical_category'    => $symptom['medical_category'] ?? '',
                    'severity_weight'      => (int) ($symptom['severity_weight'] ?? 0),
                    'emergency_weight'    => (int) ($symptom['emergency_weight'] ?? 0),
                    'urgent_weight'       => (int) ($symptom['urgent_weight'] ?? 0),
                    'danger_sign'         => (bool) ($symptom['danger_sign'] ?? false),
                    'recommended_action'  => (string) ($symptom['recommended_action'] ?? ''),
                    'matched_term'        => $term,
                    'common_causes'       => $symptom['common_causes'] ?? [],
                    'danger_signs'        => $symptom['danger_signs'] ?? [],
                ];
                $seen[$sid] = true;
                break;
            }
        }
        usort($matched, static fn (array $a, array $b): int => ($b['severity_weight'] <=> $a['severity_weight']));

        // Cap overly broad multi-symptom extraction (prevents score inflation)
        return array_slice($matched, 0, 8);
    }

    /**
     * Require qualifier words when a generic term (fever, pain) would otherwise over-match.
     *
     * @param array<string, mixed> $symptom
     */
    private static function termMatchesWithContext(string $hay, string $term, array $symptom): bool
    {
        if (!self::flexiblePhraseHit($hay, $term)) {
            return false;
        }

        $name = strtolower(trim((string) ($symptom['symptom_name'] ?? '')));
        if ($name !== '' && str_contains($hay, $name)) {
            return true;
        }

        if ($name === 'acute abdomen') {
            return (bool) preg_match('/\b(acute|rigid|peritonitis|sudden severe)\b/u', $hay);
        }

        $explicit = [];
        foreach (['keywords', 'synonyms', 'hiligaynon_terms', 'filipino_terms'] as $key) {
            foreach (($symptom[$key] ?? []) as $t) {
                $t = strtolower(trim((string) $t));
                if ($t !== '') {
                    $explicit[] = $t;
                }
            }
        }
        $explicit = array_values(array_unique($explicit));

        $generic = ['fever', 'pain', 'cough', 'ache', 'bleeding', 'weakness', 'fatigue', 'rash', 'swelling'];
        $stopWords = ['with', 'in', 'and', 'the', 'of', 'a', 'for', 'to', 'sore', 'mild', 'severe'];

        $isLocalTerm = false;
        foreach (['hiligaynon_terms', 'filipino_terms'] as $key) {
            foreach (($symptom[$key] ?? []) as $local) {
                if (strtolower(trim((string) $local)) === $term) {
                    $isLocalTerm = true;
                    break 2;
                }
            }
        }

        // Multi-word symptom names must not match on a single shared English token only.
        if (!$isLocalTerm && str_contains($name, ' ') && $term !== $name) {
            if (!self::compoundQualifiersSatisfied($name, $term, $hay, $generic, $stopWords)) {
                return false;
            }
        }

        if (in_array($term, $explicit, true) && !in_array($term, $generic, true) && strlen($term) >= 6) {
            if ($isLocalTerm || !str_contains($name, ' ') || self::compoundQualifiersSatisfied($name, $term, $hay, $generic, $stopWords)) {
                return true;
            }
        }
        if (str_contains($term, ' ') && in_array($term, $explicit, true)) {
            return true;
        }

        $highAcuity = ['appendicitis pain', 'pancreatitis pain', 'testicular torsion', 'sepsis symptoms', 'meningitis symptoms'];
        if (in_array($name, $highAcuity, true)
            && !preg_match('/\b(severe|acute|sudden|worst|rigid|unbearable|grabe)\b/u', $hay)) {
            return false;
        }

        if (preg_match('/^(child|infant|pediatric)\s+/u', $name, $prefix)) {
            $specific = trim(substr($name, strlen($prefix[0])));
            if ($specific !== '' && !str_contains($hay, $specific)) {
                return false;
            }
        }

        if (str_ends_with($name, ' pain')) {
            $location = str_replace(' pain', '', $name);
            $bodyMap = [
                'abdominal' => '/\b(abdomen|abdominal|stomach|belly|tiyan)\b/u',
                'back' => '/\b(back|likod)\b/u',
                'chest' => '/\b(chest|dughan|dibdib)\b/u',
                'head' => '/\b(head|ulo)\b/u',
                'neck' => '/\b(neck|liog|leeg)\b/u',
            ];
            if (isset($bodyMap[$location])) {
                return (bool) preg_match($bodyMap[$location], $hay);
            }
            if ($location !== 'chronic' && !str_contains($hay, $location)) {
                return false;
            }
        }

        $nameWords = preg_split('/\s+/u', $name) ?: [];
        $termWords = preg_split('/\s+/u', $term) ?: [];
        $qualGeneric = ['fever', 'pain', 'cough', 'bleeding', 'ache', 'symptoms', 'symptom', 'severe', 'acute', 'chronic', 'mild', 'high', 'low', 'with'];

        if (count($nameWords) <= count($termWords)) {
            return true;
        }

        $qualifiers = array_values(array_filter(
            array_diff($nameWords, $termWords),
            static fn (string $w): bool => strlen($w) >= 4 && !in_array($w, $qualGeneric, true)
        ));

        if ($qualifiers === []) {
            return true;
        }

        foreach ($qualifiers as $q) {
            if (str_contains($hay, $q)) {
                return true;
            }
        }

        if (array_intersect($qualifiers, ['infant', 'neonatal', 'newborn'])) {
            if (preg_match('/\b(infant|baby|newborn|sanggol)\b/u', $hay)) {
                return true;
            }
        }
        if (array_intersect($qualifiers, ['child', 'pediatric'])) {
            if (preg_match('/\b(child|anak|bata)\b/u', $hay)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public static function scanRedFlagsLibrary(string $original, string $english = ''): array
    {
        $lib = self::loadRedFlags();
        $allowMild = (bool) (($lib['policy']['allow_mild_override'] ?? true));
        $hay = strtolower(trim($original . ' ' . $english));
        if ($hay === '') {
            return [];
        }

        $matched = [];
        $seen = [];
        foreach (($lib['red_flags'] ?? []) as $flag) {
            if (!is_array($flag)) {
                continue;
            }
            $fid = (string) ($flag['id'] ?? $flag['name'] ?? '');
            if ($fid === '' || isset($seen[$fid])) {
                continue;
            }
            $patterns = [];
            foreach (['english', 'hiligaynon', 'filipino'] as $lang) {
                foreach (($flag['patterns'][$lang] ?? []) as $pat) {
                    $p = strtolower(trim((string) $pat));
                    if ($p !== '') {
                        $patterns[] = [$lang, $p];
                    }
                }
            }
            usort($patterns, static fn (array $a, array $b): int => strlen($b[1]) <=> strlen($a[1]));

            $hitLang = '';
            $hitPat = '';
            foreach ($patterns as [$lang, $pat]) {
                if (self::redFlagPatternMatches($hay, $pat)) {
                    $hitLang = $lang;
                    $hitPat = $pat;
                    break;
                }
            }
            if ($hitPat === '') {
                continue;
            }

            if ($allowMild) {
                $mildHit = false;
                foreach (($flag['mild_exclusions'] ?? []) as $excl) {
                    if (str_contains($hay, strtolower(trim((string) $excl)))) {
                        $mildHit = true;
                        break;
                    }
                }
                if ($mildHit) {
                    $hard = str_contains($hay, 'cannot breathe')
                        || str_contains($hay, 'indi makaginhawa')
                        || str_contains($hay, 'unconscious')
                        || str_contains($hay, 'vomiting blood')
                        || str_contains($hay, 'suicidal');
                    if (!$hard) {
                        continue;
                    }
                }
            }

            $matched[] = [
                'flag_id'            => $fid,
                'flag_name'          => (string) ($flag['name'] ?? $fid),
                'category'           => (string) ($flag['category'] ?? ''),
                'auto_triage'        => strtoupper((string) ($flag['auto_triage'] ?? 'EMERGENCY')),
                'severity_points'     => (int) ($flag['severity_points'] ?? 12),
                'clinical_rationale' => (string) ($flag['rationale'] ?? ''),
                'matched_on'         => $hitLang,
                'matched_pattern'    => $hitPat,
                'english_pattern'    => $hitPat,
                'source'             => 'red_flags_library.json',
            ];
            $seen[$fid] = true;
        }

        return $matched;
    }

    private static function redFlagPatternMatches(string $hay, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }
        // Short patterns (e.g. "PE") must be whole-word matches to avoid false positives in "persistent".
        if (strlen($pattern) <= 3) {
            return (bool) preg_match('/(?<!\w)' . preg_quote($pattern, '/') . '(?!\w)/iu', $hay);
        }

        return str_contains($hay, $pattern);
    }

    /**
     * Require distinguishing qualifiers for compound symptom names.
     *
     * @param list<string> $generic
     * @param list<string> $stopWords
     */
    private static function compoundQualifiersSatisfied(
        string $name,
        string $term,
        string $hay,
        array $generic,
        array $stopWords
    ): bool {
        if (str_contains($hay, $name)) {
            return true;
        }

        // "X with Y" — Y must appear in the complaint (e.g. sore throat with fever → fever required).
        if (preg_match('/\bwith\s+(\w+(?:\s+\w+)?)\b/u', $name, $withMatch)) {
            $required = strtolower(trim($withMatch[1]));
            if ($required !== '' && !self::qualifierPresent($hay, $required)) {
                return false;
            }
        }

        // "X in pregnancy" — pregnancy context required.
        if (str_contains($name, 'pregnancy') && !preg_match('/\b(pregnan|buntis|gravid)\b/u', $hay)) {
            return false;
        }

        $nameWords = preg_split('/\s+/u', $name) ?: [];
        $termWords = preg_split('/\s+/u', $term) ?: [];
        $qualGeneric = ['fever', 'pain', 'cough', 'bleeding', 'ache', 'symptoms', 'symptom', 'severe', 'acute', 'chronic', 'mild', 'high', 'low', 'with', 'in', 'and', 'the', 'of'];

        $required = array_values(array_filter(
            array_diff($nameWords, $termWords),
            static fn (string $w): bool => !in_array($w, array_merge($qualGeneric, $stopWords), true)
                && strlen($w) >= 4
        ));

        if ($required === []) {
            // Still require clinically meaningful qualifiers (swelling, fever, etc.).
            foreach ($nameWords as $w) {
                if (in_array($w, $generic, true) && !in_array($w, $termWords, true)) {
                    $required[] = $w;
                }
            }
        }

        foreach ($required as $q) {
            if (!self::qualifierPresent($hay, $q)) {
                return false;
            }
        }

        return true;
    }

    private static function qualifierPresent(string $hay, string $qualifier): bool
    {
        if (str_contains($hay, $qualifier)) {
            return true;
        }

        $synonyms = [
            'swelling' => '/\b(swollen|hubag|gahabok|edema|pamamaga)\b/u',
            'fever'    => '/\b(lagnat|hilanat|pyrexia|hyperthermia|nilalagnat|ginakalagnat)\b/u',
            'severe'   => '/\b(grabe|worst|unbearable|8\/10|9\/10|10\/10)\b/u',
            'pregnancy'=> '/\b(buntis|gravid)\b/u',
        ];

        return isset($synonyms[$qualifier]) && (bool) preg_match($synonyms[$qualifier], $hay);
    }

    private static function flexiblePhraseHit(string $hay, string $term): bool
    {
        if ($term === '') {
            return false;
        }
        if (str_contains($hay, $term)) {
            return true;
        }
        $parts = preg_split('/\s+/u', $term) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        if (count($parts) < 2) {
            return (bool) preg_match('/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/u', $hay);
        }
        $escaped = array_map(static fn (string $p): string => preg_quote($p, '/'), $parts);
        $pattern = '/(?<!\w)' . implode('(?:\W+\w+){0,2}\W+', $escaped) . '(?!\w)/u';

        return (bool) preg_match($pattern, $hay);
    }

    public static function clearCache(): void
    {
        self::$kb = null;
        self::$redFlags = null;
        self::$symptomIndex = null;
        self::$csvBoosts = null;
    }
}
