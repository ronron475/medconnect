<?php
require dirname(__DIR__, 2) . '/bootstrap/app.php';
require dirname(__DIR__, 2) . '/app/includes/bhw_triage_nlp.php';

$complaint = $argv[1] ?? 'may hubag ako kag luya';
$start = microtime(true);
$pipeline = bhw_run_chief_complaint_nlp($complaint);
$elapsed = round(microtime(true) - $start, 2);

echo "Elapsed: {$elapsed}s\n";
echo 'Engine: ' . ($pipeline['engine'] ?? '?') . "\n";
echo 'Service used: ' . (($pipeline['service_used'] ?? false) ? 'yes' : 'no') . "\n";
