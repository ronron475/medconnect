<?php
$page_title = 'Records';
$bhw_current_file = 'records/index.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
$preselect = (int) ($_GET['patient_id'] ?? 0);
$barangay_label = htmlspecialchars($bhw_barangay_name);
$records_css_ver = (int) @filemtime(ASSETS_PATH . '/css/bhw-records.css');
$records_js_ver = (int) @filemtime(ASSETS_PATH . '/js/bhw-records.js');
$bhw_head_css = ASSET_BASE . '/assets/css/bhw-records.css?v=' . $records_css_ver;
require __DIR__ . '/../partials/layout_open.php';
?>
<script src="<?= ASSET_BASE ?>/assets/js/bhw-records.js?v=<?= $records_js_ver ?>" defer></script>

<div class="bhw-records-page" id="bhwRecordsView" data-preselect="<?= $preselect ?>">

  <header class="bhw-records-header bhw-page-intro">
    <div>
      <p class="bhw-records-sub">Search residents in <strong>Brgy. <?= $barangay_label ?></strong> and open their record. Profile, health information, consultations, prescriptions, and documents stay in your barangay.</p>
    </div>
  </header>

  <section class="bhw-records-finder" aria-labelledby="bhwRecordsSearchLabel">
    <label class="bhw-records-search-label" id="bhwRecordsSearchLabel" for="bhwRecordsSearch">Search patients</label>
    <div class="bhw-records-search">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" id="bhwRecordsSearch" placeholder="Search by name, email, or contact number" autocomplete="off" aria-controls="bhwRecordsResults">
    </div>
    <p class="bhw-records-section__sub" id="bhwRecordsResultMeta">Loading patients in your barangay…</p>
    <div id="bhwRecordsResults" class="bhw-records-results" aria-live="polite"></div>
  </section>

  <div class="bhw-records-modal" id="bhwRecordsModal" hidden>
    <div class="bhw-records-modal__backdrop" data-records-close></div>
    <div class="bhw-records-modal__panel" role="dialog" aria-modal="true" aria-labelledby="bhwRecordsModalTitle">
      <header class="bhw-records-modal__head">
        <div class="bhw-records-modal__identity">
          <div class="bhw-records-card__avatar" id="bhwRecordsModalAvatar" aria-hidden="true">—</div>
          <div>
            <h2 id="bhwRecordsModalTitle">Patient record</h2>
            <p id="bhwRecordsModalMeta">Loading record…</p>
          </div>
        </div>
        <button type="button" class="bhw-records-modal__close" id="bhwRecordsModalClose" data-records-close aria-label="Close patient record">Close</button>
      </header>
      <div class="bhw-records-tabs" role="tablist" aria-label="Patient record sections">
        <button type="button" class="bhw-records-tabs__btn is-active" role="tab" id="bhwRecordsTabProfile" aria-selected="true" aria-controls="bhwRecordsPanelProfile" data-records-tab="profile">Profile</button>
        <button type="button" class="bhw-records-tabs__btn" role="tab" id="bhwRecordsTabHealth" aria-selected="false" aria-controls="bhwRecordsPanelHealth" data-records-tab="health" tabindex="-1">Health Information</button>
        <button type="button" class="bhw-records-tabs__btn" role="tab" id="bhwRecordsTabConsults" aria-selected="false" aria-controls="bhwRecordsPanelConsults" data-records-tab="consults" tabindex="-1">Consultations</button>
        <button type="button" class="bhw-records-tabs__btn" role="tab" id="bhwRecordsTabRx" aria-selected="false" aria-controls="bhwRecordsPanelRx" data-records-tab="rx" tabindex="-1">Prescriptions</button>
        <button type="button" class="bhw-records-tabs__btn" role="tab" id="bhwRecordsTabDocs" aria-selected="false" aria-controls="bhwRecordsPanelDocs" data-records-tab="docs" tabindex="-1">Documents</button>
      </div>
      <div class="bhw-records-modal__body">
        <section class="bhw-records-panel is-active" id="bhwRecordsPanelProfile" role="tabpanel" aria-labelledby="bhwRecordsTabProfile" data-records-panel="profile"></section>
        <section class="bhw-records-panel" id="bhwRecordsPanelHealth" role="tabpanel" aria-labelledby="bhwRecordsTabHealth" data-records-panel="health" hidden></section>
        <section class="bhw-records-panel" id="bhwRecordsPanelConsults" role="tabpanel" aria-labelledby="bhwRecordsTabConsults" data-records-panel="consults" hidden></section>
        <section class="bhw-records-panel" id="bhwRecordsPanelRx" role="tabpanel" aria-labelledby="bhwRecordsTabRx" data-records-panel="rx" hidden></section>
        <section class="bhw-records-panel" id="bhwRecordsPanelDocs" role="tabpanel" aria-labelledby="bhwRecordsTabDocs" data-records-panel="docs" hidden></section>
      </div>
    </div>
  </div>

</div>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
