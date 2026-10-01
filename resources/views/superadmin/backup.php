<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/backup.php';

$page_title = 'Database Backup & Restore';
$backups = superadmin_list_backups($pdo);
$status = superadmin_backup_status_summary($pdo);
$settings = $status['settings'];
$latest = $status['latest'];
$lastAuto = $status['last_auto'];
$api = ASSET_BASE . '/app/api/superadmin/backup.php';
$cronPath = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'backup_scheduled.php';
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isProductionHost = $host === 'medconnect.bccbsis.com' || str_ends_with($host, '.bccbsis.com');
// Prefer the live Hostinger path when viewing production; otherwise show a Hostinger-style example.
$cronDisplayPath = $isProductionHost
    ? str_replace('\\', '/', $cronPath)
    : '/home/USER/domains/medconnect.bccbsis.com/public_html/scripts/cron/backup_scheduled.php';
$weekdayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function sa_backup_status_badge(string $status): string
{
    $s = strtolower($status);
    $tone = $s === 'success' ? 'approved' : ($s === 'failed' ? 'danger' : 'pending');
    return '<span class="mc-badge mc-badge--' . htmlspecialchars($tone) . '">' . strtoupper(htmlspecialchars($status)) . '</span>';
}

require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row admin-page-intro">
  <div>
    <p class="admin-page-intro__desc text-muted">Manual and scheduled database backups, download, and authorized restore. Automatic restores never run.</p>
  </div>
  <button type="button" class="mc-btn mc-btn--primary" id="btnBackup">Create Manual Backup</button>
</div>

<div class="mc-card" style="padding:14px 16px;margin-bottom:12px;">
  <h3 class="text-h3" style="margin:0 0 10px;">Backup Security</h3>
  <dl class="text-xs" style="display:grid;grid-template-columns:minmax(160px,220px) 1fr;gap:6px 16px;margin:0;line-height:1.45;">
    <dt class="text-muted" style="font-weight:700;">Super Admin access</dt>
    <dd style="margin:0;">Protected</dd>
    <dt class="text-muted" style="font-weight:700;">POST actions</dt>
    <dd style="margin:0;">CSRF protected</dd>
    <dt class="text-muted" style="font-weight:700;">Backup path</dt>
    <dd style="margin:0;">Protected</dd>
    <dt class="text-muted" style="font-weight:700;">Restore</dt>
    <dd style="margin:0;">Password re-authentication required</dd>
    <dt class="text-muted" style="font-weight:700;">Restore confirmation</dt>
    <dd style="margin:0;"><code>RESTORE DATABASE</code> required</dd>
    <dt class="text-muted" style="font-weight:700;">Backup integrity</dt>
    <dd style="margin:0;">SHA-256 verified</dd>
    <dt class="text-muted" style="font-weight:700;">Backup encryption</dt>
    <dd style="margin:0;">Not enabled</dd>
    <dt class="text-muted" style="font-weight:700;">Storage</dt>
    <dd style="margin:0;">Same server</dd>
    <dt class="text-muted" style="font-weight:700;">Scheduled backup</dt>
    <dd style="margin:0;">Depends on Hostinger cron configuration</dd>
  </dl>
</div>

