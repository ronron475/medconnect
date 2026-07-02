<div
  id="medconnectThemeRoot"
  data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
  data-asset-base="<?= htmlspecialchars(ASSET_BASE) ?>"
  hidden
  aria-hidden="true"
></div>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'patient') {
    require_once BASE_PATH . '/config/db.php';
    require_once BASE_PATH . '/app/includes/patient_account_security.php';
    if (patient_requires_account_setup($pdo, (int) $_SESSION['user_id'])) {
        header('Location: ' . ASSET_BASE . '/views/patient/account_setup.php');
        exit;
    }
}
?>
<div class="root-wrapper portal-shell">
  <?php require_once VIEWS_PATH . '/partials/sidebar.php'; ?>
  <?php require_once VIEWS_PATH . '/partials/header.php'; ?>

  <main class="main-content portal-main">
    <div class="portal-page-body">
