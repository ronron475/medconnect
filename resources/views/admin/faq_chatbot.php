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
require_once __DIR__ . '/_portal_access.php';

require_once BASE_PATH . '/app/includes/faq_chatbot_schema.php';
faq_chatbot_ensure_schema($pdo);

$page_title = 'FAQ Chatbot NLP Dictionary';
$dictCount = (int) $pdo->query('SELECT COUNT(*) FROM translation_dictionary')->fetchColumn();
$medCount = (int) $pdo->query('SELECT COUNT(*) FROM medical_terms')->fetchColumn();
$histCount = (int) $pdo->query('SELECT COUNT(*) FROM conversation_history')->fetchColumn();
$api = ASSET_BASE . '/app/api/admin/faq_chatbot_dictionary.php';

require __DIR__ . '/partials/layout_open.php';
?>
<div class="container-fluid py-4">
    <h1 class="h3 mb-3">FAQ Chatbot — Hiligaynon NLP</h1>
    <p class="text-muted">Rule-based translation, FAQ memory, and dictionary maintenance (PHP only, no external AI).</p>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Translation rows</div>
                    <div class="h4 mb-0"><?= number_format($dictCount) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Medical terms</div>
                    <div class="h4 mb-0"><?= number_format($medCount) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Conversation history rows</div>
                    <div class="h4 mb-0"><?= number_format($histCount) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header">Dictionary maintenance</div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Build JSON: <code>php scripts/data/build_faq_chatbot_dictionary.php</code><br>
                Import MySQL: <code>php scripts/data/import_faq_chatbot_dictionary.php --force</code>
            </p>
            <button type="button" class="btn btn-primary me-2" id="btnReimport">Re-import from seed + JSON</button>
            <button type="button" class="btn btn-outline-danger" id="btnForceReimport">Force rebuild (truncate)</button>
            <div id="importStatus" class="mt-2 small"></div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">Translation test</div>
        <div class="card-body">
            <div class="mb-2">
                <textarea class="form-control" id="testText" rows="2" placeholder="Nalipong gid ko kag gasakit akon dughan."></textarea>
            </div>
            <button type="button" class="btn btn-secondary" id="btnTest">Run pipeline</button>
            <pre class="bg-light p-3 mt-3 small" id="testOut" style="max-height:320px;overflow:auto;"></pre>
        </div>
    </div>
</div>
<script>
(function () {
  const api = <?= json_encode($api) ?>;
  async function post(action, extra) {
    const res = await fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action, ...extra }),
    });
    return res.json();
  }
  document.getElementById('btnReimport').addEventListener('click', async () => {
    const j = await post('reimport', {});
    document.getElementById('importStatus').textContent = j.success
      ? 'Inserted ' + j.inserted + ', total ' + j.total
      : (j.message || 'Failed');
    if (j.success) location.reload();
  });
  document.getElementById('btnForceReimport').addEventListener('click', async () => {
    if (!confirm('Truncate translation_dictionary and re-import?')) return;
    const j = await post('reimport', { force: true });
    document.getElementById('importStatus').textContent = j.success
      ? 'Rebuilt. Total ' + j.total
      : (j.message || 'Failed');
    if (j.success) location.reload();
  });
  document.getElementById('btnTest').addEventListener('click', async () => {
    const text = document.getElementById('testText').value;
    const j = await post('translate_test', { text });
    document.getElementById('testOut').textContent = JSON.stringify(j.data || j, null, 2);
  });
})();
</script>
<?php require __DIR__ . '/partials/layout_close.php'; ?>
