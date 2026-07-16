<?php
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}

require_once __DIR__ . '/env_loader.php';

/**
 * Local XAMPP vs Hostinger cloud / Vercel.
 * Override with .env or Vercel env: DB_ENV, DB_HOST, DB_NAME, DB_USER, DB_PASS.
 *
 * On Vercel, DB_HOST must be the Hostinger *remote* MySQL hostname
 * (hPanel → Databases → Remote MySQL), not "localhost".
 */
$dbEnv = strtolower((string) (getenv('DB_ENV') ?: ($_ENV['DB_ENV'] ?? '')));
$hostHeader = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
$hostHeader = preg_replace('/:\d+$/', '', $hostHeader) ?: '';
$onVercel = getenv('VERCEL') !== false
    || getenv('VERCEL_ENV') !== false
    || !empty($_ENV['VERCEL'])
    || !empty($_ENV['VERCEL_ENV']);

$isLocal = match (true) {
    $onVercel => false,
    $dbEnv === 'local' || $dbEnv === 'dev' => true,
    $dbEnv === 'cloud' || $dbEnv === 'production' || $dbEnv === 'prod' => false,
    $hostHeader === '' && PHP_SAPI === 'cli' => true,
    in_array($hostHeader, ['localhost', '127.0.0.1', '::1'], true) => true,
    (bool) preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $hostHeader) => true,
    default => false,
};

if ($isLocal) {
    $dbHost = 'localhost';
    $dbName = 'medconnect';
    $dbUser = 'root';
    $dbPass = '';
} else {
    // Hostinger: use localhost when PHP runs on Hostinger; remote host on Vercel.
    $dbHost = $onVercel
        ? (string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ''))
        : 'localhost';
    $dbName = 'u520834156_meDBConnect26';
    $dbUser = 'u520834156_usrMedConnect';
    $dbPass = '0#KQFw#m;p@V';
}

// Optional per-key overrides from environment / .env
$dbHost = (string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? $dbHost));
$dbName = (string) (getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? $dbName));
$dbUser = (string) (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? $dbUser));
// Allow blank password (XAMPP root); only override when the key is set
if (getenv('DB_PASS') !== false) {
    $dbPass = (string) getenv('DB_PASS');
} elseif (array_key_exists('DB_PASS', $_ENV)) {
    $dbPass = (string) $_ENV['DB_PASS'];
}

if ($onVercel && $dbHost === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database not configured for Vercel.\n\n"
        . "In Vercel → Project → Settings → Environment Variables, set:\n"
        . "  DB_HOST = your Hostinger Remote MySQL hostname (hPanel → Databases)\n"
        . "  DB_NAME = u520834156_meDBConnect26\n"
        . "  DB_USER = u520834156_usrMedConnect\n"
        . "  DB_PASS = (your password)\n\n"
        . "Also enable Remote MySQL in Hostinger and allow access from % (any host).";
    exit;
}

if (!defined('DB_HOST')) {
    define('DB_HOST', $dbHost);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $dbName);
}
if (!defined('DB_USER')) {
    define('DB_USER', $dbUser);
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $dbPass);
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Hostinger default is often utf8mb4_general_ci; app schema uses utf8mb4_unicode_ci.
    // Without this, string/ENUM comparisons (e.g. day_of_week, DAYNAME) throw error 1267.
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");

    if (defined('APP_TIMEZONE')) {
        $tz = new DateTimeZone(APP_TIMEZONE);
        $offset = $tz->getOffset(new DateTimeImmutable('now', $tz));
        $hours = intdiv($offset, 3600);
        $mins  = intdiv(abs($offset) % 3600, 60);
        $pdo->exec(sprintf(
            "SET time_zone = '%+03d:%02d'",
            $hours,
            $mins
        ));
    }
} catch (PDOException $e) {
    http_response_code(500);
    // Never leak DB connection details to clients.
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $wantsJson = str_contains($uri, '/app/api/');
    if (!$wantsJson) {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $xrw = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $wantsJson = stripos($accept, 'application/json') !== false
            || strtolower($xrw) === 'xmlhttprequest';
    }
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database connection failed.';
    exit;
}
