<?php
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
require_once BASE_PATH . '/app/includes/portal_auth.php';
require_once BASE_PATH . '/app/includes/nlp_inventory.php';
require_once __DIR__ . '/_portal_access.php';

if (!portal_is_superadmin()) {
    header('Location: ' . ASSET_BASE . '/views/admin/dashboard.php');
    exit;
}

$page_title = 'System Settings & AI Configuration';
$rules = $pdo->query("SELECT * FROM triage_rules ORDER BY base_level ASC, symptom_name ASC")->fetchAll();
$stored = system_settings_get_all($pdo);
$triageApi = ASSET_BASE . '/app/api/superadmin/triage_rules.php';
$settingsApi = ASSET_BASE . '/app/api/admin/save_system_settings.php';

$system_vars = [
    ['key' => 'AI_CONFIDENCE_THRESHOLD', 'value' => $stored['AI_CONFIDENCE_THRESHOLD'] ?? '0.85', 'desc' => 'Minimum confidence score for auto-triage.'],
    ['key' => 'MAX_APPOINTMENTS_PER_PROVIDER', 'value' => $stored['MAX_APPOINTMENTS_PER_PROVIDER'] ?? '15', 'desc' => 'Daily limit for standard providers.'],
    ['key' => 'SESSION_TIMEOUT_MINUTES', 'value' => $stored['SESSION_TIMEOUT_MINUTES'] ?? '60', 'desc' => 'Automatic logout duration.'],
];

$nlpCatalog = nlp_inventory_catalog();
$nlpSummary = nlp_inventory_summary($nlpCatalog);
$nlpMysql = nlp_inventory_mysql_stats($pdo);
$nlpCategories = array_keys($nlpSummary['categories']);
sort($nlpCategories);
$dictApi = ASSET_BASE . '/app/api/admin/faq_chatbot_dictionary.php';

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-ai-config.css?v=1.0">

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

<section class="ai-config-section" aria-labelledby="nlpCatalogTitle">
  <h2 class="ai-config-section__title" id="nlpCatalogTitle">NLP Knowledge Base</h2>
  <p class="ai-config-section__desc">All medConnect NLP datasets from <code>data/nlp/</code> used by triage, registration, Hiligaynon translation, and the FAQ chatbot.</p>

  <div class="nlp-catalog-summary">
    <div class="nlp-catalog-stat">
      <div class="nlp-catalog-stat__value"><?= number_format($nlpSummary['total_rows']) ?></div>
      <div class="nlp-catalog-stat__label">Total NLP rows (CSV/JSON)</div>
    </div>
    <div class="nlp-catalog-stat">
      <div class="nlp-catalog-stat__value"><?= (int) $nlpSummary['loaded'] ?> / <?= (int) $nlpSummary['total_datasets'] ?></div>
      <div class="nlp-catalog-stat__label">Datasets loaded</div>
    </div>
    <div class="nlp-catalog-stat">
      <div class="nlp-catalog-stat__value"><?= number_format((int) $nlpMysql['csv_triage_rules']) ?></div>
      <div class="nlp-catalog-stat__label">CSV triage rules (pipeline)</div>
    </div>
    <div class="nlp-catalog-stat">
      <div class="nlp-catalog-stat__value"><?= number_format((int) $nlpMysql['translation_dictionary']) ?></div>
      <div class="nlp-catalog-stat__label">FAQ dictionary (MySQL)</div>
    </div>
    <div class="nlp-catalog-stat">
      <div class="nlp-catalog-stat__value"><?= number_format((int) $nlpMysql['medical_terms']) ?></div>
      <div class="nlp-catalog-stat__label">Medical terms (MySQL)</div>
    </div>
  </div>

  <div class="mc-card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="padding:16px 20px 0;">
      <div class="nlp-catalog-toolbar">
        <input type="search" class="nlp-catalog-search" id="nlpCatalogSearch" placeholder="Search datasets…" aria-label="Search NLP datasets">
        <select class="nlp-catalog-filter" id="nlpCatalogCategory" aria-label="Filter by category">
          <option value="">All categories</option>
          <?php foreach ($nlpCategories as $cat): ?>
          <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="nlp-catalog-table-wrap">
      <table class="mc-table nlp-catalog-table" id="nlpCatalogTable">
        <thead>
          <tr>
            <th>Dataset</th>
            <th>Category</th>
            <th>Rows</th>
            <th>Status</th>
            <th>Path</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($nlpCatalog as $ds): ?>
          <tr data-category="<?= htmlspecialchars($ds['category']) ?>" data-label="<?= htmlspecialchars(strtolower($ds['label'] . ' ' . $ds['description'])) ?>">
            <td>
              <strong><?= htmlspecialchars($ds['label']) ?></strong>
              <div class="text-xs text-muted" style="margin-top:2px;"><?= htmlspecialchars($ds['description']) ?></div>
            </td>
            <td><?= htmlspecialchars($ds['category']) ?></td>
            <td><?= number_format((int) $ds['rows']) ?></td>
            <td><span class="nlp-status nlp-status--<?= htmlspecialchars($ds['status']) ?>"><?= htmlspecialchars($ds['status']) ?></span></td>
            <td><code class="nlp-catalog-path"><?= htmlspecialchars($ds['path']) ?></code></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="ai-config-section" aria-labelledby="nlpDictTitle">
  <h2 class="ai-config-section__title" id="nlpDictTitle">FAQ Chatbot Dictionary (MySQL)</h2>
  <p class="ai-config-section__desc">Import Hiligaynon NLP translation data into MySQL for the landing-page FAQ chatbot pipeline.</p>

  <div class="mc-card">
    <div class="nlp-dict-panel">
      <div class="nlp-catalog-stat">
        <div class="nlp-catalog-stat__value"><?= number_format((int) $nlpMysql['translation_dictionary']) ?></div>
        <div class="nlp-catalog-stat__label">Translation rows</div>
      </div>
      <div class="nlp-catalog-stat">
        <div class="nlp-catalog-stat__value"><?= number_format((int) $nlpMysql['medical_terms']) ?></div>
        <div class="nlp-catalog-stat__label">Medical terms</div>
      </div>
      <div class="nlp-catalog-stat">
        <div class="nlp-catalog-stat__value"><?= number_format((int) $nlpMysql['conversation_history']) ?></div>
        <div class="nlp-catalog-stat__label">Conversation history</div>
      </div>
    </div>
    <div class="nlp-dict-actions">
      <button type="button" class="mc-btn mc-btn--primary" id="btnNlpReimport">Re-import from seed + JSON</button>
      <button type="button" class="mc-btn mc-btn--outline" id="btnNlpForceReimport">Force rebuild (truncate)</button>
    </div>
    <p class="text-xs text-muted" id="nlpImportStatus" style="margin:0 0 16px;">
      CLI: <code>php scripts/data/build_faq_chatbot_dictionary.php</code> ·
      <code>php scripts/data/import_faq_chatbot_dictionary.php --force</code>
    </p>
    <div class="nlp-test-area">
      <label class="text-xs" style="font-weight:800;text-transform:uppercase;display:block;margin-bottom:8px;">Translation pipeline test</label>
      <textarea id="nlpTestText" placeholder="Nalipong gid ko kag gasakit akon dughan."></textarea>
      <button type="button" class="mc-btn mc-btn--outline" id="btnNlpTest" style="margin-top:10px;">Run NLP pipeline</button>
      <pre class="nlp-test-output" id="nlpTestOut" hidden></pre>
    </div>
  </div>
