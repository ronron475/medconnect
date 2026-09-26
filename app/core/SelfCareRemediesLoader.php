<?php
/**
 * Loads curated non-urgent self-care tips from data/nlp/self_care_remedies.csv.
 *
 * Matching order:
 * 1) Local NLP/CSV alias scoring with particle-aware + fuzzy paraphrase matching
 * 2) Cohere Rerank over relevant CSV entries from the FULL catalog when local match is weak/ambiguous
 *
 * Cohere never generates or rewrites tip text. Unreliable matches return no tip
 * (no generic/default unrelated tips).
 */

final class SelfCareRemediesLoader
{
    /** Local scores at/above this are trusted without Cohere. */
    private const LOCAL_STRONG_SCORE = 70;
    /** Minimum similar_text percent for misspelling / near-match tokens. */
    private const FUZZY_SIMILAR_PERCENT = 86.0;
    /** Minimum alias length for loose substring matching. */
    private const MIN_SUBSTRING_ALIAS_LEN = 6;
    /** Minimum alias length for whole-word matching. */
    private const MIN_WORD_ALIAS_LEN = 4;
    /** Cohere documents per request (API / Railway batch size). */
    private const COHERE_BATCH_SIZE = 1000;

    /** Fallback copy that must never be approved as a Care Tip. */
    public const NO_MATCH_PRIMARY = 'No specific self-care tip matched this complaint with enough confidence.';
    public const NO_MATCH_SECONDARY = 'A licensed healthcare provider should review before any home-care guidance is shared with the patient.';

    /**
     * Ultra-generic aliases that must equal the full complaint (no word/substring hit).
     * Keeps "pain" from matching inside "painstakingly" while still allowing "back pain".
     *
     * @var list<string>
     */
    private const FULL_COMPLAINT_ONLY_ALIASES = [
        'pain', 'gas', 'cut', 'burn', 'itch', 'mild', 'sick', 'ache', 'sore',
        'weak', 'tired', 'uri', 'cold', 'colds', 'flu',
    ];

    /**
     * Retrieval-only synonym groups (never tip text). Helps paraphrase matching
     * across English / Hiligaynon / Tagalog informal wording.
     *
     * @var array<string, list<string>>
     */
    private const RETRIEVAL_SYNONYMS = [
        'hurt' => ['pain', 'ache', 'sakit', 'masakit'],
        'hurts' => ['pain', 'ache', 'sakit', 'masakit'],
        'hurting' => ['pain', 'ache', 'sakit', 'masakit'],
        'ached' => ['pain', 'ache', 'sakit'],
        'aches' => ['pain', 'ache', 'sakit', 'hurt'],
        'aching' => ['pain', 'ache', 'sakit', 'hurt'],
        'painful' => ['pain', 'hurt', 'ache', 'sakit'],
        'sakit' => ['pain', 'hurt', 'ache'],
        'masakit' => ['pain', 'hurt', 'ache', 'sakit'],
        'pain' => ['hurt', 'ache', 'sakit', 'masakit'],
        'ache' => ['pain', 'hurt', 'sakit'],
        'ulo' => ['head'],
        'head' => ['ulo'],
        'tiyan' => ['stomach', 'belly', 'abdomen'],
        'stomach' => ['tiyan', 'belly', 'abdomen'],
        'belly' => ['tiyan', 'stomach', 'abdomen'],
        'tutunlan' => ['throat'],
        'lalamunan' => ['throat'],
        'throat' => ['tutunlan', 'lalamunan'],
        'ubo' => ['cough'],
        'cough' => ['ubo'],
        'lagnat' => ['fever'],
        'fever' => ['lagnat', 'hilanat'],
        'hilanat' => ['fever', 'lagnat'],
        'libang' => ['diarrhea', 'diarrhoea'],
        'paglibang' => ['diarrhea', 'diarrhoea'],
        'diarrhea' => ['diarrhoea', 'libang', 'paglibang'],
        'diarrhoea' => ['diarrhea', 'libang', 'paglibang'],
        'sipon' => ['cold', 'runny'],
        'ilong' => ['nose'],
        'nose' => ['ilong'],
        'likod' => ['back'],
        'back' => ['likod'],
    ];

    /**
     * Function words stripped when comparing multi-word local phrases
     * (e.g. "sakit sa ulo" ↔ "sakit ulo").
     *
     * @var list<string>
     */
    private const MATCH_PARTICLES = [
        'sa', 'ang', 'ng', 'mga', 'yung', 'ung', 'nang', 'na', 'ay', 'si', 'ni',
        'para', 'kay', 'at', 'o', 'ba', 'po', 'opo', 'ho',
        'the', 'a', 'an', 'my', 'your', 'his', 'her', 'their', 'our', 'me', 'i',
        'is', 'are', 'was', 'were', 'am', 'of', 'to', 'for', 'in', 'on', 'at',
        'ako', 'ko', 'mo', 'nya', 'niya', 'namin', 'natin', 'gid', 'lang', 'man',
        'may', 'has', 'have', 'had', 'with', 'some', 'any',
    ];

    /** @var list<array<string, mixed>>|null */
    private static ?array $rows = null;

    /**
     * Alias-token → row indexes for fast fuzzy / paraphrase candidate lookup.
     *
     * @var array<string, list<int>>|null
     */
    private static ?array $aliasTokenIndex = null;

