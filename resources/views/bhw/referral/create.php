<?php
$page_title = 'Referrals';
$bhw_current_file = 'referral/create.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';

$preselect = (int) ($_GET['patient_id'] ?? 0);
$barangay_label = htmlspecialchars($bhw_barangay_name);
$bhw_subnav_items = [
    ['file' => 'status.php', 'label' => 'Referral status'],
    ['file' => 'create.php', 'label' => 'New referral'],
];
$bhw_subnav_active = 'referral/create.php';
?>
<div class="bhw-referral-page">

  <header class="bhw-referral-header">
    <h2 class="text-h2">Referrals</h2>
    <p>Create a referral for a resident in <strong>Brgy. <?= $barangay_label ?></strong>. Emergency triage cases may also generate referrals automatically.</p>
  </header>

  <?php require __DIR__ . '/../partials/bhw_module_subnav.php'; ?>

  <div class="bhw-card bhw-referral-card">
    <form id="bhwRefForm" class="bhw-portal-form" novalidate>
      <div class="bhw-field">
        <label class="form-label" for="bhwPatient">Patient</label>
        <select name="patient_id" id="bhwPatient" class="form-select" required aria-required="true">
          <option value="">Loading patients…</option>
        </select>
      </div>

      <div class="bhw-field">
        <label class="form-label" for="bhwRefType">Referral type</label>
        <select name="referral_type" id="bhwRefType" class="form-select">
          <option>Hospital</option>
          <option>Specialist</option>
          <option>Laboratory</option>
          <option>ABTC</option>
          <option>TB-DOTS</option>
          <option>Other</option>
        </select>
      </div>

      <div class="bhw-field">
        <label class="form-label" for="bhwFacility">Facility name</label>
        <input type="text" name="facility_name" id="bhwFacility" class="form-control" placeholder="From admin facility list or enter manually">
      </div>

      <div class="bhw-field">
        <label class="form-label" for="bhwReason">Reason for referral</label>
        <textarea name="reason" id="bhwReason" class="form-control" rows="4" required aria-required="true" placeholder="Clinical reason, urgency, and any instructions for the receiving facility…"></textarea>
      </div>

      <div class="bhw-portal-form-actions">
        <button type="submit" class="bhw-btn-teal">Create Referral</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var preselect = <?= (int) $preselect ?>;
  var patientEl = document.getElementById('bhwPatient');
  var form = document.getElementById('bhwRefForm');

  BhwPortal.loadPatients(patientEl).then(function () {
    if (preselect > 0) {
      patientEl.value = String(preselect);
    }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    var fd = new FormData(form);
    fd.append('action', 'create');
    var btn = form.querySelector('[type="submit"]');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Creating…';
    }
    BhwPortal.post('referrals.php', fd).then(function (r) {
      BhwPortal.toast(r.message, r.success);
      if (r.success) {
        location.href = 'status.php';
        return;
      }
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Create Referral';
      }
    }).catch(function () {
      BhwPortal.toast('Could not create referral. Please try again.', false);
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Create Referral';
      }
    });
  });
})();
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
