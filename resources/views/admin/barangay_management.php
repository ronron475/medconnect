<?php
session_start();
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
require_once BASE_PATH . '/app/includes/auth_guard.php';
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Barangay Management';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM barangays WHERE archived_at IS NULL";
$params = [];
if ($search !== '') { $sql .= " AND name LIKE ?"; $params[] = "%$search%"; }
$sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="display:flex;justify-content:space-between;margin-bottom:24px;">
  <div><h2 class="text-h2">Barangay Management</h2><p class="text-muted">CRUD for barangay records used by GIS and BHW sector assignment.</p></div>
  <button class="mc-btn mc-btn--primary" id="brgyAddBtn">+ Add Barangay</button>
</div>

<form method="get" class="mc-card" style="padding:16px;margin-bottom:16px;">
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search barangay…" class="mc-btn mc-btn--outline" style="width:100%;max-width:320px;background:#fff;">
</form>

<div id="brgyForm" class="mc-card" style="display:none;margin-bottom:16px;">
  <form id="barangayForm">
    <input type="hidden" name="id" value="">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <input name="name" required placeholder="Barangay name" class="mc-btn mc-btn--outline" style="background:#fff;">
      <input name="city" value="Bago City" placeholder="City" class="mc-btn mc-btn--outline" style="background:#fff;">
      <input name="latitude" placeholder="Latitude" class="mc-btn mc-btn--outline" style="background:#fff;">
      <input name="longitude" placeholder="Longitude" class="mc-btn mc-btn--outline" style="background:#fff;">
      <button type="submit" class="mc-btn mc-btn--primary">Save Barangay</button>
    </div>
  </form>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead><tr><th>Name</th><th>City</th><th>Latitude</th><th>Longitude</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($barangays as $b): ?>
      <tr>
        <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
        <td><?= htmlspecialchars($b['city'] ?? 'Bago City') ?></td>
        <td><?= htmlspecialchars($b['latitude'] ?? '—') ?></td>
        <td><?= htmlspecialchars($b['longitude'] ?? '—') ?></td>
        <td><?= !empty($b['is_active']) ? 'Active' : 'Inactive' ?></td>
        <td>
          <button class="mc-btn mc-btn--outline mc-btn--sm" data-edit='<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>'>Edit</button>
          <button class="mc-btn mc-btn--outline mc-btn--sm" data-archive="<?= (int)$b['id'] ?>">Archive</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
var api='<?= ASSET_BASE ?>/app/api/admin/barangays.php';
document.getElementById('brgyAddBtn').onclick=function(){ document.getElementById('brgyForm').style.display='block'; };
document.getElementById('barangayForm').onsubmit=function(e){ e.preventDefault(); var fd=new FormData(e.target); fetch(api,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ alert(j.message); if(j.success) location.reload(); }); };
document.querySelectorAll('[data-edit]').forEach(function(b){ b.onclick=function(){ var d=JSON.parse(b.dataset.edit); var f=document.getElementById('barangayForm'); document.getElementById('brgyForm').style.display='block'; f.id.value=d.id; f.name.value=d.name; f.city.value=d.city||'Bago City'; f.latitude.value=d.latitude||''; f.longitude.value=d.longitude||''; };});
document.querySelectorAll('[data-archive]').forEach(function(b){ b.onclick=function(){ if(!confirm('Archive this barangay?'))return; var fd=new FormData(); fd.append('action','archive'); fd.append('id',b.dataset.archive); fetch(api,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.success) location.reload(); }); };});
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
