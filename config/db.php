<?php
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}

// Database configuration
// LOCAL: use these for XAMPP/localhost development
define('DB_HOST', 'localhost');
define('DB_NAME', 'medconnect');  // Updated for local XAMPP environment
define('DB_USER', 'root');         // ← XAMPP default
define('DB_PASS', '');             // ← XAMPP default (blank)

// PRODUCTION:
// Do not hardcode production credentials in this repository.
// Load them via environment variables or a non-committed config file.
define('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

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
