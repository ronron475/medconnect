<?php
/**
 * Clear banner_image + attachment on all announcements (keep records).
 * Does NOT delete files from disk/storage.
 *
 * Usage:
 *   php scripts/dev/reset_announcement_files.php              # local dry-run
 *   php scripts/dev/reset_announcement_files.php --execute    # local clear
 *   php scripts/dev/reset_announcement_files.php --prod       # production dry-run (remote MySQL)
 *   php scripts/dev/reset_announcement_files.php --prod --execute
 */
declare(strict_types=1);

$prod = in_array('--prod', $argv ?? [], true) || in_array('--cloud', $argv ?? [], true);
$execute = in_array('--execute', $argv ?? [], true);

require dirname(__DIR__, 2) . '/bootstrap.php';

if ($prod) {
    $prodHost = trim((string) (getenv('MEDCONNECT_PROD_DB_HOST') ?: ($_ENV['MEDCONNECT_PROD_DB_HOST'] ?? '')));
    $prodName = trim((string) (getenv('MEDCONNECT_PROD_DB_NAME') ?: ($_ENV['MEDCONNECT_PROD_DB_NAME'] ?? 'u520834156_meDBConnect26')));
    $prodUser = trim((string) (getenv('MEDCONNECT_PROD_DB_USER') ?: ($_ENV['MEDCONNECT_PROD_DB_USER'] ?? 'u520834156_usrMedConnect')));
    $prodPass = (string) (getenv('MEDCONNECT_PROD_DB_PASS') ?: ($_ENV['MEDCONNECT_PROD_DB_PASS'] ?? ''));

    if ($prodHost === '') {
        $prodHost = trim((string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '')));
    }
    if ($prodPass === '' && getenv('DB_PASS') !== false) {
        $prodPass = (string) getenv('DB_PASS');
    } elseif ($prodPass === '' && array_key_exists('DB_PASS', $_ENV)) {
        $prodPass = (string) $_ENV['DB_PASS'];
    }

    if ($prodHost === '' || $prodHost === 'localhost' || $prodPass === '') {
        echo "STOPPED: Need remote production MySQL host + password.\n";
        echo "Set MEDCONNECT_PROD_DB_HOST / MEDCONNECT_PROD_DB_PASS (Hostinger Remote MySQL).\n";
        exit(2);
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . $prodHost . ';dbname=' . $prodName . ';charset=utf8mb4',
            $prodUser,
            $prodPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        echo "STOPPED: Could not connect to production DB.\n";
        echo 'error=' . $e->getMessage() . PHP_EOL;
        exit(2);
    }
    echo 'target=production host=' . $prodHost . ' db=' . $prodName . PHP_EOL;
} else {
    require_once BASE_PATH . '/config/db.php';
    echo 'target=local DB_HOST=' . DB_HOST . ' DB_NAME=' . DB_NAME . PHP_EOL;
}

echo 'mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN') . PHP_EOL;

$rows = $pdo->query(
    "SELECT id, title, status, banner_image, attachment
     FROM announcements
     WHERE deleted_at IS NULL
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$withFiles = array_values(array_filter($rows, static function (array $r): bool {
    return trim((string)($r['banner_image'] ?? '')) !== '' || trim((string)($r['attachment'] ?? '')) !== '';
}));

echo 'announcements=' . count($rows) . ' with_files=' . count($withFiles) . PHP_EOL;
foreach ($rows as $r) {
    echo sprintf(
        "  id=%d status=%s title=%s banner=%s attach=%s\n",
        (int)$r['id'],
        $r['status'],
        substr((string)$r['title'], 0, 40),
        $r['banner_image'] ?: '-',
        $r['attachment'] ?: '-'
    );
}

if (!$execute) {
    echo "DRY-RUN only. Re-run with --execute to clear banner_image and attachment.\n";
    exit(0);
}

$stmt = $pdo->prepare(
    "UPDATE announcements
     SET banner_image = NULL, attachment = NULL
     WHERE deleted_at IS NULL
       AND (banner_image IS NOT NULL OR attachment IS NOT NULL)"
);
$stmt->execute();
echo 'cleared_rows=' . $stmt->rowCount() . PHP_EOL;
echo "Done. Announcement records kept; files on disk untouched.\n";
