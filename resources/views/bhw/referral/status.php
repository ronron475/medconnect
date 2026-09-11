<?php
$page_title = 'Referral Follow-up';
$bhw_current_file = 'referral/status.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
$bhw_subnav_items = [
    ['file' => 'status.php', 'label' => 'Referral follow-up'],
];
$bhw_subnav_active = 'referral/status.php';
?>
<div class="bhw-referral-status-page">

  <header class="bhw-referral-header">
    <div>
      <h2 class="text-h2">Referral Follow-up</h2>
      <p>Doctor referrals for residents in <strong>Brgy. <?= $barangay_label ?></strong>. Record whether the patient was contacted and acted on the referral. You cannot create or change the clinical referral.</p>
    </div>
  </header>

  <?php require __DIR__ . '/../partials/bhw_module_subnav.php'; ?>

  <div class="bhw-card bhw-referral-table-card">
    <div class="table-responsive">
      <table class="table bhw-table mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Patient</th>
            <th>Type</th>
            <th>Facility</th>
            <th>Clinical status</th>
            <th>BHW follow-up</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="bhwRefBody"></tbody>
      </table>
    </div>
  </div>

  <div id="bhwFuPanel" class="bhw-card bhw-referral-card" hidden style="margin-top:16px;">
    <h3 class="text-h3" style="margin-top:0;">Record community follow-up</h3>
    <p class="text-muted text-sm" id="bhwFuMeta"></p>
    <form id="bhwFuForm" class="bhw-portal-form" novalidate>
      <input type="hidden" name="referral_id" id="bhwFuReferralId" value="">
      <div class="bhw-field">
        <label class="form-label" for="bhwFuContacted">Patient contacted?</label>
        <select name="patient_contacted" id="bhwFuContacted" class="form-select" required>
          <option value="1">Yes</option>
          <option value="0">No</option>
        </select>
      </div>
      <div class="bhw-field">
        <label class="form-label" for="bhwFuActed">Received / acted on referral?</label>
        <select name="acted_on_referral" id="bhwFuActed" class="form-select" required>
          <option value="1">Yes</option>
          <option value="0">No</option>
        </select>
      </div>
      <div class="bhw-field">
        <label class="form-label" for="bhwFuNotes">Follow-up notes</label>
        <textarea name="notes" id="bhwFuNotes" class="form-control" rows="3" placeholder="Optional notes about contact or referral outcome…"></textarea>
      </div>
      <div class="bhw-portal-form-actions">
        <button type="submit" class="bhw-btn-teal">Save follow-up</button>
        <button type="button" class="btn btn-outline-secondary" id="bhwFuCancel">Cancel</button>
      </div>
    </form>
    <div id="bhwFuHistory" class="mt-3"></div>
  </div>
</div>
<script>
(function () {
  var tb = document.getElementById('bhwRefBody');
  var panel = document.getElementById('bhwFuPanel');
  var form = document.getElementById('bhwFuForm');
  var meta = document.getElementById('bhwFuMeta');
  var historyEl = document.getElementById('bhwFuHistory');

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function loadList() {
    return BhwPortal.get('referrals.php', { action: 'list' }).then(function (r) {
      var rows = r.referrals || [];
      if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No doctor referrals for your barangay yet.</td></tr>';
        return;
      }
      tb.innerHTML = rows.map(function (x) {
        var fu = x.followup_summary
          ? esc(x.followup_summary) + (x.followup_at ? ' <span class="text-muted">(' + esc(x.followup_at) + ')</span>' : '')
          : '<span class="text-muted">Not recorded</span>';
        return '<tr>' +
          '<td>' + esc(x.created_at || '') + '</td>' +
          '<td>' + esc(x.patient_name) + '</td>' +
          '<td>' + esc(x.referral_type) + '</td>' +
          '<td>' + esc(x.facility_display || '—') + '</td>' +
          '<td>' + esc(x.status || '') + '</td>' +
          '<td>' + fu + '</td>' +
          '<td><button type="button" class="bhw-pl-btn bhw-pl-btn--outline bhw-fu-open" data-id="' + esc(x.id) + '" data-patient="' + esc(x.patient_name) + '" data-type="' + esc(x.referral_type) + '">Follow up</button></td>' +
          '</tr>';
      }).join('');
    }).catch(function () {
      tb.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Could not load referrals.</td></tr>';
    });
  }

  function loadHistory(referralId) {
    historyEl.innerHTML = '<p class="text-muted text-sm">Loading history…</p>';
    BhwPortal.get('referrals.php', { action: 'followups', referral_id: referralId }).then(function (r) {
      var rows = r.followups || [];
      if (!rows.length) {
        historyEl.innerHTML = '<p class="text-muted text-sm mb-0">No previous community follow-ups.</p>';
        return;
      }
      historyEl.innerHTML = '<h4 class="text-sm" style="margin:0 0 8px;">Previous follow-ups</h4><ul class="mb-0" style="padding-left:1.1rem;">' +
        rows.map(function (f) {
          var line = (Number(f.patient_contacted) ? 'Contacted' : 'Not contacted') +
            '; ' + (Number(f.acted_on_referral) ? 'acted on' : 'not acted on');
          if (f.notes) line += ' — ' + f.notes;
          return '<li class="text-sm">' + esc(f.created_at || '') + ' · ' + esc(line) +
            (f.bhw_name ? ' <span class="text-muted">(' + esc(f.bhw_name) + ')</span>' : '') + '</li>';
        }).join('') + '</ul>';
    }).catch(function () {
      historyEl.innerHTML = '<p class="text-danger text-sm mb-0">Could not load follow-up history.</p>';
    });
  }

  tb.addEventListener('click', function (e) {
    var btn = e.target.closest('.bhw-fu-open');
    if (!btn) return;
    var id = btn.getAttribute('data-id');
    document.getElementById('bhwFuReferralId').value = id;
    meta.textContent = (btn.getAttribute('data-patient') || 'Patient') + ' · ' + (btn.getAttribute('data-type') || 'Referral') + ' · clinical referral stays unchanged';
    form.reset();
    document.getElementById('bhwFuReferralId').value = id;
    panel.hidden = false;
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    loadHistory(id);
  });

  document.getElementById('bhwFuCancel').addEventListener('click', function () {
    panel.hidden = true;
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(form);
    fd.append('action', 'record_followup');
    var btn = form.querySelector('[type="submit"]');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Saving…';
    }
    BhwPortal.post('referrals.php', fd).then(function (r) {
      BhwPortal.toast(r.message, r.success);
      if (r.success) {
        panel.hidden = true;
        loadList();
      }
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Save follow-up';
      }
    }).catch(function () {
      BhwPortal.toast('Could not save follow-up.', false);
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Save follow-up';
      }
    });
  });

  loadList();
})();
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
