<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title><?= htmlspecialchars($page_title ?? 'Super Admin') ?> — medConnect</title>
  <link rel="icon" type="image/png" href="<?= ASSET_BASE ?>/assets/img/medcon_logo.png"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <?php
  if (!empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
      require_once BASE_PATH . '/app/includes/theme_preferences.php';
      theme_preferences_sync_session($pdo, (int) $_SESSION['user_id'], 'admin');
  }
  require_once VIEWS_PATH . '/partials/theme_init.php';
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/design-system.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin_dashboard.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-dashboard-charts.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/superadmin_dashboard.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/sidebar.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/topbar.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/dashboard.css"/>
  <?php require_once VIEWS_PATH . '/partials/responsive_assets.php'; ?>
  <?php require_once VIEWS_PATH . '/partials/notification_assets.php'; ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/portal_shell.css"/>
  <?php
  $profilePictureCssVer = (int) @filemtime(ASSETS_PATH . '/css/profile-picture.css');
  if (!empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
      require_once BASE_PATH . '/app/includes/profile_picture.php';
      profile_picture_ensure_schema($pdo);
      profile_picture_sync_session($pdo, (int) $_SESSION['user_id']);
  }
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/profile-picture.css?v=<?= $profilePictureCssVer ?>"/>
  <?php require_once VIEWS_PATH . '/partials/auth_transition_assets.php'; ?>
</head>
<body
  class="admin-body superadmin-body"
  data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
  data-asset-base="<?= htmlspecialchars(ASSET_BASE) ?>"
  data-portal="superadmin"
>

  <div class="portal-shell portal-shell--superadmin">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    <?php require_once VIEWS_PATH . '/partials/header.php'; ?>

    <div class="main portal-main">
      <div class="portal-page-body">
