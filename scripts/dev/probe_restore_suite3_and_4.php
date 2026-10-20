<?php
/**
 * Focused disposable probe: Phase1 suite 3 barrier routes + suite 4 FK/restore path.
 * Does not run the full phase1 suite. CLI only.
 *
 * Run: php scripts/dev/probe_restore_suite3_and_4.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$failures = 0;
$passes = 0;
function pass(string $m): void
{
    global $passes;
    $passes++;
    echo "[PASS] {$m}\n";
}
function fail(string $m, string $d = ''): void
{
    global $failures;
    $failures++;
    echo "[FAIL] {$m}" . ($d !== '' ? " — {$d}" : '') . "\n";
}

$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

require_once $root . '/config/env_loader.php';
require_once $root . '/app/includes/superadmin/backup.php';
require_once $root . '/app/includes/security_throttle.php';

$testDb = 'medconnect_s34probe_' . bin2hex(random_bytes(4));
echo "Disposable DB: {$testDb}\n";
$rootPdo = new PDO('mysql:host=localhost;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rootPdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO("mysql:host=localhost;port=3306;dbname={$testDb};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$runnerFile = sys_get_temp_dir() . '/medconnect_s34_runner_' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($runnerFile, '<?php
declare(strict_types=1);
$method = $argv[1] ?? "GET";
$route = $argv[2] ?? "/";
$action = $argv[3] ?? "";
$lockPath = $argv[4] ?? "";
$userRole = $argv[5] ?? "";
$_SERVER["REQUEST_METHOD"] = $method;
$_SERVER["SCRIPT_NAME"] = $route;
$_SERVER["REQUEST_URI"] = $route;
$_SERVER["HTTP_ACCEPT"] = "application/json";
$_SERVER["MEDCONNECT_TEST_ENFORCE_BARRIER"] = "1";
if ($action !== "" && $action !== "-") {
    $_POST["action"] = $action;
    $_GET["action"] = $action;
}
$root = ' . var_export($root, true) . ';
if (!defined("BASE_PATH")) { define("BASE_PATH", $root); }
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if ($userRole !== "" && $userRole !== "-") {
    $_SESSION["user_id"] = 1;
    $_SESSION["user_role"] = $userRole;
} else {
    unset($_SESSION["user_id"], $_SESSION["user_role"]);
}
require_once $root . "/app/includes/superadmin/backup.php";
superadmin_restore_barrier_enforce($lockPath !== "" ? $lockPath : null);
echo json_encode(["status" => 200, "allowed" => true]);
');

register_shutdown_function(static function () use ($rootPdo, $testDb, $runnerFile, $root): void {
    @unlink($runnerFile);
    try {
        $rootPdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
        echo "[cleanup] dropped {$testDb}\n";
    } catch (Throwable $e) {
    }
    $lock = $root . '/storage/backups/.restore_state.lock';
    if (is_file($lock)) {
        @unlink($lock);
        echo "[cleanup] removed .restore_state.lock\n";
    }
    try {
        $rootPdo->query("SELECT RELEASE_LOCK('medconnect_db_restore_lock')");
    } catch (Throwable $e) {
    }
});

function run_barrier(string $method, string $script, ?string $action, string $lockPath, ?string $role = null, int $timeoutSec = 8): array
{
    global $runnerFile;
    $cmd = [
        PHP_BINARY,
        $runnerFile,
        $method,
        $script,
        ($action !== null && $action !== '') ? $action : '-',
        $lockPath,
        ($role !== null && $role !== '') ? $role : '-',
    ];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return ['status' => 0, 'raw' => 'proc_open_failed'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $deadline = microtime(true) + $timeoutSec;
    while (true) {
        $st = proc_get_status($proc);
        $out .= (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            if (!empty($st['pid']) && strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                @exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>NUL');
            } else {
                @proc_terminate($proc, 9);
            }
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            return ['status' => 0, 'raw' => 'timeout'];
        }
        usleep(20000);
    }
    $out .= (string) stream_get_contents($pipes[1]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode($out, true);
    if (!is_array($decoded)) {
        return ['status' => 0, 'raw' => $out];
    }
    if (isset($decoded['code']) && in_array($decoded['code'], ['maintenance_mode', 'emergency_lockdown'], true)) {
        return ['status' => 503, 'code' => $decoded['code'], 'allowed' => false];
    }
    return ['status' => (int) ($decoded['status'] ?? 200), 'code' => $decoded['code'] ?? 'ok', 'allowed' => true];
}

// Minimal seed (same shape as phase1 suite 4 needs)
$pdo->exec("
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(191) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('patient','provider','admin','bhw','superadmin') NOT NULL DEFAULT 'patient',
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  session_epoch INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE consultations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id INT UNSIGNED NOT NULL,
  provider_id INT UNSIGNED NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE video_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  consultation_id INT UNSIGNED NOT NULL,
  session_token VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'waiting',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE backup_logs (
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
CREATE TABLE security_logs (
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
CREATE TABLE active_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  session_id VARCHAR(128) NOT NULL,
  last_activity DATETIME NOT NULL
);
CREATE TABLE system_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_by INT NULL,
  updated_at DATETIME NULL
);
");
security_throttle_ensure_schema($pdo);
$plain = 'SuperSecret!123';
$hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]);
$pdo->prepare("INSERT INTO users (id, first_name, last_name, email, password, role, status, session_epoch, created_at)
 VALUES (1,'Super','Admin','superadmin@example.com',?,'superadmin','active',1,NOW())")->execute([$hash]);
superadmin_ensure_schema($pdo);

echo "\n--- Suite 3 focused: barrier routes ---\n";
$mockLock = superadmin_backup_dir() . '/.restore_state_s34_' . bin2hex(random_bytes(3)) . '.lock';
file_put_contents($mockLock, json_encode([
    'status' => 'restoring',
    'token' => 'tok_mock',
    'owner_id' => 1,
    'owner_role' => 'superadmin',
    'started_at' => date('c'),
    'pid' => getmypid(),
    'reason' => 'probe',
]));

$routes = [
    ['POST', '/app/api/patient/submit_triage.php', null, null],
    ['GET', '/views/patient/dashboard.php', null, null],
    ['GET', '/app/api/patient/appointments.php', null, null],
    ['POST', '/app/controllers/auth/send_otp.php', null, null],
    ['POST', '/app/controllers/patient/process_id_ocr.php', null, null],
    ['GET', '/app/api/superadmin/backup.php', 'status', 'superadmin'],
];
foreach ($routes as [$method, $script, $action, $role]) {
    $resp = run_barrier($method, $script, $action, $mockLock, $role);
    $expectAllow = ($script === '/app/api/superadmin/backup.php' && $action === 'status' && $role === 'superadmin');
    if ($expectAllow) {
        if (($resp['status'] ?? 0) === 200) {
            pass("allows superadmin status during restore ({$script})");
        } else {
            fail("allows superadmin status during restore ({$script})", json_encode($resp));
        }
    } else {
        if (($resp['status'] ?? 0) === 503 && ($resp['code'] ?? '') === 'maintenance_mode') {
            pass("blocks {$method} {$script}");
        } else {
            fail("blocks {$method} {$script}", json_encode($resp));
        }
    }
}
@unlink($mockLock);

echo "\n--- Suite 4 focused: bad-SQL restore + FK cleanup ---\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$fkBefore = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
$badSql = "SET FOREIGN_KEY_CHECKS=0;\nCREATE TABLE medconnect_temp_broken (id INT PRIMARY KEY);\nINVALID SQL SYNTAX HERE EXCEPTION;\n";
$badPath = superadmin_backup_dir() . '/medconnect_s34_broken_' . bin2hex(random_bytes(3)) . '.sql';
file_put_contents($badPath, $badSql);
$checksum = hash_file('sha256', $badPath);
$pdo->prepare("INSERT INTO backup_logs (filename, file_path, backup_type, file_size, file_checksum, status, created_by, notes, created_at)
 VALUES (?, ?, 'manual', ?, ?, 'success', 1, 'probe', NOW())")
    ->execute([basename($badPath), $badPath, strlen($badSql), $checksum]);
$badId = (int) $pdo->lastInsertId();

$t0 = microtime(true);
$res = superadmin_restore_backup($pdo, $badId, 1, $plain, 'RESTORE DATABASE');
$elapsed = microtime(true) - $t0;
echo sprintf("restore_backup elapsed=%.2fs\n", $elapsed);
$fkAfter = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();

if ($fkBefore === 1 && $fkAfter === 1) {
    pass('FOREIGN_KEY_CHECKS=1 after failed restore');
} else {
    fail('FOREIGN_KEY_CHECKS=1 after failed restore', "after={$fkAfter}");
}
if ($res['success'] === false && str_contains(strtolower((string) ($res['message'] ?? '')), 'safely reverted')) {
    pass('failed restore safely reverted');
} else {
    fail('failed restore safely reverted', json_encode($res));
}
$state = superadmin_restore_state();
if (empty($state['active'])) {
    pass('restore lock cleared after safe revert');
} else {
    fail('restore lock cleared after safe revert', json_encode($state));
}
@unlink($badPath);

echo "\n{$passes} PASS, {$failures} FAIL\n";
exit($failures === 0 ? 0 : 1);
