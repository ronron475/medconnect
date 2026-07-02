<?php
/**
 * Detects invalid medical profile entries after dataset validation.
 * Rejects submission when any term is not in official datasets — no new records are created or inferred.
 */

final class MedicalInvalidEntryDetector
{
    public const POLICY_NO_CREATE = true;

    public const POLICY_NO_INFER = true;

    /** @var array<string, string> */
    private const FAILURE_MESSAGES = [
        'rejected_at_fuzzy' => 'No close match was found in the official %s list (below 85%% similarity).',
        'not_in_dataset'    => '"%s" is not listed in our official %s database.',
        'id_mismatch'       => '"%s" could not be verified against the official %s record.',
        'missing_standard_term' => '"%s" could not be standardized for validation.',
        'unknown'           => '"%s" is not a recognized %s in our system.',
    ];

    /** @var array<string, string> */
    private const USER_HINTS = [
        'rejected_at_fuzzy' => 'Check spelling, use English if possible, or pick a condition/allergy from the approved list. We cannot add new medical terms.',
        'not_in_dataset'    => 'Only conditions and allergies already in our hospital dataset can be saved. We do not create or guess new entries.',
        'id_mismatch'       => 'Please re-enter the term or contact support if you believe this is an error.',
        'missing_standard_term' => 'Try rephrasing the entry using a known medical term.',
        'unknown'           => 'This entry cannot be saved until it matches an official dataset record.',
    ];

