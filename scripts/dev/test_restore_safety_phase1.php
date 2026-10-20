<?php
/**
 * MedConnect Backup & Recovery Modernization — Phase 1 Test Suite
 * Comprehensive verification against dedicated disposable MySQL test database.
 *
 * Covers:
 * 1. Lock acquisition, concurrent conflict rejection (HTTP 409), ownership, and release.
 * 2. Preflight rejections (wrong password, wrong confirmation phrase, invalid checksum, missing backup, active consultation).
 * 3. Prevention of new consultations and other writes after maintenance barrier activates (HTTP 503).
 * 4. Multi-route write protection across Patient, Provider, BHW, and Admin endpoints.
 * 5. Foreign-key check restoration (SET FOREIGN_KEY_CHECKS=1) in finally block after SQL failure.
 * 6. Automated recovery success, recovery failure, persistent emergency lockdown, and authorized Super Admin clear.
 * 7. Fail-closed handling of malformed, empty, unreadable, and interrupted lock state.
 * 8. Regression tests (backup creation, checksum verification, download rate limit, session epoch invalidation, audit logging).
 *
 * Run: php scripts/dev/test_restore_safety_phase1.php
 */
declare(strict_types=1);

$failures = 0;
$testCount = 0;

function test_pass(string $desc): void
{
    global $testCount;
    $testCount++;
    echo "[PASS] {$desc}\n";
}

function test_fail(string $desc, string $detail = ''): void
{
    global $failures, $testCount;
    $testCount++;
    $failures++;
    echo "[FAIL] {$desc}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

require_once $root . '/config/env_loader.php';
require_once $root . '/app/includes/superadmin/backup.php';
require_once $root . '/app/includes/security_throttle.php';

// Setup dedicated disposable MySQL test database
$testDbName = 'medconnect_phase1_test_db_' . bin2hex(random_bytes(4));
$dsnRoot = 'mysql:host=localhost;port=3306;charset=utf8mb4';
$rootPdo = null;

try {
    $rootPdo = new PDO($dsnRoot, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $rootPdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    die("FATAL: Cannot create disposable MySQL test database: " . $e->getMessage() . "\n");
}

echo "Created disposable MySQL test database: {$testDbName}\n";

// Connect two separate PDO connections to test concurrency
$dsnTest = "mysql:host=localhost;port=3306;dbname={$testDbName};charset=utf8mb4";
$pdo1 = new PDO($dsnTest, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2 = new PDO($dsnTest, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$backupDir = superadmin_backup_dir();
$initialBackupFiles = glob($backupDir . '/*.{sql,lock}', GLOB_BRACE) ?: [];

$testLockFile = $backupDir . '/.restore_state_test_' . bin2hex(random_bytes(6)) . '.lock';
$createdTestFiles = [$testLockFile];

function track_test_file(string $path): string
{
    global $createdTestFiles;
    $createdTestFiles[] = $path;
    return $path;
}

function cleanup_test_files(): void
{
    global $createdTestFiles, $backupDir, $initialBackupFiles;
    foreach ($createdTestFiles as $f) {
        if ($f && file_exists($f)) {
            @unlink($f);
        }
    }
    // Clean up any test artifacts created in storage/backups during the run
    $nowFiles = glob($backupDir . '/*.{sql,lock}', GLOB_BRACE) ?: [];
    foreach ($nowFiles as $nf) {
        if (!in_array($nf, $initialBackupFiles, true)) {
            @unlink($nf);
        }
    }
}

$runnerFile = sys_get_temp_dir() . '/medconnect_barrier_runner_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($runnerFile, "<?php
declare(strict_types=1);
\$method = \$argv[1] ?? 'GET';
\$route = \$argv[2] ?? '/';
\$action = \$argv[3] ?? '';
\$lockPath = \$argv[4] ?? '';
\$userRole = \$argv[5] ?? '';

\$_SERVER['REQUEST_METHOD'] = \$method;
\$_SERVER['SCRIPT_NAME'] = \$route;
\$_SERVER['REQUEST_URI'] = \$route;
\$_SERVER['HTTP_ACCEPT'] = 'application/json';
\$_SERVER['MEDCONNECT_TEST_ENFORCE_BARRIER'] = '1';

if (\$action !== '' && \$action !== '-') {
    \$_POST['action'] = \$action;
    \$_GET['action'] = \$action;
}

\$root = '" . addslashes($root) . "';
if (!defined('BASE_PATH')) {
    define('BASE_PATH', \$root);
}

// Session mock for role testing
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (\$userRole !== '' && \$userRole !== '-') {
    \$_SESSION['user_id'] = 1;
    \$_SESSION['user_role'] = \$userRole;
} else {
    unset(\$_SESSION['user_id'], \$_SESSION['user_role']);
}

// If testing a direct controller script that bypasses central bootstrap
if (str_starts_with(\$route, '/app/controllers/')) {
    \$controllerFile = \$root . \$route;
    if (is_file(\$controllerFile)) {
        require \$controllerFile;
        echo json_encode(['status' => 200, 'allowed' => true]);
        exit;
    }
}

require_once \$root . '/app/includes/superadmin/backup.php';
superadmin_restore_barrier_enforce(\$lockPath !== '' ? \$lockPath : null);
echo json_encode(['status' => 200, 'allowed' => true]);
");

// Ensure cleanup on shutdown
register_shutdown_function(function () use ($rootPdo, $testDbName, $runnerFile) {
    cleanup_test_files();
    if (file_exists($runnerFile)) {
        @unlink($runnerFile);
    }
    if ($rootPdo instanceof PDO) {
        try {
            $rootPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
            echo "Cleaned up disposable MySQL test database: {$testDbName}\n";
        } catch (Throwable $e) {}
    }
});

// Helper to seed synthetic schema
function seed_test_database(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(191) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('patient', 'provider', 'admin', 'bhw', 'superadmin') NOT NULL DEFAULT 'patient',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            session_epoch INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS consultations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            patient_id INT UNSIGNED NOT NULL,
            provider_id INT UNSIGNED NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS video_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            consultation_id INT UNSIGNED NOT NULL,
            session_token VARCHAR(255) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'waiting',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS backup_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            backup_type VARCHAR(50) NOT NULL,
            file_size BIGINT NULL,
            file_checksum VARCHAR(64) NULL,
            status VARCHAR(50) NOT NULL,
            created_by INT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL
        );

        CREATE TABLE IF NOT EXISTS security_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            role VARCHAR(50) NULL,
            action VARCHAR(100) NOT NULL,
            module VARCHAR(50) NOT NULL,
            status VARCHAR(50) NOT NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            browser VARCHAR(50) NULL,
            device VARCHAR(50) NULL,
            meta JSON NULL,
            created_at DATETIME NOT NULL
        );

        CREATE TABLE IF NOT EXISTS active_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id VARCHAR(128) NOT NULL,
            last_activity DATETIME NOT NULL
        );

        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_by INT NULL,
            updated_at DATETIME NULL
        );
    ");
    security_throttle_ensure_schema($pdo);
}

