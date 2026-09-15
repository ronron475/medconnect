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
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-notification-center.css?v=<?= (int) @filemtime(ASSETS_PATH . '/css/admin-notification-center.css') ?>">

<div class="sa-nc">
  <div class="sa-nc__header">
    <div class="sa-nc__title-block">
      <h2 class="text-h2">Notification Center</h2>
      <p class="text-muted"><?= number_format($pending) ?> unread notifications system-wide.</p>
    </div>
    <div class="sa-nc__filters" role="group" aria-label="Notification filters">
      <a href="?filter=all" class="mc-btn mc-btn--outline<?= $filter === 'all' ? ' is-active' : '' ?>">All</a>
      <a href="?filter=unread" class="mc-btn mc-btn--outline<?= $filter === 'unread' ? ' is-active' : '' ?>">Unread</a>
      <a href="?filter=read" class="mc-btn mc-btn--outline<?= $filter === 'read' ? ' is-active' : '' ?>">Read</a>
      <button type="button" class="mc-btn mc-btn--primary" id="markAllRead">Mark all read</button>
    </div>
  </div>

  <div class="mc-card sa-nc__broadcast">
    <h3 class="text-h3 sa-nc__broadcast-title">Broadcast Notification</h3>
    <form id="broadcastForm" class="sa-nc__broadcast-form">
      <label class="sa-nc__field">
        <span class="sa-nc__label">Target audience</span>
        <select name="target_role" class="sa-nc__control">
          <?php foreach ($roles as $val => $label): ?>
          <option value="<?= $val === 'all' ? 'all' : $val ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="sa-nc__field">
        <span class="sa-nc__label">Priority</span>
        <select name="priority" class="sa-nc__control">
          <option value="normal">Normal</option>
          <option value="high">High</option>
          <option value="critical">Critical</option>
        </select>
      </label>
      <label class="sa-nc__field sa-nc__field--full">
        <span class="sa-nc__label">Title</span>
        <input type="text" name="title" required class="sa-nc__control">
      </label>
      <label class="sa-nc__field sa-nc__field--full">
        <span class="sa-nc__label">Message</span>
        <textarea name="message" required rows="3" class="sa-nc__control sa-nc__control--textarea"></textarea>
      </label>
      <div class="sa-nc__broadcast-actions">
        <button type="submit" class="mc-btn mc-btn--primary">Send Broadcast</button>
      </div>
    </form>
  </div>

  <div class="mc-card sa-nc__table-card">
    <div class="sa-nc__table-scroll">
      <table class="mc-table admin-stack-table sa-nc__table">
        <thead>
          <tr>
            <th>User</th>
            <th>Type</th>
            <th>Title</th>
            <th>Priority</th>
            <th>Read</th>
            <th>Time</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recent)): ?>
          <tr><td colspan="7"><div class="mc-table-empty"><p>No notifications found.</p></div></td></tr>
          <?php else: foreach ($recent as $n): ?>
          <tr data-id="<?= (int) $n['id'] ?>">
            <td data-label="User" class="text-xs sa-nc__cell-user"><?= htmlspecialchars($n['email'] ?? '—') ?></td>
            <td data-label="Type"><?= htmlspecialchars($n['type'] ?? '') ?></td>
            <td data-label="Title" class="sa-nc__cell-title">
              <strong><?= htmlspecialchars($n['title'] ?? '') ?></strong>
              <?php if (!empty($n['message'])): ?>
              <div class="text-xs text-muted"><?= htmlspecialchars(mb_strimwidth($n['message'], 0, 80, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td data-label="Priority"><span class="mc-badge"><?= htmlspecialchars($n['priority'] ?? 'normal') ?></span></td>
            <td data-label="Read"><?= $n['is_read'] ? 'Yes' : 'No' ?></td>
            <td data-label="Time" class="text-xs text-muted"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></td>
            <td data-label="Actions" class="sa-nc__cell-actions">
              <?php if (!$n['is_read']): ?>
              <button type="button" class="mc-btn mc-btn--outline mc-btn--info sa-nc__row-btn js-mark-read" data-id="<?= (int) $n['id'] ?>">Read</button>
              <?php else: ?>
              <button type="button" class="mc-btn mc-btn--outline mc-btn--neutral sa-nc__row-btn js-mark-unread" data-id="<?= (int) $n['id'] ?>">Unread</button>
              <?php endif; ?>
              <button type="button" class="mc-btn mc-btn--outline mc-btn--danger sa-nc__row-btn js-delete" data-id="<?= (int) $n['id'] ?>">Delete</button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
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
