<?php
/**
 * Dummy chief-complaint NLP trainer (sandbox).
 * Does not save to patient records.
 *
 * Open: http://localhost/medconnect/public/nlp_complaint_trainer.php
 */
require_once dirname(__DIR__) . '/bootstrap.php';
$assetBase = ASSET_BASE;

$dummyComplaints = [
    'non_urgent' => [
        ['text' => 'sakit ulo ko', 'en' => 'headache'],
        ['text' => 'ubo ko', 'en' => 'cough'],
        ['text' => 'gakatol kamot ko', 'en' => 'itchy hand'],
        ['text' => 'gapula mata ko', 'en' => 'red eye'],
        ['text' => 'galagas buhok ko', 'en' => 'hair loss'],
        ['text' => 'nahilo tiyan ko', 'en' => 'nauseous stomach'],
        ['text' => 'sip-on ko kag gamay nga ubo', 'en' => 'runny nose and mild cough'],
        ['text' => 'gasakit likod ko', 'en' => 'back pain'],
        ['text' => 'nabun-og kamot ko', 'en' => 'bruised hand'],
        ['text' => 'kakatol bilat ko', 'en' => 'vaginal itch'],
    ],
    'urgent' => [
        ['text' => 'ginahilanat ko 3 ka adlaw na', 'en' => 'fever for 3 days'],
        ['text' => 'masakit pag-ihi ko', 'en' => 'painful urination'],
        ['text' => 'alta presyon ko', 'en' => 'high blood pressure'],
        ['text' => 'ginkagat sang ido ko', 'en' => 'dog bite'],
        ['text' => 'nabali kamot ko', 'en' => 'broken hand'],
        ['text' => 'nasunog kamot ko sang mantika', 'en' => 'oil burn on hand'],
        ['text' => 'may nana sa bilat ko', 'en' => 'pus / infection'],
        ['text' => 'gahubag kamot ko', 'en' => 'swollen hand'],
        ['text' => 'ginabaldom gid ko', 'en' => 'severe abdominal pain'],
        ['text' => 'ginahilanat ko kag gahika ko', 'en' => 'fever and asthma'],
    ],
    'emergency' => [
        ['text' => 'budlay magginhawa ko', 'en' => 'difficulty breathing'],
        ['text' => 'masakit dughan ko', 'en' => 'chest pain'],
        ['text' => 'masakit dughan ko kag dula ginhawa ko', 'en' => 'chest pain + short of breath'],
        ['text' => 'naguyam ko', 'en' => 'seizure'],
        ['text' => 'daw indi ko makahambal', 'en' => 'cannot speak'],
        ['text' => 'nabunggo ko sa salakyan', 'en' => 'hit by a vehicle'],
        ['text' => 'nakuryente ko', 'en' => 'electrocuted'],
        ['text' => 'nagdugo ulo ko', 'en' => 'head bleeding'],
        ['text' => 'namaga gid dila ko', 'en' => 'tongue swelling'],
        ['text' => 'gahubag ngabil ko', 'en' => 'lip swelling'],
        ['text' => 'wala ko maka-ihi', 'en' => 'cannot urinate'],
        ['text' => 'gusto ko magpakamatay', 'en' => 'suicidal ideation'],
    ],
    'english' => [
        ['text' => 'I have a mild headache today', 'en' => 'mild headache'],
        ['text' => 'My cough has lasted two days', 'en' => 'cough'],
        ['text' => 'I feel dizzy and nauseous', 'en' => 'dizziness'],
        ['text' => 'I have a fever for 3 days', 'en' => 'fever'],
        ['text' => 'Painful urination since yesterday', 'en' => 'dysuria'],
        ['text' => 'My chest hurts and I cannot breathe well', 'en' => 'chest pain'],
        ['text' => 'I fainted this morning', 'en' => 'syncope'],
        ['text' => 'I was bitten by a dog', 'en' => 'dog bite'],
        ['text' => 'Severe shortness of breath', 'en' => 'dyspnea'],
        ['text' => 'I need a follow-up for my diabetes', 'en' => 'follow-up'],
    ],
    'mixed' => [
        ['text' => 'May fever ko kag sakit ulo', 'en' => 'mixed fever + headache'],
        ['text' => 'Doctor, sakit ulo ko gid subong', 'en' => 'telemedicine style'],
        ['text' => 'Help, budlay gid akon ginhawa', 'en' => 'help + dyspnea'],
        ['text' => 'May chest pain gid ko', 'en' => 'mixed chest pain'],
        ['text' => 'saket ulo ko', 'en' => 'misspelled sakit'],
        ['text' => 'ginahilnat ko', 'en' => 'misspelled fever'],
        ['text' => 'ubo gid', 'en' => 'shorthand'],
        ['text' => 'kalipong ko subong', 'en' => 'incomplete dizziness'],
        ['text' => 'gahbok mata ko', 'en' => 'misspelled gahabok'],
        ['text' => 'Shortness of breath gid ko', 'en' => 'English + gid'],
    ],
];

