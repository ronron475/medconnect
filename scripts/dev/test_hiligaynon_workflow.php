<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$cases = [
    ['May alta presyon ko.', ''],
    ['', 'May allergy ako sa penicillin'],
    ['May diabetes kag hubak ako.', ''],
    ['Hypertension', 'Penicillin'],
];

foreach ($cases as [$cond, $allergy]) {
    echo "=== conditions: {$cond} | allergies: {$allergy} ===\n";
    $r = MedicalValidationWorkflow::run($allergy, $cond);
    foreach ($r['term_results'] as $t) {
        echo sprintf(
            "  [%s] %s → EN: %s | STD: %s | %s | %s\n",
            $t['display_status'],
            $t['original_local'],
            $t['english_term'],
            $t['standardized_term'] ?? '—',
            $t['input_language'],
            $t['user_message']
        );
    }
    echo 'Eligible: ' . ($r['registration_eligible'] ? 'yes' : 'no') . "\n\n";
}
