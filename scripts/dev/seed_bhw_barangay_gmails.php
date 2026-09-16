<?php
/**
 * Seed one BHW login per Bago City barangay (Gmail-format emails).
 * Skips Poblacion (existing account). Idempotent create/update by email.
 *
 * Run: php scripts/dev/seed_bhw_barangay_gmails.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/barangays_bago.php';

/**
 * Local-part for [barangayname]@gmail.com / [barangayname]12345.
 * Lowercase; drop spaces and periods; keep hyphens used in official names.
 */
function bhw_barangay_account_slug(string $barangayName): string
{
    $slug = strtolower(trim($barangayName));
    $slug = str_replace(['.', ' '], '', $slug);
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? $slug;

    return $slug;
}

$columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
$hasVerified = in_array('is_email_verified', $columns, true);
$hasBarangay = in_array('barangay_id', $columns, true);
$hasAccountStatus = in_array('account_status', $columns, true);
$hasMustChange = in_array('must_change_password', $columns, true);
$hasCreatedAt = in_array('created_at', $columns, true);
$hasUpdatedAt = in_array('updated_at', $columns, true);

if (!$hasBarangay) {
    fwrite(STDERR, "FAIL users.barangay_id is required\n");
    exit(1);
}

$barangays = barangays_list_bago_city($pdo);
$created = 0;
$updated = 0;
$skipped = 0;

$findByEmail = $pdo->prepare('SELECT id, role, barangay_id FROM users WHERE email = ? LIMIT 1');

foreach ($barangays as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    $barangayId = (int) ($row['id'] ?? 0);
    if ($name === '' || $barangayId <= 0) {
        continue;
    }

    if (strcasecmp($name, 'Poblacion') === 0) {
        echo "SKIP  Poblacion (existing account)\n";
        $skipped++;
        continue;
    }

    $slug = bhw_barangay_account_slug($name);
    if ($slug === '') {
        echo "WARN  empty slug for barangay: {$name}\n";
        continue;
    }

    $email = $slug . '@gmail.com';
    $password = $slug . '12345';
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

    $findByEmail->execute([$email]);
    $existing = $findByEmail->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $sets = [
            'first_name = ?',
            'last_name = ?',
            'password = ?',
            'role = ?',
            'barangay_id = ?',
            'is_active = 1',
        ];
        $params = [$name, 'BHW', $hash, 'bhw', $barangayId];

        if ($hasVerified) {
            $sets[] = 'is_email_verified = 1';
            $sets[] = 'email_verified_at = COALESCE(email_verified_at, NOW())';
        }
        if ($hasAccountStatus) {
            $sets[] = "account_status = 'active'";
        }
        if ($hasMustChange) {
            $sets[] = 'must_change_password = 0';
        }
        if ($hasUpdatedAt) {
            $sets[] = 'updated_at = NOW()';
        }

        $params[] = $email;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE email = ?')->execute($params);
        echo "OK    Updated  {$email}  /  {$password}  →  {$name} (id {$barangayId})\n";
        flush();
        $updated++;
        continue;
    }

    $fields = ['first_name', 'last_name', 'email', 'password', 'role', 'barangay_id', 'is_active'];
    $values = [$name, 'BHW', $email, $hash, 'bhw', $barangayId, 1];
    $placeholders = array_fill(0, count($fields), '?');

    if ($hasVerified) {
        $fields[] = 'is_email_verified';
        $fields[] = 'email_verified_at';
        $values[] = 1;
        $values[] = date('Y-m-d H:i:s');
        $placeholders[] = '?';
        $placeholders[] = '?';
    }
    if ($hasAccountStatus) {
        $fields[] = 'account_status';
        $values[] = 'active';
        $placeholders[] = '?';
    }
    if ($hasMustChange) {
        $fields[] = 'must_change_password';
        $values[] = 0;
        $placeholders[] = '?';
    }
    if ($hasCreatedAt) {
        $fields[] = 'created_at';
        $values[] = date('Y-m-d H:i:s');
        $placeholders[] = '?';
    }
    if ($hasUpdatedAt) {
        $fields[] = 'updated_at';
        $values[] = date('Y-m-d H:i:s');
        $placeholders[] = '?';
    }

    $sql = 'INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($values);
    echo "OK    Created  {$email}  /  {$password}  →  {$name} (id {$barangayId})\n";
    flush();
    $created++;
}

echo "\nDone. created={$created} updated={$updated} skipped={$skipped}\n";