    /**
     * Prefix (first 3 chars) → alias tokens for misspelling neighbor search.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $aliasTokenPrefixIndex = null;

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];
        self::$aliasTokenIndex = null;
        self::$aliasTokenPrefixIndex = null;
        $path = BASE_PATH . '/data/nlp/self_care_remedies.csv';
        if (!is_readable($path)) {
            return self::$rows;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return self::$rows;
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            return self::$rows;
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $data = [];
            foreach ($header as $i => $key) {
                $data[$key] = trim((string) ($row[$i] ?? ''));
            }
            if (($data['active'] ?? '1') === '0') {
                continue;
            }
            $key = strtolower(trim((string) ($data['symptom_key'] ?? '')));
            if ($key === '') {
                continue;
            }

            $aliases = preg_split('/\|/', (string) ($data['aliases'] ?? '')) ?: [];
            $aliasList = [];
            foreach ($aliases as $alias) {
                $alias = self::normalize((string) $alias);
                if ($alias !== '') {
                    $aliasList[] = $alias;
                }
            }
            $aliasList[] = self::normalize($key);
            $aliasList[] = self::normalize((string) ($data['display_name'] ?? ''));
            $aliasList = array_values(array_unique(array_filter($aliasList)));

            $tips = [];
            foreach (['tip_1', 'tip_2', 'tip_3', 'tip_4'] as $tipKey) {
                $tip = trim((string) ($data[$tipKey] ?? ''));
                if ($tip !== '') {
                    $tips[] = $tip;
                }
            }

            $resourceLabel = trim((string) ($data['resource_label'] ?? ''));
            $resourceUrl = trim((string) ($data['resource_url'] ?? ''));
            if ($resourceUrl !== '' && !self::isTrustedResourceUrl($resourceUrl)) {
                $resourceLabel = '';
                $resourceUrl = '';
            }

            self::$rows[] = [
                'symptom_key' => $key,
                'display_name' => (string) ($data['display_name'] ?? $key),
                'category' => (string) ($data['category'] ?? 'general'),
                'aliases' => $aliasList,
                'tips' => $tips,
                'when_to_seek_care' => trim((string) ($data['when_to_seek_care'] ?? '')),
                'resource_label' => $resourceLabel,
                'resource_url' => $resourceUrl,
            ];
        }
        fclose($handle);

        return self::$rows;
    }

    /**
     * @param list<string|array<string, mixed>> $detectedSymptoms
     * @return array{
     *   matched: bool,
     *   symptom_key: string,
     *   display_name: string,
     *   tips: list<string>,
     *   when_to_seek_care: string,
     *   resource_label: string,
     *   resource_url: string,
     *   match_source: string,
     *   match_score: float,
     *   cohere_attempted: bool,
     *   cohere_error: string
     * }
     */
    public static function match(string $chiefComplaint, string $englishText = '', array $detectedSymptoms = []): array
    {
        $empty = self::emptyMatch();
        $complaintOnly = self::normalize($chiefComplaint);
        $haystackParts = [$chiefComplaint, $englishText];
        foreach ($detectedSymptoms as $symptom) {
            if (is_string($symptom)) {
                $haystackParts[] = $symptom;
            } elseif (is_array($symptom)) {
                $haystackParts[] = (string) ($symptom['term'] ?? $symptom['symptom'] ?? $symptom['english'] ?? $symptom['name'] ?? '');
            }
        }
        $haystack = self::normalize(implode(' ', $haystackParts));
        $queryText = trim(implode(' ', array_filter([
            trim($chiefComplaint),
            trim($englishText),
            ...array_map(static function ($symptom): string {
                if (is_string($symptom)) {
                    return trim($symptom);
                }
                if (is_array($symptom)) {
                    return trim((string) ($symptom['term'] ?? $symptom['symptom'] ?? $symptom['english'] ?? $symptom['name'] ?? ''));
                }

                return '';
            }, $detectedSymptoms),
        ], static fn (string $p): bool => $p !== '')));

        // Enrich for matching only: dictionary gloss + particle-compacted forms (never tip text).
        $complaintMatch = self::prepareMatchText($complaintOnly);
        $haystackMatch = self::prepareMatchText($haystack !== '' ? $haystack : $complaintOnly);

        // 1) Strong local complaint-only hit → trust CSV NLP immediately.
        $primary = self::bestMatch($complaintMatch, true, false);
        if ($primary !== null && (int) ($primary['_score'] ?? 0) >= self::LOCAL_STRONG_SCORE) {
            return self::formatMatch($primary, 'local_csv', (float) ($primary['_score'] ?? 0));
        }

        // 2) Broader local haystack match (never auto-accept default_non_urgent / weak scores).
        $local = self::bestMatch($haystackMatch, false, false);
        $localScore = (int) ($local['_score'] ?? 0);
        $localKey = (string) ($local['symptom_key'] ?? '');
        if ($local !== null
            && $localKey !== ''
            && $localKey !== 'default_non_urgent'
            && $localScore >= self::LOCAL_STRONG_SCORE
        ) {
            return self::formatMatch($local, 'local_csv', (float) $localScore);
        }

        // 3) Inconclusive local → prefer Cohere semantic ranking over relevant CSV rows.
        $cohereAttempted = false;
        $cohereError = '';
        if (self::shouldTryCohere($queryText)) {
            $cohereAttempted = true;
            $ranked = self::matchViaCohere($queryText, $haystackMatch);
            if ($ranked !== null) {
                return self::formatMatch(
                    $ranked,
                    'cohere_rerank',
                    (float) ($ranked['_score'] ?? 0),
                    true,
                    ''
                );
            }
            $cohereError = class_exists('CohereRerankClient') ? CohereRerankClient::lastError() : 'cohere_unavailable';
        }

        // 4) Never accept weak local matches after Cohere miss.
        $empty['cohere_attempted'] = $cohereAttempted;
        $empty['cohere_error'] = $cohereError;

        return $empty;
    }

