<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/core/NotificationManager.php';
NotificationManager::ensureSchema($pdo);

$page_title = 'Notification Center';
$filter = $_GET['filter'] ?? 'all';
$where = "n.status = 'active'";
if ($filter === 'unread') {
    $where .= ' AND n.is_read = 0';
} elseif ($filter === 'read') {
    $where .= ' AND n.is_read = 1';
}

$pending = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND status = 'active'")->fetchColumn();
$recent = $pdo->query("
    SELECT n.*, u.email
    FROM notifications n
    LEFT JOIN users u ON u.id = n.user_id
    WHERE {$where}
    ORDER BY n.created_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$api = ASSET_BASE . '/app/api/superadmin/notifications.php';
$roles = ['all' => 'All active users', 'patient' => 'Patients', 'provider' => 'Providers', 'bhw' => 'BHW', 'admin' => 'Admins', 'superadmin' => 'Super Admins'];

require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:24px;">
  <div>
    <h2 class="text-h2">Notification Center</h2>
    <p class="text-muted"><?= number_format($pending) ?> unread notifications system-wide.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="?filter=all" class="mc-btn mc-btn--outline <?= $filter === 'all' ? 'is-active' : '' ?>">All</a>
    <a href="?filter=unread" class="mc-btn mc-btn--outline <?= $filter === 'unread' ? 'is-active' : '' ?>">Unread</a>
    <a href="?filter=read" class="mc-btn mc-btn--outline <?= $filter === 'read' ? 'is-active' : '' ?>">Read</a>
    <button type="button" class="mc-btn mc-btn--primary" id="markAllRead">Mark all read</button>
  </div>
</div>

<div class="mc-card mb-md">
  <h3 class="text-h3 mb-md">Broadcast Notification</h3>
  <form id="broadcastForm" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
    <label class="text-sm">Target audience
      <select name="target_role" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;margin-top:6px;">
        <?php foreach ($roles as $val => $label): ?>
        <option value="<?= $val === 'all' ? 'all' : $val ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">Priority
      <select name="priority" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;margin-top:6px;">
        <option value="normal">Normal</option>
        <option value="high">High</option>
        <option value="critical">Critical</option>
      </select>
    </label>
    <label class="text-sm" style="grid-column:1/-1;">Title
      <input type="text" name="title" required class="mc-btn mc-btn--outline" style="width:100%;background:#fff;text-align:left;margin-top:6px;">
    </label>
    <label class="text-sm" style="grid-column:1/-1;">Message
      <textarea name="message" required rows="3" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;text-align:left;margin-top:6px;resize:vertical;"></textarea>
    </label>
    <div style="grid-column:1/-1;">
      <button type="submit" class="mc-btn mc-btn--primary">Send Broadcast</button>
    </div>
  </form>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead>
      <tr><th>User</th><th>Type</th><th>Title</th><th>Priority</th><th>Read</th><th>Time</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (empty($recent)): ?>
      <tr><td colspan="7"><div class="mc-table-empty"><p>No notifications found.</p></div></td></tr>
      <?php else: foreach ($recent as $n): ?>
      <tr data-id="<?= (int) $n['id'] ?>">
        <td class="text-xs"><?= htmlspecialchars($n['email'] ?? '—') ?></td>
        <td><?= htmlspecialchars($n['type'] ?? '') ?></td>
        <td>
          <strong><?= htmlspecialchars($n['title'] ?? '') ?></strong>
          <?php if (!empty($n['message'])): ?>
          <div class="text-xs text-muted"><?= htmlspecialchars(mb_strimwidth($n['message'], 0, 80, '…')) ?></div>
          <?php endif; ?>
        </td>
        <td><span class="mc-badge"><?= htmlspecialchars($n['priority'] ?? 'normal') ?></span></td>
        <td><?= $n['is_read'] ? 'Yes' : 'No' ?></td>
        <td class="text-xs text-muted"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></td>
        <td style="white-space:nowrap;">
          <?php if (!$n['is_read']): ?>
          <button type="button" class="mc-btn mc-btn--outline js-mark-read" data-id="<?= (int) $n['id'] ?>" style="padding:2px 8px;font-size:10px;">Read</button>
          <?php else: ?>
          <button type="button" class="mc-btn mc-btn--outline js-mark-unread" data-id="<?= (int) $n['id'] ?>" style="padding:2px 8px;font-size:10px;">Unread</button>
          <?php endif; ?>
          <button type="button" class="mc-btn mc-btn--outline js-delete" data-id="<?= (int) $n['id'] ?>" style="padding:2px 8px;font-size:10px;">Delete</button>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
(function () {
  var api = <?= json_encode($api) ?>;
  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(api, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }
  document.getElementById('markAllRead').onclick = function () {
    if (!confirm('Mark all notifications as read?')) return;
    post('mark_all_read').then(function (j) { alert(j.message); if (j.success) location.reload(); });
  };
  document.getElementById('broadcastForm').onsubmit = function (e) {
    e.preventDefault();
    if (!confirm('Send this notification to the selected audience?')) return;
    var fd = new FormData(e.target);
    fd.append('action', 'broadcast');
    fetch(api, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
      .then(function (j) { alert(j.message); if (j.success) location.reload(); });
  };
  document.querySelectorAll('.js-mark-read').forEach(function (btn) {
    btn.onclick = function () {
      post('mark_read', { notification_id: btn.dataset.id }).then(function (j) { if (j.success) location.reload(); });
    };
  });
  document.querySelectorAll('.js-mark-unread').forEach(function (btn) {
    btn.onclick = function () {
      post('mark_unread', { notification_id: btn.dataset.id }).then(function (j) { if (j.success) location.reload(); });
    };
  });
  document.querySelectorAll('.js-delete').forEach(function (btn) {
    btn.onclick = function () {
      if (!confirm('Delete this notification?')) return;
      post('delete', { notification_id: btn.dataset.id }).then(function (j) { if (j.success) location.reload(); });
    };
  });
})();
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
