<?php
$active_page = 'followup_management';
$page_title  = 'Follow-Up Management';
$page_styles = ['provider-followup.css'];
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

<div class="fu-page">
  <div class="fu-hero">
    <h2 class="fu-title">Follow-Up Management</h2>
    <p class="fu-sub">Track scheduled, completed, and missed follow-ups.</p>
  </div>

  <div class="fu-card">
    <form method="get" class="fu-filters">
      <div class="fu-field">
        <label for="fuStatus">Status</label>
        <select id="fuStatus" name="status" class="fu-input">
          <option value="upcoming" <?= $status_filter==='upcoming'?'selected':'' ?>>Upcoming</option>
          <option value="completed" <?= $status_filter==='completed'?'selected':'' ?>>Completed</option>
          <option value="missed" <?= $status_filter==='missed'?'selected':'' ?>>Missed</option>
        </select>
      </div>
      <div class="fu-field">
        <label for="fuFrom">From date</label>
        <div class="fu-date">
          <input id="fuFrom" type="date" name="from" value="<?= htmlspecialchars($date_from) ?>" class="fu-input" placeholder="YYYY-MM-DD" aria-label="From date">
        </div>
      </div>
      <div class="fu-field">
        <label for="fuTo">To date</label>
        <div class="fu-date">
          <input id="fuTo" type="date" name="to" value="<?= htmlspecialchars($date_to) ?>" class="fu-input" placeholder="YYYY-MM-DD" aria-label="To date">
        </div>
      </div>
      <div class="fu-field fu-field--search">
        <label for="fuSearch">Search Patient</label>
        <input id="fuSearch" type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name or email" class="fu-input" autocomplete="off">
      </div>
      <div class="fu-field fu-field--action">
        <button type="submit" class="mc-btn mc-btn--primary fu-filter-btn">Filter</button>
      </div>
    </form>
  </div>

  <div class="fu-card fu-card--table">
    <table class="mc-table fu-table">
      <thead>
        <tr>
          <th>Patient</th>
          <th>Follow-Up Date</th>
          <th>Status</th>
          <th>Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($followups)): ?>
        <tr class="fu-empty-row">
          <td colspan="5"><div class="mc-table-empty"><p>No follow-ups found for this filter.</p></div></td>
        </tr>
        <?php else: foreach ($followups as $f): ?>
        <tr>
          <td class="fu-td--patient" data-label="Patient">
            <strong><?= htmlspecialchars($f['first_name'].' '.$f['last_name']) ?></strong>
            <div class="text-xs text-muted"><?= htmlspecialchars($f['email']) ?></div>
          </td>
          <td data-label="Follow-up date"><?= date('M j, Y', strtotime($f['followup_date'])) ?></td>
          <td data-label="Status"><span class="mc-badge"><?= htmlspecialchars($f['status']) ?></span></td>
          <td data-label="Notes" class="text-sm"><?= htmlspecialchars($f['notes'] ?? $f['message'] ?? '—') ?></td>
          <td data-label="Action">
            <?php if ($f['status'] === 'scheduled'): ?>
            <button type="button" class="mc-btn mc-btn--outline mc-btn--sm" data-reschedule="<?= (int)$f['id'] ?>" data-date="<?= htmlspecialchars($f['followup_date']) ?>">Reschedule</button>
            <?php else: ?>
            <span class="text-xs text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
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
