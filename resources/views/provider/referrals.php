<?php
$active_page = 'referrals';
$page_title  = 'Digital Referrals';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require __DIR__.'/partials/layout_open.php';

$provider_id = (int)$_SESSION['user_id'];
$referrals = [];
$facilities = [];

try {
    $s = $pdo->prepare("
        SELECT r.*, u.first_name, u.last_name
        FROM digital_referrals r
        JOIN users u ON r.patient_id = u.id
        WHERE r.provider_id = ?
        ORDER BY r.created_at DESC
    ");
    $s->execute([$provider_id]);
    $referrals = $s->fetchAll(PDO::FETCH_ASSOC);
    $facilities = $pdo->query("SELECT id, facility_name, facility_type FROM facilities WHERE status = 'active' ORDER BY facility_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $facilities = $pdo->query("SELECT DISTINCT facility_name AS facility_name, referral_type AS facility_type FROM digital_referrals WHERE facility_name IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {}
}
?>

<div class="greeting-banner" style="margin-bottom:20px;">
  <div><h2 class="text-h2">Digital Referrals</h2><p class="text-muted text-sm">Track referral status, destinations, and history.</p></div>
  <a href="<?= ASSET_BASE ?>/views/provider/queue.php" class="mc-btn mc-btn--primary">+ New Referral from Queue</a>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead><tr><th>Date</th><th>Patient</th><th>Type</th><th>Destination</th><th>Reason</th><th>Status</th></tr></thead>
    <tbody>
      <?php if (empty($referrals)): ?>
      <tr><td colspan="6"><div class="mc-table-empty"><p>No referrals created yet. Start from the Live Queue during a consultation.</p></div></td></tr>
      <?php else: foreach ($referrals as $r):
        $badge = 'background:#fef3c7;color:#92400e';
        if ($r['status'] === 'completed') $badge = 'background:#dcfce7;color:#16a34a';
        elseif ($r['status'] === 'cancelled') $badge = 'background:#fee2e2;color:#991b1b';
      ?>
      <tr>
        <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
        <td><strong><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></strong></td>
        <td><span class="mc-badge"><?= htmlspecialchars($r['referral_type']) ?></span></td>
        <td><?= htmlspecialchars($r['facility_name'] ?? $r['destination_facility'] ?? '—') ?></td>
        <td class="text-sm"><?= htmlspecialchars(mb_strimwidth($r['reason'], 0, 80, '…')) ?></td>
        <td><span class="mc-badge" style="<?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($facilities)): ?>
<div class="mc-card" style="margin-top:16px;">
  <h3 class="text-h3 mb-sm">Available Referral Destinations</h3>
  <div style="display:flex;flex-wrap:wrap;gap:8px;">
    <?php foreach ($facilities as $f): ?>
    <span class="mc-badge"><?= htmlspecialchars($f['facility_name']) ?> · <?= htmlspecialchars($f['facility_type'] ?? '') ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__.'/partials/layout_close.php'; ?>
