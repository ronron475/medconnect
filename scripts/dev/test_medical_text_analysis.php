<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$samples = [
    'May alta presyon ko kag sakit ulo. Allergic sa penicillin.',
    'hypertension, fever, shellfish allergy',
];

foreach ($samples as $text) {
    echo "\n=== Input: {$text}\n";
    $result = MedicalTextAnalysisWorkflow::analyze($text);
    echo 'Engine: ' . ($result['engine'] ?? 'php') . "\n";
    echo 'Language: ' . ($result['detected_language'] ?? '') . "\n";
    echo 'Translated: ' . ($result['translated_english'] ?? '') . "\n";
    echo 'Valid/invalid/total: '
        . ($result['valid_count'] ?? 0) . '/'
        . ($result['invalid_count'] ?? 0) . '/'
        . ($result['total_count'] ?? 0) . "\n";
    foreach ($result['term_results'] ?? [] as $term) {
        $std = $term['standardized_term'] ?? '—';
        echo '  [' . ($term['term_type'] ?? '') . '] '
            . ($term['original_local'] ?? '') . ' → ' . $std
            . ' (' . ($term['validation_status'] ?? '') . ")\n";
    }
}
