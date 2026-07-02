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
require_once BASE_PATH . '/app/includes/system_settings.php';
require_once __DIR__ . '/_portal_access.php';

$page_title = 'System Settings & AI Configuration';
$rules = $pdo->query("SELECT * FROM triage_rules ORDER BY base_level ASC, symptom_name ASC")->fetchAll();
$stored = system_settings_get_all($pdo);
$isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
$triageApi = ASSET_BASE . '/app/api/superadmin/triage_rules.php';
$settingsApi = ASSET_BASE . '/app/api/admin/save_system_settings.php';

$system_vars = [
    ['key' => 'AI_CONFIDENCE_THRESHOLD', 'value' => $stored['AI_CONFIDENCE_THRESHOLD'] ?? '0.85', 'desc' => 'Minimum confidence score for auto-triage.'],
    ['key' => 'MAX_APPOINTMENTS_PER_PROVIDER', 'value' => $stored['MAX_APPOINTMENTS_PER_PROVIDER'] ?? '15', 'desc' => 'Daily limit for standard providers.'],
    ['key' => 'SESSION_TIMEOUT_MINUTES', 'value' => $stored['SESSION_TIMEOUT_MINUTES'] ?? '60', 'desc' => 'Automatic logout duration.'],
];

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;flex-wrap:wrap;gap:12px;">
  <div>
    <h2 class="text-h2">System Configuration</h2>
    <p class="text-muted">Adjust AI triage parameters, system thresholds, and triage priority rules.</p>
  </div>
  <button type="button" class="mc-btn mc-btn--primary" id="saveSystemSettings">Save Global Changes</button>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">
  <div class="mc-card" style="padding:0;overflow:hidden;">
    <div style="padding:20px 20px 12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <h3 class="text-h3" style="margin:0;">AI Triage Priority Rules</h3>
      <button type="button" class="mc-btn mc-btn--outline" id="addRuleBtn">Add Rule</button>
    </div>
    <div style="overflow-x:auto;">
      <table class="mc-table" id="rulesTable">
        <thead><tr><th>Symptom Cluster</th><th>Base Level</th><th>Weight</th><th>Emergency</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($rules)): ?>
          <tr><td colspan="5"><div class="mc-table-empty"><p>No triage rules defined. Add one to get started.</p></div></td></tr>
          <?php else: foreach ($rules as $r): ?>
          <tr data-id="<?= (int) $r['id'] ?>">
            <td><input type="text" class="rule-name mc-btn mc-btn--outline" value="<?= htmlspecialchars($r['symptom_name']) ?>" style="width:100%;background:#fff;text-align:left;"></td>
            <td><input type="number" min="1" max="5" class="rule-level mc-btn mc-btn--outline" value="<?= (int) $r['base_level'] ?>" style="width:70px;background:#fff;"></td>
            <td><input type="number" step="0.01" min="0" class="rule-weight mc-btn mc-btn--outline" value="<?= htmlspecialchars((string) $r['weight']) ?>" style="width:80px;background:#fff;"></td>
            <td><input type="checkbox" class="rule-emergency" <?= $r['is_emergency'] ? 'checked' : '' ?>></td>
            <td style="white-space:nowrap;">
              <button type="button" class="mc-btn mc-btn--outline js-save-rule" style="padding:4px 8px;font-size:10px;">Save</button>
              <button type="button" class="mc-btn mc-btn--outline js-delete-rule" style="padding:4px 8px;font-size:10px;">Delete</button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mc-card">
    <h3 class="text-h3 mb-md">Global System Variables</h3>
    <form id="systemSettingsForm" style="display:flex;flex-direction:column;gap:20px;">
      <?php foreach ($system_vars as $v): ?>
      <div>
        <label class="text-xs" style="font-weight:800;text-transform:uppercase;"><?= str_replace('_', ' ', $v['key']) ?></label>
        <input type="text" name="<?= htmlspecialchars($v['key']) ?>" value="<?= htmlspecialchars($v['value']) ?>"
               class="mc-btn mc-btn--outline" style="width:100%;background:#fff;text-align:left;margin-top:8px;">
        <p class="text-xs text-muted" style="font-style:italic;margin-top:4px;"><?= $v['desc'] ?></p>
      </div>
      <?php endforeach; ?>
    </form>
  </div>
</div>

<template id="ruleRowTemplate">
  <tr data-id="0">
    <td><input type="text" class="rule-name mc-btn mc-btn--outline" placeholder="Symptom name" style="width:100%;background:#fff;text-align:left;"></td>
    <td><input type="number" min="1" max="5" class="rule-level mc-btn mc-btn--outline" value="3" style="width:70px;background:#fff;"></td>
    <td><input type="number" step="0.01" min="0" class="rule-weight mc-btn mc-btn--outline" value="1" style="width:80px;background:#fff;"></td>
    <td><input type="checkbox" class="rule-emergency"></td>
    <td style="white-space:nowrap;">
      <button type="button" class="mc-btn mc-btn--outline js-save-rule" style="padding:4px 8px;font-size:10px;">Save</button>
      <button type="button" class="mc-btn mc-btn--outline js-delete-rule" style="padding:4px 8px;font-size:10px;">Delete</button>
    </td>
  </tr>
</template>

<script>
(function () {
  var triageApi = <?= json_encode($triageApi) ?>;
  var settingsApi = <?= json_encode($settingsApi) ?>;

  function rulePayload(row) {
    var fd = new FormData();
    fd.append('symptom_name', row.querySelector('.rule-name').value.trim());
    fd.append('base_level', row.querySelector('.rule-level').value);
    fd.append('weight', row.querySelector('.rule-weight').value);
    if (row.querySelector('.rule-emergency').checked) fd.append('is_emergency', '1');
    return fd;
  }

  function bindRuleRow(row) {
    row.querySelector('.js-save-rule').onclick = function () {
      var id = parseInt(row.getAttribute('data-id') || '0', 10);
      var fd = rulePayload(row);
      fd.append('action', id > 0 ? 'update' : 'create');
      if (id > 0) fd.append('id', String(id));
      fetch(triageApi, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
        .then(function (j) { alert(j.message); if (j.success) location.reload(); });
    };
    row.querySelector('.js-delete-rule').onclick = function () {
      var id = parseInt(row.getAttribute('data-id') || '0', 10);
      if (id > 0) {
        if (!confirm('Delete this triage rule?')) return;
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', String(id));
        fetch(triageApi, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
          .then(function (j) { alert(j.message); if (j.success) location.reload(); });
      } else {
        row.remove();
      }
    };
  }

  document.querySelectorAll('#rulesTable tbody tr[data-id]').forEach(bindRuleRow);

  document.getElementById('addRuleBtn').onclick = function () {
    var tbody = document.querySelector('#rulesTable tbody');
    var empty = tbody.querySelector('.mc-table-empty');
    if (empty) empty.closest('tr').remove();
    var row = document.getElementById('ruleRowTemplate').content.firstElementChild.cloneNode(true);
    tbody.appendChild(row);
    bindRuleRow(row);
  };

  document.getElementById('saveSystemSettings').addEventListener('click', function () {
    var fd = new FormData(document.getElementById('systemSettingsForm'));
    fd.append('csrf_token', document.body.dataset.csrf || '');
    fetch(settingsApi, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) { alert(j.message || 'Done'); if (j.success) location.reload(); });
  });
})();
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
