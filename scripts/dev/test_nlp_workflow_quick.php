<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$start = microtime(true);
$result = MedicalValidationWorkflow::run('Penicillin', 'Hypertension');
$elapsed = round(microtime(true) - $start, 2);

echo "elapsed={$elapsed}s\n";
echo 'term_results=' . count($result['term_results'] ?? []) . "\n";
echo 'has_summary=' . (isset($result['summary']) ? 'yes' : 'no') . "\n";
echo json_encode($result['term_results'] ?? [], JSON_PRETTY_PRINT) . "\n";
