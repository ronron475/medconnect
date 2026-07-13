<?php
$page_title = 'Records';
$bhw_current_file = 'records/index.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$preselect = (int) ($_GET['patient_id'] ?? 0);
$barangay_label = htmlspecialchars($bhw_barangay_name);
$records_css_ver = (int) @filemtime(ASSETS_PATH . '/css/bhw-records.css');
$records_js_ver = (int) @filemtime(ASSETS_PATH . '/js/bhw-records.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-records.css?v=<?= $records_css_ver ?>">
<script src="<?= ASSET_BASE ?>/assets/js/bhw-records.js?v=<?= $records_js_ver ?>" defer></script>

<div class="bhw-records-page" id="bhwRecordsView" data-preselect="<?= $preselect ?>">

  <header class="bhw-records-header">
    <div>
      <h2 class="text-h2">Records</h2>
      <p class="bhw-records-sub">Browse uploaded documents and prescriptions for residents in <strong>Brgy. <?= $barangay_label ?></strong>. Select a patient below to review their file history.</p>
    </div>
    <a href="upload.php<?= $preselect ? '?patient_id=' . $preselect : '' ?>" class="bhw-records-link-btn" id="bhwRecordsUploadLink" style="display:none;">Upload document</a>
  </header>

  <nav class="bhw-records-nav" aria-label="Records sections">
    <a href="index.php<?= $preselect ? '?patient_id=' . $preselect : '' ?>" class="bhw-records-nav__item is-active" aria-current="page">View records</a>
    <a href="upload.php<?= $preselect ? '?patient_id=' . $preselect : '' ?>" class="bhw-records-nav__item">Upload document</a>
  </nav>

  <div class="bhw-records-main bhw-records-view-main">
    <section class="bhw-records-section" aria-labelledby="bhwRecordsSecFind">
      <h3 class="bhw-records-section__title" id="bhwRecordsSecFind">Find patient</h3>
      <p class="bhw-records-section__sub">Search by name, email, or contact number. Only patients in your barangay are listed.</p>
      <div id="bhwRecordsPicker" class="bhw-records-picker-mount" aria-live="polite"></div>
    </section>

    <section class="bhw-records-section" aria-labelledby="bhwRecordsSecDocs">
      <h3 class="bhw-records-section__title" id="bhwRecordsSecDocs">Uploaded documents</h3>
      <p class="bhw-records-section__sub">Residency and supporting files submitted for the selected patient.</p>
      <div id="bhwRecordsDocs">
        <div class="bhw-records-empty">
          <strong>Select a patient</strong>
          <span>Choose a resident from your barangay to view their records.</span>
        </div>
      </div>
    </section>

    <section class="bhw-records-section bhw-records-section--last" aria-labelledby="bhwRecordsSecRx">
      <h3 class="bhw-records-section__title" id="bhwRecordsSecRx">Prescriptions</h3>
      <p class="bhw-records-section__sub">Medications prescribed during provider consultations.</p>
      <div id="bhwRecordsRx"></div>
    </section>
  </div>

</div>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