    /**
     * True when text contains no-match / review fallback copy that must not be approved.
     */
    public static function containsBlockedFallbackCopy(string $text): bool
    {
        $raw = trim($text);
        if ($raw === '') {
            return true;
        }
        $needles = [
            self::NO_MATCH_PRIMARY,
            self::NO_MATCH_SECONDARY,
            'No specific self-care tip matched',
            'could not be triaged with sufficient confidence',
            'Needs Healthcare Provider Review',
        ];
        foreach ($needles as $needle) {
            if ($needle !== '' && stripos($raw, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify recommendation text is grounded in an existing CSV self-care entry.
     * Framing lines (focus label, resource, booking note, disclaimer) are allowed;
     * clinical tip lines must match tip_1..tip_4 / when_to_seek_care from one CSV row.
     *
     * @return array{ok:bool,message:string,symptom_key:string}
     */
    public static function validateCsvBackedRecommendations(string $text): array
    {
        $fail = static fn (string $message): array => [
            'ok' => false,
            'message' => $message,
            'symptom_key' => '',
        ];

        $raw = trim($text);
        if ($raw === '') {
            return $fail('No Care Tips text to approve.');
        }
        if (self::containsBlockedFallbackCopy($raw)) {
            return $fail('This case has no reliable CSV Care Tip match and cannot be approved for the patient.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $clinicalLines = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || self::isFramingRecommendationLine($line)) {
                continue;
            }
            $clinicalLines[] = $line;
        }
        $clinicalLines = array_values(array_unique($clinicalLines));
        if ($clinicalLines === []) {
            return $fail('Approved Care Tips must include tip text from self_care_remedies.csv.');
        }

        foreach (self::all() as $row) {
            $key = (string) ($row['symptom_key'] ?? '');
            if ($key === '' || $key === 'default_non_urgent') {
                continue;
            }
            $allowed = [];
            foreach (self::stringList($row['tips'] ?? []) as $tip) {
                $allowed[$tip] = true;
            }
            $when = trim((string) ($row['when_to_seek_care'] ?? ''));
            if ($when !== '') {
                $allowed[$when] = true;
            }
            if ($allowed === []) {
                continue;
            }

            $allFound = true;
            $matchedTipCount = 0;
            foreach ($clinicalLines as $line) {
                if (isset($allowed[$line])) {
                    if (isset($row['tips']) && is_array($row['tips']) && in_array($line, $row['tips'], true)) {
                        $matchedTipCount++;
                    }
                    continue;
                }
                $allFound = false;
                break;
            }
            if ($allFound && $matchedTipCount > 0) {
                return [
                    'ok' => true,
                    'message' => '',
                    'symptom_key' => $key,
                ];
            }
        }

        return $fail('Every Care Tip line must come from an existing self_care_remedies.csv entry.');
    }

    /**
     * Rebuild CSV-backed recommendations for provider approval (match + format only).
     *
     * @param list<string|array<string, mixed>> $detectedSymptoms
     * @param list<string|array<string, mixed>> $possibleConditions
     * @return array{ok:bool,message:string,text:string,list:list<string>,symptom_key:string}
     */
    public static function buildApprovableRecommendations(
        string $chiefComplaint,
        string $englishText = '',
        array $detectedSymptoms = [],
        array $possibleConditions = []
    ): array {
        require_once __DIR__ . '/MedicalRecommendationEngine.php';

        $match = self::match($chiefComplaint, $englishText, $detectedSymptoms);
        if (empty($match['matched']) || ($match['symptom_key'] ?? '') === '' || ($match['tips'] ?? []) === []) {
            return [
                'ok' => false,
                'message' => 'No reliable Care Tip matched this complaint in self_care_remedies.csv. Withhold guidance or ask the patient for a clearer symptom description.',
                'text' => '',
                'list' => [],
                'symptom_key' => '',
            ];
        }

        $lines = MedicalRecommendationEngine::buildRecommendations(
            [
                'triage_classification' => 'NON_URGENT',
                'recommended_action' => 'Monitor symptoms and schedule a routine consultation if symptoms persist.',
            ],
            $possibleConditions,
            $chiefComplaint,
            $englishText,
            $detectedSymptoms
        );
        $text = implode("\n", $lines);
        $check = self::validateCsvBackedRecommendations($text);
        if (!$check['ok']) {
            return [
                'ok' => false,
                'message' => $check['message'],
                'text' => '',
                'list' => [],
                'symptom_key' => '',
            ];
        }

        return [
            'ok' => true,
            'message' => '',
            'text' => $text,
            'list' => $lines,
            'symptom_key' => (string) ($check['symptom_key'] ?: $match['symptom_key']),
        ];
    }

    private static function isFramingRecommendationLine(string $line): bool
    {
        if (stripos($line, 'Self-care focus:') === 0) {
            return true;
        }
        if (stripos($line, 'Optional reading') === 0) {
            return true;
        }
        if (stripos($line, 'You may follow these tips on your own') === 0) {
            return true;
        }
        if (stripos($line, 'Possible related conditions') === 0) {
            return true;
        }
        if (class_exists('MedicalRecommendationEngine')
            && $line === MedicalRecommendationEngine::DISCLAIMER
        ) {
            return true;
        }
        if (stripos($line, 'AI-assisted triage recommendation') !== false) {
            return true;
        }

        return false;
    }

    /**
     * @return array{
     *   matched: bool,
     *   symptom_key: string,
     *   display_name: string,
     *   tips: list<string>,
     *   when_to_seek_care: string,
     *   resource_label: string,
     *   resource_url: string,
     *   match_source: string,
     *   match_score: float,
     *   cohere_attempted: bool,
     *   cohere_error: string
     * }
     */
    private static function emptyMatch(): array
    {
        return [
            'matched' => false,
            'symptom_key' => '',
            'display_name' => '',
            'tips' => [],
            'when_to_seek_care' => '',
            'resource_label' => '',
            'resource_url' => '',
            'match_source' => 'none',
            'match_score' => 0.0,
            'cohere_attempted' => false,
            'cohere_error' => '',
        ];
    }

    /**
     * Allow only fixed public-health / professional-society domains (HTTPS).
     * No YouTube, blogs, or arbitrary hosts.
     *
     * PH: DOH, FDA Philippines, NNC, PPS, PHA, POGS.
     * International: WHO, CDC, NHS, MedlinePlus, NIH, Mayo Clinic,
     * Cleveland Clinic, FDA US, AHA, AAFP, National Cancer Institute.
     */
    private static function isTrustedResourceUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        // Apex + subdomains (e.g. www., my., nhlbi.nih.gov).
        $allowedDomains = [
            // Existing
            'nhs.uk',
            'cdc.gov',
            'doh.gov.ph',
            'medlineplus.gov',
            // PH
            'fda.gov.ph',
            'nnc.gov.ph',
            'pps.org.ph',
            'philheart.org',
            'pogsinc.org',
            // International
            'who.int',
            'nih.gov',
            'mayoclinic.org',
            'clevelandclinic.org',
            'fda.gov',
            'heart.org',
            'aafp.org',
            'cancer.gov',
        ];

        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private static function shouldTryCohere(string $queryText): bool
    {
        if (trim($queryText) === '') {
            return false;
        }
        if (!class_exists('CohereRerankClient') || !CohereRerankClient::enabled()) {
            return false;
        }

        // Strong local already returned before this call.
        return true;
    }

    /**
     * Rerank existing CSV rows selected from the FULL catalog; return winning row with original tip text unchanged.
     *
     * @return array<string, mixed>|null
     */
    private static function matchViaCohere(string $queryText, string $normalizedHaystack = ''): ?array
    {
        if (!class_exists('CohereRerankClient')) {
            return null;
        }

        $candidates = [];
        $documents = [];
        $relevance = [];
        $queryNorm = self::normalize($queryText);
        $haystack = $normalizedHaystack !== '' ? $normalizedHaystack : $queryNorm;
        $queryTokens = self::expandRetrievalTokens(self::tokens($haystack));
        $rows = self::all();

        // Prefer indexed candidates; fall back to cheap full scan only for relevance gating.
        $candidateIdx = self::candidateRowIndexes($queryTokens);
        if ($candidateIdx === []) {
            // Cheap pass: any row with non-zero cheap alias score.
            foreach ($rows as $i => $row) {
                $key = (string) ($row['symptom_key'] ?? '');
                if ($key === '' || $key === 'default_non_urgent' || ($row['tips'] ?? []) === []) {
                    continue;
                }
                $rel = 0;
                foreach (self::stringList($row['aliases'] ?? []) as $alias) {
                    $rel = max($rel, self::aliasScore($haystack, $alias, false));
                }
                if ($rel > 0) {
                    $candidateIdx[] = (int) $i;
                }
            }
        }

        $seen = [];
        foreach ($candidateIdx as $i) {
            if (isset($seen[$i]) || !isset($rows[$i])) {
                continue;
            }
            $seen[$i] = true;
            $row = $rows[$i];
            $key = (string) ($row['symptom_key'] ?? '');
            if ($key === '' || $key === 'default_non_urgent') {
                continue;
            }
            if (($row['tips'] ?? []) === []) {
                continue;
            }
            $aliasText = implode(', ', array_slice(self::stringList($row['aliases'] ?? []), 0, 12));
            $doc = trim((string) ($row['display_name'] ?? $key) . '. ' . $aliasText);
            if ($doc === '') {
                continue;
            }
            $candidates[] = $row;
            $documents[] = $doc;
            $relevance[] = self::candidateRelevance($haystack, $queryTokens, $row);
        }
        if ($documents === []) {
            return null;
        }

        // Prefer relevant rows from the full catalog (not file-order first-N).
        // If none score locally, still rank the full catalog in Cohere batches.
        $indexed = [];
        foreach ($documents as $i => $doc) {
            $indexed[] = [
                'row' => $candidates[$i],
                'doc' => $doc,
                'rel' => (int) ($relevance[$i] ?? 0),
            ];
        }
        usort($indexed, static function (array $a, array $b): int {
            return $b['rel'] <=> $a['rel'];
        });

        $hasRelevant = false;
        foreach ($indexed as $item) {
            if ($item['rel'] > 0) {
                $hasRelevant = true;
                break;
            }
        }
        if (!$hasRelevant) {
            // No row in the full catalog looks related — do not burn Cohere on the entire file.
            return null;
        }
        $indexed = array_values(array_filter(
            $indexed,
            static fn (array $item): bool => $item['rel'] > 0
        ));
        // Safety cap after full-catalog relevance sort (still not file-order first-N).
        if (count($indexed) > self::COHERE_BATCH_SIZE * 2) {
            $indexed = array_slice($indexed, 0, self::COHERE_BATCH_SIZE * 2);
        }

        $batchSize = self::COHERE_BATCH_SIZE;
        $minScore = CohereRerankClient::minScore();
        $bestRow = null;
        $bestScore = -1.0;

        $chunks = array_chunk($indexed, $batchSize);
        foreach ($chunks as $chunk) {
            $docs = array_map(static fn (array $item): string => $item['doc'], $chunk);
            $ranked = CohereRerankClient::rerank($queryText, $docs, min(5, count($docs)));
            if ($ranked === []) {
                continue;
            }
            foreach ($ranked as $hit) {
                $idx = (int) ($hit['index'] ?? -1);
                $score = (float) ($hit['score'] ?? -1.0);
                if ($idx < 0 || !isset($chunk[$idx]) || $score < $minScore) {
                    continue;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestRow = $chunk[$idx]['row'];
                    $bestRow['_score'] = $score;
                }
            }
        }

        return $bestRow;
    }

    /**
     * @param list<string> $queryTokens
     * @param array<string, mixed> $row
     */
    private static function candidateRelevance(string $haystack, array $queryTokens, array $row): int
    {
        $score = 0;
        foreach (self::stringList($row['aliases'] ?? []) as $alias) {
            $score = max($score, self::aliasScore($haystack, $alias));
        }
        $display = self::normalize((string) ($row['display_name'] ?? ''));
        if ($display !== '') {
            $score = max($score, self::aliasScore($haystack, $display));
        }
        $key = self::normalize((string) ($row['symptom_key'] ?? ''));
        if ($key !== '') {
            $score = max($score, self::aliasScore($haystack, str_replace('_', ' ', $key)));
        }

        $expandedQuery = self::expandRetrievalTokens($queryTokens !== [] ? $queryTokens : self::tokens($haystack));
        if ($expandedQuery === []) {
            return $score;
        }
        $rowTokens = self::expandRetrievalTokens(self::tokens(
            $display . ' ' . str_replace('_', ' ', $key) . ' ' . implode(' ', self::stringList($row['aliases'] ?? []))
        ));
        if ($rowTokens === []) {
            return $score;
        }

        $overlap = 0;
        foreach ($expandedQuery as $qt) {
            foreach ($rowTokens as $rt) {
                if (self::tokensFuzzyEqual($qt, $rt) || self::tokenCompoundLink($qt, $rt)) {
                    $overlap++;
                    break;
                }
            }
        }
        if ($overlap > 0) {
            $score = max($score, min(65, 18 * $overlap));
        }

        return $score;
    }

    /**
     * Query tokens ignored for Cohere candidate relevance (too common).
     *
     * @var list<string>
     */
    private const STOP_TOKENS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'have', 'has', 'had',
        'was', 'were', 'are', 'is', 'am', 'been', 'been', 'some', 'any', 'very',
        'today', 'yesterday', 'now', 'just', 'about', 'like', 'feel', 'feeling',
        'ako', 'ko', 'ang', 'nga', 'sa', 'may', 'mga', 'gid', 'lang', 'man',
        'my', 'me', 'i', 'im', 'ive', 'dont', 'not', 'no', 'yes', 'please',
        'mild', 'slight', 'little', 'bit', 'kinda', 'sort', 'of', 'to', 'in',
        'on', 'at', 'it', 'its', 'a', 'an', 'or', 'but', 'if', 'so', 'as',
        'since', 'when', 'while', 'because', 'then', 'than', 'also', 'still',
    ];

    /**
     * Normalize + particle compaction for matching only.
     * (Dictionary gloss is optional and skipped when it would dominate latency.)
     */
    private static function prepareMatchText(string $normalized): string
    {
        $text = self::normalize($normalized);
        if ($text === '') {
            return '';
        }

        $compact = self::compactParticles($text);
        if ($compact !== '' && $compact !== $text) {
            $text = trim($text . ' ' . $compact);
        }

        return self::normalize($text);
    }

    /**
     * Drop function words so "sakit sa ulo" aligns with "sakit ulo".
     */
    private static function compactParticles(string $normalized): string
    {
        $parts = preg_split('/\s+/u', self::normalize($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        foreach ($parts as $part) {
            if (in_array($part, self::MATCH_PARTICLES, true)) {
                continue;
            }
            if (in_array($part, self::STOP_TOKENS, true) && mb_strlen($part) <= 3) {
                continue;
            }
            $kept[] = $part;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $normalized): array
    {
        $parts = preg_split('/\s+/u', self::normalize($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 3) {
                continue;
            }
            if (in_array($part, self::STOP_TOKENS, true) || in_array($part, self::MATCH_PARTICLES, true)) {
                continue;
            }
            $out[] = $part;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private static function expandRetrievalTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $token = self::normalize((string) $token);
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }
            $out[] = $token;
            foreach (self::RETRIEVAL_SYNONYMS[$token] ?? [] as $syn) {
                $syn = self::normalize((string) $syn);
                if ($syn !== '' && mb_strlen($syn) >= 3) {
                    $out[] = $syn;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Misspelling / near-match equality for retrieval tokens (ASCII clinical terms).
     */
    private static function tokensFuzzyEqual(string $a, string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $la = strlen($a);
        $lb = strlen($b);
        // Keep fuzzy on mostly-latin clinical tokens (misspellings like diarhea).
        if ($la < 4 || $lb < 4) {
            return false;
        }
        if (abs($la - $lb) > 2) {
            return false;
        }

        $dist = levenshtein(substr($a, 0, 255), substr($b, 0, 255));
        $allow = $la <= 5 || $lb <= 5 ? 1 : ($la <= 8 || $lb <= 8 ? 1 : 2);
        if ($dist >= 0 && $dist <= $allow) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= self::FUZZY_SIMILAR_PERCENT;
    }

    /**
     * Link short body-part tokens to compound aliases (head ↔ headache).
     * Rejects false friends like head → headphones.
     */
    private static function tokenCompoundLink(string $short, string $compound): bool
    {
        $short = self::normalize($short);
        $compound = self::normalize($compound);
        if ($short === '' || $compound === '' || mb_strlen($short) < 4) {
            return false;
        }
        if ($short === $compound) {
            return true;
        }
        if (str_contains(' ' . $compound . ' ', ' ' . $short . ' ')) {
            return true;
        }
        if (!str_starts_with($compound, $short)) {
            return false;
        }
        $rest = substr($compound, strlen($short));
        if ($rest === '') {
            return true;
        }
        // Clinical compound tails only (head+ache, tooth+ache, stomach+ache, …).
        $allowedTails = [
            'ache', 'aches', 'aching',
            'pain', 'pains', 'painful',
        ];
        if (in_array($rest, $allowedTails, true)) {
            return true;
        }
        foreach (['ache', 'pain', 'sakit'] as $tail) {
            if (str_starts_with($rest, $tail) && strlen($rest) <= strlen($tail) + 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Score one alias against a normalized haystack.
     *
     * @param bool $allowFuzzy Expensive misspelling/paraphrase path (use sparingly).
     */
    private static function aliasScore(string $haystack, string $alias, bool $allowFuzzy = true): int
    {
        $alias = self::normalize($alias);
        $haystack = self::normalize($haystack);
        if ($alias === '' || $haystack === '' || mb_strlen($alias) < 3) {
            return 0;
        }

        if ($haystack === $alias) {
            return 100;
        }

        // Ultra-generic single tokens must not attach tips from partial/word hits alone.
        if (in_array($alias, self::FULL_COMPLAINT_ONLY_ALIASES, true)) {
            return 0;
        }

        $hCompact = self::compactParticles($haystack);
        $aCompact = self::compactParticles($alias);
        if ($aCompact !== '' && ($hCompact === $aCompact || $haystack === $aCompact)) {
            return 100;
        }

        $len = mb_strlen($alias);
        $quoted = preg_quote($alias, '/');
        $wordHit = (bool) preg_match('/(?:^|\s)' . $quoted . '(?:\s|$)/u', $haystack);
        if (!$wordHit && $aCompact !== '' && $aCompact !== $alias) {
            $qCompact = preg_quote($aCompact, '/');
            $wordHit = (bool) preg_match('/(?:^|\s)' . $qCompact . '(?:\s|$)/u', $hCompact)
                || (bool) preg_match('/(?:^|\s)' . $qCompact . '(?:\s|$)/u', $haystack);
            if ($wordHit) {
                $len = mb_strlen($aCompact);
            }
        }

        if ($wordHit) {
            if ($len < self::MIN_WORD_ALIAS_LEN) {
                return 0;
            }

            return max(70, 40 + min(40, $len));
        }

        if ($len >= self::MIN_SUBSTRING_ALIAS_LEN && str_contains($haystack, $alias)) {
            return 40 + min(40, $len);
        }
        if ($aCompact !== '' && mb_strlen($aCompact) >= self::MIN_SUBSTRING_ALIAS_LEN
            && (str_contains($hCompact, $aCompact) || str_contains($haystack, $aCompact))
        ) {
            return 40 + min(40, mb_strlen($aCompact));
        }

        if (!$allowFuzzy) {
            return 0;
        }

        return self::aliasScoreFuzzy($haystack, $alias, $aCompact);
    }

    private static function aliasScoreFuzzy(string $haystack, string $alias, string $aCompact = ''): int
    {
        $aliasTokens = self::tokens($aCompact !== '' ? $aCompact : $alias);
        if ($aliasTokens === []) {
            $aliasTokens = self::tokens($alias);
        }
        $hayTokens = self::expandRetrievalTokens(self::tokens($haystack));
        if ($aliasTokens === [] || $hayTokens === []) {
            return 0;
        }

        // Single-token alias misspelling (diarhea ↔ diarrhea).
        if (count($aliasTokens) === 1) {
            $aliasTok = $aliasTokens[0];
            if (mb_strlen($aliasTok) >= 5) {
                foreach ($hayTokens as $ht) {
                    if (self::tokensFuzzyEqual($ht, $aliasTok)) {
                        return 85;
                    }
                }
            }
        }

        // Multi-token alias: all content tokens must be covered.
        $covered = 0;
        foreach ($aliasTokens as $aliasTok) {
            $hit = false;
            foreach ($hayTokens as $ht) {
                if (self::tokensFuzzyEqual($ht, $aliasTok) || self::tokenCompoundLink($ht, $aliasTok) || self::tokenCompoundLink($aliasTok, $ht)) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $covered++;
            }
        }

        if ($covered > 0 && $covered === count($aliasTokens)) {
            return max(75, min(95, 55 + (15 * $covered)));
        }

        // Compound alias like "headache" with query "head" + symptom signal.
        if (count($aliasTokens) === 1) {
            $aliasTok = $aliasTokens[0];
            foreach ($hayTokens as $ht) {
                if (!self::tokenCompoundLink($ht, $aliasTok)) {
                    continue;
                }
                $hasSymptom = false;
                foreach ($hayTokens as $ht2) {
                    if (in_array($ht2, ['pain', 'hurt', 'hurts', 'ache', 'aches', 'sakit', 'masakit', 'painful'], true)) {
                        $hasSymptom = true;
                        break;
                    }
                }
                if ($hasSymptom || str_contains($aliasTok, 'pain') || str_contains($aliasTok, 'ache') || str_contains($aliasTok, 'sakit')) {
                    return 80;
                }
            }
        }

        return 0;
    }

    private static function ensureAliasIndexes(): void
    {
        if (self::$aliasTokenIndex !== null && self::$aliasTokenPrefixIndex !== null) {
            return;
        }

        self::$aliasTokenIndex = [];
        self::$aliasTokenPrefixIndex = [];
        foreach (self::all() as $idx => $row) {
            if (($row['symptom_key'] ?? '') === 'default_non_urgent') {
                continue;
            }
            $tokenBag = [];
            foreach (self::stringList($row['aliases'] ?? []) as $alias) {
                foreach (self::tokens($alias) as $tok) {
                    $tokenBag[$tok] = true;
                }
                $compact = self::compactParticles($alias);
                if ($compact !== '' && $compact !== $alias) {
                    foreach (self::tokens($compact) as $tok) {
                        $tokenBag[$tok] = true;
                    }
                }
            }
            $display = self::normalize((string) ($row['display_name'] ?? ''));
            foreach (self::tokens($display) as $tok) {
                $tokenBag[$tok] = true;
            }
            $key = self::normalize(str_replace('_', ' ', (string) ($row['symptom_key'] ?? '')));
            foreach (self::tokens($key) as $tok) {
                $tokenBag[$tok] = true;
            }

            foreach (array_keys($tokenBag) as $tok) {
                if (strlen($tok) > 48) {
                    continue;
                }
                self::$aliasTokenIndex[$tok][] = (int) $idx;
                if (strlen($tok) >= 4 && strlen($tok) <= 24) {
                    $prefix = substr($tok, 0, 4);
                    self::$aliasTokenPrefixIndex[$prefix][$tok] = true;
                }
            }
        }
    }

    /**
     * @param list<string> $queryTokens
     * @return list<int>
     */
    private static function candidateRowIndexes(array $queryTokens): array
    {
        self::ensureAliasIndexes();
        $indexes = [];
        $clinicalTails = ['ache', 'aches', 'aching', 'pain', 'pains', 'painful'];

        foreach ($queryTokens as $qt) {
            $qt = self::normalize((string) $qt);
            if ($qt === '' || mb_strlen($qt) < 3) {
                continue;
            }
            foreach (self::$aliasTokenIndex[$qt] ?? [] as $idx) {
                $indexes[$idx] = true;
            }

            // Direct compound forms (head+ache → headache) without scanning huge prefix buckets.
            if (mb_strlen($qt) >= 4) {
                foreach ($clinicalTails as $tail) {
                    $compound = $qt . $tail;
                    foreach (self::$aliasTokenIndex[$compound] ?? [] as $idx) {
                        $indexes[$idx] = true;
                    }
                }
            }

            // Misspelling neighbors: same 4-char prefix + similar length only (tight bucket).
            if (mb_strlen($qt) >= 5) {
                $prefix = substr($qt, 0, min(4, strlen($qt)));
                foreach (array_keys(self::$aliasTokenPrefixIndex[$prefix] ?? []) as $aliasTok) {
                    if (abs(strlen($aliasTok) - strlen($qt)) > 2) {
                        continue;
                    }
                    if (self::tokensFuzzyEqual($qt, $aliasTok)) {
                        foreach (self::$aliasTokenIndex[$aliasTok] ?? [] as $idx) {
                            $indexes[$idx] = true;
                        }
                    }
                }
            }
        }

        return array_map('intval', array_keys($indexes));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function bestMatch(string $haystack, bool $preferExact, bool $allowDefaultFallback): ?array
    {
        if ($haystack === '') {
            return null;
        }

        $rows = self::all();
        $fallback = null;
        foreach ($rows as $row) {
            if (($row['symptom_key'] ?? '') === 'default_non_urgent') {
                $fallback = $row;
                break;
            }
        }

        $best = null;
        $bestScore = 0;
        $qTokens = self::tokens($haystack);
        $candidateIdx = self::candidateRowIndexes($qTokens);
        // Also consider synonym-expanded roots without indexing ultra-generic hubs like "pain".
        $expandedForLookup = [];
        foreach ($qTokens as $qt) {
            foreach (self::RETRIEVAL_SYNONYMS[$qt] ?? [] as $syn) {
                $syn = self::normalize((string) $syn);
                if ($syn === '' || in_array($syn, self::FULL_COMPLAINT_ONLY_ALIASES, true)) {
                    continue;
                }
                $expandedForLookup[] = $syn;
            }
        }
        if ($expandedForLookup !== []) {
            $candidateIdx = array_values(array_unique(array_merge(
                $candidateIdx,
                self::candidateRowIndexes($expandedForLookup)
            )));
        }
        // Keep fuzzy scoring bounded on large catalogs.
        if (count($candidateIdx) > 120) {
            $candidateIdx = array_slice($candidateIdx, 0, 120);
        }
        $scored = [];

        $scoreRow = static function (array $row, string $haystack, bool $fuzzy) use (&$best, &$bestScore): void {
            $score = 0;
            foreach ($row['aliases'] as $alias) {
                $score = max($score, self::aliasScore($haystack, (string) $alias, $fuzzy));
            }
            if ($fuzzy) {
                $score = max($score, self::aliasScore($haystack, (string) ($row['display_name'] ?? ''), true));
                $score = max($score, self::aliasScore($haystack, str_replace('_', ' ', (string) ($row['symptom_key'] ?? '')), true));
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
                $best['_score'] = $score;
            }
        };

        // Pass 1: cheap scores on indexed candidates (fast path for known tokens/phrases).
        foreach ($candidateIdx as $idx) {
            if (!isset($rows[$idx])) {
                continue;
            }
            $scored[$idx] = true;
            $scoreRow($rows[$idx], $haystack, false);
        }
        if ($bestScore >= self::LOCAL_STRONG_SCORE) {
            return $best;
        }

        // Pass 2: fuzzy / paraphrase on the same candidate set.
        foreach ($candidateIdx as $idx) {
            if (!isset($rows[$idx])) {
                continue;
            }
            $scoreRow($rows[$idx], $haystack, true);
        }
        if ($bestScore >= self::LOCAL_STRONG_SCORE) {
            return $best;
        }

        // No full-catalog fallback: unrelated/no-signal queries stay unmatched
        // (Cohere may still rank indexed candidates when enabled).

        if ($preferExact && $bestScore >= self::LOCAL_STRONG_SCORE) {
            return $best;
        }

        if ($best === null || $bestScore < 1) {
            if ($allowDefaultFallback && $fallback !== null) {
                $fallback['_score'] = 10;

                return $fallback;
            }

            return null;
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $best
     * @return array{
     *   matched: bool,
     *   symptom_key: string,
     *   display_name: string,
     *   tips: list<string>,
     *   when_to_seek_care: string,
     *   resource_label: string,
     *   resource_url: string,
     *   match_source: string,
     *   match_score: float,
     *   cohere_attempted: bool,
     *   cohere_error: string
     * }
     */
    private static function formatMatch(
        array $best,
        string $source,
        float $score,
        bool $cohereAttempted = false,
        string $cohereError = ''
    ): array {
        $key = (string) ($best['symptom_key'] ?? '');
        $tips = array_values(array_filter(
            is_array($best['tips'] ?? null) ? $best['tips'] : [],
            static fn ($t): bool => is_string($t) && trim($t) !== ''
        ));
        $specific = $key !== '' && $key !== 'default_non_urgent' && $tips !== [];
        $resourceLabel = $specific ? trim((string) ($best['resource_label'] ?? '')) : '';
        $resourceUrl = $specific ? trim((string) ($best['resource_url'] ?? '')) : '';
        if ($resourceUrl !== '' && !self::isTrustedResourceUrl($resourceUrl)) {
            $resourceLabel = '';
            $resourceUrl = '';
        }

        return [
            'matched' => $specific,
            'symptom_key' => $specific ? $key : '',
            'display_name' => $specific ? (string) ($best['display_name'] ?? '') : '',
            'tips' => $specific ? $tips : [],
            'when_to_seek_care' => $specific ? (string) ($best['when_to_seek_care'] ?? '') : '',
            'resource_label' => $resourceLabel,
            'resource_url' => $resourceUrl,
            'match_source' => $specific ? $source : 'none',
            'match_score' => $score,
            'cohere_attempted' => $cohereAttempted,
            'cohere_error' => $cohereError,
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $text);
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
