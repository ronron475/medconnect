<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title><?= htmlspecialchars($page_title ?? 'Provider') ?> — medConnect</title>
  <?php require_once dirname(__DIR__, 3) . '/bootstrap.php'; ?>
  <link rel="icon" type="image/png" href="<?= ASSET_BASE ?>/assets/img/medcon_logo.png"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  
  <!-- Unified Aqua Clinical Design System -->
  <?php require_once VIEWS_PATH . '/partials/theme_init.php'; ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/design-system.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/sidebar_aqua.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/topbar.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/dashboard.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/provider_dashboard.css"/>
  <?php if (!empty($page_styles) && is_array($page_styles)): ?>
    <?php foreach ($page_styles as $css_file): ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/<?= htmlspecialchars($css_file) ?>"/>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php
  $providerLandingThemeCss = ASSETS_PATH . '/css/provider-landing-theme.css';
  $providerLandingThemeVer = file_exists($providerLandingThemeCss) ? (int) filemtime($providerLandingThemeCss) : time();
  $patientDashMockCss = ASSETS_PATH . '/css/patient-dashboard-mock.css';
  $patientDashMockVer = file_exists($patientDashMockCss) ? (int) filemtime($patientDashMockCss) : time();
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/provider-landing-theme.css?v=<?= $providerLandingThemeVer ?>"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/patient-dashboard-mock.css?v=<?= $patientDashMockVer ?>"/>
  <?php require_once VIEWS_PATH . '/partials/responsive_assets.php'; ?>
  <?php require_once VIEWS_PATH . '/partials/notification_assets.php'; ?>
  <?php $providerShellCssVer = (int) filemtime(ASSETS_PATH . '/css/provider_shell.css'); ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/provider_shell.css?v=<?= $providerShellCssVer ?>"/>
  <?php $profilePictureCssVer = (int) filemtime(ASSETS_PATH . '/css/profile-picture.css'); ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/profile-picture.css?v=<?= $profilePictureCssVer ?>"/>
  <?php require_once VIEWS_PATH . '/partials/auth_transition_assets.php'; ?>
</head>
<body
  class="provider-body"
  data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
  data-asset-base="<?= htmlspecialchars(ASSET_BASE) ?>"
  data-provider-theme="<?= htmlspecialchars($_SESSION['user_theme'] ?? $_SESSION['provider_theme'] ?? 'system') ?>"
  data-language="<?= htmlspecialchars($_SESSION['provider_language'] ?? 'en') ?>"
  data-time-format="<?= htmlspecialchars($_SESSION['provider_time_format'] ?? '12h') ?>"
  data-date-format="<?= htmlspecialchars($_SESSION['provider_date_format'] ?? 'M j, Y') ?>"
  data-auto-logout="<?= (int) ($_SESSION['provider_auto_logout'] ?? 30) ?>"
  data-expire-url="<?= htmlspecialchars(ASSET_BASE . '/app/api/provider/session/expire.php') ?>"
>

<div class="root-wrapper provider-shell">
  <?php require_once __DIR__ . '/sidebar.php'; ?>
  <?php require_once __DIR__ . '/header.php'; ?>

  <main class="main-content provider-main">
    <div class="provider-page-body">
