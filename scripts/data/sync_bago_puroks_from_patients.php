<?php
/**
 * Merge distinct patient_registration puroks into barangay_puroks table per Bago barangay.
 * Usage: php scripts/data/sync_bago_puroks_from_patients.php
 */
declare(strict_types=1);

$base = dirname(dirname(__DIR__));
require_once $base . '/bootstrap.php';
require_once $base . '/config/db.php';
require_once $base . '/app/includes/bago_barangay_puroks.php';
require_once $base . '/app/includes/bhw_scope.php';

barangays_ensure_bago_city($pdo);
BagoBarangayPuroks::ensureSchema($pdo);

if (!in_array('purok', bhw_pr_columns($pdo), true)) {
    echo "patient_registrations.purok column missing.\n";
    exit(1);
}

$brgys = barangays_list_bago_city($pdo);
$insert = $pdo->prepare("
    INSERT IGNORE INTO barangay_puroks (barangay_id, purok_name, source)
    VALUES (?, ?, 'patient')
");
$total = 0;

foreach ($brgys as $brgy) {
    $id = (int) ($brgy['id'] ?? 0);
    $name = trim((string) ($brgy['name'] ?? ''));
    if ($id <= 0 || $name === '') {
        continue;
    }

    $params = [$name];
    $sql = "
        SELECT DISTINCT TRIM(pr.purok) AS purok
        FROM patient_registrations pr
        WHERE pr.purok IS NOT NULL AND TRIM(pr.purok) != ''
          AND LOWER(TRIM(pr.barangay)) = LOWER(?)
    ";
    if (in_array('barangay_id', bhw_pr_columns($pdo), true)) {
        $sql = "
            SELECT DISTINCT TRIM(pr.purok) AS purok
            FROM patient_registrations pr
            WHERE pr.purok IS NOT NULL AND TRIM(pr.purok) != ''
              AND (pr.barangay_id = ? OR LOWER(TRIM(pr.barangay)) = LOWER(?))
        ";
        $params = [$id, $name];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $purok) {
        $p = trim((string) $purok);
        if ($p === '') {
            continue;
        }
        $insert->execute([$id, $p]);
        $n++;
        $total++;
    }
    echo "{$name}: {$n} patient purok(s)\n";
}

BagoBarangayPuroks::seedFromCatalog($pdo);
echo "Done. Upserted {$total} patient purok row(s); reference catalog re-seeded.\n";
