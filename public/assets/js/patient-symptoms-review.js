/**
 * Patient dashboard — submit symptoms for AI self-care review (no booking).
 */
(function () {
  'use strict';

  var form = document.getElementById('pdashSymptomsReviewForm');
  if (!form) return;

  var alertEl = document.getElementById('pdashSymptomsReviewAlert');
  var submitBtn = document.getElementById('pdashSymptomsReviewSubmit');
  var registrationNlpEl = document.getElementById('pdashSymptomsRegistrationNlp');
  var registrationNlp = registrationNlpEl ? registrationNlpEl.value : '';

  function base() {
    return (typeof window.APP_BASE !== 'undefined' && window.APP_BASE)
      ? String(window.APP_BASE).replace(/\/$/, '')
      : '';
  }

  function csrf() {
    if (document.body && document.body.dataset && document.body.dataset.csrf) {
      return document.body.dataset.csrf;
    }
    var root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset && root.dataset.csrf) {
      return root.dataset.csrf;
    }
    return '';
  }

  function showAlert(type, message) {
    if (!alertEl) return;
    alertEl.hidden = false;
    alertEl.className = 'patient-triage-alert patient-triage-alert--' + type + ' is-visible';
    alertEl.textContent = message;
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var complaint = (form.querySelector('#pdashSymptomsComplaint')?.value || '').trim();
    if (!complaint) {
      showAlert('error', 'Please describe your symptoms or concern.');
      return;
    }

    var fd = new FormData();
    fd.set('chief_complaint', complaint);
    fd.set('csrf_token', csrf());
    try {
      var pendingNlp = registrationNlp || sessionStorage.getItem('medconnect_pending_nlp_result');
      if (pendingNlp) fd.set('registration_nlp_json', pendingNlp);
    } catch (_) { /* ignore */ }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting…';
    }

    try {
      var res = await fetch(base() + '/app/api/patient/submit_symptoms_review.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      });
      var data = await res.json().catch(function () { return null; });
      if (!data || !data.success) {
        showAlert('error', (data && data.message) || 'Could not submit. Please try again.');
        return;
      }

      var payload = data.data || data;
      if (payload.emergency) {
        var emMsg = data.message || 'Emergency symptoms detected. Seek emergency care.';
        showAlert('error', emMsg);
        if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showEmergency === 'function') {
          window.mcPatientUrgencyModal.showEmergency(emMsg);
        }
        return;
      }
      if (payload.urgent) {
        var urgMsg = data.message || 'Please book an urgent consultation.';
        var complaint = (form.querySelector('#pdashSymptomsComplaint')?.value || '').trim();
        var triageId = payload.triage_id ? payload.triage_id : 0;
        showAlert('error', urgMsg);
        if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showUrgent === 'function') {
          window.mcPatientUrgencyModal.showUrgent(urgMsg, payload.book_url || '', {
            complaint: complaint,
            triageId: triageId,
          });
        } else if (payload.book_url) {
          setTimeout(function () { window.location.href = payload.book_url; }, 2200);
        }
        return;
      }

      showAlert('success', data.message || 'Submitted for provider review.');
      setTimeout(function () { window.location.reload(); }, 1400);
    } catch (_) {
      showAlert('error', 'Network error. Please try again.');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit for doctor review';
      }
    }
  });
})();