    /**
     * @param array<string, mixed> $datasetValidation
     * @return array<string, mixed>
     */
    public static function detect(array $datasetValidation): array
    {
        $invalidEntries = self::collectInvalidEntries($datasetValidation);
        $hasInvalid = $invalidEntries !== [];
        $registration = $datasetValidation['registration'] ?? [];
        $eligible = (bool) ($datasetValidation['registration_eligible'] ?? $registration['eligible'] ?? false);

        $submissionRejected = $hasInvalid || ($registration['rejected_count'] ?? 0) > 0;
        $validationStatus = $submissionRejected ? 'rejected' : ($eligible ? 'approved' : 'empty');

        $userMessage = self::buildUserMessage($invalidEntries, $submissionRejected, $eligible);
        $summaryMessage = self::buildSummaryMessage($invalidEntries, $submissionRejected);

        return [
            'validation_status'     => $validationStatus,
            'submission_rejected'   => $submissionRejected,
            'submission_accepted'   => !$submissionRejected && $eligible,
            'save_allowed'          => !$submissionRejected && $eligible,
            'invalid_count'         => count($invalidEntries),
            'invalid_entries'       => $invalidEntries,
            'failure_reasons'       => array_values(array_unique(array_column($invalidEntries, 'failure_reason'))),
            'user_message'          => $userMessage,
            'summary_message'       => $summaryMessage,
            'error_message'         => $submissionRejected ? $userMessage : null,
            'policy'                => [
                'create_new_records' => false,
                'infer_new_terms'    => false,
                'dataset_only'       => true,
                'description'        => 'MedConnect only stores conditions and allergies that exist in the official CSV datasets.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $datasetValidation
     * @return list<array<string, mixed>>
     */
    private static function collectInvalidEntries(array $datasetValidation): array
    {
        $entries = [];

        foreach (['conditions', 'symptoms', 'allergies'] as $field) {
            $block = $datasetValidation[$field] ?? [];
            foreach ($block['results'] ?? [] as $row) {
                if (($row['final_status'] ?? '') === 'valid') {
                    continue;
                }
                $category = match ($field) {
                    'allergies' => 'allergy',
                    'symptoms'  => 'symptom',
                    default     => 'condition',
                };
                $entries[] = self::formatInvalidEntry($row, $category);
            }
        }

        $registration = $datasetValidation['registration'] ?? [];
        if ($entries === [] && !empty($registration['rejected'])) {
            foreach ($registration['rejected'] as $rej) {
                $entries[] = self::formatInvalidEntry($rej, (string) ($rej['category'] ?? 'condition'));
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatInvalidEntry(array $row, string $category): array
    {
        $displayTerm = self::displayTerm($row);
        $reasonCode = (string) ($row['validation_result'] ?? 'unknown');
        $datasetLabel = match ($category) {
            'allergy'  => 'allergy',
            'symptom'  => 'symptom',
            default    => 'medical condition',
        };
        $datasetTable = (string) ($row['dataset_table'] ?? match ($category) {
            'allergy'  => 'allergies',
            'symptom'  => 'symptoms',
            default    => 'medical_conditions',
        });

        $failureReason = match ($reasonCode) {
            'rejected_at_fuzzy'     => 'no_dataset_match_fuzzy',
            'not_in_dataset'        => 'not_in_official_dataset',
            'id_mismatch'           => 'dataset_record_mismatch',
            'missing_standard_term' => 'missing_standardized_term',
            default                 => 'not_in_official_dataset',
        };

        $technicalMessage = (string) ($row['validation_message'] ?? '');
        $sprintfKey = $reasonCode;
        if (!isset(self::FAILURE_MESSAGES[$sprintfKey])) {
            $sprintfKey = 'unknown';
        }
        $detail = sprintf(
            self::FAILURE_MESSAGES[$sprintfKey],
            $displayTerm,
            $datasetLabel
        );

        $userFriendly = sprintf(
            '“%s” cannot be saved as a %s. %s',
            $displayTerm,
            $datasetLabel,
            self::USER_HINTS[$sprintfKey] ?? self::USER_HINTS['unknown']
        );

        return [
            'local_term'           => (string) ($row['local_term'] ?? ''),
            'english_term'         => (string) ($row['english_term'] ?? ''),
            'display_term'         => $displayTerm,
            'category'             => $category,
            'dataset_table'        => $datasetTable,
            'dataset_source'       => (string) ($row['dataset_source'] ?? ''),
            'failure_reason'       => $failureReason,
            'failure_reason_code'  => $reasonCode,
            'validation_status'    => 'invalid',
            'blocked'              => true,
            'detail_message'       => $technicalMessage !== '' ? $technicalMessage : $detail,
            'user_friendly_error'  => $userFriendly,
            'fuzzy_score'          => isset($row['fuzzy_score']) ? (int) $row['fuzzy_score'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function displayTerm(array $row): string
    {
        $local = trim((string) ($row['local_term'] ?? ''));
        $english = trim((string) ($row['english_term'] ?? ''));
        $standard = trim((string) ($row['standardized_term'] ?? ''));

        if ($local !== '') {
            return $local;
        }
        if ($english !== '') {
            return $english;
        }

        return $standard !== '' ? $standard : 'Unknown term';
    }

    /**
     * @param list<array<string, mixed>> $invalidEntries
     */
    private static function buildUserMessage(array $invalidEntries, bool $rejected, bool $eligible): string
    {
        if (!$rejected && $eligible) {
            return 'All medical terms were verified against the official datasets. You may proceed with registration.';
        }

        if (!$rejected) {
            return 'No medical terms were submitted for validation.';
        }

        if (count($invalidEntries) === 1) {
            return $invalidEntries[0]['user_friendly_error'];
        }

        $terms = array_map(static fn ($e) => '“' . ($e['display_term'] ?? '') . '”', $invalidEntries);
        $list = implode(', ', array_slice($terms, 0, 3));
        if (count($terms) > 3) {
            $list .= ', and others';
        }

        return count($invalidEntries) . ' entries could not be verified: ' . $list . '. '
            . 'MedConnect does not create or save new conditions or allergies that are not in the official datasets. '
            . 'Please correct or remove invalid entries before submitting.';
    }

    /**
     * @param list<array<string, mixed>> $invalidEntries
     */
    private static function buildSummaryMessage(array $invalidEntries, bool $rejected): string
    {
        if (!$rejected) {
            return 'Submission passed invalid-entry checks.';
        }

        $count = count($invalidEntries);

        return $count === 1
            ? 'Submission rejected: 1 invalid entry detected.'
            : "Submission rejected: {$count} invalid entries detected.";
    }
}
