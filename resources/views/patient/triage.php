<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
require_once BASE_PATH . '/app/includes/patient_portal_bootstrap.php';

$booking_today_ymd   = date('Y-m-d');
$booking_today_label = date('l, M j, Y');

$triage_history = [];
$default_complaint = '';
if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
    $s = $pdo->prepare('SELECT level, symptoms, assessed_at, chief_complaint, urgency_label, triage_level FROM triage_results WHERE patient_id=? ORDER BY assessed_at DESC');
    $s->execute([$uid]);
    $triage_history = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($triage_history[0]['chief_complaint'])) {
        $default_complaint = (string) $triage_history[0]['chief_complaint'];
    }
}

$booking_providers = [];
if ($pdo->query("SHOW TABLES LIKE 'users'")->rowCount()) {
    $bp = $pdo->query("
        SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name
        FROM users u
        WHERE u.role = 'provider' AND u.is_active = 1
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $booking_providers = $bp ? $bp->fetchAll(PDO::FETCH_ASSOC) : [];
}

$all_consults = [];
if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
    $s = $pdo->prepare("
        SELECT c.id, c.consult_date, c.consult_time, c.provider_name, c.consult_type, c.status
        FROM consultations c
        WHERE c.patient_id = ?
        ORDER BY c.consult_date DESC, c.consult_time DESC
    ");
    $s->execute([$uid]);
    $all_consults = $s->fetchAll(PDO::FETCH_ASSOC);
}

$active_consultation = null;
foreach ($all_consults as $c) {
    if (in_array($c['status'] ?? '', ['pending', 'scheduled', 'in_consultation'], true)) {
        $active_consultation = $c;
        break;
    }
}

$page_title = 'Book Consultation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

    <div class="patient-page">
      <?php require VIEWS_PATH . '/patient/partials/view_triage.php'; ?>
    </div>

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

  <script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
  <script>window.BOOKING_BLOCKED_IN_CONSULTATION = <?= json_encode(($active_consultation['status'] ?? '') === 'in_consultation') ?>;</script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-portal.js?v=<?= $patient_portal_ver ?>"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.refreshBookingPicker === 'function') {
      window.refreshBookingPicker();
    }
  });
  </script>
</body>
</html>
