<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$result = MedicalValidationWorkflow::run(
    'Penicillin',
    'May alta presyon ko kag sakit ulo'
);

echo "Summary: " . ($result['summary'] ?? '') . "\n";
echo "Matched records: " . count($result['matched_records'] ?? []) . "\n";
echo "Conditions recognition translated: " . ($result['conditions_recognition']['translated_english'] ?? '') . "\n";
echo "Has highlight: " . (strpos($result['conditions_recognition']['highlighted_english'] ?? '', '<mark') !== false ? 'yes' : 'no') . "\n";
foreach ($result['term_results'] ?? [] as $term) {
    echo '  [' . ($term['term_type'] ?? '') . '] ' . ($term['original_local'] ?? '') . ' → ' . ($term['standardized_term'] ?? 'INVALID') . "\n";
}
