<?php
/**
 * End-to-end Step 3 validation: Hiligaynon/English → dictionary translation →
 * multi-dataset match (conditions, symptoms, allergies) → dataset check → recognition UI.
 */

final class MedicalValidationWorkflow
{
    public const WORKFLOW_VERSION = '1.2';

    /**
     * @return array<string, mixed>
     */
    public static function run(string $allergies, string $conditions): array
    {
        $allergies = trim($allergies);
        $conditions = trim($conditions);

        $preprocessing = NlpPreprocessor::preprocessProfile($allergies, $conditions);
        $translation = MedicalTranslationPipeline::run($preprocessing, $conditions, $allergies);

        $conditionsFuzzy = MedicalFuzzyMatcher::matchTextQueue(
            $translation['conditions']['validation_queue'] ?? []
        );
        $allergiesFuzzy = MedicalFuzzyMatcher::matchQueue(
            $translation['allergies']['validation_queue'] ?? [],
            'allergy'
        );

        $fuzzyMatching = self::buildFuzzyMatching($conditionsFuzzy, $allergiesFuzzy);
        $datasetValidation = self::buildDatasetValidation($conditionsFuzzy, $allergiesFuzzy);
        $invalidDetection = MedicalInvalidEntryDetector::detect($datasetValidation);

        $termResults = self::buildTermResults($translation, $conditionsFuzzy, $allergiesFuzzy, $datasetValidation);
        $summary = self::buildSummary($termResults, $invalidDetection);

        $conditionsTerms = array_values(array_filter(
            $termResults,
            static fn (array $t): bool => ($t['field'] ?? '') === 'conditions'
        ));
        $allergiesTerms = array_values(array_filter(
            $termResults,
            static fn (array $t): bool => ($t['field'] ?? '') === 'allergies'
        ));

        return MedicalProfilePipelineSteps::enrich([
            'workflow' => [
                'version' => MedicalProfilePipelineSteps::workflowMeta()['version'],
                'steps'   => MedicalProfilePipelineSteps::workflowMeta()['steps'],
                'policy'  => MedicalProfilePipelineSteps::workflowMeta()['policy'],
            ],
            'preprocessing'              => $preprocessing,
            'translation'              => $translation,
            'fuzzy_matching'           => $fuzzyMatching,
            'dataset_validation'     => $datasetValidation,
            'invalid_entry_detection' => $invalidDetection,
            'registration'             => $datasetValidation['registration'] ?? [],
            'registration_eligible'  => (bool) ($datasetValidation['registration_eligible'] ?? false),
            'submission_rejected'      => (bool) ($invalidDetection['submission_rejected'] ?? false),
            'submission_accepted'      => (bool) ($invalidDetection['submission_accepted'] ?? false),
            'save_allowed'             => (bool) ($invalidDetection['save_allowed'] ?? false),
            'term_results'             => $termResults,
            'matched_records'          => $datasetValidation['matched_records'] ?? [],
            'conditions_recognition'   => array_merge(
                MedicalRecognitionHelper::buildFieldRecognition(
                    $conditions,
                    $preprocessing['conditions'] ?? [],
                    $translation['conditions'] ?? [],
                    $conditionsTerms
                ),
                ['detected_language' => MedicalRecognitionHelper::detectFieldLanguage(
                    $preprocessing['conditions'] ?? [],
                    $translation['conditions'] ?? []
                )]
            ),
            'allergies_recognition'    => array_merge(
                MedicalRecognitionHelper::buildFieldRecognition(
                    $allergies,
                    $preprocessing['allergies'] ?? [],
                    $translation['allergies'] ?? [],
                    $allergiesTerms
                ),
                ['detected_language' => MedicalRecognitionHelper::detectFieldLanguage(
                    $preprocessing['allergies'] ?? [],
                    $translation['allergies'] ?? []
                )]
            ),
            'summary'                  => $summary,
            'allergies_text'           => $allergies,
            'medications_text'         => $conditions,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildFuzzyMatching(array $conditionsFuzzy, array $allergiesFuzzy): array
    {
        $accepted = ($conditionsFuzzy['accepted_count'] ?? 0) + ($allergiesFuzzy['accepted_count'] ?? 0);
        $rejected = ($conditionsFuzzy['rejected_count'] ?? 0) + ($allergiesFuzzy['rejected_count'] ?? 0);
        $total = ($conditionsFuzzy['total_count'] ?? 0) + ($allergiesFuzzy['total_count'] ?? 0);

        if ($total === 0) {
            $overall = 'empty';
            $label = 'No terms to fuzzy match';
        } elseif ($accepted === $total) {
            $overall = 'complete';
            $label = 'RapidFuzz: ' . $accepted . '/' . $total . ' terms accepted (≥85%)';
        } elseif ($accepted > 0) {
            $overall = 'partial';
            $label = 'RapidFuzz: ' . $accepted . '/' . $total . ' accepted, ' . $rejected . ' unrecognized';
        } else {
            $overall = 'none';
            $label = 'RapidFuzz: 0/' . $total . ' terms met 85% threshold';
        }

        return [
            'conditions'           => $conditionsFuzzy,
            'allergies'            => $allergiesFuzzy,
            'overall_status'       => $overall,
            'overall_status_label' => $label,
            'threshold'            => MedicalFuzzyMatcher::ACCEPT_THRESHOLD,
            'engine'               => $conditionsFuzzy['engine'] ?? $allergiesFuzzy['engine'] ?? 'rapidfuzz',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildDatasetValidation(array $conditionsFuzzy, array $allergiesFuzzy): array
    {
        $conditionsAnalysis = MedicalDatasetValidator::validateTextAnalysis($conditionsFuzzy);
        $allergies = MedicalDatasetValidator::validateFieldResults(
            $allergiesFuzzy['results'] ?? [],
            'allergy'
        );

        $valid = ($conditionsAnalysis['valid_count'] ?? 0) + ($allergies['valid_count'] ?? 0);
        $invalid = ($conditionsAnalysis['invalid_count'] ?? 0) + ($allergies['invalid_count'] ?? 0);
        $total = $valid + $invalid;

        if ($total === 0) {
            $overall = 'empty';
            $overallLabel = 'No terms to validate against datasets';
        } elseif ($invalid === 0) {
            $overall = 'complete';
            $overallLabel = "All {$total} term(s) valid in official datasets — registration allowed";
        } elseif ($valid > 0) {
            $overall = 'partial';
            $overallLabel = "{$valid}/{$total} valid, {$invalid} blocked from registration";
        } else {
            $overall = 'failed';
            $overallLabel = "0/{$total} valid — registration blocked";
        }

        $matchedRecords = array_merge(
            $conditionsAnalysis['matched_records'] ?? [],
            self::matchedRecordsFromField($allergies)
        );

        $registration = self::buildRegistrationGate($conditionsAnalysis, $allergies);

        return [
            'conditions'             => $conditionsAnalysis['conditions'] ?? [],
            'symptoms'               => $conditionsAnalysis['symptoms'] ?? [],
            'allergies'              => $allergies,
            'matched_records'        => $matchedRecords,
            'registration'           => $registration,
            'overall_status'         => $overall,
            'overall_status_label'   => $overallLabel,
            'registration_eligible'  => (bool) ($registration['eligible'] ?? false),
            'valid_count'            => $valid,
            'invalid_count'          => $invalid,
            'total_count'            => $total,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function matchedRecordsFromField(array $fieldValidation): array
    {
        $records = [];
        foreach ($fieldValidation['results'] ?? [] as $row) {
            if (($row['final_status'] ?? '') !== 'valid' || empty($row['record'])) {
                continue;
            }
            $rec = $row['record'];
            $records[] = [
                'term_type'           => (string) ($row['category'] ?? 'allergy'),
                'local_term'          => (string) ($row['local_term'] ?? ''),
                'english_term'        => (string) ($row['english_term'] ?? ''),
                'standardized_term'   => (string) ($rec['name'] ?? ''),
                'record_id'           => (int) ($rec['record_id'] ?? 0),
                'dataset_table'       => (string) ($row['dataset_table'] ?? ''),
                'dataset_source'      => (string) ($row['dataset_source'] ?? ''),
                'dataset_category'    => $rec['dataset_category'] ?? null,
                'description'         => $rec['description'] ?? null,
                'icd10_code'          => $rec['icd10_code'] ?? null,
                'related_body_system' => $rec['related_body_system'] ?? null,
                'validation_status'   => 'valid',
            ];
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildRegistrationGate(array $conditionsAnalysis, array $allergies): array
    {
        $acceptedConditions = self::registrationItems($conditionsAnalysis['conditions']['results'] ?? []);
        $acceptedSymptoms = self::registrationItems($conditionsAnalysis['symptoms']['results'] ?? []);
        $acceptedAllergies = self::registrationItems($allergies['results'] ?? []);

        $rejected = array_merge(
            self::rejectedItems($conditionsAnalysis['conditions']['results'] ?? []),
            self::rejectedItems($conditionsAnalysis['symptoms']['results'] ?? []),
            self::rejectedItems($allergies['results'] ?? [])
        );

        $total = ($conditionsAnalysis['total_count'] ?? 0) + ($allergies['total_count'] ?? 0);
        $invalid = ($conditionsAnalysis['invalid_count'] ?? 0) + ($allergies['invalid_count'] ?? 0);
        $eligible = $total > 0 && $invalid === 0;

        return [
            'eligible'           => $eligible,
            'eligible_label'     => $eligible
                ? 'All terms verified — safe to save for registration'
                : ($total > 0
                    ? 'Registration blocked — one or more terms are not in the official datasets'
                    : 'No medical terms to register'),
            'conditions'         => $acceptedConditions,
            'symptoms'           => $acceptedSymptoms,
            'allergies'          => $acceptedAllergies,
            'rejected'           => $rejected,
            'accepted_count'     => count($acceptedConditions) + count($acceptedSymptoms) + count($acceptedAllergies),
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
                'local_term'        => $row['local_term'] ?? '',
                'standardized_term' => $rec['name'],
                'record_id'         => $rec['record_id'],
                'term_type'         => $row['category'] ?? '',
                'category'          => $rec['record_category'] ?? $row['category'] ?? '',
                'dataset_category'  => $rec['dataset_category'] ?? null,
                'dataset_source'    => $row['dataset_source'] ?? '',
                'dataset_table'     => $row['dataset_table'] ?? '',
                'icd10_code'        => $rec['icd10_code'] ?? null,
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
     * @param list<array<string, mixed>> $termResults
     */
    private static function buildSummary(array $termResults, array $invalidDetection): string
    {
        if ($termResults === []) {
            return 'No medical terms were extracted from your input.';
        }

        $parts = [];
        foreach ($termResults as $term) {
            $type = (string) ($term['term_type'] ?? 'condition');
            $label = ucfirst($type);
            $input = (string) ($term['original_local'] ?? $term['english_term'] ?? '');
            if (($term['display_status'] ?? '') === 'valid') {
                $standard = (string) ($term['standardized_term'] ?? $term['english_term'] ?? '');
                $parts[] = "{$label}: {$input} → {$standard} (verified)";
                continue;
            }
            $parts[] = "{$label}: {$input} (not in official dataset)";
        }

        $summary = implode('. ', $parts) . '.';
        if (!empty($invalidDetection['submission_rejected'])) {
            $userMsg = trim((string) ($invalidDetection['user_message'] ?? ''));
            if ($userMsg !== '') {
                return $userMsg;
            }
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildTermResults(
        array $translation,
        array $conditionsFuzzy,
        array $allergiesFuzzy,
        array $datasetValidation
    ): array {
        $results = [];
        $results = array_merge(
            $results,
            self::fieldTermResults(
                'conditions',
                $translation['conditions'] ?? [],
                $conditionsFuzzy,
                $datasetValidation
            )
        );
        $results = array_merge(
            $results,
            self::fieldTermResults(
                'allergies',
                $translation['allergies'] ?? [],
                $allergiesFuzzy,
                $datasetValidation,
                true
            )
        );

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fieldTermResults(
        string $field,
        array $translationField,
        array $fuzzyField,
        array $datasetValidation,
        bool $allergyOnly = false
    ): array {
        $fuzzyByEnglish = [];
        foreach ($fuzzyField['results'] ?? [] as $row) {
            $key = mb_strtolower((string) ($row['english_term'] ?? $row['match_term'] ?? $row['input_term'] ?? ''));
            if ($key !== '') {
                $fuzzyByEnglish[$key] = $row;
            }
        }

        $datasetByEnglish = [];
        $datasetBlocks = $allergyOnly
            ? ['allergies']
            : ['conditions', 'symptoms'];
        foreach ($datasetBlocks as $blockName) {
            foreach (($datasetValidation[$blockName]['results'] ?? []) as $row) {
                $key = mb_strtolower((string) ($row['english_term'] ?? ''));
                if ($key !== '') {
                    $datasetByEnglish[$key] = $row;
                }
            }
        }

        $itemsByEnglish = [];
        foreach ($translationField['items'] ?? [] as $item) {
            $key = mb_strtolower((string) ($item['english_term'] ?? ''));
            if ($key !== '') {
                $itemsByEnglish[$key] = $item;
            }
        }

        $queue = $translationField['validation_queue'] ?? [];
        if ($queue === []) {
            $queue = $translationField['items'] ?? [];
        }

        $terms = [];
        foreach ($queue as $queueItem) {
            $english = (string) ($queueItem['english_term'] ?? $queueItem['match_term'] ?? '');
            $key = mb_strtolower($english);
            $item = $itemsByEnglish[$key] ?? $queueItem;
            $fuzzy = $fuzzyByEnglish[$key] ?? null;
            $dataset = $datasetByEnglish[$key] ?? null;

            $datasetValid = ($dataset['final_status'] ?? '') === 'valid';
            $fuzzyAccepted = ($fuzzy['validation_status'] ?? '') === 'accepted';
            $displayValid = $datasetValid && $fuzzyAccepted;
            $termType = MedicalRecognitionHelper::termTypeLabel(
                $field,
                (string) ($dataset['category'] ?? $fuzzy['category'] ?? $fuzzy['dataset_category'] ?? '')
            );
            $datasetLabel = match ($termType) {
                'allergy'   => 'allergies',
                'symptom'   => 'symptoms',
                default     => 'medical conditions',
            };

            $terms[] = [
                'field'              => $field,
                'term_type'          => $termType,
                'original_local'     => (string) ($item['local_term'] ?? $queueItem['local_term'] ?? ''),
                'input_language'     => (string) ($item['input_language'] ?? $queueItem['input_language'] ?? 'unknown'),
                'was_translated'     => (bool) ($item['was_translated'] ?? $queueItem['was_translated'] ?? false),
                'english_term'       => $english,
                'standardized_term'  => $displayValid
                    ? (string) ($dataset['record']['name'] ?? $fuzzy['standardized_term'] ?? $english)
                    : ($fuzzyAccepted ? (string) ($fuzzy['standardized_term'] ?? '') : null),
                'dataset_record_id'  => $displayValid ? ($dataset['record']['record_id'] ?? null) : null,
                'dataset_table'      => $displayValid ? (string) ($dataset['dataset_table'] ?? '') : null,
                'matched_record'     => $displayValid ? ($dataset['record'] ?? null) : null,
                'fuzzy_score'        => (int) ($fuzzy['similarity_score'] ?? 0),
                'translation_status' => (string) ($item['status'] ?? ''),
                'match_language'     => 'english',
                'dataset_valid'      => $datasetValid,
                'display_status'     => $displayValid ? 'valid' : 'invalid',
                'highlight'          => $displayValid,
                'user_message'       => $displayValid
                    ? 'Found in official ' . $datasetLabel . ' dataset.'
                    : self::invalidUserMessage($item, $fuzzy, $dataset, $termType),
            ];
        }

        return $terms;
    }

    /**
     * @param array<string, mixed>|null $fuzzy
     * @param array<string, mixed>|null $dataset
     */
    private static function invalidUserMessage(
        array $item,
        ?array $fuzzy,
        ?array $dataset,
        string $termType
    ): string {
        $local = (string) ($item['local_term'] ?? '');
        $english = (string) ($item['english_term'] ?? '');

        if (($item['was_translated'] ?? false) && $english !== '') {
            $base = "Translated “{$local}” → “{$english}”, but no matching official record was found.";
        } else {
            $base = "“{$english}” is not listed in the official {$termType} dataset.";
        }

        if (($fuzzy['validation_status'] ?? '') === 'unrecognized') {
            return $base . ' Only terms from Medical Conditions, Allergies, or Symptoms datasets are valid.';
        }

        if (($dataset['final_status'] ?? '') === 'invalid') {
            return (string) ($dataset['validation_message'] ?? $base);
        }

        return $base;
    }
}
