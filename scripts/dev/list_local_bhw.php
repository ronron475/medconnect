<?php
/**
 * Read-only: list local BHW accounts for sync planning (never prints password hashes).
 */
require dirname(__DIR__, 2) . '/bootstrap.php';
require_once BASE_PATH . '/config/db.php';

$cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
$hasBarangayId = in_array('barangay_id', $cols, true);
$passCol = in_array('password', $cols, true) ? 'password' : (in_array('password_hash', $cols, true) ? 'password_hash' : null);

$sql = "SELECT id, email, first_name, last_name, phone, role, account_status, is_active,
               archived_at, created_at"
    . ($hasBarangayId ? ', barangay_id' : '')
    . ($passCol ? ", CASE WHEN {$passCol} IS NOT NULL AND {$passCol} <> '' THEN 1 ELSE 0 END AS has_password" : ', 0 AS has_password')
    . " FROM users WHERE LOWER(TRIM(role)) = 'bhw' ORDER BY id ASC";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo 'local_db=' . DB_NAME . PHP_EOL;
echo 'local_bhw_count=' . count($rows) . PHP_EOL;

$barangayNames = [];
try {
    foreach ($pdo->query('SELECT id, name FROM barangays') as $b) {
        $barangayNames[(int) $b['id']] = (string) $b['name'];
    }
} catch (Throwable $e) {
}

$byBarangay = [];
foreach ($rows as $r) {
    $bid = $hasBarangayId ? (int) ($r['barangay_id'] ?? 0) : 0;
    $bname = $barangayNames[$bid] ?? '(unknown/unassigned)';
    $byBarangay[$bname] = ($byBarangay[$bname] ?? 0) + 1;
    echo json_encode([
        'id' => (int) $r['id'],
        'email' => strtolower(trim((string) $r['email'])),
        'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
        'phone' => (string) ($r['phone'] ?? ''),
        'account_status' => (string) ($r['account_status'] ?? ''),
        'is_active' => (int) ($r['is_active'] ?? 0),
        'barangay_id' => $bid ?: null,
        'barangay_name' => $bid ? $bname : null,
        'has_password' => (int) ($r['has_password'] ?? 0),
        'archived_at' => $r['archived_at'] ?? null,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== local BHW by barangay ===\n";
ksort($byBarangay);
foreach ($byBarangay as $name => $n) {
    echo $name . '=' . $n . PHP_EOL;
}
