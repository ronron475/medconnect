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
      return '<tr>' +
        '<td><span class="bhw-records-doc-name">' + escapeHtml(d.original_name) + '</span></td>' +
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

    outDocs.innerHTML = '<div class="bhw-records-loading">Loading documents…</div>';
    outRx.innerHTML = '<div class="bhw-records-loading">Loading prescriptions…</div>';
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
    var uploadPickerEl = document.getElementById('bhwUploadPicker');
    var form = document.getElementById('bhwUploadForm');
    var fileInput = document.getElementById('bhwUploadFile');
    var fileZone = document.getElementById('bhwUploadZone');
    var fileNameEl = document.getElementById('bhwUploadFileName');
    var hiddenPatient = document.getElementById('bhwUploadPatientId');
    var pre = parseInt(uploadRoot.dataset.preselect || '0', 10);

    var uploadPicker = BhwPortal.mountPatientPicker(uploadPickerEl, {
      label: 'Patient',
      placeholder: 'Search patient to attach this document…',
      preselect: pre > 0 ? pre : 0,
      onSelect: function (id) {
        if (hiddenPatient) hiddenPatient.value = String(id);
      },
      onClear: function () {
        if (hiddenPatient) hiddenPatient.value = '';
      }
    });

    function showFileName() {
      if (!fileNameEl || !fileInput) return;
      fileNameEl.textContent = fileInput.files && fileInput.files[0]
        ? 'Selected: ' + fileInput.files[0].name
        : '';
    }

    if (fileInput) {
      fileInput.addEventListener('change', showFileName);
    }

    if (fileZone && fileInput) {
      ['dragenter', 'dragover'].forEach(function (ev) {
        fileZone.addEventListener(ev, function (e) {
          e.preventDefault();
          fileZone.classList.add('is-dragover');
        });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        fileZone.addEventListener(ev, function (e) {
          e.preventDefault();
          fileZone.classList.remove('is-dragover');
        });
      });
      fileZone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
          fileInput.files = e.dataTransfer.files;
          showFileName();
        }
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var patientId = hiddenPatient ? hiddenPatient.value : (uploadPicker ? String(uploadPicker.getSelectedId()) : '');
        if (!patientId) {
          BhwPortal.toast('Please select a patient first.', false);
          return;
        }
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
          BhwPortal.toast('Please choose a document to upload.', false);
          return;
        }
        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('patient_id', patientId);
        fd.append('document', fileInput.files[0]);
        BhwPortal.post('records.php', fd).then(function (r) {
          BhwPortal.toast(r.message, r.success);
          if (r.success) {
            window.location.href = 'index.php?patient_id=' + encodeURIComponent(patientId);
          }
        });
      });
    }
  }
})();