<div class="superadmin-stat-grid superadmin-stat-grid--compact" style="margin-bottom:12px;">
  <div class="mc-card superadmin-stat-card">
    <div class="text-xs text-muted" style="text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Latest Backup</div>
    <?php if ($latest): ?>
      <div class="text-sm" style="font-weight:700;margin-top:4px;word-break:break-all;"><?= htmlspecialchars($latest['filename']) ?></div>
      <div class="text-xs text-muted" style="margin-top:4px;">
        <?= sa_backup_status_badge((string) $latest['status']) ?>
        · <?= htmlspecialchars((string) $latest['backup_type']) ?>
        · <?= date('M j, Y', strtotime((string) $latest['created_at'])) . ' • ' . date('g:i A', strtotime((string) $latest['created_at'])) ?>
      </div>
    <?php else: ?>
      <div class="text-sm text-muted" style="margin-top:6px;">No backups yet</div>
    <?php endif; ?>
  </div>
  <div class="mc-card superadmin-stat-card">
    <div class="text-xs text-muted" style="text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Last Automatic Backup</div>
    <?php
      $autoStatus = $settings['last_auto_status'] ?: ($lastAuto['status'] ?? null);
      $autoAt = $settings['last_auto_at'] ?: ($lastAuto['created_at'] ?? null);
      $autoMsg = $settings['last_auto_message'] ?: ($lastAuto['notes'] ?? null);
    ?>
    <?php if ($autoStatus): ?>
      <div style="margin-top:6px;"><?= sa_backup_status_badge((string) $autoStatus) ?></div>
      <div class="text-xs text-muted" style="margin-top:4px;">
        <?= $autoAt ? date('M j, Y', strtotime((string) $autoAt)) . ' • ' . date('g:i A', strtotime((string) $autoAt)) : '—' ?>
        <?php if (!empty($lastAuto['filename'])): ?>
          · <?= htmlspecialchars((string) $lastAuto['filename']) ?>
        <?php endif; ?>
      </div>
      <?php if ($autoMsg): ?>
        <div class="text-xs text-muted" style="margin-top:4px;"><?= htmlspecialchars((string) $autoMsg) ?></div>
      <?php endif; ?>
    <?php else: ?>
      <div class="text-sm text-muted" style="margin-top:6px;">No automatic backup has run yet</div>
    <?php endif; ?>
  </div>
  <div class="mc-card superadmin-stat-card">
    <div class="text-xs text-muted" style="text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Schedule</div>
    <div class="text-sm" style="font-weight:700;margin-top:6px;">
      <?= $settings['enabled'] ? 'Enabled' : 'Disabled' ?>
      · <?= htmlspecialchars(ucfirst($settings['frequency'])) ?>
    </div>
    <div class="text-xs text-muted" style="margin-top:4px;">
      <?php if ($settings['frequency'] === 'hourly'): ?>
        Every hour (cron must run at least hourly)
      <?php elseif ($settings['frequency'] === 'weekly'): ?>
        <?= htmlspecialchars($weekdayLabels[$settings['weekday']] ?? 'Sunday') ?> at <?= sprintf('%02d:00', $settings['hour']) ?>
      <?php else: ?>
        Daily at <?= sprintf('%02d:00', $settings['hour']) ?>
      <?php endif; ?>
      · Keep last <?= (int) $settings['retention_count'] ?> backups
    </div>
  </div>
</div>

