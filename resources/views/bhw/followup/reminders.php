<?php
$page_title = 'Follow-Up Monitoring';
$bhw_current_file = 'followup/reminders.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$bhw_subnav_items = [
    ['file' => 'track.php', 'label' => 'Appointment and Follow-up Queue'],
    ['file' => 'reminders.php', 'label' => 'Send reminders'],
];
$bhw_subnav_active = 'followup/reminders.php';
?>
<div class="bhw-followup-page">
  <header class="bhw-followup-header bhw-page-intro">
    <p class="bhw-page-intro__desc">Send email reminders to the patient’s registered Gmail for upcoming doctor follow-ups. Each successful send is audited.</p>
  </header>

  <?php require __DIR__ . '/../partials/bhw_module_subnav.php'; ?>

  <div class="bhw-card bhw-followup-card">
    <div class="table-responsive bhw-followup-table-wrap" id="bhwRemList">
      <p class="bhw-followup-empty">Loading upcoming follow-ups…</p>
    </div>
  </div>
</div>
<?php
ob_start();
?>
(function () {
  var el = document.getElementById('bhwRemList');
  var sending = {};

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function loadReminders() {
    el.innerHTML = '<p class="bhw-followup-empty">Loading upcoming follow-ups…</p>';
    BhwPortal.get('followups.php', { action: 'list', status: 'upcoming' }).then(function (r) {
      if (!r.success) {
        el.innerHTML = '<p class="bhw-followup-empty bhw-followup-empty--error">' +
          esc(r.message || 'Could not load follow-ups.') + '</p>';
        return;
      }
      var rows = r.followups || [];
      if (!rows.length) {
        el.innerHTML = '<p class="bhw-followup-empty">No upcoming follow-ups to remind.</p>';
        return;
      }
      el.innerHTML =
        '<table class="table bhw-table bhw-followup-table mb-0">' +
        '<thead><tr><th>Date</th><th>Patient</th><th>Registered email</th><th>Actions</th></tr></thead><tbody>' +
        rows.map(function (f) {
          var email = (f.patient_email || '').trim();
          var emailCell = email
            ? '<span class="bhw-fu-email">' + esc(email) + '</span>'
            : '<span class="bhw-fu-email bhw-fu-email--missing">No email on file</span>';
          var disabled = !email || sending[f.id] ? ' disabled' : '';
          return '<tr>' +
            '<td data-label="Date">' + esc(f.followup_datetime_label || f.followup_date || '—') +
              '<div class="bhw-fu-patient-sub">Ref #' + esc(f.id) + '</div></td>' +
            '<td data-label="Patient"><span class="bhw-fu-patient-name">' + esc(f.patient_name || '—') + '</span></td>' +
            '<td data-label="Registered email">' + emailCell + '</td>' +
            '<td data-label="Actions"><div class="bhw-fu-actions">' +
              '<button type="button" class="bhw-btn-teal bhw-fu-remind"' + disabled +
              ' data-id="' + f.id + '" data-email="' + esc(email) + '">Send email reminder</button>' +
            '</div></td>' +
          '</tr>';
        }).join('') + '</tbody></table>';

      el.querySelectorAll('.bhw-fu-remind').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = parseInt(btn.dataset.id, 10);
          if (!id || sending[id]) return;
          if (!btn.dataset.email) {
            BhwPortal.toast('Patient has no valid registered email on file.', false);
            return;
          }
          sending[id] = true;
          btn.disabled = true;
          btn.textContent = 'Sending…';
          BhwPortal.post('followups.php', { action: 'remind', followup_id: id }).then(function (res) {
            BhwPortal.toast(res.message, res.success);
            sending[id] = false;
            if (res.success) {
              btn.textContent = 'Sent';
              btn.disabled = true;
            } else {
              btn.textContent = 'Send email reminder';
              btn.disabled = false;
            }
          }).catch(function () {
            sending[id] = false;
            btn.textContent = 'Send email reminder';
            btn.disabled = false;
            BhwPortal.toast('Could not send reminder. Please try again.', false);
          });
        });
      });
    }).catch(function () {
      el.innerHTML = '<p class="bhw-followup-empty bhw-followup-empty--error">Could not load follow-ups.</p>';
    });
  }

  loadReminders();
})();
<?php
$bhw_inline_script = ob_get_clean();
require __DIR__ . '/../partials/layout_close.php';
?>
