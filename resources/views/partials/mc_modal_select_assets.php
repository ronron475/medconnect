<?php
/**
 * Modal select dropdown fix — prevents native browser pickers from opening upward
 * inside scrollable modals. Loaded on admin, superadmin, and provider portals.
 */
$mcStaffFormsCssPath = ASSETS_PATH . '/css/admin-staff-forms.css';
$mcStaffFormUtilsPath = ASSETS_PATH . '/js/admin-staff-form-utils.js';
$mcStaffFormsCssVer = file_exists($mcStaffFormsCssPath) ? (int) filemtime($mcStaffFormsCssPath) : time();
$mcStaffFormUtilsVer = file_exists($mcStaffFormUtilsPath) ? (int) filemtime($mcStaffFormUtilsPath) : time();
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-forms.css?v=<?= $mcStaffFormsCssVer ?>"/>
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-form-utils.js?v=<?= $mcStaffFormUtilsVer ?>"></script>
