<?php
/**
 * Sync BHW user accounts from local XAMPP DB → production Hostinger DB.
 *
 * SAFETY:
 * - Default is DRY-RUN (compare only). Pass --execute to write.
 * - Never prints password hashes.
 * - Matches by email; never overwrites existing production users.
 * - Resolves barangay by NAME (IDs differ across environments).
 * - Copies existing bcrypt password hash only (no plaintext).
 *
 * Required env for production connection (remote MySQL):
 *   MEDCONNECT_PROD_DB_HOST   e.g. srv1234.hstgr.io
 *   MEDCONNECT_PROD_DB_NAME   (default: u520834156_meDBConnect26)
 *   MEDCONNECT_PROD_DB_USER   (default: u520834156_usrMedConnect)
 *   MEDCONNECT_PROD_DB_PASS   (required)
 *
 * Usage:
 *   php scripts/dev/sync_bhw_local_to_prod.php
 *   php scripts/dev/sync_bhw_local_to_prod.php --execute
 */
declare(strict_types=1);

$execute = in_array('--execute', $argv ?? [], true);

require dirname(__DIR__, 2) . '/bootstrap.php';
require_once BASE_PATH . '/config/db.php';

/** @var PDO $local */
$local = $pdo;

$prodHost = trim((string) (getenv('MEDCONNECT_PROD_DB_HOST') ?: ($_ENV['MEDCONNECT_PROD_DB_HOST'] ?? '')));
$prodName = trim((string) (getenv('MEDCONNECT_PROD_DB_NAME') ?: ($_ENV['MEDCONNECT_PROD_DB_NAME'] ?? 'u520834156_meDBConnect26')));
$prodUser = trim((string) (getenv('MEDCONNECT_PROD_DB_USER') ?: ($_ENV['MEDCONNECT_PROD_DB_USER'] ?? 'u520834156_usrMedConnect')));
$prodPass = (string) (getenv('MEDCONNECT_PROD_DB_PASS') ?: ($_ENV['MEDCONNECT_PROD_DB_PASS'] ?? ''));

echo "mode=" . ($execute ? 'EXECUTE' : 'DRY-RUN') . PHP_EOL;
echo 'local_db=' . DB_NAME . ' @ ' . DB_HOST . PHP_EOL;

if ($prodHost === '' || $prodPass === '') {
    echo PHP_EOL;
    echo "STOPPED: Production database is not reachable from this machine.\n";
    echo "Missing remote MySQL connection settings.\n\n";
    echo "Set these environment variables (Hostinger → Databases → Remote MySQL):\n";
    echo "  MEDCONNECT_PROD_DB_HOST=srvXXXX.hstgr.io\n";
    echo "  MEDCONNECT_PROD_DB_NAME=u520834156_meDBConnect26\n";
    echo "  MEDCONNECT_PROD_DB_USER=u520834156_usrMedConnect\n";
    echo "  MEDCONNECT_PROD_DB_PASS=(your Hostinger DB password)\n";
    echo "  (Also allow your PC IP / % in Hostinger Remote MySQL.)\n\n";
    echo "Then re-run:\n";
    echo "  php scripts/dev/sync_bhw_local_to_prod.php          # compare only\n";
    echo "  php scripts/dev/sync_bhw_local_to_prod.php --execute # insert missing\n";
    exit(2);
}

