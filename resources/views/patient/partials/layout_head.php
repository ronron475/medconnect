<?php
/**
 * Shared <head> assets for patient portal pages.
 * Expects: $page_title (optional)
 */
$page_title = $page_title ?? 'Patient Portal';

if (!empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
    require_once BASE_PATH . '/app/includes/theme_preferences.php';
    theme_preferences_sync_session($pdo, (int) $_SESSION['user_id'], (string) ($_SESSION['user_role'] ?? 'patient'));
}
?>
  <meta charset="UTF-8"/>
  <?php require_once VIEWS_PATH . '/partials/theme_init.php'; ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title><?= htmlspecialchars($page_title) ?> — medConnect</title>
  <link rel="icon" type="image/png" href="<?= ASSET_BASE ?>/assets/img/medcon_logo.png"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/design-system.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/sidebar.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/topbar.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/dashboard.css"/>
  <?php
  $patientPortalCss = ASSETS_PATH . '/css/patient-portal.css';
  $patientPortalCssVer = file_exists($patientPortalCss) ? (int) filemtime($patientPortalCss) : time();
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/patient-portal.css?v=<?= $patientPortalCssVer ?>"/>
  <?php
  $patientLandingThemeCss = ASSETS_PATH . '/css/patient-landing-theme.css';
  $patientLandingThemeCssVer = file_exists($patientLandingThemeCss) ? (int) filemtime($patientLandingThemeCss) : time();
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/patient-landing-theme.css?v=<?= $patientLandingThemeCssVer ?>"/>
  <?php $profilePictureCssVer = (int) filemtime(ASSETS_PATH . '/css/profile-picture.css'); ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/profile-picture.css?v=<?= $profilePictureCssVer ?>"/>
  <?php require_once VIEWS_PATH . '/partials/responsive_assets.php'; ?>
  <?php require_once VIEWS_PATH . '/partials/notification_assets.php'; ?>
  <?php $portalShellCssVer = (int) filemtime(ASSETS_PATH . '/css/portal_shell.css'); ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/portal_shell.css?v=<?= $portalShellCssVer ?>"/>
  <?php
  $aiAssessmentCss = ASSETS_PATH . '/css/ai-assessment.css';
  $aiAssessmentCssVer = file_exists($aiAssessmentCss) ? (int) filemtime($aiAssessmentCss) : time();
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/ai-assessment.css?v=<?= $aiAssessmentCssVer ?>"/>
  <?php require_once VIEWS_PATH . '/partials/auth_transition_assets.php'; ?>
