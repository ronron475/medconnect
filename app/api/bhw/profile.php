<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_scope.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/profile_picture.php';

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$ctx = bhw_api_bootstrap($pdo, $isPost, false);
$userId = (int) $_SESSION['user_id'];
$action = $isPost ? ($_POST['action'] ?? 'save') : ($_GET['action'] ?? 'get');

if (!$isPost && $action === 'get') {
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

if (!$isPost || $action !== 'save') {
    Api::error('Invalid action.', 400);
}

$first = trim((string) ($_POST['first_name'] ?? ''));
$last = trim((string) ($_POST['last_name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
if ($first === '') {
    Api::error('First name is required.');
}
if ($last === '') {
    Api::error('Last name is required.');
}
if (mb_strlen($first) > 80 || mb_strlen($last) > 80) {
    Api::error('First and last name must be 80 characters or fewer.');
}
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/contact_validation.php';
if ($phone !== '') {
    if ($phoneErr = mc_phone_validation_error($phone, true)) {
        Api::error($phoneErr);
    }
    $phone = mc_canonical_ph_mobile($phone);
    if (mc_users_phone_exists($pdo, $phone, $userId)) {
        Api::error(MC_MSG_PHONE_DUP);
    }
} else {
    $phone = null;
}
try {
    $updated = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?')
        ->execute([$first, $last, $phone, $userId]);
    $check = $pdo->prepare('SELECT first_name, last_name, phone FROM users WHERE id = ? LIMIT 1');
    $check->execute([$userId]);
    $saved = $check->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[bhw/profile] save failed for user ' . $userId . ': ' . $e->getMessage());
    Api::error('Could not save your profile. Please try again.', 500);
}

if (
    !$updated
    || !$saved
    || (string) $saved['first_name'] !== $first
    || (string) $saved['last_name'] !== $last
    || (string) ($saved['phone'] ?? '') !== (string) ($phone ?? '')
) {
    Api::error('Could not save your profile. Please try again.', 500);
}

$_SESSION['first_name'] = $first;
$_SESSION['last_name'] = $last;
$_SESSION['user_name'] = trim($first . ' ' . $last);
try {
    bhw_audit($pdo, $userId, 'bhw_profile_updated', 'BHW updated profile.');
} catch (Throwable $e) {
    error_log('[bhw/profile] audit failed for user ' . $userId . ': ' . $e->getMessage());
}
Api::success([
    'profile' => [
        'first_name' => $first,
        'last_name' => $last,
        'phone' => $phone,
    ],
], 'Profile updated successfully.');
