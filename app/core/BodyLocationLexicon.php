<?php
/**
 * Dataset-driven body-location extraction.
 *
 * Priority:
 *  1) data/nlp/body_parts.csv (validated terminology)
 *  2) data/nlp/body_part_pain_symptoms.csv (Hiligaynon part aliases)
 *  3) data/nlp/hiligaynon_body_location_100000_compact.csv (unique hil→en coverage)
 *
 * Does not invent locations. Does not replace the patient's original complaint text.
 */

final class BodyLocationLexicon
{
    private const SOURCE_BODY_PARTS = 'body_parts';
    private const SOURCE_PAIN = 'body_part_pain_symptoms';
    private const SOURCE_TRAINING = 'hiligaynon_body_location_100000_compact';

    /** @var array<string, array{canonical:string,region:string,source:string,confidence:float}>|null */
    private static ?array $aliasIndex = null;

    /** @var list<string>|null */
    private static ?array $aliasesByLength = null;

    public static function bodyPartsPath(): string
    {
        return BASE_PATH . '/data/nlp/body_parts.csv';
    }

    public static function painSymptomsPath(): string
    {
        return BASE_PATH . '/data/nlp/body_part_pain_symptoms.csv';
    }

    public static function trainingCompactPath(): string
    {
        return BASE_PATH . '/data/nlp/hiligaynon_body_location_100000_compact.csv';
    }

    /**
     * @return list<array{
     *   original_term:string,
     *   normalized_term:string,
     *   canonical_body_location:string,
     *   anatomical_region:string,
     *   confidence:float,
     *   source:string
     * }>
     */
    public static function extractDetailed(string $text, string $originalText = ''): array
    {
        $raw = trim($text);
        if ($raw === '') {
            return [];
        }

        $displayOriginal = trim($originalText) !== '' ? trim($originalText) : $raw;
        $matchText = self::normalizeForMatch($raw);
        if ($matchText === '') {
            return [];
        }

        $index = self::aliasIndex();
        $candidates = [];

        foreach (self::aliasesByLength() as $alias) {
            if ($alias === '' || !isset($index[$alias])) {
                continue;
            }
            if (!preg_match('/\b' . preg_quote($alias, '/') . '\b/u', $matchText)) {
                continue;
            }
            $meta = $index[$alias];
            $canonical = $meta['canonical'];
            if ($canonical === '') {
                continue;
            }
            $candidates[] = [
                'original_term' => $displayOriginal,
                'normalized_term' => $alias,
                'canonical_body_location' => $canonical,
                'anatomical_region' => $meta['region'],
                'confidence' => (float) $meta['confidence'],
                'source' => $meta['source'],
                'verification_status' => 'local_dataset_match',
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            $cmp = ($b['confidence'] <=> $a['confidence']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $priority = [
                self::SOURCE_BODY_PARTS => 3,
                self::SOURCE_PAIN => 2,
                self::SOURCE_TRAINING => 1,
            ];
            $pa = $priority[$a['source']] ?? 0;
            $pb = $priority[$b['source']] ?? 0;
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }

            return mb_strlen((string) $b['normalized_term']) <=> mb_strlen((string) $a['normalized_term']);
        });

        $found = [];
        $seenCanonical = [];
        foreach ($candidates as $row) {
            $canonical = $row['canonical_body_location'];
            if (isset($seenCanonical[$canonical])) {
                continue;
            }
            // Drop lower-confidence conflicts for the same matched anatomy token
            // when a validated body_parts mapping already claimed a different site.
            $token = (string) $row['normalized_term'];
            $conflict = false;
            foreach ($found as $kept) {
                if ($kept['normalized_term'] === $token && $kept['canonical_body_location'] !== $canonical) {
                    $conflict = true;
                    break;
                }
                // Prefer validated single-token anatomy over symptom phrases containing it.
                if (
                    $kept['source'] === self::SOURCE_BODY_PARTS
                    && $row['source'] !== self::SOURCE_BODY_PARTS
                    && preg_match('/\b' . preg_quote($kept['normalized_term'], '/') . '\b/u', $token)
                    && $kept['canonical_body_location'] !== $canonical
                ) {
                    $conflict = true;
                    break;
                }
            }
            if ($conflict) {
                continue;
            }
            $seenCanonical[$canonical] = true;
            $found[] = $row;
        }

        return $found;
    }