$groupMeta = [
    'non_urgent' => ['label' => 'Non-urgent', 'tone' => 'routine', 'expected' => 'NON-URGENT'],
    'urgent'     => ['label' => 'Urgent', 'tone' => 'urgent', 'expected' => 'URGENT'],
    'emergency'  => ['label' => 'Emergency', 'tone' => 'emergency', 'expected' => 'EMERGENCY'],
    'english'    => ['label' => 'English', 'tone' => 'english', 'expected' => ''],
    'mixed'      => ['label' => 'Mixed / misspelled', 'tone' => 'mixed', 'expected' => ''],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>medConnect — Complaint NLP Trainer</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/nlp_complaint_trainer.css?v=1.0" />
</head>
<body class="nct-body">
  <main class="nct-main">
    <header class="nct-header">
      <p class="nct-kicker">Sandbox · does not save to records</p>
      <h1 class="nct-title">Complaint NLP trainer</h1>
      <p class="nct-sub">
        Click a dummy complaint or type your own. Runs the live chief-complaint pipeline
        (Hiligaynon → English → symptoms → triage). Use mismatches to train and tune.
      </p>
      <p class="nct-links">
        Also: <a href="nlp_step3_demo.php">Registration Step 3 demo</a>
      </p>
    </header>

    <section class="nct-card nct-composer" aria-labelledby="nct-composer-title">
      <h2 id="nct-composer-title" class="nct-card-title">Patient complaint</h2>
      <form id="nct-form" novalidate>
        <label class="nct-label" for="nct-complaint">Type Hiligaynon, English, or mixed</label>
        <textarea
          id="nct-complaint"
          name="chief_complaint"
          rows="3"
          maxlength="1000"
          placeholder="e.g. Masakit gid akon ulo subong / I have chest pain / May fever ko"
        ></textarea>
        <div class="nct-actions">
          <button type="submit" class="nct-btn nct-btn--primary" id="nct-analyze">Analyze</button>
          <button type="button" class="nct-btn" id="nct-clear">Clear</button>
          <label class="nct-check">
            <input type="checkbox" id="nct-debug" />
            Pipeline debug
          </label>
          <span class="nct-hint">Ctrl+Enter to analyze</span>
        </div>
      </form>
      <div class="nct-status" id="nct-status" hidden></div>
    </section>

    <section class="nct-card" aria-labelledby="nct-dummy-title">
      <div class="nct-dummy-head">
        <h2 id="nct-dummy-title" class="nct-card-title">Dummy complaints</h2>
        <input type="search" id="nct-filter" class="nct-search" placeholder="Filter phrases…" />
      </div>
      <p class="nct-hint nct-hint--block">Click a chip to fill and analyze immediately. Colored chips have an expected triage for match scoring.</p>
      <div class="nct-tabs" role="tablist">
        <button type="button" class="nct-tab is-active" data-group="all">All</button>
        <?php foreach ($groupMeta as $key => $meta): ?>
          <button type="button" class="nct-tab" data-group="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($meta['label']) ?></button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($dummyComplaints as $group => $items):
          $meta = $groupMeta[$group];
      ?>
        <div class="nct-group" data-group="<?= htmlspecialchars($group) ?>">
          <div class="nct-group-bar">
            <h3 class="nct-group-title nct-group-title--<?= htmlspecialchars($meta['tone']) ?>">
              <?= htmlspecialchars($meta['label']) ?>
              <?php if ($meta['expected'] !== ''): ?>
                <span class="nct-expect">expect <?= htmlspecialchars($meta['expected']) ?></span>
              <?php endif; ?>
            </h3>
            <button type="button" class="nct-btn nct-btn--tiny" data-run-group="<?= htmlspecialchars($group) ?>">Run group</button>
          </div>
          <div class="nct-chips">
            <?php foreach ($items as $item): ?>
              <button
                type="button"
                class="nct-chip nct-chip--<?= htmlspecialchars($meta['tone']) ?>"
                data-text="<?= htmlspecialchars($item['text'], ENT_QUOTES) ?>"
                data-expected="<?= htmlspecialchars($meta['expected']) ?>"
                data-group="<?= htmlspecialchars($group) ?>"
                title="<?= htmlspecialchars($item['en']) ?>"
              ><?= htmlspecialchars($item['text']) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="nct-card nct-results" id="nct-results" hidden aria-live="polite">
      <h2 class="nct-card-title">NLP result</h2>
      <div id="nct-results-body"></div>
    </section>

    <section class="nct-card" aria-labelledby="nct-log-title">
      <div class="nct-dummy-head">
        <h2 id="nct-log-title" class="nct-card-title">Session log</h2>
        <div class="nct-log-actions">
          <span class="nct-score" id="nct-score">0 tested</span>
          <button type="button" class="nct-btn nct-btn--tiny" id="nct-export">Export CSV</button>
          <button type="button" class="nct-btn nct-btn--tiny" id="nct-clear-log">Clear log</button>
        </div>
      </div>
      <div class="nct-table-wrap">
        <table class="nct-table" id="nct-log">
          <thead>
            <tr>
              <th>#</th>
              <th>Complaint</th>
              <th>Expected</th>
              <th>Actual</th>
              <th>Match</th>
              <th>Confidence</th>
              <th>English</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <p class="nct-empty" id="nct-log-empty">No runs yet. Click a dummy complaint to start.</p>
    </section>
  </main>

  <script>window.APP_BASE = <?= json_encode($assetBase) ?>;</script>
  <script src="<?= htmlspecialchars($assetBase) ?>/assets/js/nlp_complaint_trainer.js?v=1.0"></script>
</body>
</html>
