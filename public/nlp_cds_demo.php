<?php
/**
 * Demo: Clinical Decision Support (CDS) Triage NLP
 * Open: http://localhost/medconnect/public/nlp_cds_demo.php
 */
require_once dirname(__DIR__) . '/bootstrap.php';
$assetBase = ASSET_BASE;
$phpNlpPrimary = filter_var(getenv('MEDCONNECT_PHP_NLP_ONLY') ?: '1', FILTER_VALIDATE_BOOLEAN);
$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$apiBase = $assetBase;
if (str_ends_with(rtrim($scriptDir, '/'), '/public')) {
    $apiBase = preg_replace('#/public$#', '', rtrim($scriptDir, '/')) ?: $assetBase;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>medConnect — CDS Triage NLP Demo</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/nlp_cds_demo.css?v=1.0" />
</head>
<body class="cds-demo-body">

  <main class="cds-demo-main">
    <header class="cds-demo-header">
      <p class="cds-demo-badge">Testing only · Not for production diagnosis</p>
      <h1 class="cds-demo-title">CDS Triage NLP Demo</h1>
      <p class="cds-demo-sub">Rule-based clinical decision support — English, Filipino, Hiligaynon, mixed &amp; misspelled chief complaints</p>
    </header>

    <section class="cds-demo-status cds-demo-status--ok" id="cds-service-status" role="status">
      <div class="cds-status-line"><strong>PHP rule-based CDS engine active</strong> — primary triage path (not a fallback).</div>
      <div class="cds-status-line cds-status-line--muted">Loading optional Python AI service status…</div>
    </section>

    <form id="cds-demo-form" class="cds-demo-form" novalidate>
      <label class="cds-label" for="chief-complaint">Chief complaint</label>
      <textarea
        id="chief-complaint"
        name="chief_complaint"
        class="cds-input"
        rows="4"
        maxlength="1000"
        placeholder="e.g. Budlay gid ang ginhawa ko kag masakit dughan. / I have fever for 5 days."
        required
      ></textarea>
      <div class="cds-form-actions">
        <button type="submit" class="cds-btn" id="btn-analyze">Analyze triage</button>
        <button type="button" class="cds-btn cds-btn--ghost" id="btn-clear">Clear</button>
      </div>
    </form>

    <section class="cds-samples" aria-label="Sample chief complaints">
      <h2 class="cds-samples__title">Quick test phrases (click to load)</h2>

      <h3 class="cds-samples__level cds-samples__level--routine">🟢 Non-urgent</h3>
      <div class="cds-chips">
        <button type="button" class="cds-chip" data-text="I have a mild cough and runny nose.">Mild cough (EN)</button>
        <button type="button" class="cds-chip" data-text="I need a medicine refill.">Medicine refill (EN)</button>
        <button type="button" class="cds-chip" data-text="May sipon kag ubo ako.">Sipon + ubo (HIL)</button>
        <button type="button" class="cds-chip" data-text="May sipon at ubo ako.">Sipon at ubo (FIL)</button>
      </div>

      <h3 class="cds-samples__level cds-samples__level--urgent">🟡 Urgent</h3>
      <div class="cds-chips">
        <button type="button" class="cds-chip" data-text="I have fever for 5 days.">Fever 5 days (EN)</button>
        <button type="button" class="cds-chip" data-text="Nilalagnat ako nang 5 araw.">Lagnat 5 araw (FIL)</button>
        <button type="button" class="cds-chip" data-text="Pain 8/10 in my abdomen for 2 days.">Pain 8/10 abdomen (EN)</button>
        <button type="button" class="cds-chip" data-text="My child has a high fever.">Child high fever (EN)</button>
      </div>

      <h3 class="cds-samples__level cds-samples__level--emergency">🔴 Emergency</h3>
      <div class="cds-chips">
        <button type="button" class="cds-chip" data-text="I have chest pain and difficulty breathing.">Chest pain + SOB (EN)</button>
        <button type="button" class="cds-chip" data-text="Budlay gid ang ginhawa ko.">Budlay ginhawa (HIL)</button>
        <button type="button" class="cds-chip" data-text="Hirap akong huminga.">Hirap huminga (FIL)</button>
        <button type="button" class="cds-chip" data-text="My left arm suddenly became weak and I cannot speak properly.">Stroke signs (EN)</button>
        <button type="button" class="cds-chip" data-text="I fainted.">Fainted (EN)</button>
        <button type="button" class="cds-chip" data-text="putol ang kamot ko">Hand cut off (HIL)</button>
      </div>

      <h3 class="cds-samples__level">Contextual reasoning</h3>
      <div class="cds-chips">
        <button type="button" class="cds-chip" data-text="Facial swelling">Facial swelling only (EN)</button>
        <button type="button" class="cds-chip" data-text="Facial swelling with difficulty breathing.">Facial swelling + SOB (EN)</button>
        <button type="button" class="cds-chip" data-text="Facial swelling with fever and severe pain.">Facial swelling + fever (EN)</button>
        <button type="button" class="cds-chip" data-text="I have headache.">Headache only (EN)</button>
        <button type="button" class="cds-chip" data-text="Sudden worst headache with neck stiffness.">Thunderclap headache (EN)</button>
        <button type="button" class="cds-chip" data-text="Masakit akon dughan.">Chest pain only (HIL)</button>
      </div>

      <h3 class="cds-samples__level">Mixed / misspelled</h3>
      <div class="cds-chips">
        <button type="button" class="cds-chip" data-text="May fever ako for 3 days na.">Mixed EN/FIL</button>
        <button type="button" class="cds-chip" data-text="Budlay ginhwa ko kag masakit dughan.">Misspelled HIL</button>
        <button type="button" class="cds-chip" data-text="I have fevr for 5 days.">Misspelled EN</button>
        <button type="button" class="cds-chip" data-text="Wala akong lagnat pero may ubo ako.">Negated fever (FIL)</button>
      </div>
    </section>

    <div class="cds-feedback" id="cds-feedback" role="status" hidden></div>
    <div class="cds-results" id="cds-results" hidden aria-live="polite"></div>
  </main>

  <script>
    window.APP_BASE = <?= json_encode($assetBase) ?>;
    window.CDS_DEMO = {
      apiBase: <?= json_encode($apiBase) ?>,
      phpNlpPrimary: <?= $phpNlpPrimary ? 'true' : 'false' ?>
    };
  </script>
  <script src="<?= htmlspecialchars($assetBase) ?>/assets/js/nlp_cds_demo.js?v=1.1"></script>
</body>
</html>
