<?php
/**
 * Demo: Registration Step 3 — NLP validation UI
 * Open: http://localhost/medconnect/public/nlp_step3_demo.php
 */
require_once dirname(__DIR__) . '/bootstrap.php';
$assetBase = ASSET_BASE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>medConnect — NLP Demo (Step 3)</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/nlp_step3_demo.css?v=2.8" />
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/nlp_medical_recognition.css" />
</head>
<body class="nlp-demo-body">

  <main class="nlp-demo-main">
    <header class="nlp-demo-header">
      <h1 class="nlp-demo-title">Registration Step 3 — NLP Demo</h1>
      <p class="nlp-demo-sub">Seven-step medical NLP pipeline: Hiligaynon/Ilonggo → English → dataset validation → confidence → triage → registration decision</p>
    </header>

    <section class="nlp-process-guide" aria-label="NLP pipeline process">
      <h2 class="nlp-process-guide__title">Pipeline process</h2>
      <ol class="nlp-process-steps">
        <li><strong>Step 1 — Preprocessing:</strong> lowercase, remove punctuation/fillers, normalize spelling, extract keywords</li>
        <li><strong>Step 2 — Medical Translation:</strong> Patient Input → Medical Dictionary → Hiligaynon Dataset → Keyword Extraction → Groq Context Analysis → English Interpretation → Fuzzy Matching → Validation</li>
        <li><strong>Step 3 — Fuzzy Matching:</strong> English only, RapidFuzz WRatio, ≥85% threshold</li>
        <li><strong>Step 4 — Dataset Validation:</strong> verify against official conditions, symptoms, allergies CSVs</li>
        <li><strong>Step 5 — Medical Confidence Assessment:</strong> per-term confidence score and level</li>
        <li class="nlp-process-steps__item--highlight"><strong>Step 6 — Clinical Urgency Classification:</strong> NON-URGENT / URGENT / EMERGENCY triage</li>
        <li><strong>Step 7 — Registration Decision:</strong> ACCEPTED, REJECTED, or EMERGENCY PRIORITY</li>
      </ol>
      <p class="nlp-process-engine">Engine: <strong>Python AI Service</strong> (preferred) · <strong>PHP Fallback</strong></p>
    </section>

    <form id="nlp-demo-form" class="nlp-demo-form" novalidate>
      <div class="nlp-field">
        <label class="nlp-label" for="existing-conditions">Known Medical Conditions &amp; Symptoms</label>
        <textarea
          id="existing-conditions"
          name="existing_conditions"
          class="nlp-input"
          rows="3"
          placeholder="e.g. May alta presyon ko kag sakit ulo. / hypertension, fever, diabetes"
        ></textarea>
      </div>

      <div class="nlp-field">
        <label class="nlp-label" for="known-allergies">Known Allergies</label>
        <textarea
          id="known-allergies"
          name="allergies"
          class="nlp-input"
          rows="3"
          placeholder="e.g. Penicillin, Shellfish (leave blank if none)"
        ></textarea>
      </div>

      <button type="submit" class="nlp-btn-validate" id="btn-validate">Validate</button>
    </form>

    <section class="nlp-wv-samples nlp-step6-samples" aria-label="Step 6 Clinical Urgency sample phrases">
      <h2 class="nlp-wv-samples__title">Step 6 — Clinical Urgency Classification (click to test)</h2>
      <p class="nlp-wv-samples__hint">Combinatorial engine: 1.4M+ Hiligaynon expressions · Click a phrase, then <strong>Validate</strong> and scroll to <a href="#nlp-step-6">Step 6</a> for explainable triage.</p>

      <h3 class="nlp-triage-samples__level nlp-triage-samples__level--routine">🟢 NON-URGENT — routine consultation</h3>
      <div class="nlp-wv-chips" data-triage="non_urgent">
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="kakatol bilat ko">kakatol bilat ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="gapula mata ko kag gakatol">gapula mata ko kag gakatol</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="sakit ulo ko">sakit ulo ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="galagas buhok ko">galagas buhok ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="ubo ko">ubo ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="gakatol kamot ko">gakatol kamot ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="gapula mata ko">gapula mata ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="gahabok tudlo ko">gahabok tudlo ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="nahilo tiyan ko">nahilo tiyan ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--routine" data-text="nabun-og kamot ko">nabun-og kamot ko</button>
      </div>

      <h3 class="nlp-triage-samples__level nlp-triage-samples__level--urgent">🟡 URGENT — consult provider within hours</h3>
      <div class="nlp-wv-chips" data-triage="urgent">
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="may nana sa bilat ko">may nana sa bilat ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="may nana akon mata">may nana akon mata</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="gahabok itlog ko">gahabok itlog ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="gadugo bilat ko">gadugo bilat ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="masakit ari ko">masakit ari ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="ginahilanat ko kag gahika ko">ginahilanat ko kag gahika ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="ginahilanat ko 3 ka adlaw na">ginahilanat ko 3 ka adlaw na</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="gahabok mata ko">gahabok mata ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="gahubag kamot ko">gahubag kamot ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="masakit pag-ihi ko">masakit pag-ihi ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="ginabaldom gid ko">ginabaldom gid ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="alta presyon ko">alta presyon ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="ginkagat sang ido ko">ginkagat sang ido ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="may pilas ko sa kamot kag nagdugo">may pilas ko sa kamot kag nagdugo</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="nabali kamot ko">nabali kamot ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="nasunog kamot ko sang mantika">nasunog kamot ko sang mantika</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="gahubag tungod ko kag ginahilanat ko">gahubag tungod ko kag ginahilanat ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="namaga mata ko">namaga mata ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--urgent" data-text="gahabok dila ko">gahabok dila ko</button>
      </div>

      <h3 class="nlp-triage-samples__level nlp-triage-samples__level--emergency">🔴 EMERGENCY — seek care immediately</h3>
      <div class="nlp-wv-chips" data-triage="emergency">
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="budlay magginhawa ko">budlay magginhawa ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="masakit dughan ko kag dula ginhawa ko">masakit dughan ko kag dula ginhawa ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="masakit dughan ko">masakit dughan ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="grabe gid nagadugo bilat ko">grabe gid nagadugo bilat ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="nautod tudlo ko">nautod tudlo ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="nabunggo ko sa salakyan">nabunggo ko sa salakyan</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="nakuryente ko">nakuryente ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="daw indi ko makahambal">daw indi ko makahambal</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="daw indi ko makabaton sang kamot ko">daw indi ko makabaton sang kamot ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="wala ko maka-ihi">wala ko maka-ihi</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="gahubag lawas ko kag gakatol">gahubag lawas ko kag gakatol</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="ginsumbag ko kag nabun-og ulo ko">ginsumbag ko kag nabun-og ulo ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="namaga gid dila ko">namaga gid dila ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="gahubag ngabil ko">gahubag ngabil ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="naguyam ko">naguyam ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="nagdugo ulo ko">nagdugo ulo ko</button>
        <button type="button" class="nlp-wv-chip nlp-wv-chip--emergency" data-text="galuya lawas ko kag kapoy gid ko">galuya lawas ko kag kapoy gid ko</button>
      </div>
    </section>

    <section class="nlp-wv-samples" aria-label="Western Visayas Hiligaynon sample phrases">
      <h2 class="nlp-wv-samples__title">More Western Visayas phrases</h2>
      <p class="nlp-wv-samples__hint">Additional combinatorial + dataset phrases (swelling, pain, trauma, ENT, reproductive).</p>
      <div class="nlp-wv-chips" id="nlp-wv-chips">
        <button type="button" class="nlp-wv-chip" data-text="gasakit ulo ko kag gasakit dughan ko">gasakit ulo ko kag gasakit dughan ko</button>
        <button type="button" class="nlp-wv-chip" data-text="gahbok mata ko">gahbok mata ko</button>
        <button type="button" class="nlp-wv-chip" data-text="may hubag sa mata ko">may hubag sa mata ko</button>
        <button type="button" class="nlp-wv-chip" data-text="akon mata gahubag">akon mata gahubag</button>
        <button type="button" class="nlp-wv-chip" data-text="mata ko gahabok">mata ko gahabok</button>
        <button type="button" class="nlp-wv-chip" data-text="gahubag gid akon kamot">gahubag gid akon kamot</button>
        <button type="button" class="nlp-wv-chip" data-text="may nana sa ari ko">may nana sa ari ko</button>
        <button type="button" class="nlp-wv-chip" data-text="gahubag itlog ko">gahubag itlog ko</button>
        <button type="button" class="nlp-wv-chip" data-text="masakit dughan ko kag gahika ko">masakit dughan ko kag gahika ko</button>
        <button type="button" class="nlp-wv-chip" data-text="ginahilanat gid ko">ginahilanat gid ko</button>
        <button type="button" class="nlp-wv-chip" data-text="ginadudul-om ko">ginadudul-om ko</button>
        <button type="button" class="nlp-wv-chip" data-text="ginasuka ko">ginasuka ko</button>
        <button type="button" class="nlp-wv-chip" data-text="nahilo ko">nahilo ko</button>
        <button type="button" class="nlp-wv-chip" data-text="nasunog lawas ko">nasunog lawas ko</button>
        <button type="button" class="nlp-wv-chip" data-text="nagdugo kamot ko">nagdugo kamot ko</button>
        <button type="button" class="nlp-wv-chip" data-text="gahabok tiil ko">gahabok tiil ko</button>
        <button type="button" class="nlp-wv-chip" data-text="gahubag tiyan ko">gahubag tiyan ko</button>
        <button type="button" class="nlp-wv-chip" data-text="gasakit likod ko">gasakit likod ko</button>
      </div>
    </section>

    <div class="nlp-service-status" id="nlp-service-status" role="status" hidden></div>
    <div class="nlp-feedback" id="nlp-feedback" role="status" hidden></div>
    <div class="nlp-results" id="nlp-results" hidden aria-live="polite"></div>
  </main>

  <script>window.APP_BASE = <?= json_encode($assetBase) ?>;</script>
  <script src="<?= htmlspecialchars($assetBase) ?>/assets/js/nlp_step3_demo.js?v=3.0"></script>
</body>
</html>
