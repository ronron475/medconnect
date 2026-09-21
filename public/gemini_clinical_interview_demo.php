<?php
/**
 * DEMO / DEVELOPMENT ONLY — Gemini-driven clinical interview experiment.
 *
 * Local:  http://localhost/medconnect/public/gemini_clinical_interview_demo.php
 * Online: https://medconnect.bccbsis.com/public/gemini_clinical_interview_demo.php
 *         (or /gemini_clinical_interview_demo.php via api router)
 *
 * Does NOT modify production patient triage / ClinicalInterviewEngine.
 * Final triage always comes from ClinicalTriageEngine.
 */
require_once dirname(__DIR__) . '/bootstrap.php';
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['gemini_clinical_demo_token']) || !is_string($_SESSION['gemini_clinical_demo_token'])) {
    $_SESSION['gemini_clinical_demo_token'] = bin2hex(random_bytes(24));
}
$csrfToken = (string) $_SESSION['csrf_token'];
$demoToken = (string) $_SESSION['gemini_clinical_demo_token'];
$assetBase = ASSET_BASE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MedConnect — Gemini Clinical Interview Demo</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/gemini_clinical_interview_demo.css?v=1.0" />
</head>
<body
  class="gci-body"
  data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
  data-demo-token="<?= htmlspecialchars($demoToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
>
  <script>window.APP_BASE = <?= json_encode($assetBase, JSON_UNESCAPED_SLASHES) ?>;</script>
  <main class="gci-main">
    <header class="gci-header">
      <p class="gci-kicker">DEMO / DEVELOPMENT ONLY · not a production clinical decision tool</p>
      <h1 class="gci-title">Gemini Clinical Interview Demo</h1>
      <p class="gci-sub">
        Experiment: <strong>Gemini</strong> first checks that the opening text is a genuine health concern (any language),
        then asks dynamic follow-ups and extracts structured facts.
        Final acuity is always decided by the existing <strong>ClinicalTriageEngine</strong> (WHO IITT + clinical rules).
        Gemini must not set EMERGENCY / URGENT / NON-URGENT.
      </p>
      <p class="gci-pipeline" aria-label="Pipeline">
        Gemini interview → collected facts → ClinicalTriageEngine → final triage
      </p>
    </header>

    <section class="gci-card" aria-labelledby="gci-start-title">
      <h2 id="gci-start-title" class="gci-card-title">1. Patient complaint</h2>
      <label class="gci-label" for="gci-complaint">Primary complaint</label>
      <textarea
        id="gci-complaint"
        class="gci-input"
        rows="3"
        maxlength="1000"
        placeholder="e.g. gasakit tiil ko · daw malupot ko · sakit ulo · chest pain and shortness of breath"
      ></textarea>
      <div class="gci-actions">
        <button type="button" class="gci-btn gci-btn-primary" id="gci-start">Start Interview</button>
        <button type="button" class="gci-btn gci-btn-secondary" id="gci-reset">Reset</button>
      </div>
      <div class="gci-chips" aria-label="Sample complaints">
        <button type="button" class="gci-chip" data-text="gasakit tiil ko">gasakit tiil ko</button>
        <button type="button" class="gci-chip" data-text="sakit akon tiyan">sakit akon tiyan</button>
        <button type="button" class="gci-chip" data-text="daw malupot ko">daw malupot ko</button>
        <button type="button" class="gci-chip" data-text="Masakit gid akon dughan kag budlay magginhawa.">chest + breathing</button>
      </div>
    </section>

    <section class="gci-grid">
      <div class="gci-card" aria-labelledby="gci-chat-title">
        <h2 id="gci-chat-title" class="gci-card-title">2. Conversation</h2>
        <div id="gci-status" class="gci-status" data-status="idle">Status: Ready</div>
        <div id="gci-conversation" class="gci-conversation" aria-live="polite"></div>

        <div id="gci-answer-wrap" class="gci-answer-wrap" hidden>
          <label class="gci-label" for="gci-answer">Follow-up answer</label>
          <textarea id="gci-answer" class="gci-input" rows="2" maxlength="1000" placeholder="Type your answer here..."></textarea>
          <button type="button" class="gci-btn gci-btn-primary" id="gci-submit-answer">Submit Answer</button>
        </div>
      </div>

      <div class="gci-side">
        <section class="gci-card" aria-labelledby="gci-facts-title">
          <h2 id="gci-facts-title" class="gci-card-title">3. Collected clinical facts</h2>
          <pre id="gci-facts" class="gci-pre">{}</pre>
          <p class="gci-hint">Missing: <span id="gci-missing">—</span></p>
        </section>

        <section class="gci-card" aria-labelledby="gci-triage-title">
          <h2 id="gci-triage-title" class="gci-card-title">4. Final triage (ClinicalTriageEngine)</h2>
          <div id="gci-triage" class="gci-triage gci-triage--idle">Not finalized</div>
          <p id="gci-triage-reason" class="gci-hint"></p>
        </section>
      </div>
    </section>

    <section class="gci-card" aria-labelledby="gci-debug-title">
      <h2 id="gci-debug-title" class="gci-card-title">5. Debug panel</h2>
      <details open>
        <summary>Structured Gemini output + engine result</summary>
        <pre id="gci-debug" class="gci-pre gci-pre--debug">{}</pre>
      </details>
    </section>

    <p class="gci-footer">
      Production path remains: PHP NLP → ClinicalInterviewEngine → AdaptivePolicy/question bank → ClinicalTriageEngine.
      This page is a comparison experiment only.
    </p>
  </main>

  <script src="<?= htmlspecialchars($assetBase) ?>/assets/js/gemini_clinical_interview_demo.js?v=1.0" defer></script>
</body>
</html>