</section>

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

  var dictApi = <?= json_encode($dictApi) ?>;
  function nlpDictPost(action, extra) {
    return fetch(dictApi, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(Object.assign({ action: action }, extra || {})),
    }).then(function (r) { return r.json(); });
  }
  document.getElementById('btnNlpReimport').addEventListener('click', function () {
    nlpDictPost('reimport', {}).then(function (j) {
      document.getElementById('nlpImportStatus').textContent = j.success
        ? 'Inserted ' + j.inserted + ', total ' + j.total
        : (j.message || 'Failed');
      if (j.success) location.reload();
    });
  });
  document.getElementById('btnNlpForceReimport').addEventListener('click', function () {
    if (!confirm('Truncate translation_dictionary and re-import?')) return;
    nlpDictPost('reimport', { force: true }).then(function (j) {
      document.getElementById('nlpImportStatus').textContent = j.success
        ? 'Rebuilt. Total ' + j.total
        : (j.message || 'Failed');
      if (j.success) location.reload();
    });
  });
  document.getElementById('btnNlpTest').addEventListener('click', function () {
    var out = document.getElementById('nlpTestOut');
    out.hidden = false;
    out.textContent = 'Running…';
    nlpDictPost('translate_test', { text: document.getElementById('nlpTestText').value }).then(function (j) {
      out.textContent = JSON.stringify(j.data || j, null, 2);
    });
  });

  function filterNlpCatalog() {
    var q = (document.getElementById('nlpCatalogSearch').value || '').toLowerCase().trim();
    var cat = document.getElementById('nlpCatalogCategory').value;
    document.querySelectorAll('#nlpCatalogTable tbody tr').forEach(function (row) {
      var matchCat = !cat || row.getAttribute('data-category') === cat;
      var matchQ = !q || (row.getAttribute('data-label') || '').indexOf(q) !== -1;
      row.style.display = matchCat && matchQ ? '' : 'none';
    });
  }
  document.getElementById('nlpCatalogSearch').addEventListener('input', filterNlpCatalog);
  document.getElementById('nlpCatalogCategory').addEventListener('change', filterNlpCatalog);
})();
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
