<?php
/**
 * RapidFuzz matching (via Python) of translated terms against official datasets.
 * Falls back to PHP WRatio-style scoring when the ai_service venv is unavailable.
 */

final class MedicalFuzzyMatcher
{
    public const ACCEPT_THRESHOLD = 85;

    /** @var list<array{record_id:int, name:string, category:string}>|null */
    private static ?array $conditionRecords = null;

    /** @var list<array{record_id:int, name:string, category:string}>|null */
    private static ?array $allergyRecords = null;

    /** @var list<array{record_id:int, name:string, category:string}>|null */
    private static ?array $symptomRecords = null;

    /** @var array<string, array{record_id:int, name:string, category:string}>|null */
    private static ?array $conditionByExactName = null;

    /** @var array<string, array{record_id:int, name:string, category:string}>|null */
    private static ?array $allergyByExactName = null;

    /**
     * @param array{allergies:array, conditions:array} $translation
     * @return array<string, mixed>
     */
    public static function matchProfile(array $translation): array
    {
        $python = self::matchProfileViaPython($translation);
        if ($python !== null) {
            return $python;
        }

        return self::matchProfilePhp($translation);
    }

    /**
     * @param list<array{english_term:string, local_term?:string, category?:string}> $queue
     * @return array<string, mixed>
     */
    public static function matchQueue(array $queue, string $category): array
    {
        if ($category === 'symptom') {
            return self::matchQueuePhp($queue, 'symptom');
        }

        $translation = [
            'allergies'  => ['validation_queue' => $category === 'allergy' ? $queue : []],
            'conditions' => ['validation_queue' => $category === 'condition' ? $queue : []],
        ];
        $profile = self::matchProfile($translation);

        return match ($category) {
            'allergy'   => $profile['allergies'],
            'condition' => $profile['conditions'],
            default     => self::emptyFieldResult(),
        };
    }

    /**
     * Match translated terms against conditions, allergies, and symptoms datasets.
     *
     * @param list<array{english_term:string, local_term?:string, category?:string}> $queue
     * @return array<string, mixed>
     */
    public static function matchTextQueue(array $queue): array
    {
        $python = self::matchTextQueueViaPython($queue);
        if ($python !== null) {
            return $python;
        }

        return self::matchTextQueuePhp($queue);
    }

    /**
     * Pick the best fuzzy match across official datasets for one English term.
     *
     * @return array<string, mixed>
     */
    public static function matchTermBest(string $english, ?string $hintCategory = null): array
    {
        $english = trim($english);
        if ($english === '') {
            return self::unmatchedRow('');
        }

        $english = BodyPartPainSymptoms::canonicalEnglish($english);
        $officialPain = BodyPartPainSymptoms::officialSymptomName($english);
        if ($officialPain !== null) {
            $records = self::recordsForCategory('symptom');
            $exactMap = self::exactNameMapForRecords($records);
            $officialKey = mb_strtolower($officialPain);
            if (isset($exactMap[$officialKey])) {
                return array_merge(
                    self::acceptedMatchRow($english, $exactMap[$officialKey], 100),
                    ['dataset_category' => 'symptom']
                );
            }
        }

        $order = self::categorySearchOrder($hintCategory, $english);
        $bestMatch = null;
        $bestScore = 0;
        $bestCategory = null;

        foreach ($order as $category) {
            $records = self::recordsForCategory($category);
            $match = self::matchTermPhp($english, $records);
            $score = (int) ($match['similarity_score'] ?? 0);
            if (($match['validation_status'] ?? '') === 'accepted' && $score > $bestScore) {
                $bestMatch = $match;
                $bestScore = $score;
                $bestCategory = $category;
                if ($score === 100) {
                    break;
                }
            }
        }

        if ($bestMatch === null) {
            return array_merge(self::unmatchedRow($english), [
                'dataset_category' => $hintCategory ?? 'unknown',
            ]);
        }

        return array_merge($bestMatch, [
            'dataset_category' => $bestCategory,
        ]);
    }

