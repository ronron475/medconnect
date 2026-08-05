<?php
define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/includes/nlp_inventory.php';

$catalog = nlp_inventory_catalog();
$summary = nlp_inventory_summary($catalog);

echo "=== NLP INVENTORY SUMMARY ===\n";
echo json_encode($summary, JSON_PRETTY_PRINT) . "\n\n";

$byCat = [];
foreach ($catalog as $row) {
    $cat = $row['category'];
    if (!isset($byCat[$cat])) {
        $byCat[$cat] = ['datasets' => 0, 'rows' => 0, 'loaded' => 0];
    }
    $byCat[$cat]['datasets']++;
    $byCat[$cat]['rows'] += (int) $row['rows'];
    if ($row['status'] === 'loaded') {
        $byCat[$cat]['loaded']++;
    }
}

echo "=== BY CATEGORY ===\n";
foreach ($byCat as $cat => $stats) {
    printf("%-20s  datasets=%d  loaded=%d  rows=%d\n", $cat, $stats['datasets'], $stats['loaded'], $stats['rows']);
}

echo "\n=== TOP DATASETS BY ROW COUNT ===\n";
usort($catalog, static fn($a, $b) => $b['rows'] <=> $a['rows']);
foreach (array_slice($catalog, 0, 20) as $row) {
    if ($row['rows'] > 0) {
        printf("%-45s %8d  [%s]\n", $row['label'], $row['rows'], $row['status']);
    }
}
