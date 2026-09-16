<?php
/**
 * Assign existing patients to their correct Bago City barangay for BHW access.
 * Updates only barangay_id on patient_registrations (+ mirror on patient users).
 * Does not create accounts or change personal/medical data.
 *
 * Run: php scripts/dev/assign_patient_barangays.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/barangays_bago.php';
require_once dirname(__DIR__, 2) . '/app/includes/bhw_scope.php';
require_once dirname(__DIR__, 2) . '/app/includes/bhw_workflows.php';
require_once dirname(__DIR__, 2) . '/app/core/BagoBarangayCentroids.php';

barangays_ensure_bago_city($pdo);
patient_registrations_ensure_barangay_id($pdo);

$aliased = patient_registrations_backfill_barangay_ids_aliased($pdo);
$synced = patients_sync_users_barangay_id($pdo);

// Patients with GIS/location barangay but no users.barangay_id yet (no inventing PR rows).
$locSynced = 0;
try {
    $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $locExists = (bool) $pdo->query("SHOW TABLES LIKE 'patient_locations'")->fetchColumn();
    if ($locExists && in_array('barangay_id', $userCols, true)) {
        $rows = $pdo->query("
            SELECT u.id AS user_id, COALESCE(NULLIF(TRIM(pl.canonical_barangay), ''), NULLIF(TRIM(pl.barangay), '')) AS bname
            FROM users u
            INNER JOIN patient_locations pl ON pl.patient_id = u.id
            WHERE u.role = 'patient'
              AND (u.barangay_id IS NULL OR u.barangay_id = 0)
              AND (
                (pl.canonical_barangay IS NOT NULL AND TRIM(pl.canonical_barangay) <> '')
                OR (pl.barangay IS NOT NULL AND TRIM(pl.barangay) <> '')
              )
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upd = $pdo->prepare('UPDATE users SET barangay_id = ? WHERE id = ? AND role = ? AND (barangay_id IS NULL OR barangay_id = 0)');
        foreach ($rows as $row) {
            $resolved = barangay_resolve_id_by_name($pdo, (string) ($row['bname'] ?? ''));
            if ($resolved === null) {
                continue;
            }
            $upd->execute([$resolved, (int) $row['user_id'], 'patient']);
            if ($upd->rowCount() > 0) {
                $locSynced++;
            }
        }
    }
} catch (Throwable $e) {
    echo 'WARN location sync: ' . $e->getMessage() . PHP_EOL;
}

echo "Updated registration barangay_id rows: {$aliased}\n";
echo "Synced users.barangay_id from registrations: {$synced}\n";
echo "Synced users.barangay_id from locations: {$locSynced}\n\n";

echo "=== patient_registrations ===\n";
$rows = $pdo->query("
    SELECT pr.id, pr.email, pr.barangay, pr.barangay_id, b.name AS resolved_name
    FROM patient_registrations pr
    LEFT JOIN barangays b ON b.id = pr.barangay_id
    ORDER BY COALESCE(b.name, pr.barangay), pr.id
");
foreach ($rows as $r) {
    echo sprintf(
        "#%d  %s  text=%s  id=%s  name=%s\n",
        (int) $r['id'],
        (string) $r['email'],
        (string) $r['barangay'],
        $r['barangay_id'] ?? 'NULL',
        $r['resolved_name'] ?? '(unassigned)'
    );
}

echo "\n=== BHW patient list verification ===\n";
$bhws = $pdo->query("
    SELECT u.id, u.email, u.barangay_id, b.name AS barangay_name
    FROM users u
    LEFT JOIN barangays b ON b.id = u.barangay_id
    WHERE u.role = 'bhw' AND u.is_active = 1 AND u.barangay_id IS NOT NULL AND u.barangay_id > 0
    ORDER BY b.name, u.email
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($bhws as $bhw) {
    $ctx = [
        'allowed' => true,
        'barangay_id' => (int) $bhw['barangay_id'],
        'barangay_name' => (string) ($bhw['barangay_name'] ?? ''),
    ];
    $patients = BhwWorkflows::listPatients($pdo, $ctx, '');
    $leak = 0;
    foreach ($patients as $p) {
        $check = $pdo->prepare('SELECT barangay_id, barangay FROM patient_registrations WHERE user_id = ? OR email = ? LIMIT 1');
        $check->execute([(int) $p['id'], (string) $p['email']]);
        $pr = $check->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$pr) {
            continue;
        }
        $pid = (int) ($pr['barangay_id'] ?? 0);
        $sameName = strcasecmp(trim((string) ($pr['barangay'] ?? '')), (string) $ctx['barangay_name']) === 0;
        if ($pid > 0 && $pid !== (int) $ctx['barangay_id'] && !$sameName) {
            $leak++;
        }
    }
    echo sprintf(
        "%s (%s): %d patient(s)%s\n",
        $bhw['email'],
        $ctx['barangay_name'],
        count($patients),
        $leak > 0 ? "  LEAK={$leak}" : ''
    );
    foreach ($patients as $p) {
        echo '  - ' . trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) . ' / ' . ($p['barangay'] ?? '') . PHP_EOL;
    }
}

$unassigned = (int) $pdo->query("
    SELECT COUNT(*) FROM patient_registrations
    WHERE barangay_id IS NULL OR barangay_id = 0
")->fetchColumn();
echo "\nUnassigned registrations: {$unassigned}\n";
