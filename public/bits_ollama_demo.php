<?php
/**
 * DEMO / DEVELOPMENT ONLY — campus BITS Ollama playground.
 *
 * Local:  http://localhost/medconnect/public/bits_ollama_demo.php
 * Online: https://medconnect.bccbsis.com/public/bits_ollama_demo.php
 *
 * Language assist only. Does not set EMERGENCY / URGENT / NON-URGENT.
 * Does not replace ClinicalTriageEngine.
 */
require_once dirname(__DIR__) . '/bootstrap.php';
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['bits_ollama_demo_token']) || !is_string($_SESSION['bits_ollama_demo_token'])) {
    $_SESSION['bits_ollama_demo_token'] = bin2hex(random_bytes(24));
}
$csrfToken = (string) $_SESSION['csrf_token'];
$demoToken = (string) $_SESSION['bits_ollama_demo_token'];
$assetBase = ASSET_BASE;
$bitsUrl = defined('BITS_SERVICE_URL') ? BITS_SERVICE_URL : 'https://bits-service.bagocitycollege.com';
$bitsModel = defined('BITS_OLLAMA_MODEL') ? BITS_OLLAMA_MODEL : 'phi3:mini';
$bitsEnabled = defined('BITS_SERVICE_ENABLED') ? BITS_SERVICE_ENABLED : true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MedConnect — BITS Ollama Demo</title>
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
      <p class="gci-kicker">DEMO / DEVELOPMENT ONLY · campus Ollama · not a triage tool</p>
      <h1 class="gci-title">BITS Ollama Demo</h1>
      <p class="gci-sub">
        This page talks only to the campus BITS server
        (<strong><?= htmlspecialchars($bitsUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>)
        using model <strong><?= htmlspecialchars($bitsModel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>.
        It is a language demo. Final clinical acuity is still decided by
        <strong>ClinicalTriageEngine</strong>, not by BITS.
      </p>
      <p class="gci-pipeline" aria-label="Pipeline">
        Your text → POST /api/generate (BITS) → reply shown here · no booking · no triage write
      </p>
    </header>

    <section class="gci-card" aria-labelledby="bod-prompt-title">
      <h2 id="bod-prompt-title" class="gci-card-title">1. Ask BITS</h2>
      <p id="bod-status" class="gci-status" data-status="idle">
        Status: <?= $bitsEnabled ? 'Ready' : 'BITS is disabled in config' ?>
      </p>
      <label class="gci-label" for="bod-prompt">Prompt</label>
      <textarea
        id="bod-prompt"
        class="gci-input"
        rows="4"
        maxlength="2000"
        placeholder="e.g. Hello, say hi in one sentence.  ·  Sakit ulo ko since yesterday."
        <?= $bitsEnabled ? '' : 'disabled' ?>
      ></textarea>
      <div class="gci-actions">
        <button type="button" class="gci-btn gci-btn-primary" id="bod-send" <?= $bitsEnabled ? '' : 'disabled' ?>>Send to BITS</button>
        <button type="button" class="gci-btn gci-btn-secondary" id="bod-clear">Clear</button>
      </div>
      <div class="gci-chips" aria-label="Sample prompts">
        <button type="button" class="gci-chip" data-text="Hello, say hi in one sentence.">Hello</button>
        <button type="button" class="gci-chip" data-text="Sakit ulo ko since yesterday. Explain in English what the patient is saying. Do not give a triage level.">Hiligaynon headache</button>
        <button type="button" class="gci-chip" data-text="Translate to English: daw malupot ko kag sakit akon tiyan.">Translate complaint</button>
      </div>
      <p class="gci-hint">First reply can take up to a minute while the campus model loads. Do not close the page.</p>
    </section>

    <section class="gci-card" aria-labelledby="bod-reply-title">
      <h2 id="bod-reply-title" class="gci-card-title">2. Conversation</h2>
      <div id="bod-conversation" class="gci-conversation" aria-live="polite"></div>
    </section>

    <p class="gci-footer">
      Production triage remains: PHP NLP → ClinicalInterviewEngine → ClinicalTriageEngine.
      BITS is an optional campus language fallback only.
    </p>
  </main>
  <script src="<?= htmlspecialchars($assetBase) ?>/assets/js/bits_ollama_demo.js?v=1.0" defer></script>
</body>
</html>
