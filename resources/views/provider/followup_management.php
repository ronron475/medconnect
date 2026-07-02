<?php
$active_page = 'followup_management';
$page_title  = 'Follow-Up Management';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require __DIR__.'/partials/layout_open.php';

$provider_id = (int)$_SESSION['user_id'];
$status_filter = $_GET['status'] ?? 'upcoming';
$date_from = $_GET['from'] ?? '';
$date_to = $_GET['to'] ?? '';
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT f.*, u.first_name, u.last_name, u.email
    FROM followups f
    JOIN users u ON u.id = f.patient_id
    WHERE f.provider_id = ?
";
$params = [$provider_id];

if ($status_filter === 'upcoming') {
    $sql .= " AND f.status = 'scheduled' AND f.followup_date >= CURDATE()";
} elseif ($status_filter === 'completed') {
    $sql .= " AND f.status = 'completed'";
} elseif ($status_filter === 'missed') {
    $sql .= " AND f.status IN ('missed','scheduled') AND f.followup_date < CURDATE()";
}

if ($date_from !== '') { $sql .= " AND f.followup_date >= ?"; $params[] = $date_from; }
if ($date_to !== '') { $sql .= " AND f.followup_date <= ?"; $params[] = $date_to; }
if ($search !== '') {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $s = "%$search%"; array_push($params, $s, $s, $s);
}
$sql .= " ORDER BY f.followup_date ASC, f.id DESC";

$followups = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>

<div class="greeting-banner" style="margin-bottom:20px;">
  <div><h2 class="text-h2">Follow-Up Management</h2><p class="text-muted text-sm">Track scheduled, completed, and missed follow-ups.</p></div>
</div>

<div class="mc-card" style="margin-bottom:16px;padding:16px;">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
    <div>
      <label class="text-xs text-muted">Status</label>
      <select name="status" class="mc-btn mc-btn--outline" style="display:block;background:#fff;">
        <option value="upcoming" <?= $status_filter==='upcoming'?'selected':'' ?>>Upcoming</option>
        <option value="completed" <?= $status_filter==='completed'?'selected':'' ?>>Completed</option>
        <option value="missed" <?= $status_filter==='missed'?'selected':'' ?>>Missed</option>
      </select>
    </div>
    <div><label class="text-xs text-muted">From</label><input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>" class="mc-btn mc-btn--outline" style="background:#fff;display:block;"></div>
    <div><label class="text-xs text-muted">To</label><input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>" class="mc-btn mc-btn--outline" style="background:#fff;display:block;"></div>
    <div style="flex:1;min-width:200px;"><label class="text-xs text-muted">Search Patient</label><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name or email" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;display:block;"></div>
    <button type="submit" class="mc-btn mc-btn--primary">Filter</button>
  </form>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead><tr><th>Patient</th><th>Follow-Up Date</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (empty($followups)): ?>
      <tr><td colspan="5"><div class="mc-table-empty"><p>No follow-ups found for this filter.</p></div></td></tr>
      <?php else: foreach ($followups as $f): ?>
      <tr>
        <td><strong><?= htmlspecialchars($f['first_name'].' '.$f['last_name']) ?></strong><div class="text-xs text-muted"><?= htmlspecialchars($f['email']) ?></div></td>
        <td><?= date('M j, Y', strtotime($f['followup_date'])) ?></td>
        <td><span class="mc-badge"><?= htmlspecialchars($f['status']) ?></span></td>
        <td class="text-sm"><?= htmlspecialchars($f['notes'] ?? $f['message'] ?? '—') ?></td>
        <td>
          <?php if ($f['status'] === 'scheduled'): ?>
          <button type="button" class="mc-btn mc-btn--outline mc-btn--sm" data-reschedule="<?= (int)$f['id'] ?>" data-date="<?= htmlspecialchars($f['followup_date']) ?>">Reschedule</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('[data-reschedule]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-reschedule');
    var nd = prompt('New follow-up date (YYYY-MM-DD):', btn.getAttribute('data-date'));
    if (!nd) return;
    var fd = new FormData();
    fd.append('followup_id', id);
    fd.append('followup_date', nd);
    fd.append('csrf_token', document.body.dataset.csrf || '');
    fetch('<?= ASSET_BASE ?>/app/api/provider/update_followup.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) { alert(j.message || 'Updated'); if (j.success) location.reload(); });
  });
});
</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