<div class="mc-card" style="padding:14px 16px;margin-bottom:12px;">
  <h3 class="text-h3" style="margin:0 0 10px;">Automatic Backup Settings</h3>
  <form id="backupSettingsForm" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end;">
    <label class="text-xs" style="display:flex;flex-direction:column;gap:4px;">
      <span class="text-muted" style="font-weight:700;">Enabled</span>
      <select name="enabled" class="form-select">
        <option value="1"<?= $settings['enabled'] ? ' selected' : '' ?>>On</option>
        <option value="0"<?= !$settings['enabled'] ? ' selected' : '' ?>>Off</option>
      </select>
    </label>
    <label class="text-xs" style="display:flex;flex-direction:column;gap:4px;">
      <span class="text-muted" style="font-weight:700;">Frequency</span>
      <select name="frequency" id="backupFrequency" class="form-select">
        <option value="hourly"<?= $settings['frequency'] === 'hourly' ? ' selected' : '' ?>>Hourly</option>
        <option value="daily"<?= $settings['frequency'] === 'daily' ? ' selected' : '' ?>>Daily</option>
        <option value="weekly"<?= $settings['frequency'] === 'weekly' ? ' selected' : '' ?>>Weekly</option>
      </select>
    </label>
    <label class="text-xs" id="backupHourWrap" style="display:flex;flex-direction:column;gap:4px;">
      <span class="text-muted" style="font-weight:700;">Hour (0–23)</span>
      <input type="number" name="hour" class="form-control" min="0" max="23" value="<?= (int) $settings['hour'] ?>">
    </label>
    <label class="text-xs" id="backupWeekdayWrap" style="display:flex;flex-direction:column;gap:4px;">
      <span class="text-muted" style="font-weight:700;">Weekday</span>
      <select name="weekday" class="form-select">
        <?php foreach ($weekdayLabels as $i => $label): ?>
        <option value="<?= $i ?>"<?= (int) $settings['weekday'] === $i ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-xs" style="display:flex;flex-direction:column;gap:4px;">
      <span class="text-muted" style="font-weight:700;">Retention (keep last N)</span>
      <input type="number" name="retention_count" class="form-control" min="1" max="365" value="<?= (int) $settings['retention_count'] ?>">
    </label>
    <div>
      <button type="submit" class="mc-btn mc-btn--primary" style="width:100%;">Save Schedule</button>
    </div>
  </form>
  <div class="text-xs text-muted" style="margin:12px 0 0;line-height:1.5;">
    <strong style="color:inherit;">Hostinger cron (required for automatic backups)</strong><br>
    Railway only runs the Python AI service — do <em>not</em> put this cron on Railway.
    Create the job in <strong>hPanel → Advanced → Cron Jobs</strong> (run hourly; PHP decides if a backup is due):
    <code style="display:block;margin-top:6px;white-space:pre-wrap;word-break:break-all;">5 * * * * /usr/bin/php <?= htmlspecialchars($cronDisplayPath) ?></code>
    <?php if (!$isProductionHost): ?>
    <span style="display:block;margin-top:6px;">Replace <code>USER</code> / path with your Hostinger account path after deploy. On this local machine the script is at <code style="word-break:break-all;"><?= htmlspecialchars(str_replace('\\', '/', $cronPath)) ?></code>.</span>
    <?php endif; ?>
  </div>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table admin-stack-table">
    <thead><tr><th>File</th><th>Type</th><th>Status</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (empty($backups)): ?>
      <tr><td colspan="6" style="text-align:center;padding:28px;color:#94a3b8;">No backups recorded yet.</td></tr>
      <?php else: foreach ($backups as $b):
        $isDump = in_array(($b['backup_type'] ?? ''), ['manual', 'scheduled'], true);
        $canAct = $isDump && ($b['status'] ?? '') === 'success';
      ?>
      <tr>
        <td data-label="File"><?= htmlspecialchars($b['filename']) ?></td>
        <td data-label="Type"><?= htmlspecialchars($b['backup_type']) ?></td>
        <td data-label="Status"><?= sa_backup_status_badge((string) $b['status']) ?></td>
        <td data-label="Size" class="text-xs"><?= !empty($b['file_size']) ? number_format(((int) $b['file_size']) / 1024, 1) . ' KB' : '—' ?></td>
        <td data-label="Created" class="text-xs text-muted"><?= !empty($b['created_at']) ? date('M j, Y', strtotime($b['created_at'])) . ' • ' . date('g:i A', strtotime($b['created_at'])) : '—' ?></td>
        <td data-label="Actions" style="display:flex;gap:6px;flex-wrap:wrap;">
          <?php if ($canAct): ?>
          <button type="button" class="mc-btn mc-btn--outline btn-download" style="padding:4px 10px;font-size:11px;" data-id="<?= (int) $b['id'] ?>" data-filename="<?= htmlspecialchars((string) $b['filename'], ENT_QUOTES, 'UTF-8') ?>">Download</button>
          <button type="button" class="mc-btn mc-btn--outline btn-restore" style="padding:4px 10px;font-size:11px;" data-id="<?= (int) $b['id'] ?>">Restore</button>
          <?php else: ?>
          <span class="text-xs text-muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div id="backupRestoreModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
  <div class="mc-card" style="width:min(480px,92vw);margin:20px;">
    <h3 class="text-h3 mb-md">Restore database</h3>
    <p class="text-xs" style="margin:0 0 12px;color:#b45309;font-weight:700;line-height:1.45;">WARNING: Restoring this backup will overwrite the current live database. This action is manual and is not automatically reversible.</p>
    <form id="backupRestoreForm" style="display:flex;flex-direction:column;gap:12px;">
      <input type="hidden" name="backup_id" id="restoreBackupId" value="">
      <label class="text-xs" style="display:flex;flex-direction:column;gap:4px;">
        <span class="text-muted" style="font-weight:700;">Current Super Admin Password</span>
        <input type="password" name="current_password" id="restorePassword" class="form-control" autocomplete="current-password" required>
      </label>
      <label class="text-xs" style="display:flex;flex-direction:column;gap:4px;">
        <span class="text-muted" style="font-weight:700;">Type RESTORE DATABASE</span>
        <input type="text" name="confirm_text" id="restoreConfirmText" class="form-control" autocomplete="off" required placeholder="RESTORE DATABASE">
      </label>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="mc-btn mc-btn--outline mc-btn--neutral" id="backupRestoreClose">Cancel</button>
        <button type="submit" class="mc-btn mc-btn--primary">Restore</button>
      </div>
    </form>
  </div>
</div>

