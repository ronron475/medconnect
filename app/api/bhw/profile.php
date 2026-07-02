<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_scope.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/profile_picture.php';

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$userId = (int) $_SESSION['user_id'];

if (($_GET['action'] ?? $_POST['action'] ?? 'get') === 'get') {
    profile_picture_ensure_schema($pdo);
    $s = $pdo->prepare('
        SELECT id, first_name, last_name, email, phone, profile_picture, is_active, is_email_verified,
               created_at, updated_at
        FROM users WHERE id = ? LIMIT 1
    ');
    $s->execute([$userId]);
    $profile = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $profile['picture_url'] = profile_picture_public_url($profile['profile_picture'] ?? null);
    Api::success([
        'profile' => $profile,
        'barangay' => $ctx['barangay_name'],
        'metrics' => BhwWorkflows::getDashboardMetrics($pdo, $ctx),
    ]);
}

$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
if ($first === '' || $last === '') {
    Api::error('Name required.');
}
$pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?')->execute([$first, $last, $phone, $userId]);
$_SESSION['first_name'] = $first;
$_SESSION['last_name'] = $last;
bhw_audit($pdo, $userId, 'bhw_profile_updated', 'BHW updated profile.');
Api::success([], 'Profile saved.');
