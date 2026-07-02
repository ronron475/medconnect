<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/profile_picture.php';
require_once __DIR__ . '/bhw_context.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'bhw') {
    header('Location: ' . ASSET_BASE . '/index.php');
    exit;
}

$bhw_context = bhw_resolve_context($pdo);
if (!$bhw_context['allowed']) {
    header('Location: ' . ASSET_BASE . '/views/bhw/dashboard.php');
    exit;
}

$bhw_barangay_id = $bhw_context['barangay_id'];
$bhw_barangay_name = $bhw_context['barangay_name'];

profile_picture_ensure_schema($pdo);
profile_picture_sync_session($pdo, (int) $_SESSION['user_id']);
