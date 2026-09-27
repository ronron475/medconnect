<?php
/**
 * Production chief-complaint NLP demo.
 * Uses ChiefComplaintNlpService via assess_chief_complaint.php.
 * Does not create a patient record.
 *
 * Local:  http://localhost/medconnect/public/nlp_production_demo.php
 * Online: https://medconnect.bccbsis.com/public/nlp_production_demo.php
 */
require_once dirname(__DIR__) . '/bootstrap.php';
$assetBase = ASSET_BASE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MedConnect — Production NLP Demo</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/nlp_production_demo.css?v=1.0" />
</head>
<body class="nlp-prod-body">
  <script>window.APP_BASE = <?= json_encode($assetBase, JSON_UNESCAPED_SLASHES) ?>;</script>
  <main class="nlp-prod-main">
    <header class="nlp-prod-header">
      <p class="nlp-prod-kicker">Production engine · nothing is saved</p>
      <h1>Chief complaint NLP</h1>
      <p class="nlp-prod-sub">
        Same path as registration Step 3: Hiligaynon medical NLP, then triage rules.
        English, Hiligaynon, and mixed complaints are accepted. This page only displays the result.
      </p>
    </header>

    <form id="nlp-prod-form" class="nlp-prod-card" novalidate>
      <label class="nlp-prod-label" for="nlp-prod-input">Patient complaint</label>
      <textarea
        id="nlp-prod-input"
        class="nlp-prod-input"
        rows="4"
        maxlength="1000"
        placeholder="e.g. Masakit gid akon dughan kag budlay magginhawa."
      ></textarea>
      <div class="nlp-prod-actions">
        <button type="submit" class="nlp-prod-btn" id="nlp-prod-submit">Analyze</button>
        <button type="button" class="nlp-prod-btn nlp-prod-btn--ghost" id="nlp-prod-clear">Clear</button>
      </div>
      <div class="nlp-prod-chips" aria-label="Sample complaints">
        <button type="button" class="nlp-prod-chip" data-text="sakit ulo ko">headache</button>
        <button type="button" class="nlp-prod-chip" data-text="ginahilanat ko 3 ka adlaw na">fever 3 days</button>
        <button type="button" class="nlp-prod-chip" data-text="Masakit gid akon dughan kag budlay magginhawa.">chest and breathing</button>
        <button type="button" class="nlp-prod-chip" data-text="hello">not medical</button>
      </div>
    </form>

    <p class="nlp-prod-status" id="nlp-prod-status" role="status" hidden></p>
    <section class="nlp-prod-result" id="nlp-prod-result" hidden aria-live="polite"></section>
  </main>
  <script src="<?= htmlspecialchars($assetBase) ?>/assets/js/nlp_production_demo.js?v=1.0" defer></script>
</body>
</html>
