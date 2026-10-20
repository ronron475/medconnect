<?php
/**
 * Regression tests for code-review fixes (lock path, SQL splitter, CLI Hostinger,
 * emergency break-glass). Restore execution uses an isolated disposable MySQL DB only.
 *
 * Run: php scripts/dev/test_restore_review_fixes.php
 */
declare(strict_types=1);

$failures = 0;
$passes = 0;

function pass(string $m): void
{
    global $passes;
    $passes++;
    echo "PASS  {$m}\n";
}

function fail(string $m, string $d = ''): void
{
    global $failures;
    $failures++;
    echo "FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n";
}

$root = dirname(__DIR__, 2);

// ── 1. Project root / lock path consistency ─────────────────────────────────
echo "[1] Restore lock path consistency\n";

$backupSrc = (string) file_get_contents($root . '/app/includes/superadmin/backup.php');
if (str_contains($backupSrc, 'dirname(__DIR__, 3)') && str_contains($backupSrc, 'function superadmin_project_root')) {
    pass('superadmin_project_root uses three-level dirname fallback');
} else {
    fail('superadmin_project_root uses three-level dirname fallback');
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}
require_once $root . '/app/includes/superadmin/backup.php';

$dirWithBase = realpath(superadmin_backup_dir());
$expected = realpath($root . '/storage/backups');
if ($dirWithBase && $expected && $dirWithBase === $expected) {
    pass('backup dir with BASE_PATH resolves to storage/backups');
} else {
    fail('backup dir with BASE_PATH resolves to storage/backups', ($dirWithBase ?: 'null') . ' vs ' . ($expected ?: 'null'));
}

$rootFromHelper = str_replace('\\', '/', superadmin_project_root());
$rootNorm = str_replace('\\', '/', $root);
if ($rootFromHelper === $rootNorm || realpath($rootFromHelper) === realpath($root)) {
    pass('superadmin_project_root matches repo root');
} else {
    fail('superadmin_project_root matches repo root', $rootFromHelper);
}

// Simulate undefined BASE_PATH path math (same formula as helper without constant).
$fallbackRoot = str_replace('\\', '/', dirname($root . '/app/includes/superadmin', 3));
// dirname of .../app/includes/superadmin with levels=3 from that path:
$fromFile = str_replace('\\', '/', dirname($root . '/app/includes/superadmin/backup.php'));
// Use actual function path math: dirname(__DIR__, 3) where __DIR__ would be .../superadmin
$mathRoot = str_replace('\\', '/', dirname($root . '/app/includes/superadmin', 3));
// Correct reference:
$correct = str_replace('\\', '/', dirname($root . '/app/includes/superadmin/x', 3));
// app/includes/superadmin -> up3 = root
$up3 = str_replace('\\', '/', dirname($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'superadmin', 3));
if ($up3 === $rootNorm || realpath($up3) === realpath($root)) {
    pass('dirname(..., 3) from app/includes/superadmin equals project root');
} else {
    fail('dirname(..., 3) from app/includes/superadmin equals project root', $up3);
}

$wrongUp2 = str_replace('\\', '/', dirname($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'superadmin', 2));
if ($wrongUp2 !== $rootNorm && str_ends_with($wrongUp2, '/app')) {
    pass('dirname(..., 2) would incorrectly land on app/ (regression guard)');
} else {
    fail('dirname(..., 2) would incorrectly land on app/ (regression guard)', $wrongUp2);
}

$lockWith = str_replace('\\', '/', superadmin_restore_lock_path());
if (str_contains($lockWith, '/storage/backups/') && str_ends_with($lockWith, SUPERADMIN_RESTORE_LOCK_FILE)) {
    pass('restore lock path is under storage/backups');
} else {
    fail('restore lock path is under storage/backups', $lockWith);
}

// ── 2. SQL splitter quote safety ────────────────────────────────────────────
echo "\n[2] SQL statement splitter (quoted semicolons)\n";

$sql = "-- medConnect backup\n"
    . "SET FOREIGN_KEY_CHECKS=0;\n"
    . "INSERT INTO `notes` (`body`) VALUES ('hello; world');\n"
    . "INSERT INTO `notes` (`body`) VALUES (\"a;b\",\"c\");\n"
    . "INSERT INTO `t` (`n`) VALUES ('it''s; fine');\n"
    . "SET FOREIGN_KEY_CHECKS=1;\n";

