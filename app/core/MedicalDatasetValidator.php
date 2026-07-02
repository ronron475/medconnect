<?php
/**
 * Dataset validation after fuzzy matching: verifies standardized terms exist in official CSV tables.
 * Only valid records may proceed to registration.
 */

final class MedicalDatasetValidator
{
    public const DATASET_ALLERGIES = 'data/nlp/allergies.csv';

    public const DATASET_CONDITIONS = 'data/nlp/medical_conditions.csv';

    public const DATASET_SYMPTOMS = 'data/nlp/symptoms.csv';

    public const TABLE_ALLERGIES = 'allergies';

    public const TABLE_CONDITIONS = 'medical_conditions';

    public const TABLE_SYMPTOMS = 'symptoms';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $allergyByName = null;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $allergyById = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $conditionByName = null;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $conditionById = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $symptomByName = null;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $symptomById = null;

    /**
     * @param array{allergies:array, conditions:array} $fuzzyMatching
     * @return array<string, mixed>
     */
    public static function validateFromFuzzyMatching(array $fuzzyMatching): array
    {
        $allergies = self::validateFieldResults(
            $fuzzyMatching['allergies']['results'] ?? [],
            'allergy'
        );
        $conditions = self::validateFieldResults(
            $fuzzyMatching['conditions']['results'] ?? [],
            'condition'
        );

        $registration = self::buildRegistrationGate($allergies, $conditions);

        $valid = ($allergies['valid_count'] ?? 0) + ($conditions['valid_count'] ?? 0);
        $invalid = ($allergies['invalid_count'] ?? 0) + ($conditions['invalid_count'] ?? 0);
        $total = $valid + $invalid;

        $overall = self::overallStatus($valid, $invalid, $total);

        return [
            'allergies'              => $allergies,
            'conditions'             => $conditions,
            'registration'           => $registration,
            'overall_status'         => $overall,
            'overall_status_label'   => self::overallLabel($overall, $valid, $invalid, $total),
            'registration_eligible'  => (bool) ($registration['eligible'] ?? false),
        ];
    }

