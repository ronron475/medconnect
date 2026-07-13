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
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin_dashboard.css"/>
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
  <?php
  $bhwPortalCss = ASSETS_PATH . '/css/bhw-portal.css';
  $bhwPortalVer = file_exists($bhwPortalCss) ? (int) filemtime($bhwPortalCss) : time();
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-portal.css?v=<?= $bhwPortalVer ?>"/>
  <?php if (!empty($bhw_head_css)): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars((string) $bhw_head_css, ENT_QUOTES, 'UTF-8') ?>"/>
  <?php endif; ?>
  <?php $profilePictureCssVer = (int) @filemtime(ASSETS_PATH . '/css/profile-picture.css'); ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/profile-picture.css?v=<?= $profilePictureCssVer ?>"/>

  <style>
    :root {
      --bhw-canvas: #f8fafc;
      --bhw-navy: #0f172a;
      --bhw-accent: #1d4ed8;
      --bhw-accent-soft: #eff6ff;
      --bhw-teal: #1d4ed8;
      --bhw-radius: 8px;
    }
    
    body.bhw-body {
      background-color: var(--bhw-canvas);
      color: var(--bhw-navy);
      font-family: 'Inter', sans-serif;
      -webkit-font-smoothing: antialiased;
    }
    
    .bhw-card {
      background: #fff;
      border-radius: var(--bhw-radius);
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
      padding: 20px;
    }

    .row > [class*='col-'] > .bhw-card,
    .bhw-card.h-100 {
      height: 100%;
    }
    
    .bhw-metric-card {
      padding: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #fff;
      border-radius: var(--bhw-radius);
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }
    
    .bhw-metric-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    
    .bhw-metric-icon {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--bhw-accent-soft);
      color: #64748b;
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
      color: var(--bhw-accent);
      border: 1px solid #cbd5e1;
      padding: 8px 16px;
      border-radius: 6px;
      font-weight: 600;
      font-size: 12px;
      letter-spacing: 0.01em;
      transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }

    .bhw-btn-outline:hover {
      background: var(--bhw-accent-soft);
      border-color: #93c5fd;
      color: #1e40af;
    }
    
    .bhw-metric-val {
      font-size: 24px;
      font-weight: 700;
      color: var(--bhw-navy);
      line-height: 1.2;
    }
    
    .bhw-btn-blue {
      background: var(--bhw-accent-soft);
      color: #1e40af;
      border: 1px solid #bfdbfe;
      padding: 8px 16px;
      border-radius: 6px;
      font-weight: 600;
      font-size: 12px;
      transition: background 0.15s ease, border-color 0.15s ease;
    }
    
    .bhw-btn-blue:hover {
      background: #dbeafe;
      border-color: #93c5fd;
    }
    
    .bhw-metric-label {
      font-size: 11px;
      font-weight: 700;
      color: var(--mc-slate-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    
    .bhw-table th {
      background: #f8fafc;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #64748b;
      padding: 12px 14px;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .bhw-table td {
      padding: 14px;
      vertical-align: middle;
      font-size: 13px;
      color: #334155;
      border-bottom: 1px solid #f1f5f9;
    }
    
    .bhw-btn-teal {
      background: var(--bhw-accent);
      color: #fff;
      border: 1px solid #1e40af;
      padding: 9px 18px;
      border-radius: 6px;
      font-weight: 600;
      font-size: 12px;
      letter-spacing: 0.01em;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      transition: background 0.15s ease, border-color 0.15s ease;
    }
    
    .bhw-btn-teal:hover {
      background: #1e40af;
      border-color: #1e3a8a;
      color: #fff;
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
      border-radius: 4px;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
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

<?php require_once VIEWS_PATH . '/partials/auth_transition_boot.php'; ?>

<div class="portal-shell">
  <?php require_once VIEWS_PATH . '/partials/sidebar.php'; ?>
  <?php require_once VIEWS_PATH . '/partials/header.php'; ?>

  <div class="main portal-main">
    <div class="portal-page-body">