$parts = superadmin_backup_split_sql_statements($sql);
if (count($parts) === 5) {
    pass('splitter yields 5 statements including quoted semicolons');
} else {
    fail('splitter yields 5 statements including quoted semicolons', 'count=' . count($parts));
}

$joined = implode("\n", $parts);
if (str_contains($joined, "'hello; world'") && str_contains($joined, "'it''s; fine'")) {
    pass('quoted semicolon payloads preserved intact');
} else {
    fail('quoted semicolon payloads preserved intact');
}

$naive = preg_split('/;\s*\n/', str_replace(["\r\n", "\r"], "\n", $sql)) ?: [];
if (count($naive) > count($parts)) {
    pass('naive ;\\n split would over-split compared to quote-aware splitter');
} else {
    // Still OK if equal for this sample — assert quote-aware kept INSERT whole
    $hasBroken = false;
    foreach ($parts as $p) {
        if (str_starts_with(trim($p), 'world') || trim($p) === "world'") {
            $hasBroken = true;
        }
    }
    if (!$hasBroken) {
        pass('quote-aware splitter did not emit broken trailing fragment');
    } else {
        fail('quote-aware splitter did not emit broken trailing fragment');
    }
}

// Disposable DB apply (never live medconnect)
echo "\n[3] Disposable DB apply of dump with quoted semicolons\n";
$testDb = null;
$rootPdo = null;
try {
    $rootPdo = new PDO('mysql:host=localhost;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $testDb = 'mc_review_fix_' . bin2hex(random_bytes(3));
    $rootPdo->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $tp = new PDO('mysql:host=localhost;port=3306;dbname=' . $testDb . ';charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $liveName = 'medconnect';
    $connected = (string) $tp->query('SELECT DATABASE()')->fetchColumn();
    if (strcasecmp($connected, $liveName) === 0) {
        fail('refused: connected to live database name');
    } else {
        pass('disposable connection is not live medconnect');
    }

    $dump = "CREATE TABLE `notes` (`id` INT PRIMARY KEY AUTO_INCREMENT, `body` VARCHAR(255) NOT NULL);\n"
        . "INSERT INTO `notes` (`body`) VALUES ('hello; world');\n"
        . "INSERT INTO `notes` (`body`) VALUES ('it''s; fine');\n";
    superadmin_backup_exec_sql_dump($tp, $dump);
    $rows = $tp->query('SELECT body FROM notes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if ($rows === ['hello; world', "it's; fine"]) {
        pass('disposable restore preserved semicolon values');
    } else {
        fail('disposable restore preserved semicolon values', json_encode($rows));
    }
} catch (Throwable $e) {
    fail('disposable MySQL test setup/apply', $e->getMessage());
} finally {
    if ($rootPdo && $testDb) {
        try {
            $rootPdo->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
            pass('disposable test database dropped');
        } catch (Throwable $e) {
            fail('disposable test database dropped', $e->getMessage());
        }
    }
}

// ── 4. CLI Hostinger detection ──────────────────────────────────────────────
echo "\n[4] CLI Hostinger path detection\n";
// Mirror the production regex used by medconnect_cli_looks_like_hostinger() without connecting DB.
$cliProbe = static function (string $probe): bool {
    return (bool) preg_match(
        '#(?:/home/u\d+|/domains/[^/]+/public_html|/public_html)#i',
        str_replace('\\', '/', $probe)
    );
};
$dbSrcForCli = (string) file_get_contents($root . '/config/db.php');
if (str_contains($dbSrcForCli, 'function medconnect_cli_looks_like_hostinger')
    && str_contains($dbSrcForCli, '/home/u\\d+|/domains/[^/]+/public_html|/public_html')) {
    pass('db.php defines Hostinger CLI path helper with shared-hosting patterns');
} else {
    fail('db.php defines Hostinger CLI path helper with shared-hosting patterns');
}
if ($cliProbe('/home/u520834156/domains/medconnect.bccbsis.com/public_html/config')) {
    pass('detects Hostinger home/u####/domains/.../public_html path');
} else {
    fail('detects Hostinger home/u####/domains/.../public_html path');
}
if (!$cliProbe('C:/xampp/htdocs/medconnect/config')) {
    pass('does not treat local XAMPP path as Hostinger');
} else {
    fail('does not treat local XAMPP path as Hostinger');
}

// ── 5. Emergency break-glass allowlist ──────────────────────────────────────
echo "\n[5] Emergency lockdown break-glass allowlist\n";

if (!function_exists('superadmin_restore_barrier_is_emergency_break_glass')) {
    fail('break-glass helper exists');
} else {
    pass('break-glass helper exists');
}

$_SESSION = [];
if (superadmin_restore_barrier_is_emergency_break_glass('/medconnect/app/api/login.php', 'POST')) {
    pass('allows login POST during emergency break-glass check');
} else {
    fail('allows login POST during emergency break-glass check');
}
if (!superadmin_restore_barrier_is_emergency_break_glass('/medconnect/app/api/login.php', 'GET')) {
    pass('rejects login GET (POST-only) for break-glass');
} else {
    fail('rejects login GET (POST-only) for break-glass');
}
if (!superadmin_restore_barrier_is_emergency_break_glass('/medconnect/app/api/patient/foo.php', 'POST')) {
    pass('rejects unrelated patient write API without superadmin session');
} else {
    fail('rejects unrelated patient write API without superadmin session');
}

$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'superadmin';
$_GET['action'] = 'emergency_clear';
if (superadmin_restore_barrier_is_emergency_break_glass('/medconnect/app/api/superadmin/backup.php', 'POST')) {
    pass('allows superadmin emergency_clear with session');
} else {
    fail('allows superadmin emergency_clear with session');
}
$_SESSION['user_role'] = 'admin';
if (!superadmin_restore_barrier_is_emergency_break_glass('/medconnect/app/api/superadmin/backup.php', 'POST')) {
    pass('rejects ordinary admin for emergency_clear break-glass');
} else {
    fail('rejects ordinary admin for emergency_clear break-glass');
}
$_SESSION = [];
unset($_GET['action']);

// Routed recovery UI: public/view.php?path=superadmin/backup.php (not bare /views/...)
if (!function_exists('superadmin_restore_barrier_is_superadmin_backup_view')) {
    fail('backup view helper exists');
} else {
    pass('backup view helper exists');
}

$_GET = ['path' => 'superadmin/backup.php'];
$_SERVER['REQUEST_URI'] = '/medconnect/views/superadmin/backup.php';
if (superadmin_restore_barrier_is_superadmin_backup_view('/medconnect/public/view.php')) {
    pass('recognizes view.php + path=superadmin/backup.php as recovery UI');
} else {
    fail('recognizes view.php + path=superadmin/backup.php as recovery UI');
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'superadmin';
if (superadmin_restore_barrier_is_emergency_break_glass('/medconnect/public/view.php', 'GET')) {
    pass('allows superadmin recovery UI via view.php router');
} else {
    fail('allows superadmin recovery UI via view.php router');
}

$_GET = ['path' => 'patient/dashboard.php'];
$_SERVER['REQUEST_URI'] = '/medconnect/views/patient/dashboard.php';
if (!superadmin_restore_barrier_is_superadmin_backup_view('/medconnect/public/view.php')) {
    pass('rejects unrelated patient view path on view.php');
} else {
    fail('rejects unrelated patient view path on view.php');
}
if (!superadmin_restore_barrier_is_emergency_break_glass('/medconnect/public/view.php', 'GET')) {
    pass('break-glass rejects unrelated portal view on view.php');
} else {
    fail('break-glass rejects unrelated portal view on view.php');
}

$_GET = ['path' => 'admin/backup.php'];
if (!superadmin_restore_barrier_is_superadmin_backup_view('/medconnect/public/view.php')) {
    pass('rejects admin backup view path on view.php');
} else {
    fail('rejects admin backup view path on view.php');
}

$_GET = [];
$_SERVER['REQUEST_URI'] = '/medconnect/public/view.php';
if (!superadmin_restore_barrier_is_superadmin_backup_view('/medconnect/public/view.php')) {
    pass('rejects bare view.php without recovery path');
} else {
    fail('rejects bare view.php without recovery path');
}

// MC_VIEW_PATH takes precedence when already defined (as after view.php bootstrap)
$_GET = ['path' => 'patient/dashboard.php'];
if (!defined('MC_VIEW_PATH')) {
    define('MC_VIEW_PATH', 'superadmin/backup.php');
}
if (superadmin_restore_barrier_is_superadmin_backup_view('/medconnect/public/view.php')) {
    pass('honors MC_VIEW_PATH over conflicting GET path');
} else {
    fail('honors MC_VIEW_PATH over conflicting GET path');
}
$_SESSION = [];
unset($_GET['path']);
$_SERVER['REQUEST_URI'] = '';

// File-backed lockdown: non-allowlisted script exits 503 when enforced in subprocess
$lockPath = $root . '/storage/backups/.restore_state_review_test_' . bin2hex(random_bytes(4)) . '.lock';
file_put_contents($lockPath, json_encode([
    'status' => 'emergency_lockdown',
    'reason' => 'review test',
    'token' => 'test',
    'started_at' => date('c'),
]));
$runner = sys_get_temp_dir() . '/mc_barrier_review_' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($runner, '<?php
define("BASE_PATH", ' . var_export($root, true) . ');
$_SERVER["MEDCONNECT_TEST_ENFORCE_BARRIER"] = "1";
$_SERVER["SCRIPT_NAME"] = "/medconnect/app/api/patient/foo.php";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"] = "/medconnect/app/api/patient/foo.php";
$_SERVER["HTTP_ACCEPT"] = "application/json";
require ' . var_export($root . '/app/includes/superadmin/backup.php', true) . ';
superadmin_restore_barrier_enforce(' . var_export($lockPath, true) . ');
echo "REACHED";
');
$out = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $code);
$joinedOut = implode("\n", $out);
@unlink($runner);
@unlink($lockPath);
if (!str_contains($joinedOut, 'REACHED') && str_contains($joinedOut, 'emergency_lockdown')) {
    pass('barrier blocks non-allowlisted API during emergency lockdown');
} else {
    fail('barrier blocks non-allowlisted API during emergency lockdown', $joinedOut);
}

$lockPath2 = $root . '/storage/backups/.restore_state_review_test_' . bin2hex(random_bytes(4)) . '.lock';
file_put_contents($lockPath2, json_encode([
    'status' => 'emergency_lockdown',
    'reason' => 'review test',
    'token' => 'test',
    'started_at' => date('c'),
]));
$runner2 = sys_get_temp_dir() . '/mc_barrier_review_login_' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($runner2, '<?php
define("BASE_PATH", ' . var_export($root, true) . ');
$_SERVER["MEDCONNECT_TEST_ENFORCE_BARRIER"] = "1";
$_SERVER["SCRIPT_NAME"] = "/medconnect/app/api/login.php";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"] = "/medconnect/app/api/login.php";
$_SERVER["HTTP_ACCEPT"] = "application/json";
require ' . var_export($root . '/app/includes/superadmin/backup.php', true) . ';
superadmin_restore_barrier_enforce(' . var_export($lockPath2, true) . ');
echo "LOGIN_ALLOWED";
');
$out2 = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner2) . ' 2>&1', $out2);
$joined2 = implode("\n", $out2);
@unlink($runner2);
@unlink($lockPath2);
if (str_contains($joined2, 'LOGIN_ALLOWED')) {
    pass('barrier allows login POST during emergency lockdown');
} else {
    fail('barrier allows login POST during emergency lockdown', $joined2);
}

// Enforce: routed recovery UI allowed; unrelated view blocked
$lockPath3 = $root . '/storage/backups/.restore_state_review_test_' . bin2hex(random_bytes(4)) . '.lock';
file_put_contents($lockPath3, json_encode([
    'status' => 'emergency_lockdown',
    'reason' => 'review test',
    'token' => 'test',
    'started_at' => date('c'),
]));
$runner3 = sys_get_temp_dir() . '/mc_barrier_review_view_' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($runner3, '<?php
define("BASE_PATH", ' . var_export($root, true) . ');
$_SERVER["MEDCONNECT_TEST_ENFORCE_BARRIER"] = "1";
$_SERVER["SCRIPT_NAME"] = "/medconnect/public/view.php";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/medconnect/views/superadmin/backup.php";
$_GET["path"] = "superadmin/backup.php";
$_SESSION = ["user_id" => 1, "user_role" => "superadmin"];
require ' . var_export($root . '/app/includes/superadmin/backup.php', true) . ';
superadmin_restore_barrier_enforce(' . var_export($lockPath3, true) . ');
echo "BACKUP_VIEW_ALLOWED";
');
$out3 = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner3) . ' 2>&1', $out3);
$joined3 = implode("\n", $out3);
@unlink($runner3);
if (str_contains($joined3, 'BACKUP_VIEW_ALLOWED')) {
    pass('barrier allows routed superadmin backup view during emergency lockdown');
} else {
    fail('barrier allows routed superadmin backup view during emergency lockdown', $joined3);
}

$runner4 = sys_get_temp_dir() . '/mc_barrier_review_badview_' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($runner4, '<?php
define("BASE_PATH", ' . var_export($root, true) . ');
$_SERVER["MEDCONNECT_TEST_ENFORCE_BARRIER"] = "1";
$_SERVER["SCRIPT_NAME"] = "/medconnect/public/view.php";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/medconnect/views/patient/dashboard.php";
$_GET["path"] = "patient/dashboard.php";
$_SESSION = ["user_id" => 1, "user_role" => "superadmin"];
require ' . var_export($root . '/app/includes/superadmin/backup.php', true) . ';
superadmin_restore_barrier_enforce(' . var_export($lockPath3, true) . ');
echo "PATIENT_VIEW_REACHED";
');
$out4 = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner4) . ' 2>&1', $out4);
$joined4 = implode("\n", $out4);
@unlink($runner4);
@unlink($lockPath3);
if (!str_contains($joined4, 'PATIENT_VIEW_REACHED') && str_contains($joined4, 'emergency_lockdown')) {
    pass('barrier blocks unrelated portal view via view.php during emergency lockdown');
} else {
    fail('barrier blocks unrelated portal view via view.php during emergency lockdown', $joined4);
}

