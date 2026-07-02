<?php
/**
 * Add BHW role to users table (if needed) and create/update BHW account.
 * Run: php scripts/dev/seed_bhw_user.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$email    = 'bhw@medconnect.local';
$password = 'bhw@1234';

require __DIR__ . '/apply_bhw_sectors.php';

$user_cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('barangay_id', $user_cols, true)) {
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN barangay_id INT(11) UNSIGNED NULL AFTER role, ADD KEY idx_users_barangay (barangay_id)');
        echo "OK  users.barangay_id column added\n";
        $pdo->exec("UPDATE users SET barangay_id = 1 WHERE role = 'bhw' AND (barangay_id IS NULL OR barangay_id = 0)");
    } catch (PDOException $e) {
        echo 'WARN barangay_id column: ' . $e->getMessage() . PHP_EOL;
    }
}

// Extend role ENUM to include bhw (MySQL 8+ / MariaDB)
try {
    $pdo->exec("
        ALTER TABLE users
        MODIFY COLUMN role ENUM('patient', 'provider', 'admin', 'bhw') NOT NULL DEFAULT 'patient'
    ");
    echo "OK  users.role ENUM now includes 'bhw'\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'already')) {
        echo "OK  role ENUM already supports bhw\n";
    } else {
        echo "WARN role ENUM: " . $e->getMessage() . "\n";
    }
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$existing = $stmt->fetch();

$columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
$hasVerified = in_array('is_email_verified', $columns, true);
$hasBarangay = in_array('barangay_id', $columns, true);

if ($existing) {
    $sql = 'UPDATE users SET first_name = ?, last_name = ?, password = ?, role = ?, is_active = 1';
    $params = ['Barangay', 'Health Worker', $hash, 'bhw'];
    if ($hasBarangay) {
        $sql .= ', barangay_id = 1';
    }
    if ($hasVerified) {
        $sql .= ', is_email_verified = 1, email_verified_at = COALESCE(email_verified_at, NOW())';
    }
    $sql .= ' WHERE email = ?';
    $params[] = $email;
    $pdo->prepare($sql)->execute($params);
    echo "OK  Updated existing BHW user (id {$existing['id']})\n";
} else {
    if ($hasVerified && $hasBarangay) {
        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, barangay_id, is_active, is_email_verified, email_verified_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'bhw', 1, 1, 1, NOW(), NOW(), NOW())
        ")->execute(['Barangay', 'Health Worker', $email, $hash]);
    } elseif ($hasVerified) {
        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, is_active, is_email_verified, email_verified_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'bhw', 1, 1, NOW(), NOW(), NOW())
        ")->execute(['Barangay', 'Health Worker', $email, $hash]);
    } elseif ($hasBarangay) {
        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, barangay_id, is_active)
            VALUES (?, ?, ?, ?, 'bhw', 1, 1)
        ")->execute(['Barangay', 'Health Worker', $email, $hash]);
    } else {
        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, is_active)
            VALUES (?, ?, ?, ?, 'bhw', 1)
        ")->execute(['Barangay', 'Health Worker', $email, $hash]);
    }
    echo "OK  Created BHW user\n";
}

if (!password_verify($password, $hash)) {
    echo "FAIL password verify\n";
    exit(1);
}

$check = $pdo->prepare('SELECT id, email, role, is_active, barangay_id FROM users WHERE email = ?');
$check->execute([$email]);
$row = $check->fetch();
echo "\nBHW login (use Email field on sign-in page):\n";
echo "  Email:    {$row['email']}\n";
echo "  Password: {$password}\n";
echo "  Role:     {$row['role']} (active={$row['is_active']})\n";
echo "  Sector:   barangay_id=" . ($row['barangay_id'] ?? 'null') . " (Poblacion)\n";
echo "  Redirect: " . BASE_URL . "/views/bhw/dashboard.php\n";
