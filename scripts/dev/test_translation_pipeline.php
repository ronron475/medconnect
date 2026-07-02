<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$cases = [
    ['', 'May alta presyon ko.'],
    ['', 'May diabetes kag hubak ako.'],
    ['Penicillin', ''],
];

foreach ($cases as [$allergies, $conditions]) {
    $prep = NlpPreprocessor::preprocessProfile($allergies, $conditions);
    $trans = MedicalTranslator::translateProfile($prep);
    $fuzzy = MedicalFuzzyMatcher::matchProfile($trans);
    $val = MedicalDatasetValidator::validateFromFuzzyMatching($fuzzy);
    echo "=== conditions: {$conditions} | allergies: {$allergies} ===\n";
    echo 'Translation: ' . ($trans['overall_status'] ?? '') . ' — ' . ($trans['overall_status_label'] ?? '') . "\n";
    echo 'Fuzzy: ' . ($fuzzy['overall_status_label'] ?? '') . ' [' . ($fuzzy['engine'] ?? '') . "]\n";
    foreach ($fuzzy['conditions']['results'] ?? [] as $row) {
        echo '  - ' . ($row['english_term'] ?? '') . ' → ' . ($row['standardized_term'] ?? 'unrecognized')
            . ' (' . ($row['similarity_score'] ?? 0) . "%, " . ($row['validation_status'] ?? '') . ")\n";
    }
    echo 'Dataset: ' . ($val['overall_status_label'] ?? '') . "\n";
    $reg = $val['registration'] ?? [];
    echo 'Registration: ' . ($reg['eligible_label'] ?? '') . "\n";
    $inv = MedicalInvalidEntryDetector::detect($val);
    echo 'Invalid detection: ' . ($inv['validation_status'] ?? '') . ' — ' . ($inv['summary_message'] ?? '') . "\n";
    if ($inv['submission_rejected'] ?? false) {
        echo 'REJECTED: ' . ($inv['user_message'] ?? '') . "\n";
        foreach ($inv['invalid_entries'] ?? [] as $e) {
            echo '  - ' . ($e['display_term'] ?? '') . ': ' . ($e['failure_reason'] ?? '') . "\n";
        }
    }
    echo "\n";
}
