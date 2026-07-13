<?php
/**
 * Ensure at least one Super Admin account exists (local dev helper).
 * Run: php scripts/dev/ensure_superadmin_user.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/superadmin/schema.php';

superadmin_ensure_schema($pdo);

$existing = $pdo->query("SELECT id, email, first_name, last_name FROM users WHERE role = 'superadmin' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
if ($existing) {
    echo "Super Admin account(s) already exist:\n";
    foreach ($existing as $row) {
        echo "  - {$row['email']} ({$row['first_name']} {$row['last_name']})\n";
    }
    exit(0);
}

$admin = $pdo->query("SELECT id, email, first_name, last_name FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($admin) {
    $pdo->prepare("UPDATE users SET role = 'superadmin' WHERE id = ?")->execute([(int) $admin['id']]);
    superadmin_link_user($pdo, (int) $admin['id'], null);
    echo "Promoted existing admin to Super Admin:\n";
    echo "  Email: {$admin['email']}\n";
    echo "  Password: unchanged (use your existing admin password)\n";
    exit(0);
}

$email = 'superadmin@medconnect.local';
$password = 'SuperAdmin@2026';
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (first_name, last_name, email, password, role, is_active, account_status, created_at)
    VALUES ('Super', 'Admin', ?, ?, 'superadmin', 1, 'active', NOW())
");
$stmt->execute([$email, $hash]);
$userId = (int) $pdo->lastInsertId();
superadmin_link_user($pdo, $userId, null);

echo "Created default Super Admin account:\n";
echo "  Email: {$email}\n";
echo "  Password: {$password}\n";
echo "  Login: " . BASE_URL . "/public/login.php\n";
