(function () {
  'use strict';

  var base = document.body.dataset.assetBase || '';
  var api = base + '/app/api/bhw/records.php';

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function formatDate(value) {
    if (!value) return '—';
    var d = new Date(value.replace(' ', 'T'));
    if (isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function statusClass(status) {
    var s = String(status || 'pending').toLowerCase();
    if (s === 'approved' || s === 'verified') return 'bhw-records-status--approved';
    if (s === 'rejected') return 'bhw-records-status--rejected';
    return 'bhw-records-status--pending';
  }

  function downloadUrl(docId) {
    return api + '?action=download&document_id=' + encodeURIComponent(docId);
  }

  function renderDocuments(docs) {
    if (!docs || !docs.length) {
      return '<div class="bhw-records-empty">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' +
        '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>' +
        '<strong>No documents yet</strong>' +
        '<span>Upload residency proof, lab results, or referral letters for this patient.</span>' +
        '</div>';
    }

    var rows = docs.map(function (d) {
      var label = d.display_name || d.document_title || d.original_name;
      return '<tr>' +
        '<td><span class="bhw-records-doc-name">' + escapeHtml(label) + '</span></td>' +
        '<td><span class="bhw-records-status ' + statusClass(d.status) + '">' + escapeHtml(d.status || 'pending') + '</span></td>' +
        '<td>' + escapeHtml(formatDate(d.uploaded_at)) + '</td>' +
        '<td><a class="bhw-records-view-btn" href="' + downloadUrl(d.id) + '" target="_blank" rel="noopener">View file</a></td>' +
        '</tr>';
    }).join('');

    return '<div class="bhw-records-table-wrap"><table class="bhw-records-table">' +
      '<thead><tr><th>Document</th><th>Status</th><th>Uploaded</th><th>Action</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div>';
  }

  function renderPrescriptions(items) {
    if (!items || !items.length) {
      return '<div class="bhw-records-empty">' +
        '<strong>No prescriptions on file</strong>' +
        '<span>Prescriptions from providers will appear here after consultations.</span>' +
        '</div>';
    }

    var rows = items.map(function (p) {
      var detail = [p.dosage, p.frequency, p.duration].filter(Boolean).join(' · ');
      return '<tr>' +
        '<td><span class="bhw-records-doc-name">' + escapeHtml(p.medication_name) + '</span></td>' +
        '<td>' + escapeHtml(detail || '—') + '</td>' +
        '<td>' + escapeHtml(formatDate(p.created_at)) + '</td>' +
        '</tr>';
    }).join('');

    return '<div class="bhw-records-table-wrap"><table class="bhw-records-table">' +
      '<thead><tr><th>Medication</th><th>Instructions</th><th>Date</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div>';
  }

  function loadRecords(patientId, outDocs, outRx, uploadLink) {
    if (!patientId) {
      outDocs.innerHTML = '<div class="bhw-records-empty"><strong>Select a patient</strong><span>Choose a resident from your barangay to view their records.</span></div>';
      outRx.innerHTML = '';
      if (uploadLink) uploadLink.style.display = 'none';
      return;
    }

    var loadingHtml = (window.BhwPortal && BhwPortal.loader && BhwPortal.loader.inlineHtml)
      ? BhwPortal.loader.inlineHtml('Loading documents…', { className: 'bhw-records-loading' })
      : '<div class="bhw-records-loading">Loading documents…</div>';
    var loadingRxHtml = (window.BhwPortal && BhwPortal.loader && BhwPortal.loader.inlineHtml)
      ? BhwPortal.loader.inlineHtml('Loading prescriptions…', { className: 'bhw-records-loading' })
      : '<div class="bhw-records-loading">Loading prescriptions…</div>';
    outDocs.innerHTML = loadingHtml;
    outRx.innerHTML = loadingRxHtml;
    if (uploadLink) {
      uploadLink.href = 'upload.php?patient_id=' + patientId;
      uploadLink.style.display = '';
    }

    BhwPortal.get('records.php', { action: 'list', patient_id: patientId }).then(function (r) {
      if (!r.success) {
        var msg = escapeHtml(r.message || 'Could not load records.');
        outDocs.innerHTML = '<div class="bhw-records-empty"><strong>Unable to load</strong><span>' + msg + '</span></div>';
        outRx.innerHTML = '';
        return;
      }
      var rec = r.records || {};
      outDocs.innerHTML = renderDocuments(rec.documents || []);
      outRx.innerHTML = renderPrescriptions(rec.prescriptions || []);
    });
  }

  var viewRoot = document.getElementById('bhwRecordsView');
  if (viewRoot) {
    var pickerEl = document.getElementById('bhwRecordsPicker');
    var outDocs = document.getElementById('bhwRecordsDocs');
    var outRx = document.getElementById('bhwRecordsRx');
    var uploadLink = document.getElementById('bhwRecordsUploadLink');
    var preselect = parseInt(viewRoot.dataset.preselect || '0', 10);

    var picker = BhwPortal.mountPatientPicker(pickerEl, {
      label: 'Search patient',
      placeholder: 'Type name, email, or contact number…',
      preselect: preselect > 0 ? preselect : 0,
      openOnLoad: false,
      onSelect: function (id) {
        loadRecords(id, outDocs, outRx, uploadLink);
      },
      onClear: function () {
        loadRecords(0, outDocs, outRx, uploadLink);
      }
    });

    if (preselect > 0) {
      loadRecords(preselect, outDocs, outRx, uploadLink);
    } else {
      loadRecords(0, outDocs, outRx, uploadLink);
    }
  }

  var uploadRoot = document.getElementById('bhwRecordsUpload');
  if (uploadRoot) {
    var MAX_BYTES = 10 * 1024 * 1024;
    var ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
    var apiBase = document.body.dataset.assetBase || '';
    var recordsApi = apiBase + '/app/api/bhw/records.php';

    var uploadPickerEl = document.getElementById('bhwUploadPicker');
    var form = document.getElementById('bhwUploadForm');
    var fileInput = document.getElementById('bhwUploadFile');
    var fileZone = document.getElementById('bhwUploadZone');
    var fileNameEl = document.getElementById('bhwUploadFileName');
    var progressWrap = document.getElementById('bhwUploadProgressWrap');
    var progressBar = document.getElementById('bhwUploadProgressBar');
    var progressLabel = document.getElementById('bhwUploadProgressLabel');
    var hiddenPatient = document.getElementById('bhwUploadPatientId');
    var patientCard = document.getElementById('bhwUploadPatientCard');
    var patientChangeBtn = document.getElementById('bhwUploadPatientChange');
    var docType = document.getElementById('bhwUploadDocType');
    var docTitle = document.getElementById('bhwUploadDocTitle');
    var description = document.getElementById('bhwUploadDescription');
    var submitBtn = document.getElementById('bhwUploadSubmit');
    var spinner = document.getElementById('bhwUploadSpinner');
    var successEl = document.getElementById('bhwUploadSuccess');
    var pre = parseInt(uploadRoot.dataset.preselect || '0', 10);
    var selectedPatient = null;
    var selectedFile = null;
    var uploading = false;

    var errPatient = document.getElementById('bhwUploadPatientError');
    var errType = document.getElementById('bhwUploadTypeError');
    var errTitle = document.getElementById('bhwUploadTitleError');
    var errFile = document.getElementById('bhwUploadFileError');

    function dash(v) {
      return v && String(v).trim() ? String(v).trim() : '—';
    }

    function initials(p) {
      return ((p.first_name || '?').charAt(0) + (p.last_name || '').charAt(0)).toUpperCase();
    }

    function formatSex(g) {
      var s = String(g || '').toLowerCase();
      if (s === 'm' || s === 'male') return 'Male';
      if (s === 'f' || s === 'female') return 'Female';
      return dash(g);
    }

    function formatFileSize(bytes) {
      if (!bytes || bytes <= 0) return '';
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
      return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function fileExt(name) {
      var parts = String(name || '').split('.');
      return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    function isValidFile(file) {
      if (!file) return false;
      if (file.size > MAX_BYTES) return false;
      return ALLOWED_EXT.indexOf(fileExt(file.name)) !== -1;
    }

    function showError(el, show) {
      if (!el) return;
      el.hidden = !show;
    }

    function formIsValid() {
      return !!selectedPatient &&
        docType && docType.value &&
        docTitle && docTitle.value.trim() &&
        selectedFile && isValidFile(selectedFile) &&
        !uploading;
    }

    function refreshSubmitState() {
      if (submitBtn) submitBtn.disabled = !formIsValid();
    }

    function renderPatientCard(p) {
      if (!patientCard || !p) return;
      var nameEl = document.getElementById('bhwUploadPatientName');
      var metaEl = document.getElementById('bhwUploadPatientMeta');
      var avatarEl = document.getElementById('bhwUploadPatientAvatar');
      var fullName = (p.last_name || '') + ', ' + (p.first_name || '');
      var meta = [
        'ID: ' + dash(p.patient_code || p.id),
        'Age: ' + (p.age != null && p.age !== '' ? p.age : '—'),
        formatSex(p.gender),
        dash(p.contact_number)
      ].join(' · ');
      if (nameEl) nameEl.textContent = fullName.trim() || '—';
      if (metaEl) metaEl.textContent = meta;
      if (avatarEl) avatarEl.textContent = initials(p);
      patientCard.hidden = false;
    }

    function hidePatientCard() {
      selectedPatient = null;
      if (patientCard) patientCard.hidden = true;
      if (hiddenPatient) hiddenPatient.value = '';
      refreshSubmitState();
    }

    function loadPatient(id) {
      if (!id) {
        hidePatientCard();
        return;
      }
      BhwPortal.get('patients.php', { action: 'get', patient_id: id }).then(function (r) {
        if (!r.success || !r.patient) {
          hidePatientCard();
          return;
        }
        selectedPatient = r.patient;
        if (hiddenPatient) hiddenPatient.value = String(id);
        renderPatientCard(r.patient);
        showError(errPatient, false);
        refreshSubmitState();
      });
    }

    function renderFileState() {
      if (!fileNameEl) return;
      if (selectedFile && isValidFile(selectedFile)) {
        fileNameEl.textContent = 'Selected: ' + selectedFile.name + ' (' + formatFileSize(selectedFile.size) + ')';
        showError(errFile, false);
        if (fileZone) fileZone.classList.remove('is-invalid');
      } else if (selectedFile) {
        fileNameEl.textContent = '';
        showError(errFile, true);
        if (fileZone) fileZone.classList.add('is-invalid');
      } else {
        fileNameEl.textContent = '';
      }
      refreshSubmitState();
    }

    function setSelectedFile(file) {
      selectedFile = file;
      if (fileInput && file) {
        try {
          var dt = new DataTransfer();
          dt.items.add(file);
          fileInput.files = dt.files;
        } catch (e) { /* ignore */ }
      }
      renderFileState();
    }

    function updateStats(stats) {
      if (!stats) return;
      var pending = document.getElementById('bhwStatPending');
      var verified = document.getElementById('bhwStatVerified');
      var rejected = document.getElementById('bhwStatRejected');
      var today = document.getElementById('bhwStatToday');
      if (pending) pending.textContent = String(stats.pending != null ? stats.pending : 0);
      if (verified) verified.textContent = String(stats.verified != null ? stats.verified : 0);
      if (rejected) rejected.textContent = String(stats.rejected != null ? stats.rejected : 0);
      if (today) today.textContent = String(stats.today != null ? stats.today : 0);
    }

    function loadStats() {
      BhwPortal.get('records.php', { action: 'upload_stats' }).then(function (r) {
        if (r.success && r.stats) updateStats(r.stats);
      });
    }

    function postWithProgress(fd, onProgress) {
      return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', recordsApi);
        xhr.withCredentials = true;
        xhr.upload.addEventListener('progress', function (e) {
          if (e.lengthComputable && typeof onProgress === 'function') {
            onProgress(e.loaded / e.total);
          }
        });
        xhr.onload = function () {
          try {
            resolve(JSON.parse(xhr.responseText || '{}'));
          } catch (err) {
            reject(err);
          }
        };
        xhr.onerror = function () { reject(new Error('Network error')); };
        if (!fd.has('csrf_token')) {
          fd.append('csrf_token', document.body.dataset.csrf || '');
        }
        xhr.send(fd);
      });
    }

    var uploadPicker = BhwPortal.mountPatientPicker(uploadPickerEl, {
      label: 'Search patient',
      placeholder: 'Type name, email, or contact number…',
      preselect: pre > 0 ? pre : 0,
      openOnLoad: false,
      hideSelectedBar: true,
      onSelect: function (id) {
        loadPatient(id);
      },
      onClear: function () {
        hidePatientCard();
      }
    });

    if (patientChangeBtn && uploadPicker) {
      patientChangeBtn.addEventListener('click', function () {
        uploadPicker.clear();
        hidePatientCard();
        var search = uploadPickerEl && uploadPickerEl.querySelector('#bhwPickerSearch');
        if (search) search.focus();
      });
    }

    if (docType) {
      docType.addEventListener('change', function () {
        showError(errType, false);
        docType.classList.remove('is-invalid');
        refreshSubmitState();
      });
    }
    if (docTitle) {
      docTitle.addEventListener('input', function () {
        showError(errTitle, false);
        docTitle.classList.remove('is-invalid');
        refreshSubmitState();
      });
    }

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) {
          setSelectedFile(fileInput.files[0]);
        }
      });
    }

    if (fileZone && fileInput) {
      ['dragenter', 'dragover'].forEach(function (ev) {
        fileZone.addEventListener(ev, function (e) {
          e.preventDefault();
          if (!uploading) fileZone.classList.add('is-dragover');
        });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        fileZone.addEventListener(ev, function (e) {
          e.preventDefault();
          fileZone.classList.remove('is-dragover');
        });
      });
      fileZone.addEventListener('drop', function (e) {
        if (uploading) return;
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
          setSelectedFile(e.dataTransfer.files[0]);
        }
      });
    }

    function validateForm() {
      var ok = true;
      if (!selectedPatient) {
        showError(errPatient, true);
        ok = false;
      }
      if (!docType || !docType.value) {
        showError(errType, true);
        if (docType) docType.classList.add('is-invalid');
        ok = false;
      }
      if (!docTitle || !docTitle.value.trim()) {
        showError(errTitle, true);
        if (docTitle) docTitle.classList.add('is-invalid');
        ok = false;
      }
      if (!selectedFile || !isValidFile(selectedFile)) {
        showError(errFile, true);
        if (fileZone) fileZone.classList.add('is-invalid');
        ok = false;
      }
      return ok;
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (uploading) return;
        if (!validateForm()) {
          refreshSubmitState();
          BhwPortal.toast('Please complete all required fields.', false);
          return;
        }

        uploading = true;
        refreshSubmitState();
        if (submitBtn) submitBtn.disabled = true;
        if (spinner) spinner.hidden = false;
        if (progressWrap) progressWrap.hidden = false;
        if (progressLabel) {
          progressLabel.hidden = false;
          progressLabel.textContent = 'Uploading…';
        }
        if (progressBar) progressBar.style.width = '0%';

        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('patient_id', hiddenPatient.value);
        fd.append('document_type', docType.value);
        fd.append('document_title', docTitle.value.trim());
        if (description && description.value.trim()) {
          fd.append('description', description.value.trim());
        }
        fd.append('document', selectedFile);

        postWithProgress(fd, function (pct) {
          var p = Math.min(100, Math.round(pct * 100));
          if (progressBar) progressBar.style.width = p + '%';
          if (progressLabel) progressLabel.textContent = 'Uploading… ' + p + '%';
        }).then(function (r) {
          uploading = false;
          if (spinner) spinner.hidden = true;
          if (r.success) {
            if (progressBar) progressBar.style.width = '100%';
            if (r.stats) updateStats(r.stats);
            if (successEl) successEl.hidden = false;
            BhwPortal.toast(r.message || 'Document uploaded.', true);
            var redirectId = hiddenPatient.value;
            setTimeout(function () {
              window.location.href = 'index.php?patient_id=' + encodeURIComponent(redirectId);
            }, 1500);
          } else {
            if (progressWrap) progressWrap.hidden = true;
            if (progressLabel) progressLabel.hidden = true;
            refreshSubmitState();
            BhwPortal.toast(r.message || 'Upload failed.', false);
          }
        }).catch(function () {
          uploading = false;
          if (spinner) spinner.hidden = true;
          if (progressWrap) progressWrap.hidden = true;
          if (progressLabel) progressLabel.hidden = true;
          refreshSubmitState();
          BhwPortal.toast('Upload failed. Please try again.', false);
        });
      });
    }

    loadStats();
    refreshSubmitState();
  }
})();