    /**
     * Validate multi-dataset text analysis fuzzy results (conditions, allergies, symptoms).
     *
     * @param array<string, mixed> $fuzzyMatching
     * @return array<string, mixed>
     */
    public static function validateTextAnalysis(array $fuzzyMatching): array
    {
        $grouped = [
            'allergy'   => [],
            'condition' => [],
            'symptom'   => [],
        ];

        foreach ($fuzzyMatching['results'] ?? [] as $row) {
            $category = self::normalizeCategory((string) ($row['category'] ?? $row['dataset_category'] ?? ''));
            if ($category !== null && isset($grouped[$category])) {
                $grouped[$category][] = $row;
            }
        }

        $allergies = self::validateFieldResults($grouped['allergy'], 'allergy');
        $conditions = self::validateFieldResults($grouped['condition'], 'condition');
        $symptoms = self::validateFieldResults($grouped['symptom'], 'symptom');

        $valid = $allergies['valid_count'] + $conditions['valid_count'] + $symptoms['valid_count'];
        $invalid = $allergies['invalid_count'] + $conditions['invalid_count'] + $symptoms['invalid_count'];
        $total = $valid + $invalid;
        $overall = self::overallStatus($valid, $invalid, $total);

        $matchedRecords = self::collectMatchedRecords($allergies, $conditions, $symptoms);

        return [
            'allergies'              => $allergies,
            'conditions'             => $conditions,
            'symptoms'               => $symptoms,
            'matched_records'        => $matchedRecords,
            'overall_status'         => $overall,
            'overall_status_label'   => self::overallLabel($overall, $valid, $invalid, $total),
            'validation_eligible'    => $total > 0 && $invalid === 0,
            'valid_count'            => $valid,
            'invalid_count'          => $invalid,
            'total_count'            => $total,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fuzzyResults
     * @return array<string, mixed>
     */
    public static function validateFieldResults(array $fuzzyResults, string $category): array
    {
        if ($fuzzyResults === []) {
            return self::emptyFieldResult($category);
        }

        $datasetMeta = self::datasetMeta($category);
        $results = [];
        $valid = 0;
        $invalid = 0;

        foreach ($fuzzyResults as $row) {
            $results[] = self::validateSingleRow($row, $category, $datasetMeta);
            if (end($results)['final_status'] === 'valid') {
                $valid++;
            } else {
                $invalid++;
            }
        }

        $total = count($results);
        $status = self::fieldStatus($valid, $invalid, $total);

        return [
            'field'           => self::fieldNameForCategory($category),
            'category'        => $category,
            'dataset_source'  => $datasetMeta['dataset_source'],
            'dataset_table'   => $datasetMeta['dataset_table'],
            'status'          => $status,
            'status_label'    => self::fieldLabel($status, $valid, $invalid, $total),
            'valid_count'     => $valid,
            'invalid_count'   => $invalid,
            'total_count'     => $total,
            'results'         => $results,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{dataset_source:string, dataset_table:string, dataset_path:string} $datasetMeta
     * @return array<string, mixed>
     */
    private static function validateSingleRow(array $row, string $category, array $datasetMeta): array
    {
        $local = (string) ($row['local_term'] ?? '');
        $english = trim((string) ($row['english_term'] ?? $row['input_term'] ?? ''));
        $standard = trim((string) ($row['standardized_term'] ?? ''));
        $fuzzyStatus = (string) ($row['validation_status'] ?? '');
        $fuzzyScore = (int) ($row['similarity_score'] ?? 0);
        $recordId = (int) ($row['record_id'] ?? 0);

        $base = [
            'local_term'        => $local,
            'english_term'      => $english,
            'standardized_term' => $standard,
            'category'          => $category,
            'fuzzy_score'       => $fuzzyScore,
            'fuzzy_status'      => $fuzzyStatus,
            'dataset_source'    => $datasetMeta['dataset_source'],
            'dataset_table'     => $datasetMeta['dataset_table'],
        ];

        if ($fuzzyStatus !== 'accepted') {
            return array_merge($base, [
                'validation_result'   => 'rejected_at_fuzzy',
                'validation_message'  => 'Term did not meet the 85% similarity threshold — not sent to registration.',
                'matched_record'      => null,
                'record'              => null,
                'final_status'        => 'invalid',
                'blocked'             => true,
                'registration_ready'  => false,
            ]);
        }

        if ($standard === '') {
            return array_merge($base, [
                'validation_result'   => 'missing_standard_term',
                'validation_message'  => 'No standardized term to validate against the dataset.',
                'matched_record'      => null,
                'record'              => null,
                'final_status'        => 'invalid',
                'blocked'             => true,
                'registration_ready'  => false,
            ]);
        }

        $record = self::resolveRecord($standard, $category, $recordId);

        if ($record === null) {
            return array_merge($base, [
                'validation_result'   => 'not_in_dataset',
                'validation_message'  => 'No matching record in ' . $datasetMeta['dataset_table'] . '.',
                'matched_record'      => null,
                'record'              => null,
                'final_status'        => 'invalid',
                'blocked'             => true,
                'registration_ready'  => false,
            ]);
        }

        if ($recordId > 0 && (int) $record['record_id'] !== $recordId) {
            return array_merge($base, [
                'validation_result'   => 'id_mismatch',
                'validation_message'  => 'Fuzzy record ID does not match the official dataset entry.',
                'matched_record'      => self::publicRecord($record),
                'record'              => null,
                'final_status'        => 'invalid',
                'blocked'             => true,
                'registration_ready'  => false,
            ]);
        }

        return array_merge($base, [
            'validation_result'   => 'found',
            'validation_message'  => 'Official dataset record verified.',
            'matched_record'      => self::publicRecord($record),
            'record'              => self::publicRecord($record),
            'final_status'        => 'valid',
            'blocked'             => false,
            'registration_ready'  => true,
        ]);
    }

    /**
     * @param array{valid_count:int, results:list} $allergies
     * @param array{valid_count:int, results:list} $conditions
     * @return array<string, mixed>
     */
    private static function buildRegistrationGate(array $allergies, array $conditions): array
    {
        $acceptedAllergies = self::registrationItems($allergies['results'] ?? []);
        $acceptedConditions = self::registrationItems($conditions['results'] ?? []);
        $rejected = array_merge(
            self::rejectedItems($allergies['results'] ?? []),
            self::rejectedItems($conditions['results'] ?? [])
        );

        $hasInput = ($allergies['total_count'] ?? 0) + ($conditions['total_count'] ?? 0) > 0;
        $hasInvalid = ($allergies['invalid_count'] ?? 0) + ($conditions['invalid_count'] ?? 0) > 0;
        $eligible = $hasInput && !$hasInvalid;

        return [
            'eligible'           => $eligible,
            'eligible_label'     => $eligible
                ? 'All terms verified — safe to save for registration'
                : ($hasInput
                    ? 'Registration blocked — one or more terms are not in the official datasets'
                    : 'No medical terms to register'),
            'conditions'         => $acceptedConditions,
            'allergies'          => $acceptedAllergies,
            'rejected'           => $rejected,
            'accepted_count'     => count($acceptedConditions) + count($acceptedAllergies),
            'rejected_count'     => count($rejected),
        ];
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    private static function registrationItems(array $results): array
    {
        $items = [];
        foreach ($results as $row) {
            if (($row['final_status'] ?? '') !== 'valid' || empty($row['record'])) {
                continue;
            }
            $rec = $row['record'];
            $items[] = [
                'local_term'       => $row['local_term'] ?? '',
                'standardized_term'=> $rec['name'],
                'record_id'        => $rec['record_id'],
                'icd10_code'       => $rec['icd10_code'] ?? null,
                'category'         => $rec['record_category'] ?? $row['category'] ?? '',
                'dataset_category' => $rec['dataset_category'] ?? null,
                'dataset_source'   => $row['dataset_source'] ?? '',
                'dataset_table'    => $row['dataset_table'] ?? '',
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    private static function rejectedItems(array $results): array
    {
        $items = [];
        foreach ($results as $row) {
            if (($row['final_status'] ?? '') === 'valid') {
                continue;
            }
            $items[] = [
                'local_term'         => $row['local_term'] ?? '',
                'english_term'       => $row['english_term'] ?? '',
                'standardized_term'  => $row['standardized_term'] ?? '',
                'category'           => $row['category'] ?? '',
                'final_status'       => 'invalid',
                'blocked'            => true,
                'validation_result'  => $row['validation_result'] ?? 'unknown',
                'validation_message' => $row['validation_message'] ?? '',
            ];
        }

        return $items;
    }

    /**
     * @return array{record_id:int, name:string, dataset_category:?string, record_category:string, description:?string, source:?string}|null
     */
    private static function resolveRecord(string $standardName, string $category, int $expectedId = 0): ?array
    {
        self::loadDataset($category);

        if ($category === 'allergy') {
            $byName = self::$allergyByName ?? [];
            $byId = self::$allergyById ?? [];
        } elseif ($category === 'symptom') {
            $byName = self::$symptomByName ?? [];
            $byId = self::$symptomById ?? [];
        } else {
            $byName = self::$conditionByName ?? [];
            $byId = self::$conditionById ?? [];
        }

        $key = mb_strtolower($standardName);
        $record = $byName[$key] ?? null;

        if ($record === null && $expectedId > 0) {
            $record = $byId[$expectedId] ?? null;
            if ($record !== null && mb_strtolower($record['name']) !== $key) {
                return null;
            }
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function publicRecord(array $record): array
    {
        return [
            'record_id'         => (int) $record['record_id'],
            'name'              => (string) $record['name'],
            'dataset_category'  => $record['dataset_category'] ?? null,
            'record_category'   => (string) ($record['record_category'] ?? ''),
            'description'       => $record['description'] ?? null,
            'source'            => $record['source'] ?? null,
            'icd10_code'        => $record['icd10_code'] ?? null,
            'related_body_system' => $record['related_body_system'] ?? null,
        ];
    }

    /** @return array{dataset_source:string, dataset_table:string, dataset_path:string} */
    private static function datasetMeta(string $category): array
    {
        if ($category === 'allergy') {
            return [
                'dataset_source' => self::DATASET_ALLERGIES,
                'dataset_table'  => self::TABLE_ALLERGIES,
                'dataset_path'   => BASE_PATH . '/' . self::DATASET_ALLERGIES,
            ];
        }

        if ($category === 'symptom') {
            return [
                'dataset_source' => self::DATASET_SYMPTOMS,
                'dataset_table'  => self::TABLE_SYMPTOMS,
                'dataset_path'   => BASE_PATH . '/' . self::DATASET_SYMPTOMS,
            ];
        }

        return [
            'dataset_source' => self::DATASET_CONDITIONS,
            'dataset_table'  => self::TABLE_CONDITIONS,
            'dataset_path'   => BASE_PATH . '/' . self::DATASET_CONDITIONS,
        ];
    }

    private static function fieldNameForCategory(string $category): string
    {
        return match ($category) {
            'allergy'   => 'allergies',
            'symptom'   => 'symptoms',
            default     => 'conditions',
        };
    }

    private static function normalizeCategory(string $category): ?string
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

    /**
     * @return list<array<string, mixed>>
     */
    private static function collectMatchedRecords(
        array $allergies,
        array $conditions,
        array $symptoms
    ): array {
        $records = [];
        foreach ([$allergies, $conditions, $symptoms] as $block) {
            foreach ($block['results'] ?? [] as $row) {
                if (($row['final_status'] ?? '') !== 'valid' || empty($row['record'])) {
                    continue;
                }
                $rec = $row['record'];
                $records[] = [
                    'term_type'         => (string) ($row['category'] ?? ''),
                    'local_term'        => (string) ($row['local_term'] ?? ''),
                    'english_term'      => (string) ($row['english_term'] ?? ''),
                    'standardized_term' => (string) ($rec['name'] ?? ''),
                    'record_id'         => (int) ($rec['record_id'] ?? 0),
                    'dataset_table'     => (string) ($row['dataset_table'] ?? ''),
                    'dataset_source'    => (string) ($row['dataset_source'] ?? ''),
                    'dataset_category'  => $rec['dataset_category'] ?? null,
                    'description'       => $rec['description'] ?? null,
                    'icd10_code'        => $rec['icd10_code'] ?? null,
                    'related_body_system' => $rec['related_body_system'] ?? null,
                    'validation_status' => 'valid',
                ];
            }
        }

        return $records;
    }

    private static function loadDataset(string $category): void
    {
        if ($category === 'allergy') {
            if (self::$allergyByName !== null) {
                return;
            }
            [$byName, $byId] = self::parseCsv(
                BASE_PATH . '/' . self::DATASET_ALLERGIES,
                'allergy_id',
                'allergy_name',
                'category',
                'allergy'
            );
            self::$allergyByName = $byName;
            self::$allergyById = $byId;

            return;
        }

        if ($category === 'symptom') {
            if (self::$symptomByName !== null) {
                return;
            }
            [$byName, $byId] = self::parseCsv(
                BASE_PATH . '/' . self::DATASET_SYMPTOMS,
                'symptom_id',
                'symptom_name',
                'category',
                'symptom',
                true,
                null,
                'related_body_system'
            );
            self::$symptomByName = $byName;
            self::$symptomById = $byId;

            return;
        }

        if (self::$conditionByName !== null) {
            return;
        }
        [$byName, $byId] = self::parseCsv(
            BASE_PATH . '/' . self::DATASET_CONDITIONS,
            'condition_id',
            'condition_name',
            'category',
            'condition',
            true,
            'icd10_code'
        );
        self::$conditionByName = $byName;
        self::$conditionById = $byId;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private static function parseCsv(
        string $path,
        string $idCol,
        string $nameCol,
        string $categoryCol,
        string $recordCategory,
        bool $extended = false,
        ?string $icd10Col = null,
        ?string $bodySystemCol = null
    ): array {
        $byName = [];
        $byId = [];

        if (!is_readable($path)) {
            return [$byName, $byId];
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [$byName, $byId];
        }

        $header = fgetcsv($handle);
        $cols = ['id' => 0, 'name' => 1, 'cat' => 2, 'desc' => 3, 'source' => 4, 'icd10' => -1, 'body' => -1];
        if (is_array($header)) {
            foreach (
                [
                    'id'     => $idCol,
                    'name'   => $nameCol,
                    'cat'    => $categoryCol,
                    'desc'   => 'description',
                    'source' => 'source',
                    'icd10'  => $icd10Col ?? 'icd10_code',
                    'body'   => $bodySystemCol,
                ] as $key => $col
            ) {
                if ($col === null || $col === '') {
                    continue;
                }
                $idx = array_search($col, $header, true);
                if ($idx !== false) {
                    $cols[$key] = (int) $idx;
                }
            }
        }

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim($row[$cols['name']] ?? '');
            if ($name === '') {
                continue;
            }
            $id = (int) ($row[$cols['id']] ?? 0);
            $icd10 = ($cols['icd10'] >= 0) ? trim($row[$cols['icd10']] ?? '') : '';
            $bodySystem = ($cols['body'] >= 0) ? trim($row[$cols['body']] ?? '') : '';
            $entry = [
                'record_id'         => $id,
                'name'              => $name,
                'dataset_category'  => trim($row[$cols['cat']] ?? '') ?: null,
                'record_category'   => $recordCategory,
                'description'       => $extended ? (trim($row[$cols['desc']] ?? '') ?: null) : null,
                'source'            => $extended ? (trim($row[$cols['source']] ?? '') ?: null) : null,
                'icd10_code'        => $icd10 !== '' ? $icd10 : null,
                'related_body_system' => $bodySystem !== '' ? $bodySystem : null,
            ];
            $key = mb_strtolower($name);
            if (!isset($byName[$key])) {
                $byName[$key] = $entry;
            }
            if ($id > 0) {
                $byId[$id] = $entry;
            }
        }
        fclose($handle);

        return [$byName, $byId];
    }

    /**
     * @deprecated Use validateFromFuzzyMatching() after RapidFuzz step.
     */
    public static function validateProfile(array $allergyQueue, array $conditionQueue): array
    {
        $translation = [
            'allergies'  => ['validation_queue' => $allergyQueue],
            'conditions' => ['validation_queue' => $conditionQueue],
        ];
        $fuzzy = MedicalFuzzyMatcher::matchProfile($translation);

        return self::validateFromFuzzyMatching($fuzzy);
    }

    private static function overallStatus(int $valid, int $invalid, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($invalid === 0) {
            return 'complete';
        }
        if ($valid > 0) {
            return 'partial';
        }

        return 'failed';
    }

    private static function overallLabel(string $status, int $valid, int $invalid, int $total): string
    {
        return match ($status) {
            'complete' => "All {$total} term(s) valid in official datasets — registration allowed",
            'partial'  => "{$valid}/{$total} valid, {$invalid} blocked from registration",
            'failed'   => "0/{$total} valid — registration blocked",
            default    => 'No terms to validate against datasets',
        };
    }

    private static function fieldStatus(int $valid, int $invalid, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($invalid === 0) {
            return 'complete';
        }
        if ($valid > 0) {
            return 'partial';
        }

        return 'failed';
    }

    private static function fieldLabel(string $status, int $valid, int $invalid, int $total): string
    {
        return match ($status) {
            'complete' => "All {$total} term(s) verified in dataset",
            'partial'  => "{$valid} valid, {$invalid} invalid (blocked)",
            'failed'   => "All {$total} term(s) invalid — not in official dataset",
            default    => 'No terms to validate',
        };
    }

    /** @return array<string, mixed> */
    private static function emptyFieldResult(string $category): array
    {
        $meta = self::datasetMeta($category);

        return [
            'field'          => self::fieldNameForCategory($category),
            'category'       => $category,
            'dataset_source' => $meta['dataset_source'],
            'dataset_table'  => $meta['dataset_table'],
            'status'         => 'empty',
            'status_label'   => 'No terms reached dataset validation',
            'valid_count'    => 0,
            'invalid_count'  => 0,
            'total_count'    => 0,
            'results'        => [],
        ];
    }
}
