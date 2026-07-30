<?php
require dirname(__DIR__, 2) . '/config/db.php';
$email = 'walanacursor12345@gmail.com';
$s = $pdo->prepare('SELECT id, email, role, password, is_active FROM users WHERE email = ? LIMIT 1');
$s->execute([$email]);
$r = $s->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    echo "NOT_FOUND\n";
    exit(0);
}
$hash = (string) ($r['password'] ?? '');
$isHashed = str_starts_with($hash, '$2y$')
    || str_starts_with($hash, '$2a$')
    || str_starts_with($hash, '$2b$')
    || str_starts_with($hash, '$argon');
echo "FOUND\n";
echo "id=" . $r['id'] . "\n";
echo "email=" . $r['email'] . "\n";
echo "role=" . $r['role'] . "\n";
echo "is_active=" . $r['is_active'] . "\n";
echo "is_hashed=" . ($isHashed ? 'yes' : 'no') . "\n";
echo "hash_prefix=" . substr($hash, 0, 7) . "...\n";
echo "hash_len=" . strlen($hash) . "\n";
