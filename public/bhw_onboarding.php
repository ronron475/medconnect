<?php
session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/core/BhwApplicationService.php';

$token = trim($_GET['token'] ?? '');
$service = new BhwApplicationService($pdo);
$app = $token !== '' ? $service->findByInviteToken($token) : null;
$status = (string) ($app['status'] ?? '');
$valid = $app && in_array($status, ['onboarding', 'requires_documents'], true);
$needsActivate = $app && $status === 'invited';
$asset = ASSET_BASE;
$api = ASSET_BASE . '/app/api/bhw/onboarding.php';
$docs = $valid ? ($app['documents'] ?? []) : [];
$hasGovId = false;
foreach ($docs as $d) {
    if (($d['document_type'] ?? '') === 'government_id') {
        $hasGovId = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Complete BHW Profile — medConnect</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($asset) ?>/assets/css/style.css"/>
  <link rel="stylesheet" href="<?= htmlspecialchars($asset) ?>/assets/css/register.css"/>
  <style>
    .setup-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 90px 24px 60px; position: relative; z-index: 1; }
    .setup-wrapper { width: 100%; max-width: 640px; }
    .setup-card { background: var(--white); border-radius: 20px; padding: 40px 36px 44px; border: 1px solid rgba(209,228,248,0.8); box-shadow: var(--shadow-float); }
    .setup-title { font-size: 20px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px; }
    .setup-sub { font-size: 14px; color: var(--text-mid); line-height: 1.55; margin-bottom: 20px; }
    .setup-group { margin-bottom: 16px; }
    .setup-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
    .setup-group input, .setup-group select { width: 100%; height: 48px; padding: 0 14px; border: 1.5px solid #d0e4f7; border-radius: 12px; font-size: 14px; box-sizing: border-box; }
    .setup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 640px) { .setup-grid { grid-template-columns: 1fr; } }
    .setup-btn { width: 100%; height: 52px; border: none; border-radius: 12px; background: linear-gradient(135deg, #0d9488, #14b8a6); color: #fff; font-weight: 700; font-size: 15px; cursor: pointer; margin-top: 8px; }
    .setup-btn.secondary { background: #fff; color: #0f766e; border: 1.5px solid #99f6e4; margin-top: 10px; }
    .setup-btn:disabled { opacity: .55; cursor: not-allowed; }
    .setup-alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; display: none; }
    .setup-alert.error { display: block; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    .setup-alert.success { display: block; background: #f0fdf4; color: #16a34a; border: 1px solid #86efac; }
    .setup-alert.warn { display: block; background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .doc-list { list-style: none; padding: 0; margin: 8px 0 0; font-size: 13px; color: #475569; }
    .readonly { background: #f8fafc; color: #64748b; }
    .section-title { font-size: 14px; font-weight: 700; margin: 18px 0 10px; color: #0f172a; }
  </style>
</head>
<body class="landing-page">
<div class="bg-canvas" aria-hidden="true"><canvas id="bubble-canvas"></canvas></div>
<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="<?= htmlspecialchars($asset) ?>/index.php" class="nav-logo">
      <img src="<?= htmlspecialchars($asset) ?>/assets/img/medcon_logo.png" alt="medConnect" class="nav-logo-img"/>
      <span class="logo-text">med<span class="logo-accent">Connect</span></span>
    </a>
  </div>
</nav>

<div class="setup-page">
  <div class="setup-wrapper">
    <div class="setup-card">
      <?php if ($needsActivate): ?>
        <h2 class="setup-title">Set Your Password First</h2>
        <p class="setup-sub">Activate your invite before completing your profile.</p>
        <a class="setup-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;" href="<?= htmlspecialchars($asset) ?>/public/bhw_activate.php?token=<?= urlencode($token) ?>">Go to Activation</a>
      <?php elseif (!$valid): ?>
        <h2 class="setup-title">Link Expired or Invalid</h2>
        <p class="setup-sub">This onboarding link is no longer valid. Contact your administrator if you still need to complete registration.</p>
      <?php else: ?>
        <h1 class="setup-title">Complete Your BHW Profile</h1>
        <p class="setup-sub">Confirm your personal information and upload your Government-issued ID. Your barangay assignment was set by the administrator.</p>
        <?php if (!empty($app['additional_docs_note'])): ?>
          <div class="setup-alert warn" style="display:block;">Corrections requested: <?= htmlspecialchars((string) $app['additional_docs_note']) ?></div>
        <?php endif; ?>
        <div id="ob-alert" class="setup-alert" role="alert"></div>
        <form id="onboardingForm" novalidate>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"/>
          <div class="section-title">Personal information</div>
          <div class="setup-grid">
            <div class="setup-group">
              <label for="first_name">First Name</label>
              <input type="text" id="first_name" name="first_name" required value="<?= htmlspecialchars((string) ($app['first_name'] ?? '')) ?>"/>
            </div>
            <div class="setup-group">
              <label for="last_name">Last Name</label>
              <input type="text" id="last_name" name="last_name" required value="<?= htmlspecialchars((string) ($app['last_name'] ?? '')) ?>"/>
            </div>
          </div>
          <div class="setup-group">
            <label for="middle_name">Middle Name (optional)</label>
            <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars((string) ($app['middle_name'] ?? '')) ?>"/>
          </div>
          <div class="setup-grid">
            <div class="setup-group">
              <label for="email">Email</label>
              <input type="email" id="email" class="readonly" value="<?= htmlspecialchars((string) ($app['email'] ?? '')) ?>" readonly/>
            </div>
            <div class="setup-group">
              <label for="phone">Mobile Number</label>
              <input type="tel" id="phone" name="phone" required value="<?= htmlspecialchars((string) ($app['phone'] ?? '')) ?>" placeholder="09171234567"/>
            </div>
          </div>
          <div class="setup-group">
            <label>Assigned Barangay</label>
            <input type="text" class="readonly" value="<?= htmlspecialchars((string) ($app['barangay_name'] ?? '')) ?>" readonly/>
          </div>

          <div class="section-title">Required personal document</div>
          <div class="setup-group">
            <label for="gov_id">Government-issued ID (PDF/JPG/PNG)</label>
            <input type="file" id="gov_id" accept=".pdf,.jpg,.jpeg,.png,.webp"/>
            <ul class="doc-list" id="docList">
              <?php foreach ($docs as $d): ?>
                <li><?= htmlspecialchars(str_replace('_', ' ', (string) $d['document_type'])) ?>: <?= htmlspecialchars((string) $d['original_name']) ?></li>
              <?php endforeach; ?>
              <?php if ($hasGovId): ?><li id="govIdOk">Government ID already uploaded.</li><?php endif; ?>
            </ul>
          </div>

          <button type="button" class="setup-btn secondary" id="saveBtn">Save Progress</button>
          <button type="submit" class="setup-btn" id="submitBtn">Submit for Approval</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>window.APP_BASE = <?= json_encode($asset) ?>;</script>
<script src="<?= htmlspecialchars($asset) ?>/assets/js/register.js"></script>
<?php if ($valid): ?>
<script>
(function () {
  var form = document.getElementById('onboardingForm');
  var alertEl = document.getElementById('ob-alert');
  var api = <?= json_encode($api) ?>;
  var token = <?= json_encode($token) ?>;
  var hasGovId = <?= $hasGovId ? 'true' : 'false' ?>;

  function showAlert(msg, ok) {
    alertEl.style.display = 'block';
    alertEl.className = 'setup-alert ' + (ok ? 'success' : 'error');
    alertEl.textContent = msg;
  }

  async function saveProfile() {
    var fd = new FormData(form);
    var res = await fetch(api + '?action=save', { method: 'POST', body: fd });
    return res.json();
  }

  async function uploadGovId() {
    var input = document.getElementById('gov_id');
    if (!input.files || !input.files[0]) return { success: true, skipped: true };
    var fd = new FormData();
    fd.append('token', token);
    fd.append('document_type', 'government_id');
    fd.append('document', input.files[0]);
    var res = await fetch(api + '?action=upload_document', { method: 'POST', body: fd });
    var json = await res.json();
    if (json.success) {
      hasGovId = true;
      var list = document.getElementById('docList');
      var li = document.createElement('li');
      li.textContent = 'government id: ' + input.files[0].name;
      list.appendChild(li);
      input.value = '';
    }
    return json;
  }

  document.getElementById('saveBtn').addEventListener('click', async function () {
    showAlert('', true);
    alertEl.style.display = 'none';
    var save = await saveProfile();
    if (!save.success) { showAlert(save.message || 'Could not save.', false); return; }
    var up = await uploadGovId();
    if (!up.success) { showAlert(up.message || 'Document upload failed.', false); return; }
    showAlert('Progress saved.', true);
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Submitting…';
    try {
      var save = await saveProfile();
      if (!save.success) { showAlert(save.message || 'Could not save.', false); return; }
      var up = await uploadGovId();
      if (!up.success) { showAlert(up.message || 'Document upload failed.', false); return; }
      if (!hasGovId && up.skipped) {
        showAlert('Upload your Government-issued ID before submitting.', false);
        return;
      }
      var fd = new FormData();
      fd.append('token', token);
      var res = await fetch(api + '?action=submit', { method: 'POST', body: fd });
      var json = await res.json();
      if (!json.success) { showAlert(json.message || 'Submit failed.', false); return; }
      showAlert(json.message || 'Submitted for approval.', true);
      form.querySelectorAll('input,button').forEach(function (el) { el.disabled = true; });
    } finally {
      btn.disabled = false;
      btn.textContent = 'Submit for Approval';
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
