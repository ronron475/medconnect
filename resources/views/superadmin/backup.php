<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/backup.php';
$page_title = 'Database Backup & Restore';
$backups = superadmin_list_backups($pdo);
$api = ASSET_BASE . '/app/api/superadmin/backup.php';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
  <div><h2 class="text-h2">Backup & Restore</h2><p class="text-muted">Manual backups, download, and restore database snapshots.</p></div>
  <button type="button" class="mc-btn mc-btn--primary" id="btnBackup">Create Manual Backup</button>
</div>
<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead><tr><th>File</th><th>Type</th><th>Status</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($backups as $b): ?>
      <tr>
        <td><?= htmlspecialchars($b['filename']) ?></td>
        <td><?= htmlspecialchars($b['backup_type']) ?></td>
        <td><span class="mc-badge"><?= strtoupper($b['status']) ?></span></td>
        <td class="text-xs"><?= $b['file_size'] ? number_format($b['file_size']/1024, 1).' KB' : '—' ?></td>
        <td class="text-xs text-muted"><?= date('M j, Y g:i A', strtotime($b['created_at'])) ?></td>
        <td style="display:flex;gap:6px;flex-wrap:wrap;">
          <?php if ($b['status'] === 'success'): ?>
          <a class="mc-btn mc-btn--outline" style="padding:4px 10px;font-size:11px;" href="<?= $api ?>?action=download&id=<?= (int)$b['id'] ?>">Download</a>
          <button type="button" class="mc-btn mc-btn--outline btn-restore" style="padding:4px 10px;font-size:11px;" data-id="<?= (int)$b['id'] ?>">Restore</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
var api=<?= json_encode($api) ?>;
document.getElementById('btnBackup').onclick=function(){var fd=new FormData();fd.append('action','create');fetch(api,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{alert(j.message);if(j.success)location.reload();});};
document.querySelectorAll('.btn-restore').forEach(function(b){b.onclick=function(){if(!confirm('Restore will overwrite current data. Continue?'))return;var fd=new FormData();fd.append('action','restore');fd.append('backup_id',b.dataset.id);fetch(api,{method:'POST',body:fd}).then(r=>r.json()).then(j=>alert(j.message));};});
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
