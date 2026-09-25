<?php
/**
 * Loads curated non-urgent self-care tips from data/nlp/self_care_remedies.csv.
 *
 * Matching order:
 * 1) Local NLP/CSV alias scoring (authoritative when strong)
 * 2) Cohere Rerank over existing CSV entries only when local match is weak/ambiguous
 *
 * Cohere never generates or rewrites tip text. Unreliable matches return no tip
 * (no generic/default unrelated tips).
 */

final class SelfCareRemediesLoader
{
    /** Local scores at/above this are trusted without Cohere. */
    private const LOCAL_STRONG_SCORE = 70;
    /** Local scores below this (or default_non_urgent) are treated as weak. */
    private const LOCAL_WEAK_BELOW = 50;

    /** @var list<array<string, mixed>>|null */
    private static ?array $rows = null;

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];
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

        // 1) Strong local complaint-only hit → trust CSV NLP immediately.
        $primary = self::bestMatch($complaintOnly, true, false);
        if ($primary !== null && (int) ($primary['_score'] ?? 0) >= self::LOCAL_STRONG_SCORE) {
            return self::formatMatch($primary, 'local_csv', (float) ($primary['_score'] ?? 0));
        }

        // 2) Broader local haystack match (never auto-accept default_non_urgent as specific tip).
        $local = self::bestMatch($haystack !== '' ? $haystack : $complaintOnly, false, false);
        $localScore = (int) ($local['_score'] ?? 0);
        $localKey = (string) ($local['symptom_key'] ?? '');
        $localStrong = $local !== null
            && $localKey !== ''
            && $localKey !== 'default_non_urgent'
            && $localScore >= self::LOCAL_WEAK_BELOW;

        if ($localStrong && $localScore >= self::LOCAL_STRONG_SCORE) {
            return self::formatMatch($local, 'local_csv', (float) $localScore);
        }

        // 3) Weak / ambiguous / missing → Cohere Rerank over existing CSV entries only.
        $cohereAttempted = false;
        $cohereError = '';
        if (self::shouldTryCohere($queryText, $localStrong, $localScore)) {
            $cohereAttempted = true;
            $ranked = self::matchViaCohere($queryText);
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

        // 4) Keep a usable local CSV-specific match if Cohere did not beat it / was unavailable.
        if ($local !== null
            && $localKey !== ''
            && $localKey !== 'default_non_urgent'
            && $localScore >= 40
        ) {
            return self::formatMatch($local, 'local_csv', (float) $localScore, $cohereAttempted, $cohereError);
        }

        // No reliable specific tip — do not return generic/default unrelated tips.
        $empty['cohere_attempted'] = $cohereAttempted;
        $empty['cohere_error'] = $cohereError;

        return $empty;
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

    private static function shouldTryCohere(string $queryText, bool $localStrong, int $localScore): bool
    {
        if (trim($queryText) === '') {
            return false;
        }
        if (!class_exists('CohereRerankClient') || !CohereRerankClient::enabled()) {
            return false;
        }
        // Strong local already handled before this call; still allow Cohere when weak/ambiguous.
        if ($localStrong && $localScore >= self::LOCAL_STRONG_SCORE) {
            return false;
        }

        return true;
    }

    /**
     * Rerank existing CSV rows; return the winning row with original tip text unchanged.
     *
     * @return array<string, mixed>|null
     */
    private static function matchViaCohere(string $queryText): ?array
    {
        if (!class_exists('CohereRerankClient')) {
            return null;
        }

        $candidates = [];
        $documents = [];
        foreach (self::all() as $row) {
            $key = (string) ($row['symptom_key'] ?? '');
            if ($key === '' || $key === 'default_non_urgent') {
                continue;
            }
            if (($row['tips'] ?? []) === []) {
                continue;
            }
            // Ranking text only — never used as tip content output.
            $aliasText = implode(', ', array_slice(self::stringList($row['aliases'] ?? []), 0, 12));
            $doc = trim((string) ($row['display_name'] ?? $key) . '. ' . $aliasText);
            if ($doc === '') {
                continue;
            }
            $candidates[] = $row;
            $documents[] = $doc;
        }
        if ($documents === []) {
            return null;
        }

        // Cap candidates for latency/cost; prefer broader coverage by keeping all if small.
        $maxDocs = 64;
        if (count($documents) > $maxDocs) {
            $documents = array_slice($documents, 0, $maxDocs);
            $candidates = array_slice($candidates, 0, $maxDocs);
        }

        $ranked = CohereRerankClient::rerank($queryText, $documents, 3);
        if ($ranked === []) {
            return null;
        }

        $minScore = CohereRerankClient::minScore();
        $best = $ranked[0];
        if (($best['score'] ?? 0.0) < $minScore) {
            return null;
        }
        $idx = (int) ($best['index'] ?? -1);
        if ($idx < 0 || !isset($candidates[$idx])) {
            return null;
        }

        $row = $candidates[$idx];
        $row['_score'] = (float) $best['score'];

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function bestMatch(string $haystack, bool $preferExact, bool $allowDefaultFallback): ?array
    {
        if ($haystack === '') {
            return null;
        }

        $fallback = null;
        $best = null;
        $bestScore = 0;

        foreach (self::all() as $row) {
            if (($row['symptom_key'] ?? '') === 'default_non_urgent') {
                $fallback = $row;
                continue;
            }
            $score = 0;
            foreach ($row['aliases'] as $alias) {
                if ($alias === '' || mb_strlen($alias) < 3) {
                    continue;
                }
                if ($haystack === $alias) {
                    $score = max($score, 100);
                } elseif (str_contains($haystack, $alias)) {
                    $score = max($score, 40 + min(40, mb_strlen($alias)));
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
                $best['_score'] = $score;
            }
        }

        if ($preferExact && $bestScore >= self::LOCAL_STRONG_SCORE) {
            return $best;
        }

        if ($best === null || $bestScore < 40) {
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
