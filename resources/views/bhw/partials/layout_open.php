<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title><?= htmlspecialchars($page_title ?? 'BHW') ?> — medConnect</title>
  <?php require_once dirname(__DIR__, 3) . '/bootstrap.php'; ?>
  <link rel="icon" type="image/png" href="<?= ASSET_BASE ?>/assets/img/medcon_logo.png"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <?php
  if (!empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
      require_once BASE_PATH . '/app/includes/theme_preferences.php';
      theme_preferences_sync_session($pdo, (int) $_SESSION['user_id'], (string) ($_SESSION['user_role'] ?? 'bhw'));
  }
  require_once VIEWS_PATH . '/partials/theme_init.php';
  ?>
  <!-- Unified Aqua Clinical Design System -->
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/design-system.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/sidebar.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/topbar.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/dashboard.css"/>
  <?php require_once VIEWS_PATH . '/partials/responsive_assets.php'; ?>
  <?php require_once VIEWS_PATH . '/partials/notification_assets.php'; ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/portal_shell.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw_sidebar.css"/>
  <?php
  $bhwLandingThemeCss = ASSETS_PATH . '/css/bhw-landing-theme.css';
  $bhwLandingThemeVer = file_exists($bhwLandingThemeCss) ? (int) filemtime($bhwLandingThemeCss) : time();
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-landing-theme.css?v=<?= $bhwLandingThemeVer ?>"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-feedback.css"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-forms.css"/>
  <?php $profilePictureCssVer = (int) @filemtime(ASSETS_PATH . '/css/profile-picture.css'); ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/profile-picture.css?v=<?= $profilePictureCssVer ?>"/>

  <style>
    :root {
      --bhw-canvas: #f4f8fa;
      --bhw-navy: #012A4A;
      --bhw-teal: #069396;
      --bhw-radius: 14px;
    }
    
    body.bhw-body {
      background-color: var(--bhw-canvas);
      color: var(--bhw-navy);
      font-family: 'Inter', sans-serif;
    }
    
    .bhw-card {
      background: #fff;
      border-radius: var(--bhw-radius);
      border: 1px solid var(--mc-border-thin);
      box-shadow: var(--mc-shadow-micro);
      padding: 20px;
      height: 100%;
    }
    
    .bhw-metric-card {
      padding: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #fff;
      border-radius: var(--bhw-radius);
      border: 1px solid var(--mc-border-thin);
      box-shadow: var(--mc-shadow-micro);
    }
    
    .bhw-metric-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    
    .bhw-metric-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--bhw-canvas);
      color: #94a3b8; /* Desaturated gray utility icon */
    }
    
    /* State-Driven Row Accents */
    .bhw-row-high {
      border-left: 4px solid #dc3545 !important;
      background-color: rgba(220, 53, 69, 0.02) !important;
    }
    
    .bhw-row-moderate {
      border-left: 4px solid #fd7e14 !important;
    }
    
    .bhw-badge-ready { background: #e0f2fe; color: #0369a1; }
    .bhw-badge-scheduled { background: #f1f5f9; color: #64748b; }
    .bhw-badge-high { background: #fee2e2; color: #dc3545; }
    .bhw-badge-moderate { background: #ffedd5; color: #d97706; }
    .bhw-badge-low { background: #dcfce7; color: #15803d; }

    .bhw-btn-outline {
      background: transparent;
      color: var(--bhw-teal);
      border: 1px solid var(--bhw-teal);
      padding: 6px 14px;
      border-radius: 99px;
      font-weight: 700;
      font-size: 10px;
      transition: all 0.2s;
    }

    .bhw-btn-outline:hover {
      background: var(--bhw-teal);
      color: #fff;
    }
    
    .bhw-metric-val {
      font-size: 24px;
      font-weight: 800;
      color: var(--bhw-navy);
      line-height: 1.2;
    }
    
    .bhw-btn-blue {
      background: #e0f2fe;
      color: #0369a1;
      border: none;
      padding: 6px 14px;
      border-radius: 99px;
      font-weight: 700;
      font-size: 10px;
      transition: all 0.2s;
    }
    
    .bhw-btn-blue:hover {
      background: #bae6fd;
      transform: translateY(-1px);
    }
    
    .bhw-metric-label {
      font-size: 11px;
      font-weight: 700;
      color: var(--mc-slate-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    
    .bhw-table th {
      background: #f8fbff;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      color: var(--bhw-teal);
      padding: 12px;
      border-bottom: 2px solid var(--bhw-canvas);
    }
    
    .bhw-table td {
      padding: 14px 12px;
      vertical-align: middle;
      font-size: 13px;
    }
    
    .bhw-btn-teal {
      background: var(--bhw-teal);
      color: #fff;
      border: none;
      padding: 8px 16px;
      border-radius: 99px;
      font-weight: 700;
      font-size: 11px;
      transition: all 0.2s;
    }
    
    .bhw-btn-teal:hover {
      background: var(--bhw-navy);
      transform: translateY(-1px);
    }
    
    .bhw-vitals-form label {
      font-size: 11px;
      font-weight: 700;
      color: var(--mc-slate-muted);
      margin-bottom: 4px;
    }
    
    .bhw-vitals-form .form-control {
      border-radius: 8px;
      border: 1px solid var(--mc-border-thin);
      padding: 8px 12px;
      font-size: 13px;
    }
    
    .bhw-badge {
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
    }
    
    .urgency-high { background: #fee2e2; color: #dc2626; }
    .urgency-mod  { background: #ffedd5; color: #ea580c; }
    .urgency-low  { background: #dcfce7; color: #16a34a; }
    
    .status-ready { background: #e0f2fe; color: #0284c7; }
    .status-sched { background: #f3f4f6; color: #4b5563; }
  </style>
  <?php require_once VIEWS_PATH . '/partials/auth_transition_assets.php'; ?>
</head>
<body
  class="bhw-body"
  data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
  data-asset-base="<?= htmlspecialchars(ASSET_BASE) ?>"
>

<div class="portal-shell">
  <?php require_once VIEWS_PATH . '/partials/sidebar.php'; ?>
  <?php require_once VIEWS_PATH . '/partials/header.php'; ?>

  <div class="main portal-main">
    <div class="portal-page-body">