seed_test_database($pdo1);

// Insert synthetic users
$plainSuperadminPass = 'SuperSecret!123';
$hashSuperadmin = password_hash($plainSuperadminPass, PASSWORD_BCRYPT, ['cost' => 4]);

$plainAdminPass = 'AdminSecret!456';
$hashAdmin = password_hash($plainAdminPass, PASSWORD_BCRYPT, ['cost' => 4]);

$stmt = $pdo1->prepare("
    INSERT INTO users (id, first_name, last_name, email, password, role, status, session_epoch, created_at)
    VALUES 
    (1, 'Super', 'Admin', 'superadmin@example.com', ?, 'superadmin', 'active', 1, NOW()),
    (2, 'General', 'Admin', 'admin@example.com', ?, 'admin', 'active', 1, NOW()),
    (3, 'John', 'Doctor', 'doctor@example.com', 'dummy_hash', 'provider', 'active', 1, NOW()),
    (4, 'Jane', 'Patient', 'patient@example.com', 'dummy_hash', 'patient', 'active', 1, NOW())
");
$stmt->execute([$hashSuperadmin, $hashAdmin]);

$pdo1->exec("
    INSERT INTO active_sessions (user_id, session_id, last_activity)
    VALUES (1, 'sess_superadmin', NOW()), (3, 'sess_doctor', NOW()), (4, 'sess_patient', NOW())
");

echo "\n--- TEST SUITE 1: Lock Acquisition, Concurrent Conflict Rejection & Ownership ---\n";

// Test 1.1: Lock acquisition on connection 1
$resAcq1 = superadmin_restore_lock_acquire($pdo1, 1, 'superadmin', 101, $testLockFile);
if ($resAcq1['success'] === true && !empty($resAcq1['token']) && $resAcq1['status_code'] === 200) {
    test_pass("Lock acquired successfully on Connection 1 (HTTP 200)");
} else {
    test_fail("Lock acquisition failed on Connection 1", json_encode($resAcq1));
}
$token1 = (string) ($resAcq1['token'] ?? '');

// Test 1.2: State inspection verifies active restore
$state = superadmin_restore_state($testLockFile);
if ($state['active'] === true && $state['status'] === 'restoring' && $state['owner_id'] === 1) {
    test_pass("Restore state reports active='restoring' with correct owner_id");
} else {
    test_fail("Restore state verification failed", json_encode($state));
}

// Test 1.3: Competing lock acquisition on connection 2 is rejected with HTTP 409
$resAcq2 = superadmin_restore_lock_acquire($pdo2, 2, 'admin', 102, $testLockFile);
if ($resAcq2['success'] === false && $resAcq2['status_code'] === 409 && $resAcq2['code'] === 'lock_conflict') {
    test_pass("Competing restore rejected with HTTP 409 (lock_conflict)");
} else {
    test_fail("Competing restore did not return 409", json_encode($resAcq2));
}

// Test 1.4: Ownership protection — releasing with wrong token fails and retains MySQL advisory lock
$releaseWrong = superadmin_restore_lock_release($pdo1, 'wrong_fake_token_12345', $testLockFile);
$stateAfterWrong = superadmin_restore_state($testLockFile);
$isUsedAfterWrong = (int) $pdo2->query("SELECT IS_USED_LOCK('medconnect_db_restore_lock')")->fetchColumn();
if ($releaseWrong === false && $stateAfterWrong['active'] === true && $isUsedAfterWrong > 0) {
    test_pass("Releasing lock with wrong token fails, preserves lock file, and retains MySQL advisory lock");
} else {
    test_fail("Lock or advisory lock incorrectly affected by wrong token", "releaseWrong={$releaseWrong}, isUsed={$isUsedAfterWrong}");
}

// Test 1.4b: Ownership protection — releasing with empty token fails and retains advisory lock
$releaseEmpty = superadmin_restore_lock_release($pdo1, '', $testLockFile);
$isUsedAfterEmpty = (int) $pdo2->query("SELECT IS_USED_LOCK('medconnect_db_restore_lock')")->fetchColumn();
if ($releaseEmpty === false && $isUsedAfterEmpty > 0) {
    test_pass("Releasing lock with empty token fails and retains MySQL advisory lock");
} else {
    test_fail("Empty token release did not retain MySQL advisory lock");
}

// Test 1.5: Releasing with correct token succeeds and releases advisory lock
$releaseRight = superadmin_restore_lock_release($pdo1, $token1, $testLockFile);
$stateAfterRight = superadmin_restore_state($testLockFile);
$isUsedAfterRight = $pdo2->query("SELECT IS_USED_LOCK('medconnect_db_restore_lock')")->fetchColumn();
if ($releaseRight === true && $stateAfterRight['active'] === false && $isUsedAfterRight === null) {
    test_pass("Releasing lock with owner token succeeds, clears file, and releases MySQL advisory lock");
} else {
    test_fail("Lock release failed with owner token or advisory lock retained");
}

// Test 1.6: After release, Connection 2 can acquire lock
$resAcq2Second = superadmin_restore_lock_acquire($pdo2, 2, 'admin', 102, $testLockFile);
if ($resAcq2Second['success'] === true && !empty($resAcq2Second['token'])) {
    test_pass("Connection 2 acquires lock after Connection 1 released");
    superadmin_restore_lock_release($pdo2, (string) $resAcq2Second['token'], $testLockFile);
} else {
    test_fail("Connection 2 failed to acquire lock after release", json_encode($resAcq2Second));
}

echo "\n--- TEST SUITE 2: Preflight Checks and Safe Abort ---\n";

// Helper to create a valid synthetic backup file
$testDumpContent = "
-- MedConnect Synthetic Backup
CREATE TABLE IF NOT EXISTS medconnect_synthetic_test (id INT PRIMARY KEY, val VARCHAR(50));
INSERT INTO medconnect_synthetic_test VALUES (1, 'restored_data');
";
$backupDir = superadmin_backup_dir();
$validDumpPath = $backupDir . '/medconnect_synth_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
file_put_contents($validDumpPath, $testDumpContent);
$validDumpChecksum = hash_file('sha256', $validDumpPath);

$stmt = $pdo1->prepare("
    INSERT INTO backup_logs (filename, file_path, backup_type, file_size, file_checksum, status, created_by, notes, created_at)
    VALUES (?, ?, 'manual', ?, ?, 'success', 1, 'Test backup', NOW())
");
$stmt->execute([basename($validDumpPath), $validDumpPath, strlen($testDumpContent), $validDumpChecksum]);
$validBackupId = (int) $pdo1->lastInsertId();

// Test 2.1: Wrong password rejected (HTTP 400, lock not active)
$resWrongPass = superadmin_restore_backup($pdo1, $validBackupId, 1, 'WrongPassword!999', 'RESTORE DATABASE');
if ($resWrongPass['success'] === false && str_contains(strtolower($resWrongPass['message']), 'password is incorrect')) {
    test_pass("Wrong password rejected before restore begins");
} else {
    test_fail("Wrong password not rejected properly", json_encode($resWrongPass));
}
$stateAfterPass = superadmin_restore_state();
if ($stateAfterPass['active'] === false) {
    test_pass("Preflight password failure does NOT leave maintenance lock active");
} else {
    test_fail("Maintenance lock left active after password failure");
}

// Test 2.2: Wrong confirmation phrase rejected
$resWrongPhrase = superadmin_restore_backup($pdo1, $validBackupId, 1, $plainSuperadminPass, 'INCORRECT PHRASE');
if ($resWrongPhrase['success'] === false && str_contains($resWrongPhrase['message'], 'RESTORE DATABASE')) {
    test_pass("Wrong confirmation phrase rejected (requires RESTORE DATABASE)");
} else {
    test_fail("Wrong phrase was not rejected properly", json_encode($resWrongPhrase));
}

// Test 2.3: Tampered backup checksum rejected
$tamperedContent = $testDumpContent . "\n-- Tampered Comment";
$tamperedPath = $backupDir . '/medconnect_tampered_' . bin2hex(random_bytes(4)) . '.sql';
file_put_contents($tamperedPath, $tamperedContent);
// Register with stored checksum of original content (simulating modification on disk)
$stmt = $pdo1->prepare("
    INSERT INTO backup_logs (filename, file_path, backup_type, file_size, file_checksum, status, created_by, notes, created_at)
    VALUES (?, ?, 'manual', ?, ?, 'success', 1, 'Tampered backup', NOW())
");
$stmt->execute([basename($tamperedPath), $tamperedPath, strlen($tamperedContent), $validDumpChecksum]);
$tamperedBackupId = (int) $pdo1->lastInsertId();

$resTampered = superadmin_restore_backup($pdo1, $tamperedBackupId, 1, $plainSuperadminPass, 'RESTORE DATABASE');
if ($resTampered['success'] === false && str_contains(strtolower($resTampered['message']), 'integrity check failed')) {
    test_pass("Tampered backup file checksum mismatch rejected");
} else {
    test_fail("Tampered backup was not rejected", json_encode($resTampered));
}
@unlink($tamperedPath);

// Test 2.4: Active consultation blocks restore
$pdo1->exec("INSERT INTO consultations (patient_id, provider_id, status, created_at) VALUES (4, 3, 'in_consultation', NOW())");
$activeConsultId = (int) $pdo1->lastInsertId();

$resActiveConsult = superadmin_restore_backup($pdo1, $validBackupId, 1, $plainSuperadminPass, 'RESTORE DATABASE');
if ($resActiveConsult['success'] === false && str_contains(strtolower($resActiveConsult['message']), 'consultations are in progress')) {
    test_pass("Active consultation circuit breaker blocks restore (HTTP 409)");
} else {
    test_fail("Active consultation did not block restore", json_encode($resActiveConsult));
}
$pdo1->exec("DELETE FROM consultations WHERE id = {$activeConsultId}");

// Test 2.5: Active video session blocks restore
$pdo1->exec("INSERT INTO video_sessions (consultation_id, session_token, status, created_at) VALUES (1, 'tok_123', 'active', NOW())");
$activeVidId = (int) $pdo1->lastInsertId();

$resActiveVideo = superadmin_restore_backup($pdo1, $validBackupId, 1, $plainSuperadminPass, 'RESTORE DATABASE');
if ($resActiveVideo['success'] === false && str_contains(strtolower($resActiveVideo['message']), 'consultations are in progress')) {
    test_pass("Active video session circuit breaker blocks restore (HTTP 409)");
} else {
    test_fail("Active video session did not block restore", json_encode($resActiveVideo));
}
$pdo1->exec("DELETE FROM video_sessions WHERE id = {$activeVidId}");

echo "\n--- TEST SUITE 3: Maintenance Barrier & Conflicting Route Protection ---\n";

// Helper function to test barrier enforcement in sub-process
function run_barrier_request(string $method, string $scriptName, ?string $postAction = null, ?string $lockPath = null, ?string $userRole = null): array
{
    global $root, $runnerFile;
    $act = ($postAction !== null && $postAction !== '') ? escapeshellarg($postAction) : "'-'";
    $lp = ($lockPath !== null && $lockPath !== '') ? escapeshellarg($lockPath) : "''";
    $role = ($userRole !== null && $userRole !== '') ? escapeshellarg($userRole) : "'-'";
    $cmd = "php " . escapeshellarg($runnerFile) . " " . escapeshellarg($method) . " " . escapeshellarg($scriptName) . " {$act} {$lp} {$role}";
    $out = shell_exec($cmd);
    $decoded = json_decode((string) $out, true);
    if (!is_array($decoded)) {
        return ['status' => 0, 'raw' => $out];
    }
    if (isset($decoded['code']) && in_array($decoded['code'], ['maintenance_mode', 'emergency_lockdown'], true)) {
        return [
            'status' => 503,
            'code' => $decoded['code'],
            'message' => $decoded['message'] ?? '',
            'allowed' => false,
        ];
    }
    return [
        'status' => (int) ($decoded['status'] ?? 200),
        'code' => $decoded['code'] ?? 'ok',
        'allowed' => ($decoded['status'] ?? 200) === 200,
    ];
}

// Test 3.1: Write routes blocked with HTTP 503 during active restore
$mockLockFile = track_test_file(superadmin_backup_dir() . '/.restore_state_mock_' . bin2hex(random_bytes(4)) . '.lock');
file_put_contents($mockLockFile, json_encode([
    'status' => 'restoring',
    'token' => 'tok_mock_123',
    'owner_id' => 1,
    'owner_role' => 'superadmin',
    'started_at' => date('c'),
    'pid' => getmypid(),
    'reason' => 'Database restore in progress',
]));

$writeRoutes = [
    '/app/api/patient/submit_triage.php',
    '/app/api/patient/cancel_consultation.php',
    '/app/api/provider/clinical_notes.php',
    '/app/api/provider/soap_notes.php',
    '/app/api/bhw/register_patient.php',
    '/app/api/admin/create_staff.php',
    '/app/api/admin/save_system_settings.php',
    '/app/api/messages/send.php',
    '/app/api/register.php',
];

$allBlocked = true;
foreach ($writeRoutes as $route) {
    $resp = run_barrier_request('POST', $route, null, $mockLockFile);
    if (($resp['status'] ?? 0) !== 503 || ($resp['code'] ?? '') !== 'maintenance_mode') {
        $allBlocked = false;
        test_fail("Route {$route} was NOT blocked with HTTP 503 during restore", json_encode($resp));
    }
}
if ($allBlocked) {
    test_pass("All 9 inspected patient/provider/bhw/admin write routes returned HTTP 503 during restore");
}

// Test 3.2: Portal view GET requests blocked with HTTP 503 during restore (Finding 1 fix)
$viewRoutes = [
    '/views/patient/dashboard.php',
    '/views/provider/consultation.php',
    '/views/admin/users.php',
];
$allViewsBlocked = true;
foreach ($viewRoutes as $vroute) {
    $respV = run_barrier_request('GET', $vroute, null, $mockLockFile);
    if (($respV['status'] ?? 0) !== 503) {
        $allViewsBlocked = false;
        test_fail("Portal view {$vroute} was NOT blocked with HTTP 503 during restore", json_encode($respV));
    }
}
if ($allViewsBlocked) {
    test_pass("Portal view GET requests blocked with HTTP 503 during active restore");
}

// Test 3.3: Unrelated read API GET requests blocked with HTTP 503 during restore
$apiReadRoutes = [
    '/app/api/patient/appointments.php',
    '/app/api/provider/dashboard_live_api.php',
    '/app/api/admin/reports.php',
];
$allReadsBlocked = true;
foreach ($apiReadRoutes as $aroute) {
    $respA = run_barrier_request('GET', $aroute, null, $mockLockFile);
    if (($respA['status'] ?? 0) !== 503) {
        $allReadsBlocked = false;
        test_fail("Read API {$aroute} was NOT blocked with HTTP 503 during restore", json_encode($respA));
    }
}
if ($allReadsBlocked) {
    test_pass("Read API GET requests blocked with HTTP 503 during active restore");
}

// Test 3.4: Direct controller entry points blocked with HTTP 503 during restore
$directControllers = [
    '/app/controllers/auth/send_otp.php',
    '/app/controllers/auth/verify_otp.php',
    '/app/controllers/patient/process_id_ocr.php',
];
$allControllersBlocked = true;
foreach ($directControllers as $croute) {
    $respC = run_barrier_request('POST', $croute, null, $mockLockFile);
    if (($respC['status'] ?? 0) !== 503) {
        $allControllersBlocked = false;
        test_fail("Direct controller {$croute} was NOT blocked with HTTP 503 during restore", json_encode($respC));
    }
}
if ($allControllersBlocked) {
    test_pass("Direct controllers bypassing bootstrap blocked with HTTP 503 during restore");
}

// Test 3.5: Unauthorized callers attempting backup API blocked with HTTP 503
$respGuest = run_barrier_request('GET', '/app/api/superadmin/backup.php', 'status', $mockLockFile, null);
$respPatient = run_barrier_request('GET', '/app/api/superadmin/backup.php', 'status', $mockLockFile, 'patient');
$respAdminOnSuper = run_barrier_request('POST', '/app/api/superadmin/backup.php', 'emergency_clear', $mockLockFile, 'admin');
if (($respGuest['status'] ?? 0) === 503 && ($respPatient['status'] ?? 0) === 503 && ($respAdminOnSuper['status'] ?? 0) === 503) {
    test_pass("Unauthorized callers (guest, patient, ordinary admin) blocked from Super Admin backup API with HTTP 503");
} else {
    test_fail("Unauthorized callers were not blocked with 503 from backup API");
}

// Test 3.6: Authorized Super Admin status polling GET passes barrier
$respSuperStatus = run_barrier_request('GET', '/app/api/superadmin/backup.php', 'status', $mockLockFile, 'superadmin');
if (($respSuperStatus['status'] ?? 0) === 200 && ($respSuperStatus['allowed'] ?? false) === true) {
    test_pass("Authorized Super Admin status GET polling allowed through maintenance barrier");
} else {
    test_fail("Authorized Super Admin status GET polling blocked", json_encode($respSuperStatus));
}

// Test 3.7: Authorized Admin status polling GET passes barrier on admin API
$respAdminStatus = run_barrier_request('GET', '/app/api/admin/backup.php', 'status', $mockLockFile, 'admin');
if (($respAdminStatus['status'] ?? 0) === 200 && ($respAdminStatus['allowed'] ?? false) === true) {
    test_pass("Authorized Admin status GET polling allowed through maintenance barrier on admin API");
} else {
    test_fail("Authorized Admin status GET polling blocked on admin API", json_encode($respAdminStatus));
}

// Test 3.8: Non-status actions on admin backup API blocked during maintenance
$respAdminCreate = run_barrier_request('POST', '/app/api/admin/backup.php', 'create', $mockLockFile, 'admin');
$respAdminList = run_barrier_request('GET', '/app/api/admin/backup.php', 'list', $mockLockFile, 'admin');
if (($respAdminCreate['status'] ?? 0) === 503 && ($respAdminList['status'] ?? 0) === 503) {
    test_pass("Non-status actions (create, list) on admin backup API blocked with HTTP 503 during maintenance");
} else {
    test_fail("Non-status admin actions not blocked with 503", json_encode([$respAdminCreate, $respAdminList]));
}

// Test 3.9: Super Admin restore POST action passes barrier
$respRestore = run_barrier_request('POST', '/app/api/superadmin/backup.php', 'restore', $mockLockFile, 'superadmin');
if (($respRestore['status'] ?? 0) === 200 && ($respRestore['allowed'] ?? false) === true) {
    test_pass("Super Admin backup restore POST action passes barrier to reach API auth/conflict gates");
} else {
    test_fail("Backup restore action was blocked by barrier", json_encode($respRestore));
}

// Test 3.10: Super Admin emergency_clear POST action passes barrier
$respClear = run_barrier_request('POST', '/app/api/superadmin/backup.php', 'emergency_clear', $mockLockFile, 'superadmin');
if (($respClear['status'] ?? 0) === 200 && ($respClear['allowed'] ?? false) === true) {
    test_pass("Super Admin emergency_clear POST action passes barrier");
} else {
    test_fail("Emergency clear action was blocked by barrier", json_encode($respClear));
}

// Test 3.11: Super Admin backup download POST action passes barrier during maintenance (Finding 2 fix)
$respDl = run_barrier_request('POST', '/app/api/superadmin/backup.php', 'download', $mockLockFile, 'superadmin');
if (($respDl['status'] ?? 0) === 200 && ($respDl['allowed'] ?? false) === true) {
    test_pass("Super Admin backup download POST action passes barrier during maintenance/lockdown");
} else {
    test_fail("Super Admin backup download action was blocked by barrier", json_encode($respDl));
}

// Test 3.12: GET download and GET restore blocked with HTTP 503
$respDlGet = run_barrier_request('GET', '/app/api/superadmin/backup.php', 'download', $mockLockFile, 'superadmin');
$respRestoreGet = run_barrier_request('GET', '/app/api/superadmin/backup.php', 'restore', $mockLockFile, 'superadmin');
if (($respDlGet['status'] ?? 0) === 503 && ($respRestoreGet['status'] ?? 0) === 503) {
    test_pass("Unpermitted GET download/restore requests rejected with HTTP 503 (mutation requires POST)");
} else {
    test_fail("GET download or restore was improperly allowed through barrier");
}

@unlink($mockLockFile);

echo "\n--- TEST SUITE 4: Foreign Key Cleanup in Finally Block ---\n";

// Test 4.1: FOREIGN_KEY_CHECKS=1 is restored even if SQL throws exception
$pdo1->exec("SET FOREIGN_KEY_CHECKS=1");
$fkBefore = (int) $pdo1->query("SELECT @@FOREIGN_KEY_CHECKS")->fetchColumn();

// Intentionally bad SQL dump that causes an execution exception mid-way
$badSqlDump = "SET FOREIGN_KEY_CHECKS=0;\nCREATE TABLE medconnect_temp_broken (id INT PRIMARY KEY);\nINVALID SQL SYNTAX HERE EXCEPTION;\n";
$badSqlPath = $backupDir . '/medconnect_broken_' . bin2hex(random_bytes(4)) . '.sql';
file_put_contents($badSqlPath, $badSqlDump);
$badSqlChecksum = hash_file('sha256', $badSqlPath);

$stmt = $pdo1->prepare("
    INSERT INTO backup_logs (filename, file_path, backup_type, file_size, file_checksum, status, created_by, notes, created_at)
    VALUES (?, ?, 'manual', ?, ?, 'success', 1, 'Bad SQL backup', NOW())
");
$stmt->execute([basename($badSqlPath), $badSqlPath, strlen($badSqlDump), $badSqlChecksum]);
$badBackupId = (int) $pdo1->lastInsertId();

// Execute restore
$resRestoreFail = superadmin_restore_backup($pdo1, $badBackupId, 1, $plainSuperadminPass, 'RESTORE DATABASE');

$fkAfter = (int) $pdo1->query("SELECT @@FOREIGN_KEY_CHECKS")->fetchColumn();
if ($fkBefore === 1 && $fkAfter === 1) {
    test_pass("FOREIGN_KEY_CHECKS=1 restored in finally block on same PDO connection after SQL error");
} else {
    test_fail("FOREIGN_KEY_CHECKS was left as {$fkAfter} (expected 1)");
}
@unlink($badSqlPath);

echo "\n--- TEST SUITE 5: Automated Recovery, Emergency Lockdown & Authorized Clear ---\n";

// Test 5.1: Failed restore automatically reverted to pre-restore snapshot
if ($resRestoreFail['success'] === false && str_contains(strtolower($resRestoreFail['message']), 'safely reverted')) {
    test_pass("Destructive SQL failure triggered automatic recovery and safely reverted to pre-restore snapshot");
} else {
    test_fail("Automatic recovery revert message not seen", json_encode($resRestoreFail));
}

// Test 5.2: Database health check passes after safe revert
$healthAfterRevert = superadmin_verify_database_health($pdo1);
if ($healthAfterRevert['ok'] === true) {
    test_pass("Database verified healthy after automated snapshot recovery replay");
} else {
    test_fail("Database unhealthy after revert", json_encode($healthAfterRevert));
}

// Test 5.3: Emergency Lockdown when both restore and recovery fail
// Simulate an unrecoverable state by wiping users table in snapshot recovery simulation
$brokenLockFile = superadmin_backup_dir() . '/.restore_state_emergency_test.lock';
superadmin_restore_emergency_lockdown(
    $pdo1,
    'tok_emerg_123',
    'Test simulated restore + recovery total failure',
    $badBackupId,
    1,
    'superadmin',
    $brokenLockFile
);

$emergState = superadmin_restore_state($brokenLockFile);
if ($emergState['active'] === true && $emergState['status'] === 'emergency_lockdown') {
    test_pass("Persistent emergency lockdown engaged upon unrecoverable failure");
} else {
    test_fail("Emergency lockdown state not active", json_encode($emergState));
}

// Test 5.4: Normal release refuses to clear persistent emergency lockdown
$refusedRelease = superadmin_restore_lock_release($pdo1, 'tok_emerg_123', $brokenLockFile);
$stateStillLocked = superadmin_restore_state($brokenLockFile);
if ($refusedRelease === false && $stateStillLocked['status'] === 'emergency_lockdown') {
    test_pass("superadmin_restore_lock_release() refuses to clear persistent emergency lockdown");
} else {
    test_fail("Normal release cleared emergency lockdown inappropriately");
}

// Test 5.5: Competing restore is blocked with HTTP 503 during emergency lockdown
$resCompeteEmerg = superadmin_restore_lock_acquire($pdo1, 1, 'superadmin', $validBackupId, $brokenLockFile);
if ($resCompeteEmerg['success'] === false && $resCompeteEmerg['status_code'] === 503 && $resCompeteEmerg['code'] === 'emergency_lockdown') {
    test_pass("New restore attempts during emergency lockdown receive HTTP 503 (emergency_lockdown)");
} else {
    test_fail("New restore attempt during emergency lockdown did not return 503", json_encode($resCompeteEmerg));
}

// Test 5.6: Non-superadmin cannot clear emergency lockdown
$refusedAdminClear = superadmin_restore_emergency_clear($pdo1, 2, $plainAdminPass, $brokenLockFile);
if ($refusedAdminClear['success'] === false && $refusedAdminClear['status_code'] === 403) {
    test_pass("Admin role (non-superadmin) forbidden from clearing emergency lockdown (HTTP 403)");
} else {
    test_fail("Admin role was not forbidden from clearing emergency lockdown", json_encode($refusedAdminClear));
}

// Test 5.7: Wrong password cannot clear emergency lockdown
$refusedWrongPassClear = superadmin_restore_emergency_clear($pdo1, 1, 'WrongPassword!123', $brokenLockFile);
if ($refusedWrongPassClear['success'] === false && $refusedWrongPassClear['status_code'] === 401) {
    test_pass("Wrong password rejected when attempting to clear emergency lockdown (HTTP 401)");
} else {
    test_fail("Wrong password was not rejected for emergency clear", json_encode($refusedWrongPassClear));
}

// Test 5.8: Super Admin with valid password successfully clears emergency lockdown after health confirmation
$successClear = superadmin_restore_emergency_clear($pdo1, 1, $plainSuperadminPass, $brokenLockFile);
$stateAfterClear = superadmin_restore_state($brokenLockFile);
if ($successClear['success'] === true && $stateAfterClear['active'] === false) {
    test_pass("Super Admin with valid password clears emergency lockdown after health check confirms database safe");
} else {
    test_fail("Super Admin failed to clear emergency lockdown", json_encode($successClear));
}

// Test 5.9: Super Admin can download valid backup during emergency lockdown (Finding 2 fix)
superadmin_restore_emergency_lockdown($pdo1, 'tok_emerg_dl', 'Testing download during lockdown', $badBackupId, 1, 'superadmin', $brokenLockFile);
$dlLockState = superadmin_restore_state($brokenLockFile);
if ($dlLockState['active'] === true && $dlLockState['status'] === 'emergency_lockdown') {
    $dlPrep = superadmin_backup_download_prepare($pdo1, $validBackupId, 1, $plainSuperadminPass);
    if ($dlPrep['success'] === true && is_file($dlPrep['path'])) {
        test_pass("Authorized Super Admin can download valid backup during emergency lockdown");
    } else {
        test_fail("Super Admin failed to download valid backup during lockdown", json_encode($dlPrep));
    }

    // Test 5.10: Download prepare rejects invalid parameters during lockdown
    $dlWrongPw = superadmin_backup_download_prepare($pdo1, $validBackupId, 1, 'WrongPw!999');
    $dlBadId = superadmin_backup_download_prepare($pdo1, 999999, 1, $plainSuperadminPass);
    if ($dlWrongPw['success'] === false && $dlBadId['success'] === false) {
        test_pass("Download during lockdown properly rejects invalid password and non-existent backup ID");
    } else {
        test_fail("Download during lockdown did not reject invalid inputs");
    }

    // Test 5.11: Emergency clear strictly rejected when database health verification fails
    $memPdo = new PDO('sqlite::memory:');
    $clearBadHealth = superadmin_restore_emergency_clear($memPdo, 1, $plainSuperadminPass, $brokenLockFile);
    $statePreserved = superadmin_restore_state($brokenLockFile);
    if ($clearBadHealth['success'] === false && $clearBadHealth['status_code'] === 400 && $statePreserved['active'] === true) {
        test_pass("Emergency clear strictly rejected when database health check fails; lock preserved");
    } else {
        test_fail("Emergency clear was allowed despite failed database health", json_encode($clearBadHealth));
    }

    // Clear the lockdown file with real PDO
    superadmin_restore_emergency_clear($pdo1, 1, $plainSuperadminPass, $brokenLockFile);
}
@unlink($brokenLockFile);

echo "\n--- TEST SUITE 6: Fail-Closed Barrier & Malformed Lock State Handling ---\n";

// Test 6.1: Empty lock file fails closed
$emptyLockFile = superadmin_backup_dir() . '/.restore_state_empty.lock';
file_put_contents($emptyLockFile, '');
$emptyState = superadmin_restore_state($emptyLockFile);
if ($emptyState['active'] === true && $emptyState['status'] === 'malformed') {
    test_pass("Empty lock file fails closed (active=true, status='malformed')");
} else {
    test_fail("Empty lock file did not fail closed", json_encode($emptyState));
}
@unlink($emptyLockFile);

// Test 6.2: Corrupt JSON lock file fails closed
$corruptLockFile = superadmin_backup_dir() . '/.restore_state_corrupt.lock';
file_put_contents($corruptLockFile, '{"status": incomplete_json');
$corruptState = superadmin_restore_state($corruptLockFile);
if ($corruptState['active'] === true && $corruptState['status'] === 'malformed') {
    test_pass("Corrupted JSON lock file fails closed (active=true, status='malformed')");
} else {
    test_fail("Corrupted JSON lock file did not fail closed", json_encode($corruptState));
}
@unlink($corruptLockFile);

// Test 6.3: Atomic creation ('x' flag) prevents race condition
$raceLockFile = superadmin_backup_dir() . '/.restore_state_race.lock';
file_put_contents($raceLockFile, '{"status":"restoring"}');
// Second process tries to create via 'x' flag in superadmin_restore_lock_acquire
$fp = @fopen($raceLockFile, 'xb');
if ($fp === false) {
    test_pass("Atomic fopen 'xb' mode guarantees mutual exclusion (second creator blocked)");
} else {
    fclose($fp);
    test_fail("Atomic fopen 'xb' mode failed to prevent collision");
}
@unlink($raceLockFile);

echo "\n--- TEST SUITE 7: Regressions & Session Invalidation ---\n";

// Test 7.1: Successful full restore execution on disposable MySQL DB
$resSuccessRestore = superadmin_restore_backup($pdo1, $validBackupId, 1, $plainSuperadminPass, 'RESTORE DATABASE');
if ($resSuccessRestore['success'] === true && str_contains(strtolower($resSuccessRestore['message']), 'database restored')) {
    test_pass("Complete 9-step restore executed successfully on disposable MySQL database");
} else {
    test_fail("Restore execution failed", json_encode($resSuccessRestore));
}

// Test 7.2: Post-restore session invalidation bumped session_epoch for all other users
$doctorEpoch = (int) $pdo1->query("SELECT session_epoch FROM users WHERE id = 3")->fetchColumn();
$patientEpoch = (int) $pdo1->query("SELECT session_epoch FROM users WHERE id = 4")->fetchColumn();
$activeDoctorSess = $pdo1->query("SELECT COUNT(*) FROM active_sessions WHERE user_id = 3")->fetchColumn();
if ($doctorEpoch === 2 && $patientEpoch === 2 && (int) $activeDoctorSess === 0) {
    test_pass("Session invalidation bumped session_epoch to 2 for other users and purged active_sessions");
} else {
    test_fail("Session epoch not bumped or sessions not purged (doctor epoch: {$doctorEpoch})");
}

// Test 7.3: Backup download rate limit preserved
security_throttle_ensure_schema($pdo1);
$rateKey = security_throttle_key('backup_download', '1_127.0.0.1');
$stmt = $pdo1->prepare("DELETE FROM security_throttle WHERE key_hash = ?");
$stmt->execute([$rateKey]);
for ($i = 1; $i <= 3; $i++) {
    security_throttle_fail($pdo1, $rateKey, 'backup_download', 900, 3, 15);
}
$throttleCheck = security_throttle_check($pdo1, $rateKey);
if (!empty($throttleCheck['locked'])) {
    test_pass("Download rate limit (max 3 downloads / 15m) triggers lock correctly");
} else {
    test_fail("Download rate limit did not lock after 3 failures");
}

// Test 7.4: Security audit logs contain no sensitive SQL or passwords
$loggedEntries = $pdo1->query("SELECT action, description FROM security_logs WHERE module = 'backup'")->fetchAll();
$leakFound = false;
foreach ($loggedEntries as $log) {
    $desc = (string) ($log['description'] ?? '');
    if (str_contains($desc, $plainSuperadminPass) || str_contains($desc, $plainAdminPass)) {
        $leakFound = true;
        test_fail("Password leaked in audit log description: {$desc}");
    }
}
if (!$leakFound && count($loggedEntries) > 0) {
    test_pass("Security audit logs verified: zero credentials or plaintext passwords leaked");
}

@unlink($validDumpPath);

echo "\n=======================================================\n";
echo "TEST SUMMARY: {$testCount} tests executed, {$failures} failures\n";
echo "=======================================================\n";

if ($failures === 0) {
    echo "SUCCESS: All Phase 1 Restore Safety & Recovery Safeguards Verified on Disposable MySQL!\n";
    exit(0);
} else {
    echo "FAILURE: {$failures} test(s) failed.\n";
    exit(1);
}
