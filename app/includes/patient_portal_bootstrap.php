<?php
/**
 * Shared auth and session setup for patient portal pages.
 * Expects: $pdo (from mc_load), active session.
 */
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'patient') {
    require_once BASE_PATH . '/app/includes/auth_guard.php';
    header('Location: ' . auth_signin_required_url());
    exit;
}

require_once BASE_PATH . '/app/includes/consultation_expiry.php';
require_once BASE_PATH . '/app/includes/profile_picture.php';
require_once BASE_PATH . '/app/includes/patient_account_security.php';

$uid = (int) $_SESSION['user_id'];

if (patient_requires_account_setup($pdo, $uid)) {
    header('Location: ' . ASSET_BASE . '/views/patient/account_setup.php');
    exit;
}

consultations_auto_expire($pdo, $uid);
profile_picture_ensure_schema($pdo);
profile_picture_sync_session($pdo, $uid);

$patient_portal_js  = ASSETS_PATH . '/js/patient-portal.js';
$patient_portal_ver = file_exists($patient_portal_js) ? (int) filemtime($patient_portal_js) : time();
