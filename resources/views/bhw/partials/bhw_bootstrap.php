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
    require_once BASE_PATH . '/app/includes/auth_guard.php';
    header('Location: ' . auth_signin_required_url());
    exit;
}

require_once BASE_PATH . '/app/includes/barangays_bago.php';
patient_registrations_ensure_barangay_id($pdo);

$bhw_context = bhw_resolve_context($pdo);
$bhw_current_script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$bhw_no_sector = !$bhw_context['allowed'] || (int) ($bhw_context['barangay_id'] ?? 0) <= 0;

if ($bhw_no_sector) {
    // No barangay assignment = no patient access. Allow dashboard/profile only (avoid redirect loops).
    $bhw_no_sector_pages = ['dashboard.php', 'profile.php'];
    if (!in_array($bhw_current_script, $bhw_no_sector_pages, true)) {
        header('Location: ' . ASSET_BASE . '/views/bhw/dashboard.php');
        exit;
    }
    $bhw_barangay_id = 0;
    $bhw_barangay_name = 'Unassigned';
} else {
    $bhw_barangay_id = (int) $bhw_context['barangay_id'];
    $bhw_barangay_name = (string) $bhw_context['barangay_name'];
}

profile_picture_ensure_schema($pdo);
profile_picture_sync_session($pdo, (int) $_SESSION['user_id']);