$cliScript = $root . '/scripts/ops/clear_emergency_lockdown.php';
if (is_file($cliScript) && str_contains((string) file_get_contents($cliScript), 'CLEAR EMERGENCY LOCKDOWN')) {
    pass('CLI emergency lockdown clear script exists with confirmation phrase');
} else {
    fail('CLI emergency lockdown clear script exists with confirmation phrase');
}

// Credentials must stay in env/.env — no hardcoded mail/OCR/AI secrets in source
echo "\n[6] No hardcoded mail/OCR/AI secrets in source\n";
$mailSrc = (string) file_get_contents($root . '/app/includes/mailer.php');
$ocrSrc = (string) file_get_contents($root . '/config/ocr_config.php');
$appSrc = (string) file_get_contents($root . '/config/app.php');
$tempMailRecovery = str_contains($mailSrc, 'TODO(temporary-hostinger-recovery)');
$mailPassHardcoded = (bool) preg_match("/'MAIL_PASSWORD'\\s*=>\\s*'[^']+'/", $mailSrc);
if (!str_contains($mailSrc, 'Temporary Hostinger online demo fallbacks')
    && ($tempMailRecovery || !$mailPassHardcoded)
    && !preg_match("/'EMAIL_API_KEY'\\s*=>\\s*'[^']+'/", $mailSrc)
    && !preg_match("/'MEDCONNECT_AI_SERVICE_TOKEN'\\s*=>\\s*'[^']+'/", $mailSrc)
) {
    pass('mailer.php has no hardcoded mail/API token fallbacks (temp Gmail recovery TODO allowed)');
} else {
    fail('mailer.php has no hardcoded mail/API token fallbacks (temp Gmail recovery TODO allowed)');
}
if (!str_contains($ocrSrc, 'Temporary Hostinger demo fallback')
    && !preg_match("/'OCR_SPACE_API_KEY'\\s*=>\\s*'[^']+'/", $ocrSrc)
) {
    pass('ocr_config.php has no hardcoded OCR.Space key fallback');
} else {
    fail('ocr_config.php has no hardcoded OCR.Space key fallback');
}
if (!str_contains($appSrc, 'TEMP_HOSTINGER_AI_TOKEN')
    && !preg_match('/\$__mcAiTok\s*=\s*[\'"][^\'"]+[\'"]/', $appSrc)
) {
    pass('app.php has no hardcoded AI service token fallback');
} else {
    fail('app.php has no hardcoded AI service token fallback');
}

echo "\n{$passes} PASS, {$failures} FAIL\n";
exit($failures === 0 ? 0 : 1);