    /**
     * @param list<array{english_term:string, local_term?:string, category?:string}> $queue
     */
    private static function matchTextQueueViaPython(array $queue): ?array
    {
        if (AI_SERVICE_ENABLED && AiServiceClient::isHealthy(2)) {
            $http = AiServiceClient::fuzzyMatchTextQueue($queue);
            if (is_array($http)) {
                return $http;
            }
        }

        $python = self::pythonExecutable();
        if ($python === null) {
            return null;
        }

        $script = BASE_PATH . '/ai_service/fuzzy_match_cli.py';
        if (!is_readable($script)) {
            return null;
        }

        $payload = json_encode(['text_queue' => $queue], JSON_THROW_ON_ERROR);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$python, $script, '--text-analysis'],
            $descriptorSpec,
            $pipes,
            BASE_PATH . '/ai_service'
        );

        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $stdout === false || $stdout === '') {
            return null;
        }

        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<array{english_term:string, local_term?:string, category?:string}> $queue
     * @return array<string, mixed>
     */
    private static function matchTextQueuePhp(array $queue): array
    {
        if ($queue === []) {
            return self::emptyTextAnalysisResult();
        }

        $results = [];
        $accepted = 0;
        $rejected = 0;

        foreach ($queue as $item) {
            $english = trim((string) ($item['match_term'] ?? $item['english_term'] ?? ''));
            if ($english === '') {
                continue;
            }

            $hint = self::normalizeHintCategory((string) ($item['category'] ?? ''));
            $match = self::matchTermBest($english, $hint);
            $row = array_merge(
                [
                    'local_term'       => $item['local_term'] ?? '',
                    'english_term'     => (string) ($item['english_term'] ?? $english),
                    'match_term'       => $english,
                    'category'         => (string) ($match['dataset_category'] ?? $hint ?? 'unknown'),
                    'matched_language' => 'english',
                    'input_language'   => $item['input_language'] ?? 'unknown',
                    'was_translated'   => (bool) ($item['was_translated'] ?? false),
                ],
                $match
            );
            $results[] = $row;
            if ($row['validation_status'] === 'accepted') {
                $accepted++;
            } else {
                $rejected++;
            }
        }

        $total = count($results);
        $status = self::fieldStatus($accepted, $total);

        return [
            'status'         => $status,
            'status_label'   => self::fieldLabel($status, $accepted, $total),
            'accepted_count' => $accepted,
            'rejected_count' => $rejected,
            'total_count'    => $total,
            'threshold'      => self::ACCEPT_THRESHOLD,
            'results'        => $results,
            'engine'         => 'php-wratio-fallback',
        ];
    }

    /** Common patient-friendly terms that should prefer the symptoms dataset. */
    private const SYMPTOM_HINT_TERMS = [
        'headache', 'fever', 'cough', 'pain', 'nausea', 'vomiting', 'diarrhea',
        'dizziness', 'fatigue', 'rash', 'itching', 'itchiness', 'shortness of breath', 'dyspnea',
        'chest pain', 'abdominal pain', 'back pain', 'sore throat', 'chills',
        'hair loss', 'body weakness', 'painful urination', 'weakness', 'alopecia',
    ];

    /** @return list<string> */
    private static function categorySearchOrder(?string $hintCategory, string $english = ''): array
    {
        $hint = self::normalizeHintCategory($hintCategory ?? '');
        $englishKey = mb_strtolower(trim($english));

        if ($hint === null && in_array($englishKey, self::SYMPTOM_HINT_TERMS, true)) {
            return ['symptom', 'condition', 'allergy'];
        }

        return match ($hint) {
            'allergy'   => ['allergy'],
            'condition' => ['symptom', 'condition'],
            'symptom'   => ['symptom', 'condition'],
            default     => ['symptom', 'condition', 'allergy'],
        };
    }

    private static function normalizeHintCategory(string $category): ?string
    {
        $category = strtolower(trim($category));
        if ($category === '') {
            return null;
        }

        return match ($category) {
            'allergy', 'allergies' => 'allergy',
            'condition', 'conditions' => 'condition',
            'symptom', 'symptoms' => 'symptom',
            default => null,
        };
    }

    /** @return list<array{record_id:int, name:string, category:string}> */
    private static function recordsForCategory(string $category): array
    {
        return match ($category) {
            'allergy'   => self::allergyRecords(),
            'symptom'   => self::symptomRecords(),
            default     => self::conditionRecords(),
        };
    }

    /**
     * @param array{allergies:array, conditions:array} $translation
     */
    private static function matchProfileViaPython(array $translation): ?array
    {
        if (AI_SERVICE_ENABLED && AiServiceClient::isHealthy(2)) {
            $http = AiServiceClient::fuzzyMatchProfile($translation);
            if (is_array($http)) {
                return $http;
            }
        }

        $python = self::pythonExecutable();
        if ($python === null) {
            return null;
        }

        $script = BASE_PATH . '/ai_service/fuzzy_match_cli.py';
        if (!is_readable($script)) {
            return null;
        }

        $payload = json_encode(['translation' => $translation], JSON_THROW_ON_ERROR);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$python, $script],
            $descriptorSpec,
            $pipes,
            BASE_PATH . '/ai_service'
        );

        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $stdout === false || $stdout === '') {
            return null;
        }

        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array{allergies:array, conditions:array} $translation
     * @return array<string, mixed>
     */
    private static function matchProfilePhp(array $translation): array
    {
        $allergies = self::matchQueuePhp(
            $translation['allergies']['validation_queue'] ?? [],
            'allergy'
        );
        $conditions = self::matchQueuePhp(
            $translation['conditions']['validation_queue'] ?? [],
            'condition'
        );

        $accepted = ($allergies['accepted_count'] ?? 0) + ($conditions['accepted_count'] ?? 0);
        $rejected = ($allergies['rejected_count'] ?? 0) + ($conditions['rejected_count'] ?? 0);
        $total = ($allergies['total_count'] ?? 0) + ($conditions['total_count'] ?? 0);

        $overall = self::overallStatus($accepted, $rejected, $total);

        return [
            'allergies'              => $allergies,
            'conditions'             => $conditions,
            'overall_status'         => $overall,
            'overall_status_label'   => self::overallLabel($overall, $accepted, $rejected, $total),
            'threshold'              => self::ACCEPT_THRESHOLD,
            'engine'                 => 'php-wratio-fallback',
        ];
    }

    /**
     * @param list<array{english_term:string, local_term?:string}> $queue
     * @return array<string, mixed>
     */
    private static function matchQueuePhp(array $queue, string $category): array
    {
        if ($queue === []) {
            return self::emptyFieldResult();
        }

        $records = self::recordsForCategory($category);
        $results = [];
        $accepted = 0;
        $rejected = 0;

        foreach ($queue as $item) {
            $english = trim((string) ($item['match_term'] ?? $item['english_term'] ?? ''));
            if ($english === '') {
                continue;
            }

            $match = self::matchTermPhp($english, $records);
            $row = array_merge(
                [
                    'local_term'       => $item['local_term'] ?? '',
                    'english_term'     => (string) ($item['english_term'] ?? $english),
                    'match_term'       => $english,
                    'category'         => $category,
                    'matched_language' => 'english',
                    'input_language'   => $item['input_language'] ?? 'unknown',
                ],
                $match
            );
            $results[] = $row;
            if ($row['validation_status'] === 'accepted') {
                $accepted++;
            } else {
                $rejected++;
            }
        }

        $total = count($results);
        $status = self::fieldStatus($accepted, $total);

        return [
            'status'         => $status,
            'status_label'   => self::fieldLabel($status, $accepted, $total),
            'accepted_count' => $accepted,
            'rejected_count' => $rejected,
            'total_count'    => $total,
            'threshold'      => self::ACCEPT_THRESHOLD,
            'results'        => $results,
        ];
    }

    /**
     * @param list<array{record_id:int, name:string}> $records
     * @return array<string, mixed>
     */
    private static function matchTermPhp(string $term, array $records): array
    {
        if ($term === '' || $records === []) {
            return self::unmatchedRow($term);
        }

        $key = mb_strtolower(trim($term));
        $exactMap = self::exactNameMapForRecords($records);
        if (isset($exactMap[$key])) {
            return self::acceptedMatchRow($term, $exactMap[$key], 100);
        }

        $candidates = self::candidateRecords($key, $records);
        $bestName = null;
        $bestScore = 0;
        $bestRecord = null;
        $bestRank = PHP_INT_MAX;
        $bestFullRatio = 0;

        $isSpecificPainQuery = preg_match('/\bpain\b/u', $key) === 1
            && preg_match('/\S+\s+\S+/u', $key) === 1;

        foreach ($candidates as $record) {
            $nameLower = mb_strtolower($record['name']);
            if ($isSpecificPainQuery && $nameLower === 'pain') {
                continue;
            }
            $score = self::wRatio($term, $record['name']);
            $rank = self::matchRank($key, $nameLower);
            $fullRatio = self::ratio($key, $nameLower);
            if (
                $score > $bestScore
                || ($score === $bestScore && $rank < $bestRank)
                || ($score === $bestScore && $rank === $bestRank && $fullRatio > $bestFullRatio)
            ) {
                $bestScore = $score;
                $bestName = $record['name'];
                $bestRecord = $record;
                $bestRank = $rank;
                $bestFullRatio = $fullRatio;
            }
        }

        if ($bestScore < self::ACCEPT_THRESHOLD) {
            return self::unmatchedRow($term);
        }

        return self::acceptedMatchRow($term, $bestRecord, $bestScore);
    }

    /**
     * @param array{record_id:int, name:string, category:string} $record
     * @return array<string, mixed>
     */
    private static function acceptedMatchRow(string $term, array $record, int $score): array
    {
        return [
            'input_term'          => $term,
            'matched_term'        => $record['name'],
            'similarity_score'    => $score,
            'confidence_level'    => self::confidenceLevel($score),
            'validation_status'   => 'accepted',
            'standardized_term'   => $record['name'],
            'record_id'           => $record['record_id'],
            'scorer'              => 'WRatio',
            'threshold'           => self::ACCEPT_THRESHOLD,
        ];
    }

    /**
     * @param list<array{record_id:int, name:string, category:string}> $records
     * @return list<array{record_id:int, name:string, category:string}>
     */
    private static function candidateRecords(string $termKey, array $records): array
    {
        if ($termKey === '') {
            return [];
        }

        $pattern = '/\b' . preg_quote($termKey, '/') . '\b/u';
        $wordMatches = [];
        $substringMatches = [];

        foreach ($records as $record) {
            $nameLower = mb_strtolower($record['name']);
            if (preg_match($pattern, $nameLower)) {
                $wordMatches[] = $record;
                continue;
            }
            if (mb_strpos($nameLower, $termKey) !== false) {
                $substringMatches[] = $record;
            }
        }

        if ($wordMatches !== []) {
            return $wordMatches;
        }
        if ($substringMatches !== []) {
            return $substringMatches;
        }

        return $records;
    }

    /**
     * @param list<array{record_id:int, name:string, category:string}> $records
     * @return array<string, array{record_id:int, name:string, category:string}>
     */
    private static function exactNameMapForRecords(array $records): array
    {
        $map = [];
        foreach ($records as $record) {
            $key = mb_strtolower($record['name']);
            if (!isset($map[$key])) {
                $map[$key] = $record;
            }
        }

        return $map;
    }

    private static function matchRank(string $termKey, string $nameLower): int
    {
        if ($nameLower === $termKey) {
            return 0;
        }
        $words = preg_split('/\s+/u', $nameLower) ?: [];
        $last = $words[count($words) - 1] ?? '';
        if ($last === $termKey) {
            return 1;
        }
        if (preg_match('/\b' . preg_quote($termKey, '/') . '\b/u', $nameLower)) {
            return 2;
        }

        return 3;
    }

    private static function wRatio(string $a, string $b): int
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === $b) {
            return 100;
        }

        $ratio = self::ratio($a, $b);
        $token = self::tokenSortRatio($a, $b);
        $partial = 0;
        if (mb_strlen($a) >= max(4, (int) (mb_strlen($b) * 0.45))) {
            $partial = self::partialRatio($a, $b);
        }

        return max($ratio, $token, $partial);
    }

    private static function ratio(string $a, string $b): int
    {
        similar_text($a, $b, $pct);

        return (int) round($pct);
    }

    private static function partialRatio(string $a, string $b): int
    {
        if (mb_strlen($a) > mb_strlen($b)) {
            [$a, $b] = [$b, $a];
        }
        if ($a === '') {
            return 0;
        }
        $len = mb_strlen($a);
        $best = 0;
        $blen = mb_strlen($b);
        for ($i = 0; $i <= $blen - $len; $i++) {
            $slice = mb_substr($b, $i, $len);
            similar_text($a, $slice, $pct);
            $best = max($best, (int) round($pct));
        }

        return $best;
    }

    private static function tokenSortRatio(string $a, string $b): int
    {
        $ta = explode(' ', $a);
        $tb = explode(' ', $b);
        sort($ta);
        sort($tb);

        return self::ratio(implode(' ', $ta), implode(' ', $tb));
    }

    private static function confidenceLevel(int $score): string
    {
        if ($score >= 95) {
            return 'high';
        }
        if ($score >= self::ACCEPT_THRESHOLD) {
            return 'medium';
        }
        if ($score >= 70) {
            return 'low';
        }

        return 'none';
    }

    /** @return array<string, mixed> */
    private static function unmatchedRow(string $term): array
    {
        return [
            'input_term'         => $term,
            'matched_term'       => null,
            'similarity_score'   => 0,
            'confidence_level' => 'none',
            'validation_status'  => 'unrecognized',
            'standardized_term'  => null,
            'record_id'          => null,
            'scorer'             => 'WRatio',
            'threshold'          => self::ACCEPT_THRESHOLD,
        ];
    }

    private static function overallStatus(int $accepted, int $rejected, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($accepted === $total) {
            return 'complete';
        }
        if ($accepted > 0) {
            return 'partial';
        }

        return 'none';
    }

    private static function overallLabel(string $status, int $accepted, int $rejected, int $total): string
    {
        $t = self::ACCEPT_THRESHOLD;

        return match ($status) {
            'complete' => "RapidFuzz: {$accepted}/{$total} terms accepted (≥{$t}%)",
            'partial'  => "RapidFuzz: {$accepted}/{$total} accepted, {$rejected} unrecognized",
            'none'     => "RapidFuzz: 0/{$total} terms met {$t}% threshold",
            default    => 'No terms to fuzzy match',
        };
    }

    private static function fieldStatus(int $accepted, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($accepted === $total) {
            return 'complete';
        }
        if ($accepted > 0) {
            return 'partial';
        }

        return 'none';
    }

    private static function fieldLabel(string $status, int $accepted, int $total): string
    {
        $t = self::ACCEPT_THRESHOLD;

        return match ($status) {
            'complete' => "All terms accepted (≥{$t}% RapidFuzz) ({$accepted}/{$total})",
            'partial'  => "Partial acceptance ({$accepted}/{$total} at ≥{$t}%)",
            'none'     => "No terms met the {$t}% similarity threshold",
            default    => 'Nothing to fuzzy match',
        };
    }

    /** @return array<string, mixed> */
    private static function emptyFieldResult(): array
    {
        return [
            'status'         => 'empty',
            'status_label'   => 'Nothing to fuzzy match',
            'accepted_count' => 0,
            'rejected_count' => 0,
            'total_count'    => 0,
            'threshold'      => self::ACCEPT_THRESHOLD,
            'results'        => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyTextAnalysisResult(): array
    {
        return array_merge(self::emptyFieldResult(), [
            'engine' => 'php-wratio-fallback',
        ]);
    }

    /** @return list<array{record_id:int, name:string, category:string}> */
    private static function symptomRecords(): array
    {
        if (self::$symptomRecords !== null) {
            return self::$symptomRecords;
        }
        self::$symptomRecords = self::loadRecords(
            BASE_PATH . '/data/nlp/symptoms.csv',
            'symptom_id',
            'symptom_name',
            'symptom'
        );

        return self::$symptomRecords;
    }

    private static function pythonExecutable(): ?string
    {
        $candidates = [
            BASE_PATH . '/ai_service/.venv/Scripts/python.exe',
            BASE_PATH . '/ai_service/.venv/bin/python',
            'python3',
            'python',
        ];

        foreach ($candidates as $bin) {
            if ($bin === 'python' || $bin === 'python3') {
                $out = [];
                $code = 0;
                @exec($bin . ' --version 2>&1', $out, $code);
                if ($code === 0) {
                    return $bin;
                }
                continue;
            }
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    /** @return list<array{record_id:int, name:string, category:string}> */
    private static function conditionRecords(): array
    {
        if (self::$conditionRecords !== null) {
            return self::$conditionRecords;
        }
        self::$conditionRecords = self::loadRecords(
            BASE_PATH . '/data/nlp/medical_conditions.csv',
            'condition_id',
            'condition_name',
            'condition'
        );
        self::$conditionByExactName = self::exactNameMapForRecords(self::$conditionRecords);

        return self::$conditionRecords;
    }

    /** @return list<array{record_id:int, name:string, category:string}> */
    private static function allergyRecords(): array
    {
        if (self::$allergyRecords !== null) {
            return self::$allergyRecords;
        }
        self::$allergyRecords = self::loadRecords(
            BASE_PATH . '/data/nlp/allergies.csv',
            'allergy_id',
            'allergy_name',
            'allergy'
        );
        self::$allergyByExactName = self::exactNameMapForRecords(self::$allergyRecords);

        return self::$allergyRecords;
    }

    /**
     * @return list<array{record_id:int, name:string, category:string}>
     */
    private static function loadRecords(string $path, string $idCol, string $nameCol, string $category): array
    {
        $records = [];
        if (!is_readable($path)) {
            return $records;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $records;
        }

        $header = fgetcsv($handle);
        $idIndex = 0;
        $nameIndex = 1;
        if (is_array($header)) {
            $idIdx = array_search($idCol, $header, true);
            $nameIdx = array_search($nameCol, $header, true);
            if ($idIdx !== false) {
                $idIndex = (int) $idIdx;
            }
            if ($nameIdx !== false) {
                $nameIndex = (int) $nameIdx;
            }
        }

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim($row[$nameIndex] ?? '');
            if ($name === '') {
                continue;
            }
            $records[] = [
                'record_id' => (int) ($row[$idIndex] ?? 0),
                'name'      => $name,
                'category'  => $category,
            ];
        }
        fclose($handle);

        return $records;
    }
}