try {
    $dsn = 'mysql:host=' . $prodHost . ';dbname=' . $prodName . ';charset=utf8mb4';
    $prod = new PDO($dsn, $prodUser, $prodPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $prod->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    echo "STOPPED: Could not connect to production DB at {$prodHost}/{$prodName}\n";
    echo 'error=' . $e->getMessage() . PHP_EOL;
    exit(2);
}

echo 'prod_db=' . $prodName . ' @ ' . $prodHost . PHP_EOL;

$localCols = $local->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
$prodCols = $prod->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
$passCol = in_array('password', $localCols, true) ? 'password' : null;
if ($passCol === null || !in_array('password', $prodCols, true)) {
    echo "STOPPED: users.password column missing on local or production.\n";
    exit(2);
}

$localBarangays = [];
foreach ($local->query('SELECT id, name FROM barangays') as $b) {
    $localBarangays[(int) $b['id']] = trim((string) $b['name']);
}
$prodBarangaysByName = [];
foreach ($prod->query('SELECT id, name FROM barangays') as $b) {
    $key = mb_strtolower(trim((string) $b['name']));
    $prodBarangaysByName[$key] = (int) $b['id'];
}

$localBhws = $local->query("
    SELECT id, email, first_name, last_name, phone, role, account_status, is_active,
           barangay_id, password, archived_at, created_at
    FROM users
    WHERE LOWER(TRIM(role)) = 'bhw'
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$prodBhws = $prod->query("
    SELECT id, email, first_name, last_name, account_status, is_active, barangay_id
    FROM users
    WHERE LOWER(TRIM(role)) = 'bhw'
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$prodByEmail = [];
foreach ($prodBhws as $row) {
    $email = mb_strtolower(trim((string) $row['email']));
    if ($email !== '') {
        $prodByEmail[$email] = $row;
    }
}

echo 'local_bhw=' . count($localBhws) . PHP_EOL;
echo 'prod_bhw_before=' . count($prodBhws) . PHP_EOL;

$toInsert = [];
$already = [];
$skipped = [];

foreach ($localBhws as $row) {
    $email = mb_strtolower(trim((string) $row['email']));
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $localBid = (int) ($row['barangay_id'] ?? 0);
    $localBname = $localBid > 0 ? ($localBarangays[$localBid] ?? '') : '';
    $hash = (string) ($row['password'] ?? '');

    if ($email === '') {
        $skipped[] = ['reason' => 'missing_email', 'local_id' => (int) $row['id'], 'name' => $name];
        continue;
    }
    if ($hash === '' || !str_starts_with($hash, '$2')) {
        $skipped[] = ['reason' => 'missing_or_invalid_password_hash', 'email' => $email, 'name' => $name];
        continue;
    }
    if (isset($prodByEmail[$email])) {
        $already[] = [
            'email' => $email,
            'name' => $name,
            'prod_id' => (int) $prodByEmail[$email]['id'],
            'action' => 'leave_unchanged',
        ];
        continue;
    }

    $prodBid = null;
    if ($localBname !== '') {
        $prodBid = $prodBarangaysByName[mb_strtolower($localBname)] ?? null;
        if ($prodBid === null) {
            $skipped[] = [
                'reason' => 'barangay_name_not_found_on_production',
                'email' => $email,
                'name' => $name,
                'barangay_name' => $localBname,
                'local_barangay_id' => $localBid,
            ];
            continue;
        }
    }

    $toInsert[] = [
        'email' => $email,
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'account_status' => (string) (($row['account_status'] ?? '') !== '' ? $row['account_status'] : 'active'),
        'is_active' => (int) ($row['is_active'] ?? 1) ? 1 : 0,
        'barangay_id' => $prodBid,
        'barangay_name' => $localBname !== '' ? $localBname : null,
        'password' => $hash,
        'local_id' => (int) $row['id'],
    ];
}

echo PHP_EOL . '=== already on production (skip, leave unchanged) ===' . PHP_EOL;
foreach ($already as $a) {
    echo json_encode($a, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
echo 'already_count=' . count($already) . PHP_EOL;

echo PHP_EOL . '=== missing on production (candidates to insert) ===' . PHP_EOL;
foreach ($toInsert as $a) {
    $safe = $a;
    unset($safe['password']);
    $safe['has_password_hash'] = 1;
    echo json_encode($safe, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
echo 'to_insert_count=' . count($toInsert) . PHP_EOL;

echo PHP_EOL . '=== skipped (cannot sync safely) ===' . PHP_EOL;
foreach ($skipped as $a) {
    echo json_encode($a, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
echo 'skipped_count=' . count($skipped) . PHP_EOL;

$expectedAfter = count($prodBhws) + count($toInsert);
echo PHP_EOL . 'prod_bhw_expected_after_insert=' . $expectedAfter . PHP_EOL;

if (!$execute) {
    echo PHP_EOL . "DRY-RUN complete. No production writes performed.\n";
    echo "Re-run with --execute after reviewing the lists above.\n";
    exit(0);
}

if ($toInsert === []) {
    echo PHP_EOL . "Nothing to insert.\n";
    exit(0);
}

$prod->beginTransaction();
try {
    $stmt = $prod->prepare('
        INSERT INTO users (
            first_name, last_name, email, phone, password, role,
            barangay_id, is_active, account_status, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, \'bhw\',
            ?, ?, ?, NOW(), NOW()
        )
    ');

    // Guard: re-check email inside transaction
    $exists = $prod->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');

    $inserted = 0;
    foreach ($toInsert as $row) {
        $exists->execute([$row['email']]);
        if ($exists->fetchColumn()) {
            echo 'skip_race_exists=' . $row['email'] . PHP_EOL;
            continue;
        }
        $stmt->execute([
            $row['first_name'],
            $row['last_name'],
            $row['email'],
            $row['phone'] !== '' ? $row['phone'] : null,
            $row['password'],
            $row['barangay_id'],
            $row['is_active'],
            $row['account_status'],
        ]);
        $inserted++;
        echo 'inserted=' . $row['email'] . ' barangay=' . ($row['barangay_name'] ?? 'null') . PHP_EOL;
    }

    $prod->commit();
} catch (Throwable $e) {
    $prod->rollBack();
    echo 'STOPPED: transaction rolled back. ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

$after = (int) $prod->query("SELECT COUNT(*) FROM users WHERE LOWER(TRIM(role)) = 'bhw'")->fetchColumn();
$dup = (int) $prod->query("
    SELECT COUNT(*) FROM (
        SELECT LOWER(TRIM(email)) e, COUNT(*) c
        FROM users
        WHERE LOWER(TRIM(role)) = 'bhw'
        GROUP BY LOWER(TRIM(email))
        HAVING c > 1
    ) t
")->fetchColumn();

echo PHP_EOL . 'inserted_count=' . $inserted . PHP_EOL;
echo 'prod_bhw_after=' . $after . PHP_EOL;
echo 'duplicate_bhw_emails=' . $dup . PHP_EOL;
echo "DONE.\n";
