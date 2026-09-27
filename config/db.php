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

$envString = static function (string $key): ?string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        if (!array_key_exists($key, $_ENV)) {
            return null;
        }
        $value = (string) $_ENV[$key];
    }

    return (string) $value;
};

if ($isLocal) {
    $dbHost = 'localhost';
    $dbName = 'medconnect';
    $dbUser = 'root';
    $dbPass = '';
    // Explicit local overrides only. Production values in .env must not replace XAMPP.
    if ($dbEnv === 'local' || $dbEnv === 'dev') {
        $dbHost = $envString('DB_HOST') ?? $dbHost;
        $dbName = $envString('DB_NAME') ?? $dbName;
        $dbUser = $envString('DB_USER') ?? $dbUser;
        if ($envString('DB_PASS') !== null) {
            $dbPass = (string) $envString('DB_PASS');
        }
    }
} else {
    // Hostinger PHP uses localhost MySQL unless DB_HOST is set (Vercel remote host).
    $dbHost = $envString('DB_HOST') ?? ($onVercel ? '' : 'localhost');
    $dbName = $envString('DB_NAME') ?? '';
    $dbUser = $envString('DB_USER') ?? '';
    $dbPass = $envString('DB_PASS');
    if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPass === null || $dbPass === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Database is not configured.\n\n"
            . "Set DB_HOST, DB_NAME, DB_USER, and DB_PASS in the server environment.\n"
            . "Do not commit those values.";
        exit;
    }
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
