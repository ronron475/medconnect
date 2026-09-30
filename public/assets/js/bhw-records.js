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
    if (s === 'approved' || s === 'verified' || s === 'completed') return 'bhw-records-status--approved';
    if (s === 'rejected' || s === 'cancelled' || s === 'canceled') return 'bhw-records-status--rejected';
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
        '<strong>No documents on file</strong>' +
        '<span>Uploaded health documents for this patient will appear here.</span>' +
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

  var viewRoot = document.getElementById('bhwRecordsView');
  if (viewRoot && window.BhwPortal) {
    var searchInput = document.getElementById('bhwRecordsSearch');
    var resultsEl = document.getElementById('bhwRecordsResults');
    var metaEl = document.getElementById('bhwRecordsResultMeta');
    var modal = document.getElementById('bhwRecordsModal');
    var modalTitle = document.getElementById('bhwRecordsModalTitle');
    var modalMeta = document.getElementById('bhwRecordsModalMeta');
    var modalAvatar = document.getElementById('bhwRecordsModalAvatar');
    var panels = {
      profile: document.getElementById('bhwRecordsPanelProfile'),
      health: document.getElementById('bhwRecordsPanelHealth'),
      rx: document.getElementById('bhwRecordsPanelRx'),
      docs: document.getElementById('bhwRecordsPanelDocs')
    };
    var preselect = parseInt(viewRoot.dataset.preselect || '0', 10);
    var searchTimer = null;
    var listFp = '';
    var recordFp = '';
    var openPatientId = 0;
    var lastFocus = null;
    var REFRESH_MS = 15000;

    function dash(value) {
      var text = value == null ? '' : String(value).trim();
      return text ? text : '—';
    }

    function formatSex(value) {
      var s = String(value || '').toLowerCase();
      if (s === 'm' || s === 'male') return 'Male';
      if (s === 'f' || s === 'female') return 'Female';
      return dash(value);
    }

    function formatWhen(dateValue, timeValue) {
      var dateText = formatDate(dateValue);
      if (!timeValue) return dateText;
      var d = new Date(String(dateValue).slice(0, 10) + 'T' + timeValue);
      if (isNaN(d.getTime())) return dateText;
      return dateText + ' • ' + d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    }

    function patientName(p) {
      return [p.first_name, p.middle_name, p.last_name, p.suffix].filter(function (part) {
        return part && String(part).trim();
      }).join(' ');
    }

    function initials(p) {
      var a = (p.first_name || '?').charAt(0);
      var b = (p.last_name || '').charAt(0);
      return (a + b).toUpperCase();
    }

    function addressLine(p) {
      if (p.full_address && String(p.full_address).trim()) return String(p.full_address).trim();
      return [p.purok, p.address, p.barangay, p.city_municipality, p.province].filter(function (part) {
        return part && String(part).trim();
      }).join(', ');
    }

    function stableFp(value) {
      try { return JSON.stringify(value || null); } catch (e) { return ''; }
    }

    function fieldGrid(rows) {
      return '<dl class="bhw-records-fields">' + rows.map(function (row) {
        return '<div><dt>' + escapeHtml(row[0]) + '</dt><dd>' + escapeHtml(dash(row[1])) + '</dd></div>';
      }).join('') + '</dl>';
    }

    function setTab(name) {
      var tabRoot = modal || viewRoot;
      tabRoot.querySelectorAll('[data-records-tab]').forEach(function (btn) {
        var on = btn.getAttribute('data-records-tab') === name;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
        btn.tabIndex = on ? 0 : -1;
      });
      Object.keys(panels).forEach(function (key) {
        if (!panels[key]) return;
        var on = key === name;
        panels[key].hidden = !on;
        panels[key].classList.toggle('is-active', on);
      });
      var body = tabRoot.querySelector('.bhw-records-modal__body');
      if (body) body.scrollTop = 0;
    }

    var WORKFLOW_LABELS = {
      registered: 'Registered',
      awaiting_complaint: 'Awaiting complaint',
      ai_processing: 'AI processing',
      emergency: 'Emergency',
      urgent: 'Urgent',
      non_urgent: 'Non-urgent',
      appointment_scheduled: 'Appointment scheduled',
      referral_generated: 'Referral generated',
      consultation_completed: 'Consultation done',
      follow_up_monitoring: 'Follow-up'
    };

    function pill(text, kind) {
      return '<span class="bhw-records-pill bhw-records-pill--' + kind + '">' + escapeHtml(text) + '</span>';
    }

    function riskPill(level) {
      var text = String(level || '').trim();
      if (!text) return pill('None', 'none');
      var key = text.toLowerCase();
      var kind = 'risk';
      if (key.indexOf('emergency') >= 0 || (key.indexOf('urgent') >= 0 && key.indexOf('non-urgent') < 0 && key.indexOf('non urgent') < 0)) {
        kind = 'alert';
      }
      return pill(text, kind);
    }

    function workflowPill(status) {
      var key = String(status || 'registered').toLowerCase();
      var label = WORKFLOW_LABELS[key] || key.replace(/_/g, ' ');
      var kind = 'none';
      if (key === 'consultation_completed' || key === 'follow_up_monitoring') kind = 'workflow';
      else if (key === 'emergency' || key === 'urgent') kind = 'alert';
      else if (key === 'appointment_scheduled' || key === 'referral_generated' || key === 'non_urgent') kind = 'workflow';
      return pill(label, kind);
    }

    function accountPill(active) {
      var on = active === true || active === 1 || active === '1';
      return pill(on ? 'Active' : 'Inactive', on ? 'account' : 'alert');
    }

    function renderPatientList(patients) {
      if (!resultsEl) return;
      if (!patients.length) {
        resultsEl.innerHTML = '<div class="bhw-records-empty"><strong>No matching patients</strong><span>Try another name, email, or contact number in your barangay.</span></div>';
        if (metaEl) metaEl.textContent = '0 patients';
        return;
      }
      if (metaEl) metaEl.textContent = patients.length + (patients.length === 1 ? ' patient' : ' patients') + ' in your barangay';
      var rows = patients.map(function (p) {
        var name = patientName(p) || 'Patient';
        var barangay = p.barangay ? ('Brgy. ' + p.barangay) : (p.email || '—');
        return '<tr class="bhw-records-dir__row" data-patient-id="' + escapeHtml(p.id) + '" tabindex="0" role="button">' +
          '<td><div class="bhw-records-dir__patient">' +
            '<span class="bhw-records-dir__avatar" aria-hidden="true">' + escapeHtml(initials(p)) + '</span>' +
            '<span><strong>' + escapeHtml(name) + '</strong><span>' + escapeHtml(barangay) + '</span></span>' +
          '</div></td>' +
          '<td>' + escapeHtml(dash(p.age)) + '</td>' +
          '<td>' + escapeHtml(formatSex(p.gender)) + '</td>' +
          '<td>' + escapeHtml(dash(p.contact_number)) + '</td>' +
          '<td>' + escapeHtml(dash(p.barangay)) + '</td>' +
          '<td>' + riskPill(p.risk_level) + '</td>' +
          '<td>' + workflowPill(p.workflow_status) + '</td>' +
          '<td>' + accountPill(p.is_active) + '</td>' +
          '<td>' + escapeHtml(formatDate(p.created_at)) + '</td>' +
        '</tr>';
      }).join('');
      resultsEl.innerHTML = '<div class="bhw-records-dir-wrap"><table class="bhw-records-dir">' +
        '<thead><tr>' +
          '<th>Patient</th><th>Age</th><th>Gender</th><th>Contact</th><th>Brgy.</th>' +
          '<th>Risk</th><th>Workflow</th><th>Account</th><th>Registered</th>' +
        '</tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function loadPatients() {
      var q = searchInput ? searchInput.value.trim() : '';
      BhwPortal.get('patients.php', { action: 'list', q: q }).then(function (r) {
        if (!r.success) {
          if (resultsEl) {
            resultsEl.innerHTML = '<div class="bhw-records-empty"><strong>Unable to load patients</strong><span>' + escapeHtml(r.message || 'Please try again.') + '</span></div>';
          }
          return;
        }
        var patients = r.patients || [];
        var fp = stableFp(patients);
        if (fp === listFp) return;
        listFp = fp;
        renderPatientList(patients);
      }).catch(function () {});
    }

    function renderRecord(payload) {
      var p = payload.patient || {};
      var rec = payload.records || {};
      var name = patientName(p) || 'Patient';
      if (modalTitle) modalTitle.textContent = name;
      if (modalAvatar) modalAvatar.textContent = initials(p);
      if (modalMeta) {
        modalMeta.textContent = '';
        modalMeta.hidden = true;
      }
      if (panels.profile) {
        panels.profile.innerHTML = fieldGrid([
          ['Name', name],
          ['Age', p.age],
          ['Sex', formatSex(p.gender)],
          ['Phone number', p.contact_number],
          ['Email', p.email],
          ['Address', addressLine(p)]
        ]);
      }
      if (panels.health) {
        var visits = rec.consultations || [];
        var visitHtml = visits.length ? visits.map(function (c) {
          var assessment = c.diagnosis || '';
          var note = c.recommendation || '';
          var triage = [c.urgency_label, c.triage_classification].filter(Boolean).join(' · ');
          return '<article class="bhw-records-visit">' +
            '<header><strong>' + escapeHtml(formatWhen(c.consult_date, c.consult_time)) + '</strong>' +
            '<span class="bhw-records-status ' + statusClass(c.status) + '">' + escapeHtml((c.status || 'scheduled').replace(/_/g, ' ')) + '</span></header>' +
            '<p><span>Provider</span> ' + escapeHtml(dash(c.provider_name)) + '</p>' +
            '<p><span>Chief complaint</span> ' + escapeHtml(dash(c.chief_complaint)) + '</p>' +
            '<p><span>Final assessment</span> ' + escapeHtml(dash(assessment)) + '</p>' +
            (note ? '<p><span>Recommendation</span> ' + escapeHtml(note) + '</p>' : '') +
            (triage ? '<p><span>Triage</span> ' + escapeHtml(triage) + '</p>' : '') +
            '</article>';
        }).join('') : '<div class="bhw-records-empty"><strong>No consultations yet</strong><span>Appointments and completed visits for this patient will appear here.</span></div>';
        panels.health.innerHTML = '<div class="bhw-records-history">' +
          fieldGrid([
            ['Allergies', p.allergies],
            ['Medical conditions', p.existing_conditions],
            ['Current medications', p.current_medications],
            ['Blood type', p.blood_type]
          ]) +
          '<div class="bhw-records-history__visits">' + visitHtml + '</div></div>';
      }
      if (panels.rx) panels.rx.innerHTML = renderPrescriptions(rec.prescriptions || []);
      if (panels.docs) panels.docs.innerHTML = renderDocuments(rec.documents || []);
    }

    function loadRecord(patientId, refresh) {
      if (!patientId) return;
      BhwPortal.get('records.php', {
        action: 'list',
        patient_id: patientId,
        refresh: refresh ? '1' : '0'
      }).then(function (r) {
        if (!r.success) {
          if (panels.profile) {
            panels.profile.innerHTML = '<div class="bhw-records-empty"><strong>Unable to open this record</strong><span>' + escapeHtml(r.message || 'This patient is outside your barangay.') + '</span></div>';
          }
          return;
        }
        var fp = stableFp({ patient: r.patient, records: r.records });
        if (fp === recordFp) return;
        recordFp = fp;
        renderRecord(r);
        if (!refresh && window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
      }).catch(function () {});
    }

    function openRecord(patientId, trigger) {
      openPatientId = patientId;
      recordFp = '';
      lastFocus = trigger || document.activeElement;
      if (modal) modal.hidden = false;
      document.body.classList.add('bhw-records-modal-open');
      setTab('profile');
      Object.keys(panels).forEach(function (key) {
        if (panels[key]) panels[key].innerHTML = '<div class="bhw-records-empty"><strong>Loading record…</strong></div>';
      });
      var closeBtn = document.getElementById('bhwRecordsModalClose');
      if (closeBtn) closeBtn.focus();
      loadRecord(patientId, false);
    }

    function closeRecord() {
      if (modal) modal.hidden = true;
      document.body.classList.remove('bhw-records-modal-open');
      openPatientId = 0;
      recordFp = '';
      if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
          listFp = '';
          loadPatients();
        }, 300);
      });
    }

    if (resultsEl) {
      function openFromRow(row) {
        if (!row) return;
        openRecord(parseInt(row.getAttribute('data-patient-id'), 10) || 0, row);
      }
      resultsEl.addEventListener('click', function (e) {
        openFromRow(e.target.closest('[data-patient-id]'));
      });
      resultsEl.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var row = e.target.closest('[data-patient-id]');
        if (!row) return;
        e.preventDefault();
        openFromRow(row);
      });
    }

    viewRoot.querySelectorAll('[data-records-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setTab(btn.getAttribute('data-records-tab'));
      });
    });

    viewRoot.querySelectorAll('[data-records-close]').forEach(function (el) {
      el.addEventListener('click', closeRecord);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) closeRecord();
    });

    if (modal && modal.parentNode !== document.body) {
      document.body.appendChild(modal);
    }

    loadPatients();
    if (preselect > 0) openRecord(preselect, null);

    setInterval(function () {
      if (document.hidden) return;
      if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
      loadPatients();
      if (openPatientId) loadRecord(openPatientId, true);
    }, REFRESH_MS);

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) return;
      listFp = '';
      recordFp = '';
      loadPatients();
      if (openPatientId) loadRecord(openPatientId, true);
    });

    document.addEventListener('medconnect:live-sync', function (ev) {
      var changed = (ev.detail && ev.detail.changed) || [];
      var relevant = ['triage', 'queue', 'appointments', 'consultations', 'followups', 'notifications'];
      if (!changed.some(function (key) { return relevant.indexOf(key) !== -1; })) return;
      listFp = '';
      recordFp = '';
      loadPatients();
      if (openPatientId) loadRecord(openPatientId, true);
    });
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
