<?php
/**
 * Unified BHW Consultation Center — schedule, status, and video assist.
 */
$page_title = 'Consultation Center';
$bhw_current_file = 'consultations/index.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';

$initialFilter = trim($_GET['filter'] ?? '');
$initialDate = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $initialDate)) {
    $initialDate = date('Y-m-d');
}

$consCssVer = (int) @filemtime(ASSETS_PATH . '/css/bhw-consultations.css');
$consJsVer = (int) @filemtime(ASSETS_PATH . '/js/bhw-consultations.js');
$bhw_extra_js = [
    ASSET_BASE . '/assets/js/bhw-consultations.js?v=' . $consJsVer,
];
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-consultations.css?v=<?= $consCssVer ?>">

<div class="bhw-cons-page" id="bhwConsRoot">

  <header class="bhw-cons-hero">
    <div>
      <h2 class="bhw-cons-hero__title">Consultation Center — Brgy. <?= htmlspecialchars($bhw_barangay_name) ?></h2>
      <p class="bhw-cons-hero__sub">
        Monitor teleconsultations for residents in your barangay, assist with video calls, and track consent status.
      </p>
    </div>
    <div class="bhw-cons-hero__actions">
      <span class="bhw-cons-sync">Last sync: <span id="bhwConsLastSync">—</span></span>
      <a href="../triage/submit.php" class="bhw-btn-teal">Triage &amp; Book</a>
    </div>
  </header>

  <div class="bhw-cons-metrics" aria-label="Consultation summary">
    <div class="bhw-cons-metric">
      <div class="bhw-cons-metric__label">Total Today</div>
      <div class="bhw-cons-metric__val" id="bhwConsMetricTotal">—</div>
    </div>
    <div class="bhw-cons-metric bhw-cons-metric--active">
      <div class="bhw-cons-metric__label">Active</div>
      <div class="bhw-cons-metric__val" id="bhwConsMetricActive">—</div>
    </div>
    <div class="bhw-cons-metric bhw-cons-metric--live">
      <div class="bhw-cons-metric__label">In Consultation</div>
      <div class="bhw-cons-metric__val" id="bhwConsMetricLive">—</div>
    </div>
    <div class="bhw-cons-metric bhw-cons-metric--done">
      <div class="bhw-cons-metric__label">Completed</div>
      <div class="bhw-cons-metric__val" id="bhwConsMetricDone">—</div>
    </div>
  </div>

  <div class="bhw-cons-panel">
    <div class="bhw-cons-panel-head">
      <h3 class="bhw-cons-panel-title">Today&apos;s Consultations</h3>
    </div>
    <div class="bhw-cons-toolbar">
      <input type="date" id="bhwConsDate" class="form-control" value="<?= htmlspecialchars($initialDate) ?>" style="width:auto;" aria-label="Consultation date">
      <select id="bhwConsStatus" class="form-select" style="width:auto;" aria-label="Status filter">
        <option value="">All statuses</option>
        <option value="active" <?= $initialFilter === 'active' ? 'selected' : '' ?>>Active today (scheduled + in call)</option>
        <option value="scheduled" <?= $initialFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
        <option value="in_consultation" <?= $initialFilter === 'in_consultation' ? 'selected' : '' ?>>In consultation</option>
        <option value="completed" <?= $initialFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="cancelled" <?= $initialFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
      </select>
      <div class="bhw-cons-search-wrap">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="bhwConsSearch" class="form-control" placeholder="Search patient or provider…" aria-label="Search consultations">
      </div>
      <button type="button" class="bhw-btn-outline" id="bhwConsRefresh">Refresh</button>
    </div>

    <div class="table-responsive bhw-cons-table-wrap">
      <table class="table bhw-table bhw-cons-table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Patient</th>
            <th>Provider</th>
            <th>Status</th>
            <th>Consent</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="bhwConsBody">
          <tr><td colspan="6" class="bhw-cons-loading">Loading consultations…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <section class="bhw-cons-help" aria-label="How consultation assist works">
    <article class="bhw-cons-help__card">
      <span class="bhw-cons-help__step">1</span>
      <div class="bhw-cons-help__body">
        <h3 class="bhw-cons-help__title">Book via Triage</h3>
        <p class="bhw-cons-help__text">Select a patient, record symptoms, capture consent, and book a provider slot.</p>
      </div>
    </article>
    <article class="bhw-cons-help__card">
      <span class="bhw-cons-help__step">2</span>
      <div class="bhw-cons-help__body">
        <h3 class="bhw-cons-help__title">Monitor status</h3>
        <p class="bhw-cons-help__text">Table refreshes every 30 seconds. Filter by date or status to track visits.</p>
      </div>
    </article>
    <article class="bhw-cons-help__card">
      <span class="bhw-cons-help__step">3</span>
      <div class="bhw-cons-help__body">
        <h3 class="bhw-cons-help__title">Assist video</h3>
        <p class="bhw-cons-help__text">When a room is active, open Assist Video on the patient&apos;s signed-in device.</p>
      </div>
    </article>
  </section>

</div>

<?php require __DIR__ . '/../partials/layout_close.php'; ?>
