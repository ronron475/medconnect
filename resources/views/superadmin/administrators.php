<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/service.php';

$page_title = 'Administrator Management';
$admins = superadmin_list_admins($pdo);
$api = ASSET_BASE . '/app/api/superadmin/administrators.php';

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
  <div>
    <h2 class="text-h2">Administrator Management</h2>
    <p class="text-muted">Create, edit, suspend, and manage system administrators. Super Admin accounts are protected.</p>
  </div>
  <button type="button" class="mc-btn mc-btn--primary" id="btnAddAdmin">Add Administrator</button>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table" id="adminsTable">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
        <th>Joined</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($admins as $a): ?>
      <tr data-id="<?= (int) $a['id'] ?>">
        <td><strong><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?></strong></td>
        <td><?= htmlspecialchars($a['email']) ?></td>
        <td><span class="mc-badge"><?= strtoupper($a['role']) ?></span></td>
        <td><?= $a['is_active'] ? '<span style="color:#16a34a;font-weight:700;">Active</span>' : '<span style="color:#ef233c;font-weight:700;">Suspended</span>' ?></td>
        <td class="text-xs text-muted"><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
        <td>
          <?php if ($a['role'] !== 'superadmin'): ?>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button type="button" class="mc-btn mc-btn--outline btn-edit" style="padding:4px 10px;font-size:11px;" data-id="<?= (int) $a['id'] ?>" data-first="<?= htmlspecialchars($a['first_name']) ?>" data-last="<?= htmlspecialchars($a['last_name']) ?>" data-email="<?= htmlspecialchars($a['email']) ?>">Edit</button>
            <button type="button" class="mc-btn mc-btn--outline btn-toggle" style="padding:4px 10px;font-size:11px;" data-id="<?= (int) $a['id'] ?>" data-status="<?= $a['is_active'] ? 0 : 1 ?>" data-name="<?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name'], ENT_QUOTES) ?>"><?= $a['is_active'] ? 'Suspend' : 'Reactivate' ?></button>
            <button type="button" class="mc-btn mc-btn--outline btn-reset" style="padding:4px 10px;font-size:11px;" data-id="<?= (int) $a['id'] ?>">Reset PW</button>
            <button type="button" class="mc-btn mc-btn--outline btn-archive" style="padding:4px 10px;font-size:11px;color:#b91c1c;" data-id="<?= (int) $a['id'] ?>" data-name="<?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name'], ENT_QUOTES) ?>">Archive</button>
          </div>
          <?php else: ?>
          <span class="text-xs text-muted">Protected</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div id="adminModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
  <div class="mc-card" style="width:min(440px,92vw);margin:20px;">
    <h3 class="text-h3 mb-md" id="adminModalTitle">Add Administrator</h3>
    <form id="adminForm" style="display:flex;flex-direction:column;gap:12px;">
      <input type="hidden" name="user_id" id="adminUserId" value="">
      <input type="text" name="first_name" id="adminFirst" placeholder="First name" class="mc-btn mc-btn--outline" style="background:#fff;text-align:left;" required>
      <input type="text" name="last_name" id="adminLast" placeholder="Last name" class="mc-btn mc-btn--outline" style="background:#fff;text-align:left;" required>
      <input type="email" name="email" id="adminEmail" placeholder="Email" class="mc-btn mc-btn--outline" style="background:#fff;text-align:left;" required>
      <input type="password" name="password" id="adminPassword" placeholder="Password (create / reset)" class="mc-btn mc-btn--outline" style="background:#fff;text-align:left;">
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="mc-btn mc-btn--outline" id="adminModalClose">Cancel</button>
        <button type="submit" class="mc-btn mc-btn--primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var api = <?= json_encode($api) ?>;
  var modal = document.getElementById('adminModal');
  var form = document.getElementById('adminForm');
  var mode = 'create';

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(api, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  document.getElementById('btnAddAdmin').onclick = function () {
    mode = 'create';
    document.getElementById('adminModalTitle').textContent = 'Add Administrator';
    form.reset();
    document.getElementById('adminUserId').value = '';
    modal.style.display = 'flex';
  };
  document.getElementById('adminModalClose').onclick = function () { modal.style.display = 'none'; };

  form.onsubmit = function (e) {
    e.preventDefault();
    var id = document.getElementById('adminUserId').value;
    var payload = {
      first_name: document.getElementById('adminFirst').value,
      last_name: document.getElementById('adminLast').value,
      email: document.getElementById('adminEmail').value
    };
    if (mode === 'create') {
      payload.password = document.getElementById('adminPassword').value;
      post('create', payload).then(function (j) { alert(j.message); if (j.success) location.reload(); });
    } else {
      payload.user_id = id;
      post('update', payload).then(function (j) { alert(j.message); if (j.success) location.reload(); });
    }
  };

  document.querySelectorAll('.btn-edit').forEach(function (btn) {
    btn.onclick = function () {
      mode = 'edit';
      document.getElementById('adminModalTitle').textContent = 'Edit Administrator';
      document.getElementById('adminUserId').value = btn.dataset.id;
      document.getElementById('adminFirst').value = btn.dataset.first;
      document.getElementById('adminLast').value = btn.dataset.last;
      document.getElementById('adminEmail').value = btn.dataset.email;
      modal.style.display = 'flex';
    };
  });
  function requireReason(promptText) {
    var reason = window.prompt(promptText);
    if (reason === null) return null;
    reason = reason.trim();
    if (reason.length < 5) {
      alert('Please provide a detailed reason (at least 5 characters).');
      return null;
    }
    return reason;
  }

  document.querySelectorAll('.btn-toggle').forEach(function (btn) {
    btn.onclick = function () {
      var label = btn.dataset.status === '1' ? 'Reactivate' : 'Suspend';
      var reason = requireReason(label + ' ' + (btn.dataset.name || 'this administrator') + ' — enter reason for audit log:');
      if (!reason) return;
      post('toggle_status', { user_id: btn.dataset.id, status: btn.dataset.status, reason: reason }).then(function (j) {
        alert(j.message); if (j.success) location.reload();
      });
    };
  });
  document.querySelectorAll('.btn-reset').forEach(function (btn) {
    btn.onclick = function () {
      var pw = prompt('New password (min 8 characters):');
      if (!pw) return;
      post('reset_password', { user_id: btn.dataset.id, password: pw }).then(function (j) { alert(j.message); });
    };
  });
  document.querySelectorAll('.btn-archive').forEach(function (btn) {
    btn.onclick = function () {
      if (!confirm('Archive this administrator? The account will be deactivated but all records are preserved for audit.')) return;
      var reason = requireReason('Archive ' + (btn.dataset.name || 'this administrator') + ' — enter reason for audit log:');
      if (!reason) return;
      post('delete', { user_id: btn.dataset.id, reason: reason }).then(function (j) { alert(j.message); if (j.success) location.reload(); });
    };
  });
})();
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