<div id="backupDownloadModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
  <div class="mc-card" style="width:min(440px,92vw);margin:20px;">
    <h3 class="text-h3 mb-md">Download backup</h3>
    <p class="text-xs text-muted" style="margin:0 0 12px;line-height:1.45;">Re-enter your Super Admin password. The file is sent only after server-side verification.</p>
    <form id="backupDownloadForm" style="display:flex;flex-direction:column;gap:12px;">
      <input type="hidden" name="backup_id" id="downloadBackupId" value="">
      <input type="hidden" name="filename" id="downloadBackupFilename" value="">
      <label class="text-xs" style="display:flex;flex-direction:column;gap:4px;">
        <span class="text-muted" style="font-weight:700;">Current Super Admin Password</span>
        <input type="password" name="current_password" id="downloadPassword" class="form-control" autocomplete="current-password" required>
      </label>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="mc-btn mc-btn--outline mc-btn--neutral" id="backupDownloadClose">Cancel</button>
        <button type="submit" class="mc-btn mc-btn--primary">Download</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var api = <?= json_encode($api) ?>;
  var freq = document.getElementById('backupFrequency');
  var hourWrap = document.getElementById('backupHourWrap');
  var weekWrap = document.getElementById('backupWeekdayWrap');
  var restoreModal = document.getElementById('backupRestoreModal');
  var downloadModal = document.getElementById('backupDownloadModal');
  var restoreForm = document.getElementById('backupRestoreForm');
  var downloadForm = document.getElementById('backupDownloadForm');

  function syncScheduleFields() {
    var v = freq ? freq.value : 'daily';
    if (hourWrap) hourWrap.style.display = v === 'hourly' ? 'none' : 'flex';
    if (weekWrap) weekWrap.style.display = v === 'weekly' ? 'flex' : 'none';
  }
  if (freq) freq.addEventListener('change', syncScheduleFields);
  syncScheduleFields();

  function closeModals() {
    if (restoreModal) restoreModal.style.display = 'none';
    if (downloadModal) downloadModal.style.display = 'none';
    if (restoreForm) restoreForm.reset();
    if (downloadForm) downloadForm.reset();
  }

  document.getElementById('btnBackup').onclick = function () {
    var fd = new FormData();
    fd.append('action', 'create');
    fetch(api, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        alert(j.message || 'Done');
        if (j.success) location.reload();
      });
  };

  document.querySelectorAll('.btn-restore').forEach(function (b) {
    b.onclick = function () {
      if (!confirm('Restore will overwrite the current live database. This is manual only and cannot be undone easily. Continue?')) return;
      document.getElementById('restoreBackupId').value = b.dataset.id || '';
      document.getElementById('restorePassword').value = '';
      document.getElementById('restoreConfirmText').value = '';
      restoreModal.style.display = 'flex';
      document.getElementById('restorePassword').focus();
    };
  });

  document.querySelectorAll('.btn-download').forEach(function (b) {
    b.onclick = function () {
      document.getElementById('downloadBackupId').value = b.dataset.id || '';
      document.getElementById('downloadBackupFilename').value = b.dataset.filename || 'medconnect_backup.sql';
      document.getElementById('downloadPassword').value = '';
      downloadModal.style.display = 'flex';
      document.getElementById('downloadPassword').focus();
    };
  });

  document.getElementById('backupRestoreClose').onclick = closeModals;
  document.getElementById('backupDownloadClose').onclick = closeModals;

  restoreForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(restoreForm);
    fd.append('action', 'restore');
    fetch(api, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        alert(j.message || 'Done');
        if (j.success) location.reload();
      })
      .finally(function () {
        document.getElementById('restorePassword').value = '';
      });
  });

  downloadForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(downloadForm);
    fd.append('action', 'download');
    var filename = document.getElementById('downloadBackupFilename').value || 'medconnect_backup.sql';
    fetch(api, { method: 'POST', body: fd })
      .then(function (r) {
        var ct = (r.headers.get('Content-Type') || '').toLowerCase();
        if (ct.indexOf('application/sql') !== -1 || ct.indexOf('octet-stream') !== -1) {
          return r.blob().then(function (blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            closeModals();
          });
        }
        return r.json().then(function (j) {
          alert(j.message || 'Download failed.');
        });
      })
      .finally(function () {
        document.getElementById('downloadPassword').value = '';
      });
  });

  var form = document.getElementById('backupSettingsForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      fd.append('action', 'save_settings');
      fetch(api, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          alert(j.message || 'Saved');
          if (j.success) location.reload();
        });
    });
  }
})();
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
