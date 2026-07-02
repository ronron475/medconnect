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
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'patient') {
    header('Location: ' . BASE_URL . '/index.php'); exit;
}

$uid = (int) $_SESSION['user_id'];

// ── Fetch follow-ups ─────────────────────────────────────────────────────────
$followups = [];
if ($pdo->query("SHOW TABLES LIKE 'followups'")->rowCount()) {
    $s = $pdo->prepare("
        SELECT f.*, u.first_name as provider_first, u.last_name as provider_last
        FROM followups f
        JOIN users u ON f.provider_id = u.id
        WHERE f.patient_id = ?
        ORDER BY f.followup_date ASC
    ");
    $s->execute([$uid]);
    $followups = $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── Fetch referrals ──────────────────────────────────────────────────────────
$referrals = [];
if ($pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
    $s = $pdo->prepare("
        SELECT r.*, u.first_name as provider_first, u.last_name as provider_last
        FROM digital_referrals r
        JOIN users u ON r.provider_id = u.id
        WHERE r.patient_id = ?
        ORDER BY r.created_at DESC
    ");
    $s->execute([$uid]);
    $referrals = $s->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Follow-Up & Referrals';
$today      = date('l, F j, Y');
$now        = date('h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
<style>
.patient-page .badge { display: inline-flex; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: capitalize; }
.patient-page .badge-scheduled { background: #e0f2fe; color: #0369a1; }
.patient-page .badge-pending { background: #fef3c7; color: #92400e; }
.patient-page .badge-completed { background: #dcfce7; color: #16a34a; }
</style>
</head>
<body class="patient-portal">

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

    <div class="patient-page">
      <h2 class="text-h2 mb-md"><?= htmlspecialchars($page_title) ?></h2>
      
      <!-- Follow-Ups Section -->
      <div class="patient-section-title">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
        Upcoming Follow-Up Appointments
      </div>
      <div class="patient-data-card">
        <?php if (empty($followups)): ?>
          <div class="patient-empty">No scheduled follow-up appointments.</div>
        <?php else: ?>
          <div class="mc-table-wrap">
          <table class="mc-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Provider</th>
                <th>Instructions/Message</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($followups as $f): ?>
                <tr>
                  <td data-label="Date" style="font-weight:700"><?= date('M j, Y', strtotime($f['followup_date'])) ?></td>
                  <td data-label="Provider">Dr. <?= htmlspecialchars(trim($f['provider_first'] . ' ' . $f['provider_last'])) ?></td>
                  <td data-label="Instructions"><?= htmlspecialchars($f['message'] ?: 'No instructions provided.') ?></td>
                  <td data-label="Status"><span class="badge badge-<?= htmlspecialchars($f['status']) ?>"><?= htmlspecialchars($f['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Referrals Section -->
      <div class="patient-section-title">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 14l2 2 4-4"/></svg>
        Digital Referrals
      </div>
      <div class="patient-data-card">
        <?php if (empty($referrals)): ?>
          <div class="patient-empty">No active referrals found.</div>
        <?php else: ?>
          <div class="mc-table-wrap">
          <table class="mc-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Facility</th>
                <th>Reason</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($referrals as $r): ?>
                <tr>
                  <td data-label="Date"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                  <td data-label="Type" style="font-weight:700"><?= htmlspecialchars($r['referral_type'] ?? '') ?></td>
                  <td data-label="Facility"><?= htmlspecialchars($r['destination_facility'] ?? $r['facility_name'] ?? 'Local Health Office') ?></td>
                  <td data-label="Reason"><?= htmlspecialchars($r['reason'] ?? '') ?></td>
                  <td data-label="Status"><span class="badge badge-<?= htmlspecialchars($r['status'] ?? 'pending') ?>"><?= htmlspecialchars($r['status'] ?? '') ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

</body>
</html>