    /** @return list<string> */
    public static function extractCanonical(string $text): array
    {
        $out = [];
        foreach (self::extractDetailed($text) as $row) {
            $c = (string) ($row['canonical_body_location'] ?? '');
            if ($c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    public static function hasConfidentMatch(string $text, float $minConfidence = 0.85): bool
    {
        foreach (self::extractDetailed($text) as $row) {
            if ((float) ($row['confidence'] ?? 0) >= $minConfidence) {
                return true;
            }
        }

        return false;
    }

    /**
     * Multi-level Hiligaynon body-location resolution.
     *
     * Order: body_parts.csv → pain CSVs → 100k training → normalize/aliases → local extract
     * → if confident accept local (skip Gemini) → else Gemini secondary verification.
     *
     * @param list<array<string, mixed>> $localMatches
     * @param array<string, mixed> $existingFacts
     * @return array{
     *   matches: list<array<string, mixed>>,
     *   body_locations: list<string>,
     *   verification_status: string,
     *   needs_clarification: bool,
     *   not_medical: bool,
     *   gemini_called: bool,
     *   reason: string
     * }
     */
    public static function resolveMultiLevel(
        string $originalText,
        string $detectedLanguage = '',
        array $localMatches = [],
        array $existingFacts = []
    ): array {
        $originalText = trim($originalText);
        if ($localMatches === []) {
            $localMatches = self::extractDetailed($originalText, $originalText);
        }
        foreach ($localMatches as &$row) {
            if (!isset($row['verification_status'])) {
                $row['verification_status'] = 'local_dataset_match';
            }
        }
        unset($row);

        $confident = self::confidentCanonicals($localMatches, 0.85);
        if ($confident !== []) {
            return [
                'matches' => $localMatches,
                'body_locations' => $confident,
                'verification_status' => 'local_dataset_match',
                'needs_clarification' => false,
                'not_medical' => false,
                'gemini_called' => false,
                'reason' => 'confident_local_csv_match',
            ];
        }

        $existing = [];
        foreach ((array) ($existingFacts['body_locations'] ?? []) as $loc) {
            $loc = strtolower(trim((string) $loc));
            if ($loc !== '') {
                $existing[] = $loc;
            }
        }
        if ($existing !== []) {
            return [
                'matches' => $localMatches,
                'body_locations' => $existing,
                'verification_status' => 'existing_facts',
                'needs_clarification' => false,
                'not_medical' => false,
                'gemini_called' => false,
                'reason' => 'body_locations_already_in_facts',
            ];
        }

        $lang = strtolower(trim($detectedLanguage));
        if ($lang === '' && class_exists('HiligaynonLanguageDetector')) {
            try {
                $lang = strtolower((string) (HiligaynonLanguageDetector::detect($originalText)['primary'] ?? ''));
            } catch (Throwable) {
                $lang = '';
            }
        }

        $lowLocal = $localMatches; // may be empty or below confidence threshold
        if (!class_exists('GeminiBodyLocationVerifier') || !GeminiBodyLocationVerifier::enabled()) {
            // Optional Groq/local interpreter only as last resort when Gemini unavailable.
            $groqMatches = self::groqInterpreterLocations($originalText, $lang);
            if ($groqMatches !== []) {
                return [
                    'matches' => $groqMatches,
                    'body_locations' => self::confidentCanonicals($groqMatches, 0.5),
                    'verification_status' => 'ai_fallback',
                    'needs_clarification' => false,
                    'not_medical' => false,
                    'gemini_called' => false,
                    'reason' => 'gemini_unavailable_interpreter_fallback',
                ];
            }

            return [
                'matches' => $lowLocal,
                'body_locations' => [],
                'verification_status' => 'unresolved',
                'needs_clarification' => true,
                'not_medical' => false,
                'gemini_called' => false,
                'reason' => 'local_miss_gemini_unavailable',
            ];
        }

        $gemini = GeminiBodyLocationVerifier::verify($originalText, $lang, $lowLocal, [
            'datasets_checked' => [
                self::SOURCE_BODY_PARTS,
                self::SOURCE_PAIN,
                self::SOURCE_TRAINING,
            ],
            'normalized_text' => self::normalizeForMatch($originalText),
        ]);

        $verification = (string) ($gemini['verification'] ?? 'uncertain');
        if ($verification === 'not_medical') {
            return [
                'matches' => [],
                'body_locations' => [],
                'verification_status' => 'not_medical',
                'needs_clarification' => false,
                'not_medical' => true,
                'gemini_called' => true,
                'reason' => (string) ($gemini['reason'] ?? 'gemini_not_medical'),
            ];
        }

        $geminiCanonical = strtolower(trim((string) ($gemini['canonical_body_location'] ?? '')));
        $geminiTerm = strtolower(trim((string) ($gemini['normalized_term'] ?? '')));
        $geminiConf = (float) ($gemini['confidence'] ?? 0);

        if ($verification === 'confirmed_body_location' && $geminiCanonical !== '' && !empty($gemini['supported_by_patient_wording'])) {
            // Disagreement: low-confidence local site differs from Gemini — do not pick blindly.
            $localCanon = self::confidentCanonicals($lowLocal, 0.5);
            if ($localCanon !== [] && !in_array($geminiCanonical, $localCanon, true)) {
                return [
                    'matches' => $lowLocal,
                    'body_locations' => [],
                    'verification_status' => 'disagreement',
                    'needs_clarification' => true,
                    'not_medical' => false,
                    'gemini_called' => true,
                    'reason' => 'local_gemini_disagreement',
                ];
            }

            $status = $localCanon !== [] && in_array($geminiCanonical, $localCanon, true)
                ? 'local_gemini_agreement'
                : 'gemini_fallback';

            $match = [
                'original_term' => $originalText,
                'normalized_term' => $geminiTerm !== '' ? $geminiTerm : self::normalizeForMatch($originalText),
                'canonical_body_location' => $geminiCanonical,
                'anatomical_region' => $geminiCanonical,
                'confidence' => max(0.55, min(0.84, $geminiConf > 0 ? $geminiConf : 0.7)),
                'source' => 'gemini_fallback',
                'verification_status' => $status,
            ];

            return [
                'matches' => [$match],
                'body_locations' => [$geminiCanonical],
                'verification_status' => $status,
                'needs_clarification' => false,
                'not_medical' => false,
                'gemini_called' => true,
                'reason' => (string) ($gemini['reason'] ?? 'gemini_confirmed_body_location'),
            ];
        }

        return [
            'matches' => $lowLocal,
            'body_locations' => [],
            'verification_status' => 'unresolved',
            'needs_clarification' => true,
            'not_medical' => false,
            'gemini_called' => (bool) ($gemini['available'] ?? false),
            'reason' => (string) ($gemini['reason'] ?? 'gemini_uncertain'),
        ];
    }

    /**
     * Optional Gemini/Groq fallback ONLY when local datasets cannot resolve a location.
     * Never overwrites confident local matches. Never invents a site without textual support.
     *
     * @param list<array<string, mixed>> $localMatches
     * @return list<array<string, mixed>>
     */
    public static function resolveWithAiFallback(
        string $originalText,
        string $detectedLanguage = '',
        array $localMatches = [],
        array $existingFacts = []
    ): array {
        $resolved = self::resolveMultiLevel($originalText, $detectedLanguage, $localMatches, $existingFacts);

        return is_array($resolved['matches'] ?? null) ? $resolved['matches'] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function groqInterpreterLocations(string $originalText, string $lang): array
    {
        if (!class_exists('MedicalAiInterpreter')) {
            return [];
        }
        $disabled = getenv('MEDCONNECT_AI_INTERPRETER');
        if ($disabled === '0' || strtolower((string) $disabled) === 'false') {
            return [];
        }
        try {
            $result = MedicalAiInterpreter::interpretComplaintMeaning($originalText);
            if (($result['status'] ?? '') !== 'complete') {
                return [];
            }
            $fromAi = self::locationsFromAiResult($result, $originalText);
            foreach ($fromAi as &$m) {
                $m['verification_status'] = 'ai_fallback';
            }
            unset($m);

            return $fromAi;
        } catch (Throwable $e) {
            error_log('BodyLocationLexicon interpreter fallback: ' . $e->getMessage());

            return [];
        }
    }

    /** @return array<string, true> */
    public static function knownAliasSet(): array
    {
        $set = [];
        foreach (array_keys(self::aliasIndex()) as $alias) {
            $set[$alias] = true;
        }

        return $set;
    }

    public static function clearCache(): void
    {
        self::$aliasIndex = null;
        self::$aliasesByLength = null;
    }

    public static function normalizeForMatch(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (class_exists('HiligaynonTextNormalizer')) {
            $normalized = HiligaynonTextNormalizer::forMatch($text);
            if ($normalized !== '') {
                $text = $normalized;
            } else {
                $text = mb_strtolower($text, 'UTF-8');
                $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
                $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            }
        } else {
            $text = mb_strtolower($text, 'UTF-8');
            $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        }

        // Always apply plural morphology after language normalization so
        // Hiligaynon/Tagalog paths still map arms→arm, legs→leg, etc.
        return self::normalizeBodyPlurals($text);
    }

    /** Plural clinical body sites → singular for alias matching. */
    private static function normalizeBodyPlurals(string $text): string
    {
        $pluralMap = [
            'arms' => 'arm', 'legs' => 'leg', 'eyes' => 'eye', 'ears' => 'ear',
            'hands' => 'hand', 'feet' => 'foot', 'teeth' => 'tooth', 'fingers' => 'finger',
            'knees' => 'knee', 'wrists' => 'wrist', 'ankles' => 'ankle', 'shoulders' => 'shoulder',
            'elbows' => 'elbow', 'hips' => 'hip', 'toes' => 'toe',
        ];

        return (string) preg_replace_callback(
            '/\b(' . implode('|', array_map(static fn ($k) => preg_quote($k, '/'), array_keys($pluralMap))) . ')\b/u',
            static function (array $m) use ($pluralMap): string {
                return $pluralMap[$m[1]] ?? $m[1];
            },
            $text
        );
    }

    /**
     * @return array<string, array{canonical:string,region:string,source:string,confidence:float}>
     */
    private static function aliasIndex(): array
    {
        if (self::$aliasIndex !== null) {
            return self::$aliasIndex;
        }

        self::$aliasIndex = [];
        self::loadBodyPartsCsv();
        self::loadPainAliases();
        self::loadTrainingUniquePairs();
        self::loadDictionaryBodyAliases();
        self::loadSymptomDatasetBodyAliases();

        return self::$aliasIndex;
    }

    /** @return list<string> */
    private static function aliasesByLength(): array
    {
        if (self::$aliasesByLength !== null) {
            return self::$aliasesByLength;
        }
        $keys = array_keys(self::aliasIndex());
        usort($keys, static function (string $a, string $b): int {
            return mb_strlen($b) <=> mb_strlen($a);
        });
        self::$aliasesByLength = $keys;

        return self::$aliasesByLength;
    }

    private static function loadBodyPartsCsv(): void
    {
        $path = self::bodyPartsPath();
        if (!is_readable($path)) {
            return;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) {
                continue;
            }
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $eng = self::canonicalizeEnglish((string) ($data['english_term'] ?? ''));
            if ($eng === '') {
                continue;
            }
            $region = (string) ($data['anatomy_category'] ?? $data['body_system'] ?? 'general');
            // Multilingual local columns (Hiligaynon / Filipino / Tagalog / generic alias).
            $locals = [];
            foreach (['hiligaynon_term', 'filipino_term', 'tagalog_term', 'local_term', 'alias'] as $col) {
                $v = trim((string) ($data[$col] ?? ''));
                if ($v !== '') {
                    foreach (preg_split('/[|;,]+/u', $v) ?: [] as $piece) {
                        $piece = trim((string) $piece);
                        if ($piece !== '') {
                            $locals[] = $piece;
                        }
                    }
                }
            }
            if ($locals === []) {
                continue;
            }
            self::registerAlias($eng, $eng, $region, self::SOURCE_BODY_PARTS, 0.95, true);
            foreach ($locals as $hil) {
                $normHil = self::normalizeKey($hil);
                if ($normHil === '') {
                    continue;
                }
                self::registerAlias($normHil, $eng, $region, self::SOURCE_BODY_PARTS, 0.97, true);
                foreach (self::possessiveBases($normHil) as $base) {
                    self::registerAlias($base, $eng, $region, self::SOURCE_BODY_PARTS, 0.96, true);
                }
            }
        }
        fclose($handle);
    }

    private static function loadPainAliases(): void
    {
        $path = self::painSymptomsPath();
        if (!is_readable($path)) {
            return;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 5) {
                continue;
            }
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $bodyPart = self::canonicalizeEnglish((string) ($data['body_part'] ?? ''));
            if ($bodyPart === '') {
                continue;
            }
            $notes = (string) ($data['notes'] ?? '');
            if (preg_match('/(?:Hiligaynon|Filipino|Tagalog|local)\s+part alias:\s*([a-z0-9\-]+)/iu', $notes, $m)) {
                self::registerAlias($m[1], $bodyPart, $bodyPart, self::SOURCE_PAIN, 0.9, false);
            }
            $alias = self::normalizeKey((string) ($data['english_alias'] ?? ''));
            if ($alias !== '') {
                // English clinical site labels only (headache, ear pain) — not Hiligaynon
                // complaint phrases like "sakit tiil" which must not override body_parts.csv.
                $first = explode(' ', $alias)[0] ?? '';
                $englishSite = in_array($first, [
                    'head', 'eye', 'ear', 'chest', 'back', 'neck', 'arm', 'hand', 'foot', 'leg',
                    'knee', 'hip', 'tooth', 'throat', 'stomach', 'abdominal', 'body', 'muscle',
                    'joint', 'facial', 'jaw', 'temple', 'forehead', 'genital', 'vaginal', 'vulvar',
                    'shoulder', 'elbow', 'wrist', 'ankle', 'upper', 'lower', 'forearm', 'biceps',
                ], true) || str_ends_with($alias, 'ache');
                if ($englishSite) {
                    self::registerAlias($alias, $bodyPart, $bodyPart, self::SOURCE_PAIN, 0.88, false);
                }
                if (preg_match('/^([a-z0-9\-]+)\s+pain$/u', $alias, $m2)) {
                    $token = $m2[1];
                    // Hiligaynon-looking tokens from alias (ulo, bukton, bilat…)
                    if (!in_array($token, [
                        'head', 'eye', 'ear', 'chest', 'back', 'neck', 'arm', 'hand', 'foot', 'leg',
                        'knee', 'hip', 'tooth', 'throat', 'stomach', 'abdominal', 'body', 'muscle',
                        'joint', 'facial', 'jaw', 'temple', 'forehead', 'genital', 'vaginal', 'vulvar',
                        'shoulder', 'elbow', 'wrist', 'ankle', 'upper', 'lower', 'forearm', 'biceps',
                    ], true)) {
                        // Never override validated body_parts.csv mappings (tiil=foot, not leg).
                        self::registerAlias($token, $bodyPart, $bodyPart, self::SOURCE_PAIN, 0.9, false);
                    }
                }
            }
            // English body_part token itself.
            self::registerAlias($bodyPart, $bodyPart, $bodyPart, self::SOURCE_PAIN, 0.88, false);
        }
        fclose($handle);
    }

    private static function loadTrainingUniquePairs(): void
    {
        $path = self::trainingCompactPath();
        if (!is_readable($path)) {
            return;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }
        $header = fgetcsv($handle);
        $seen = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 4) {
                continue;
            }
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $hil = self::normalizeKey((string) ($data['body_location_hiligaynon'] ?? ''));
            $eng = self::canonicalizeEnglish((string) ($data['body_location_en'] ?? ''));
            if ($hil === '' || $eng === '' || isset($seen[$hil])) {
                continue;
            }
            $seen[$hil] = true;
            // Supporting coverage only — never override validated body_parts mappings.
            self::registerAlias($hil, $eng, $eng, self::SOURCE_TRAINING, 0.86, false);
        }
        fclose($handle);
    }

