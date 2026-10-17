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

  function renderPrescriptions(items, shared) {
    if (shared === false) {
      return '<div class="bhw-records-empty">' +
        '<strong>Prescription sharing is off</strong>' +
        '<span>This patient has not allowed barangay health workers to view prescription details.</span>' +
        '</div>';
    }
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
      rx: document.getElementById('bhwRecordsPanelRx')
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
          var instructions = String(c.instructions || '').trim();
          return '<article class="bhw-records-visit">' +
            '<header><strong>' + escapeHtml(formatWhen(c.consult_date, c.consult_time)) + '</strong>' +
            '<span class="bhw-records-status ' + statusClass(c.status) + '">' + escapeHtml((c.status || 'scheduled').replace(/_/g, ' ')) + '</span></header>' +
            '<p><span>Provider</span> ' + escapeHtml(dash(c.provider_name)) + '</p>' +
            (instructions ? '<p><span>Follow-up instructions</span> ' + escapeHtml(instructions) + '</p>' : '') +
            '</article>';
        }).join('') : '<div class="bhw-records-empty"><strong>No follow-up visits to show</strong><span>Visit date, status, provider, and follow-up instructions appear here when this patient has a follow-up.</span></div>';
        panels.health.innerHTML = '<div class="bhw-records-history">' +
          fieldGrid([
            ['Allergies', p.allergies],
            ['Medical conditions', p.existing_conditions],
            ['Current medications', p.current_medications],
            ['Blood type', p.blood_type]
          ]) +
          '<div class="bhw-records-history__visits">' + visitHtml + '</div></div>';
      }
      if (panels.rx) panels.rx.innerHTML = renderPrescriptions(rec.prescriptions || [], rec.prescriptions_shared);
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
})();
