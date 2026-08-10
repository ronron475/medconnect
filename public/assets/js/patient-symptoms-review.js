/**
 * Patient dashboard — submit symptoms for AI self-care review (no booking).
 */
(function () {
  'use strict';

  var form = document.getElementById('pdashSymptomsReviewForm');
  if (!form) return;

  var alertEl = document.getElementById('pdashSymptomsReviewAlert');
  var submitBtn = document.getElementById('pdashSymptomsReviewSubmit');
  if (submitBtn && !submitBtn.dataset.defaultLabel) {
    submitBtn.dataset.defaultLabel = submitBtn.textContent.trim();
  }

  var IMAGE_MAX = 5 * 1024 * 1024;
  var VIDEO_MAX = 25 * 1024 * 1024;
  var ALLOWED = {
    'image/jpeg': true,
    'image/png': true,
    'image/webp': true,
    'video/mp4': true,
    'video/webm': true,
  };

  var evidenceInput = document.getElementById('pdashSupportingEvidence');
  var chooseBtn = document.getElementById('pdashBtnChooseEvidence');
  var removeBtn = document.getElementById('pdashBtnRemoveEvidence');
  var filenameEl = document.getElementById('pdashEvidenceFilename');
  var previewEl = document.getElementById('pdashEvidencePreview');
  var complaintEl = document.getElementById('pdashSymptomsComplaint');
  var evidenceSection = document.getElementById('pdashCareEvidenceSection');
  var previewUrl = null;

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

  function hasValidComplaint() {
    return !!(complaintEl && String(complaintEl.value || '').trim());
  }

  function showAlert(type, message) {
    if (!alertEl) return;
    alertEl.hidden = false;
    alertEl.className = 'patient-triage-alert patient-triage-alert--' + type + ' is-visible';
    alertEl.textContent = message;
  }

  function clearPreview() {
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = null;
    }
    if (previewEl) {
      previewEl.innerHTML = '';
      previewEl.hidden = true;
    }
  }

  function resetEvidence() {
    if (evidenceInput) {
      evidenceInput.value = '';
    }
    clearPreview();
    if (filenameEl) {
      filenameEl.textContent = '';
      filenameEl.hidden = true;
    }
    if (removeBtn) {
      removeBtn.hidden = true;
      removeBtn.disabled = !hasValidComplaint();
    }
  }

  function syncEvidenceSection() {
    if (!evidenceSection) return;
    var hasComplaint = hasValidComplaint();

    evidenceSection.hidden = !hasComplaint;
    evidenceSection.classList.toggle('pdash-care-evidence--collapsed', !hasComplaint);
    evidenceSection.setAttribute('aria-hidden', hasComplaint ? 'false' : 'true');

    if (hasComplaint) {
      evidenceSection.removeAttribute('inert');
    } else {
      evidenceSection.setAttribute('inert', '');
    }

    if (evidenceInput) {
      evidenceInput.disabled = !hasComplaint;
      if (hasComplaint) {
        evidenceInput.removeAttribute('tabindex');
      } else {
        evidenceInput.setAttribute('tabindex', '-1');
      }
    }

    if (chooseBtn) {
      chooseBtn.disabled = !hasComplaint;
    }

    if (removeBtn) {
      removeBtn.disabled = !hasComplaint;
    }

    if (!hasComplaint) {
      resetEvidence();
    }
  }

  function validateEvidenceFile(file) {
    if (!file) return null;
    if (!ALLOWED[file.type]) {
      return 'Please choose a JPG, PNG, WEBP photo or MP4/WebM video.';
    }
    var isVideo = file.type.indexOf('video/') === 0;
    var maxSize = isVideo ? VIDEO_MAX : IMAGE_MAX;
    if (file.size > maxSize) {
      return isVideo ? 'Video must be 25 MB or smaller.' : 'Photo must be 5 MB or smaller.';
    }
    return null;
  }

  function canUseEvidenceUpload() {
    return hasValidComplaint() && evidenceInput && !evidenceInput.disabled;
  }

  if (chooseBtn && evidenceInput) {
    chooseBtn.addEventListener('click', function (e) {
      if (!canUseEvidenceUpload()) {
        e.preventDefault();
        e.stopPropagation();
        resetEvidence();
        return;
      }
      evidenceInput.click();
    });
  }

  if (removeBtn) {
    removeBtn.addEventListener('click', function () {
      if (!hasValidComplaint()) {
        resetEvidence();
        return;
      }
      resetEvidence();
    });
  }

  if (evidenceInput) {
    evidenceInput.addEventListener('change', function () {
      if (!canUseEvidenceUpload()) {
        resetEvidence();
        return;
      }

      clearPreview();
      var file = evidenceInput.files && evidenceInput.files[0];
      if (!file) {
        resetEvidence();
        return;
      }

      var err = validateEvidenceFile(file);
      if (err) {
        resetEvidence();
        showAlert('error', err);
        return;
      }

      if (filenameEl) {
        filenameEl.textContent = file.name;
        filenameEl.hidden = false;
      }
      if (removeBtn) {
        removeBtn.hidden = false;
        removeBtn.disabled = false;
      }

      if (previewEl) {
        previewUrl = URL.createObjectURL(file);
        if (file.type.indexOf('video/') === 0) {
          previewEl.innerHTML = '<video src="' + previewUrl + '" controls playsinline muted></video>';
        } else {
          previewEl.innerHTML = '<img src="' + previewUrl + '" alt="Supporting evidence preview">';
        }
        previewEl.hidden = false;
      }
    });

    evidenceInput.addEventListener('click', function (e) {
      if (!canUseEvidenceUpload()) {
        e.preventDefault();
        e.stopPropagation();
        resetEvidence();
      }
    });
  }

  if (complaintEl) {
    complaintEl.addEventListener('input', syncEvidenceSection);
    complaintEl.addEventListener('change', syncEvidenceSection);
    complaintEl.addEventListener('paste', function () {
      window.setTimeout(syncEvidenceSection, 0);
    });
    syncEvidenceSection();
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var complaint = (form.querySelector('#pdashSymptomsComplaint')?.value || '').trim();
    if (!complaint) {
      syncEvidenceSection();
      var locked = document.getElementById('pdashSymptomsComplaint')?.hasAttribute('readonly');
      showAlert('error', locked
        ? 'Your chief complaint from registration is missing. Please contact the health office.'
        : 'Please describe your symptoms or concern.');
      return;
    }

    var evidenceFile = null;
    if (canUseEvidenceUpload() && evidenceInput && evidenceInput.files) {
      evidenceFile = evidenceInput.files[0] || null;
    }

    if (evidenceFile) {
      var evidenceErr = validateEvidenceFile(evidenceFile);
      if (evidenceErr) {
        showAlert('error', evidenceErr);
        return;
      }
    }

    var fd = new FormData(form);
    fd.set('chief_complaint', complaint);
    fd.set('csrf_token', csrf());
    if (!evidenceFile) {
      fd.delete('supporting_evidence');
    }

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
        var urgentComplaint = (form.querySelector('#pdashSymptomsComplaint')?.value || '').trim();
        var triageId = payload.triage_id ? payload.triage_id : 0;
        showAlert('error', urgMsg);
        if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showUrgent === 'function') {
          window.mcPatientUrgencyModal.showUrgent(urgMsg, payload.book_url || '', {
            complaint: urgentComplaint,
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
        submitBtn.textContent = submitBtn.dataset.defaultLabel || 'Submit chief complaint';
      }
    }
  });
})();
