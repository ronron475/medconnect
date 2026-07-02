<?php
$page_title = 'Refer to Hospital';
$bhw_current_file = 'referral/create.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
?>
<div class="bhw-card" style="max-width:640px;">
  <h2 class="text-h2">Refer to Hospital / Facility</h2>
  <form id="bhwRefForm">
    <div class="mb-3"><label class="form-label">Patient</label><select name="patient_id" id="bhwPatient" class="form-select" required></select></div>
    <div class="mb-3"><label class="form-label">Type</label><select name="referral_type" class="form-select"><option>Hospital</option><option>Specialist</option><option>Laboratory</option><option>ABTC</option><option>TB-DOTS</option><option>Other</option></select></div>
    <div class="mb-3"><label class="form-label">Facility name</label><input name="facility_name" class="form-control" placeholder="From admin facility list"></div>
    <div class="mb-3"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3" required></textarea></div>
    <button type="submit" class="bhw-btn-teal">Create Referral</button>
    <a href="status.php" class="bhw-btn-outline ms-2">View status</a>
  </form>
</div>
<script>
BhwPortal.loadPatients(document.getElementById('bhwPatient'));
document.getElementById('bhwRefForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('action', 'create');
  BhwPortal.post('referrals.php', fd).then(function (r) {
    BhwPortal.toast(r.message, r.success);
    if (r.success) location.href = 'status.php';
  });
});
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