    /**
     * Register MedicalDictionary single-token locals whose English gloss maps to an
     * already-known body canonical (EN/HIL/Tagalog via the same lexicon architecture).
     */
    private static function loadDictionaryBodyAliases(): void
    {
        if (!class_exists('MedicalDictionary')) {
            return;
        }
        $canonicals = [];
        foreach (self::$aliasIndex ?? [] as $meta) {
            $c = self::canonicalizeEnglish((string) ($meta['canonical'] ?? ''));
            if ($c !== '') {
                $canonicals[$c] = true;
            }
        }
        if ($canonicals === []) {
            return;
        }
        try {
            $rows = MedicalDictionary::rows();
        } catch (Throwable) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $local = self::normalizeKey((string) ($row['local_term'] ?? ''));
            $eng = self::canonicalizeEnglish((string) ($row['english_term'] ?? ''));
            if ($local === '' || $eng === '' || str_contains($local, ' ')) {
                continue;
            }
            if (!isset($canonicals[$eng])) {
                continue;
            }
            self::registerAlias($local, $eng, $eng, self::SOURCE_BODY_PARTS, 0.93, false);
            self::registerAlias($local . ' ko', $eng, $eng, self::SOURCE_BODY_PARTS, 0.92, false);
            self::registerAlias('sa ' . $local, $eng, $eng, self::SOURCE_BODY_PARTS, 0.92, false);
        }
    }

    /**
     * From symptom CSVs that declare body_part, register non-qualifier local tokens
     * as aliases to that canonical site (dataset-driven multilingual coverage).
     */
    private static function loadSymptomDatasetBodyAliases(): void
    {
        $paths = [
            BASE_PATH . '/data/nlp/hiligaynon_medical_symptoms.csv',
            BASE_PATH . '/data/nlp/filipino_medical_terms.csv',
            BASE_PATH . '/data/nlp/body_part_pain_symptoms.csv',
        ];
        $stop = [
            'sakit', 'masakit', 'pain', 'ache', 'may', 'mayron', 'mayroon', 'meron',
            'gina', 'naga', 'nag', 'ang', 'mga', 'sa', 'ng', 'ko', 'akon', 'ako',
            'my', 'i', 'have', 'a', 'the', 'of', 'and', 'with', 'for', 'gid', 'man',
            'na', 'yung', 'ung', 'nang', 'kay', 'daw', 'basin', 'feel', 'feeling',
            // Laterality / qualifiers must not become body-site aliases (e.g. "left" → abdomen
            // from "left lower abdominal pain", "wala" → abdomen from LLQ Hiligaynon rows).
            'left', 'right', 'upper', 'lower', 'mid', 'middle', 'side', 'quadrant',
            'tuo', 'wala', 'kaliwa', 'kanan', 'idalom', 'ibabaw',
            'chronic', 'acute', 'severe', 'mild', 'moderate',
            // Symptom words that are not anatomical sites.
            'fever', 'lagnat', 'hilanat', 'weakness', 'numbness', 'numb', 'dizzy',
            'dizziness', 'cough', 'ubo', 'vomit', 'nausea', 'diarrhea',
            // Fluids / substances / qualifiers — never become organ aliases
            // (e.g. "blood" from "blood;kidney;trauma" keyword rows → kidney).
            'blood', 'dugo', 'bleed', 'bleeding', 'pus', 'nana', 'infection',
            'pressure', 'sugar', 'urine', 'ihi', 'stool', 'tae', 'vomit', 'suka',
            // Functional symptom words — not anatomical sites.
            'ginhawa', 'hinga', 'breath', 'breathing', 'budlay', 'lisod', 'hirap',
            'dyspnea', 'shortness', 'catch', 'empty', 'bladder', 'broken', 'fracture',
            'fractured', 'snapped', 'cracked', 'nabali', 'bali',
        ];
        $canonicals = [];
        foreach (self::$aliasIndex ?? [] as $meta) {
            $c = self::canonicalizeEnglish((string) ($meta['canonical'] ?? ''));
            if ($c !== '') {
                $canonicals[$c] = true;
            }
        }
        foreach ($paths as $path) {
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
                $bodyRaw = (string) (
                    ($data['body_part'] ?? '')
                    ?: ($data['body'] ?? '')
                );
                // Do NOT fall back to confidence_keywords / medical_term / causes —
                // those lists mix organs with fluids, diseases, and functional words
                // (e.g. "blood;kidney", "heart failure", "ginhawa"→heart).
                if ($bodyRaw === '') {
                    continue;
                }
                // body_part / keywords may be "shoulder;joint;pain" — take first known anatomy canonical.
                $bodyParts = preg_split('/[|;,]+/u', strtolower($bodyRaw)) ?: [];
                $canonical = '';
                foreach ($bodyParts as $bp) {
                    $bp = self::canonicalizeEnglish(trim((string) $bp));
                    if ($bp !== '' && isset($canonicals[$bp])) {
                        $canonical = $bp;
                        break;
                    }
                }
                if ($canonical === '') {
                    continue;
                }
                $phrases = [];
                foreach ([
                    'hiligaynon_term', 'hiligaynon_complaint', 'term', 'local_term',
                    'english_alias', 'normalized_symptom', 'synonyms', 'alternative_spellings',
                    'english_translation',
                ] as $col) {
                    $v = trim((string) ($data[$col] ?? ''));
                    if ($v === '') {
                        continue;
                    }
                    foreach (preg_split('/[|;]+/u', $v) ?: [] as $piece) {
                        $piece = trim((string) $piece);
                        if ($piece !== '') {
                            $phrases[] = $piece;
                        }
                    }
                }
                foreach ($phrases as $phrase) {
                    $tokens = preg_split('/\s+/u', self::normalizeKey($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    foreach ($tokens as $token) {
                        if (mb_strlen($token) < 3 || in_array($token, $stop, true)) {
                            continue;
                        }
                        if (isset($canonicals[$token])) {
                            continue; // already an english canonical token
                        }
                        self::registerAlias($token, $canonical, $canonical, self::SOURCE_PAIN, 0.88, false);
                    }
                }
                $count++;
                if ($count >= 8000) {
                    break;
                }
            }
            fclose($handle);
        }
    }

    private static function registerAlias(
        string $alias,
        string $canonical,
        string $region,
        string $source,
        float $confidence,
        bool $override
    ): void {
        $key = self::normalizeKey($alias);
        $canonical = self::canonicalizeEnglish($canonical);
        if ($key === '' || $canonical === '') {
            return;
        }
        if (!$override && isset(self::$aliasIndex[$key])) {
            // Existing validated mapping wins.
            return;
        }
        if ($override && isset(self::$aliasIndex[$key]) && (self::$aliasIndex[$key]['source'] ?? '') === self::SOURCE_BODY_PARTS) {
            // Keep first body_parts registration for this alias (usually the base term).
            return;
        }
        self::$aliasIndex[$key] = [
            'canonical' => $canonical,
            'region' => $region !== '' ? $region : $canonical,
            'source' => $source,
            'confidence' => $confidence,
        ];
    }

    private static function normalizeKey(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Extract the anatomy token from possessive/prepositional variants
     * ("ulo ko", "sa tiyan ko", "akon mata") without splitting multi-word parts
     * like "talukap mata".
     *
     * @return list<string>
     */
    private static function possessiveBases(string $normHil): array
    {
        if ($normHil === '') {
            return [];
        }
        $tokens = preg_split('/\s+/u', $normHil, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($tokens);
        if ($n === 1) {
            return [];
        }
        if ($n === 2 && $tokens[1] === 'ko') {
            return [$tokens[0]];
        }
        if ($n === 2 && $tokens[0] === 'akon') {
            return [$tokens[1]];
        }
        if ($n === 2 && $tokens[0] === 'sang') {
            return [$tokens[1]];
        }
        if ($n === 3 && $tokens[0] === 'sa' && $tokens[2] === 'ko') {
            return [$tokens[1]];
        }
        if ($n === 3 && $tokens[0] === 'ko' && $tokens[1] === 'sa') {
            return [$tokens[2]];
        }

        return [];
    }

    private static function canonicalizeEnglish(string $english): string
    {
        $e = self::normalizeKey($english);
        if ($e === '') {
            return '';
        }
        // Align near-synonyms to body_parts.csv terminology where both appear in project data.
        static $map = [
            'stomach' => 'abdomen',
            'belly' => 'abdomen',
            'tummy' => 'abdomen',
            'fingernail' => 'nail',
            'eyes' => 'eye',
            'ears' => 'ear',
            'feet' => 'foot',
            'hands' => 'hand',
            'legs' => 'leg',
        ];

        return $map[$e] ?? $e;
    }

    /**
     * @param list<array<string, mixed>> $matches
     * @return list<string>
     */
    private static function confidentCanonicals(array $matches, float $min = 0.85): array
    {
        $out = [];
        foreach ($matches as $row) {
            if ((float) ($row['confidence'] ?? 0) < $min) {
                continue;
            }
            $c = strtolower(trim((string) ($row['canonical_body_location'] ?? '')));
            if ($c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private static function locationsFromAiResult(array $result, string $originalText): array
    {
        $candidates = [];
        $english = trim((string) ($result['english_interpretation'] ?? ''));
        foreach ((array) ($result['concepts'] ?? []) as $concept) {
            if (!is_array($concept)) {
                continue;
            }
            foreach (['body_part', 'term', 'english'] as $field) {
                $v = strtolower(trim((string) ($concept[$field] ?? '')));
                if ($v !== '') {
                    $candidates[] = $v;
                }
            }
        }
        if ($english !== '') {
            $candidates[] = $english;
        }
        // Re-run lexicon on AI English + original — only accept known anatomy tokens.
        $hay = trim($originalText . ' ' . implode(' ', $candidates));
        $matches = self::extractDetailed($hay, $originalText);
        foreach ($matches as &$m) {
            $m['source'] = 'ai_fallback+' . ($m['source'] ?? 'lexicon');
            $m['confidence'] = min(0.8, (float) ($m['confidence'] ?? 0.8));
            $m['verification_status'] = 'ai_fallback';
        }
        unset($m);

        return $matches;
    }
}
