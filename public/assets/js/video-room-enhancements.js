/**
 * medConnect — Video room UI enhancements (panels, chat, waiting, post-call).
 * WebRTC remains in webrtc-peer-call.js + video_room.php session script.
 */
(function (global) {
  'use strict';

  const META = global.__mcVideoRoomMeta || {};
  const API = String(META.apiBase || global.APP_BASE || '').replace(/\/$/, '');
  const TOKEN = META.roomToken || '';
  const CONSULTATION_ID = META.consultationId || 0;
  const PATIENT_ID = META.patientId || 0;
  const IS_PATIENT = !!META.isPatient;
  const CSRF = META.csrf || '';

  let contextData = null;
  let chatPollTimer = null;
  let soapSaveTimer = null;
  let callEnded = false;
  /** Several end paths can call showPostCall(); the modal must only open once. */
  let postCallShown = false;

  function hidePostCall() {
    const modal = q('mcVcPostCallModal');
    if (modal) modal.hidden = true;
    document.body.classList.remove('mc-vc-call-ended', 'is-ended-consultation');
    document.body.classList.add('is-active-consultation');
    callEnded = false;
  }

  function markCallEnded() {
    callEnded = true;
    window.__mcCallEnded = true;
  }

  function resetCallUi() {
    postCallShown = false;
    hidePostCall();
    const endModal = q('endCallModal');
    if (endModal) endModal.classList.remove('show');
    setPanelOpen(false);
  }

  function q(id) {
    return document.getElementById(id);
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fetchContext() {
    if (!TOKEN) return Promise.resolve(null);
    return fetch(API + '/app/api/consultations/session_context.php?token=' + encodeURIComponent(TOKEN), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-MC-No-Loader': '1' },
    })
      .then((r) => r.json())
      .then((data) => (data && data.success ? data : null))
      .catch(() => null);
  }

  function fallbackContext() {
    const doctor = META.providerName
      ? (/^dr\.?\s/i.test(META.providerName) ? META.providerName : ('Dr. ' + META.providerName))
      : 'Your healthcare provider';
    return {
      consultation_id: CONSULTATION_ID,
      patient_panel: {
        doctor_name: doctor,
        specialization: META.specialty || 'General Medicine',
        appointment_label: META.appointmentLabel || '',
        chief_complaint: META.chiefComplaint || '',
        triage_level: 'Not assessed',
        triage_bucket: 'unknown',
        consultation_id: CONSULTATION_ID,
      },
      provider_panel: {
        patient_name: META.patientName || 'Patient',
        patient_number: META.patientNumber || '',
        age: META.patientAge || '',
        sex: META.patientSex || '—',
        chief_complaint: META.chiefComplaint || '',
        ai_classification: '',
        final_classification: '',
        appointment_label: META.appointmentLabel || '',
        consultation_id: CONSULTATION_ID,
        allergies: [],
        conditions: [],
        medications: [],
        blood_type: '—',
      },
    };
  }

  function setInfoStatus(text, showRetry) {
    const status = q('mcVcInfoStatus');
    const retry = q('mcVcInfoRetry');
    if (status) {
      status.hidden = !text;
      status.textContent = text || '';
    }
    if (retry) retry.hidden = !showRetry;
  }

  function renderWaiting(ctx) {
    const card = q('mcVcWaitingCard');
    const title = q('mcVcWaitingTitle');
    const meta = q('mcVcWaitingMeta');
    const status = q('mcVcWaitingStatus');
    if (!card || !ctx || !ctx.waiting) return;

    const w = ctx.waiting;
    if (title) {
      if (w.title) {
        title.textContent = w.title;
      } else if (IS_PATIENT) {
        title.textContent = 'Waiting for ' + (w.doctor_name || 'your healthcare provider');
      } else {
        title.textContent = 'Waiting for ' + (w.patient_name || 'your patient');
      }
    }
    if (meta) {
      const peerLabel = IS_PATIENT ? 'Doctor status' : 'Patient status';
      const peerValue = IS_PATIENT ? (w.doctor_status || '—') : (w.patient_status || 'Waiting to join');
      meta.innerHTML =
        '<div><dt>Appointment</dt><dd>' + escapeHtml(w.appointment_label || '—') + '</dd></div>' +
        '<div><dt>Estimated wait</dt><dd>' + escapeHtml(w.estimated_wait || '—') + '</dd></div>' +
        '<div><dt>' + escapeHtml(peerLabel) + '</dt><dd>' + escapeHtml(peerValue) + '</dd></div>' +
        (w.queue_position ? '<div><dt>Queue</dt><dd>#' + escapeHtml(w.queue_position) + '</dd></div>' : '') +
        '<div><dt>Connection</dt><dd>' + escapeHtml(w.connection_status || 'Secure') + '</dd></div>';
    }
    if (status) {
      status.textContent = w.subtitle || (IS_PATIENT
        ? 'Your visit will begin automatically when your doctor joins.'
        : 'The visit will begin automatically when your patient joins the call.');
    }
  }

  function renderInfo(ctx) {
    const pane = q('mcVcInfoPane');
    if (!pane) return;
    const data = ctx || fallbackContext();

    if (IS_PATIENT) {
      const p = data.patient_panel || fallbackContext().patient_panel;
      pane.innerHTML =
        '<div class="mc-vc-info-card">' +
        '<h3 class="mc-vc-info-card__title">' + escapeHtml(p.doctor_name || 'Your healthcare provider') + '</h3>' +
        '<p class="mc-vc-info-card__sub">' + escapeHtml(p.specialization || 'General Medicine') + '</p>' +
        '<dl class="mc-vc-info-dl">' +
        '<div><dt>Consultation</dt><dd>#' + escapeHtml(p.consultation_id || CONSULTATION_ID || '—') + '</dd></div>' +
        '<div><dt>Appointment</dt><dd>' + escapeHtml(p.appointment_label || '—') + '</dd></div>' +
        '<div><dt>Chief complaint</dt><dd>' + escapeHtml(p.chief_complaint || '—') + '</dd></div>' +
        '<div><dt>AI triage</dt><dd><span class="mc-vc-triage mc-vc-triage--' + escapeHtml(p.triage_bucket || 'unknown') + '">' + escapeHtml(p.triage_level || 'Not assessed') + '</span></dd></div>' +
        '</dl></div>';
      return;
    }

    const p = data.provider_panel || fallbackContext().provider_panel;
    const list = (arr) => (Array.isArray(arr) && arr.length ? arr.map(escapeHtml).join(', ') : 'None recorded');
    pane.innerHTML =
      '<div class="mc-vc-info-card">' +
      '<h3 class="mc-vc-info-card__title">' + escapeHtml(p.patient_name || 'Patient') + '</h3>' +
      '<p class="mc-vc-info-card__sub">' + escapeHtml(p.age ? p.age + ' yrs' : '—') + ' · ' + escapeHtml(p.sex || '—') + (p.blood_type ? ' · Blood type: ' + escapeHtml(p.blood_type) : '') + '</p>' +
      '<dl class="mc-vc-info-dl">' +
      '<div><dt>Patient ID</dt><dd>' + escapeHtml(p.patient_number || '—') + '</dd></div>' +
      '<div><dt>Consultation</dt><dd>#' + escapeHtml(p.consultation_id || CONSULTATION_ID || '—') + '</dd></div>' +
      '<div><dt>Appointment</dt><dd>' + escapeHtml(p.appointment_label || '—') + '</dd></div>' +
      '<div><dt>Chief complaint</dt><dd>' + escapeHtml(p.chief_complaint || '—') + '</dd></div>' +
      '<div><dt>AI classification</dt><dd>' + escapeHtml(p.ai_classification || '—') + (p.confidence ? ' <span class="mc-vc-muted">(' + escapeHtml(p.confidence) + ')</span>' : '') + '</dd></div>' +
      '<div><dt>Final classification</dt><dd>' + escapeHtml(p.final_classification || p.ai_classification || '—') + '</dd></div>' +
      '<div><dt>Allergies</dt><dd>' + list(p.allergies) + '</dd></div>' +
      '<div><dt>Conditions</dt><dd>' + list(p.conditions) + '</dd></div>' +
      '<div><dt>Medications</dt><dd>' + list(p.medications) + '</dd></div>' +
      '</dl></div>';
  }

  function showWaitingCard(visible) {
    const card = q('mcVcWaitingCard');
    const retry = q('mcVcWaitingRetry');
    // After media is granted, the compact in-call overlay handles waiting state.
    if (visible && document.body.classList.contains('media-ready')) {
      visible = false;
    }
    if (card) card.hidden = !visible;
    if (retry && visible) retry.hidden = true;
  }

  function setWaitingRetryVisible(show) {
    const retry = q('mcVcWaitingRetry');
    if (retry) retry.hidden = !show;
  }

  function watchCallStatusForWaiting() {
    const statusEl = q('callStatus');
    if (!statusEl) return;
    const observer = new MutationObserver(() => {
      const t = String(statusEl.textContent || '').toLowerCase();
      const waiting = t.indexOf('waiting') >= 0 || t.indexOf('connecting') >= 0;
      const connected = t.indexOf('connected') >= 0 && t.indexOf('reconnecting') < 0;
      showWaitingCard(waiting && !connected);
      if (connected) showWaitingCard(false);
    });
    observer.observe(statusEl, { childList: true, characterData: true, subtree: true });
  }

  function mapConnectionLabel(level) {
    if (level === 'excellent') return '● Excellent Connection';
    if (level === 'good') return '● Good Connection';
    if (level === 'fair') return '◌ Fair Connection';
    if (level === 'poor') return '◌ Poor Connection';
    if (level === 'reconnecting') return '◌ Reconnecting…';
    return '◌ Connecting…';
  }

  function enhanceNetworkMonitor() {
    const netEl = q('mediaStatusConn');
    if (!netEl || netEl.dataset.mcNetEnhanced) return;
    netEl.dataset.mcNetEnhanced = '1';
    setInterval(() => {
      const level = netEl.dataset.level || netEl.dataset.state || '';
      if (level === 'good') netEl.textContent = mapConnectionLabel('good');
      else if (level === 'fair') netEl.textContent = mapConnectionLabel('fair');
      else if (level === 'poor') netEl.textContent = mapConnectionLabel('poor');
      else if (level === 'connected' || /good/i.test(netEl.textContent)) {
        netEl.textContent = mapConnectionLabel('excellent');
      }
    }, 5000);
  }

  function chatInitials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  function renderChat(messages) {
    const log = q('mcVcChatLog');
    if (!log) return;
    if (!messages || !messages.length) {
      log.innerHTML = '<p class="mc-vc-chat-empty">No messages yet. Send a secure message during your visit.</p>';
      return;
    }
    log.innerHTML = messages.map((m) => {
      const mine = String(m.sender_role || '') === (IS_PATIENT ? 'patient' : 'provider');
      const name = m.sender_name || 'Participant';
      const time = m.time_label || '';
      const body = escapeHtml(m.message || m.body || '');
      const roleClass = String(m.sender_role || '') === 'patient' ? ' is-from-patient' : ' is-from-provider';
      if (mine) {
        return (
          '<div class="mc-vc-chat-msg is-mine' + roleClass + '">' +
            '<div class="mc-vc-chat-msg__head">' +
              (time ? '<span class="mc-vc-chat-msg__time">' + escapeHtml(time) + '</span>' : '') +
            '</div>' +
            '<div class="mc-vc-chat-msg__body">' + body + '</div>' +
          '</div>'
        );
      }
      return (
        '<div class="mc-vc-chat-msg is-theirs' + roleClass + '">' +
          '<div class="mc-vc-chat-msg__head">' +
            '<span class="mc-vc-chat-msg__avatar" aria-hidden="true">' + escapeHtml(chatInitials(name)) + '</span>' +
            '<span class="mc-vc-chat-msg__name">' + escapeHtml(name) + '</span>' +
            (time ? '<span class="mc-vc-chat-msg__time">' + escapeHtml(time) + '</span>' : '') +
          '</div>' +
          '<div class="mc-vc-chat-msg__body">' + body + '</div>' +
        '</div>'
      );
    }).join('');
    log.scrollTop = log.scrollHeight;
  }

  function loadChat() {
    if (!CONSULTATION_ID) return Promise.resolve();
    return fetch(API + '/app/api/messages/list.php?consultation_id=' + CONSULTATION_ID + '&_=' + Date.now(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-MC-No-Loader': '1' },
    })
      .then((r) => r.json())
      .then((data) => {
        const msgs = (data && data.messages) || (data && data.data && data.data.messages) || [];
        const filtered = msgs.filter((m) => String(m.message_kind || 'chat') === 'chat');
        renderChat(filtered.map((m) => ({
          sender_role: m.sender_role,
          sender_name: m.sender_name,
          message: m.message,
          time_label: m.created_at ? new Date(m.created_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '',
        })));
      })
      .catch(() => {});
  }

  function sendChat(message) {
    const fd = new FormData();
    fd.set('consultation_id', String(CONSULTATION_ID));
    fd.set('message', message);
    fd.set('message_kind', 'chat');
    fd.set('csrf_token', CSRF);
    return fetch(API + '/app/api/messages/send.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-MC-No-Loader': '1' },
    }).then((r) => r.json());
  }

  function startChatPoll() {
    if (chatPollTimer) return;
    loadChat();
    chatPollTimer = setInterval(loadChat, 8000);
  }

  function stopChatPoll() {
    if (chatPollTimer) {
      clearInterval(chatPollTimer);
      chatPollTimer = null;
    }
  }

  function bindChat() {
    const form = q('mcVcChatForm');
    const input = q('mcVcChatInput');
    const attach = q('mcVcChatAttach');
    const fileInput = q('mcVcChatFile');
    if (!form || !input) return;

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      sendChat(text).then(() => loadChat());
    });

    if (attach && fileInput) {
      attach.addEventListener('click', () => fileInput.click());
      fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        const label = '[Attachment: ' + file.name + '] — file sharing is recorded in your consultation record.';
        sendChat(label).then(() => loadChat());
        fileInput.value = '';
      });
    }
  }

  function isMobilePanel() {
    return global.matchMedia('(max-width: 768px)').matches;
  }

  /**
   * --mc-vc-controls-height is the clearance every floating element (PiP, chat
   * panel, TTS panels) reserves above the call controls. It was a hardcoded
   * guess per breakpoint, so whenever the control bar wrapped to an extra row —
   * which it does on narrow phones — those elements sat on top of the controls.
   * Measuring the real bar keeps the clearance correct at any width.
   */
  function trackControlsHeight() {
    const controls = q('mcVcControls');
    if (!controls) return;

    const apply = () => {
      const height = Math.round(controls.getBoundingClientRect().height);
      if (height > 0) {
        document.documentElement.style.setProperty('--mc-vc-controls-height', height + 'px');
      }
    };

    apply();
    if (typeof global.ResizeObserver === 'function') {
      new global.ResizeObserver(apply).observe(controls);
    } else {
      global.addEventListener('resize', apply);
      global.addEventListener('orientationchange', apply);
    }
  }

  function setPanelOpen(open) {
    const panel = q('mcVcSidePanel');
    const toggle = q('mcVcPanelToggle');
    const backdrop = q('mcVcPanelBackdrop');
    if (!panel) return;
    panel.hidden = !open;
    panel.classList.toggle('is-open', open);
    if (toggle) toggle.classList.toggle('is-hidden', open);
    if (backdrop) {
      backdrop.hidden = !open || !isMobilePanel();
      backdrop.setAttribute('aria-hidden', open && isMobilePanel() ? 'false' : 'true');
    }
    document.body.classList.toggle('mc-vc-panel-open', open && isMobilePanel());
  }

  function openPanelTab(tab) {
    setPanelOpen(true);
    const btn = document.querySelector('[data-panel-tab="' + tab + '"]');
    if (btn) btn.click();
  }

  function bindPanelTabs() {
    const panel = q('mcVcSidePanel');
    const toggle = q('mcVcPanelToggle');
    const closeBtn = q('mcVcPanelClose');
    const backdrop = q('mcVcPanelBackdrop');
    const infoBtn = q('mcVcInfoBtn');
    const chatBtn = q('mcVcChatBtn');
    const retryBtn = q('mcVcInfoRetry');

    if (toggle && panel) {
      toggle.addEventListener('click', () => {
        setPanelOpen(panel.hidden);
      });
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', () => setPanelOpen(false));
    }
    if (backdrop) {
      backdrop.addEventListener('click', () => setPanelOpen(false));
    }
    if (infoBtn) {
      infoBtn.addEventListener('click', (e) => {
        e.preventDefault();
        openPanelTab('info');
      });
    }
    if (chatBtn) {
      chatBtn.addEventListener('click', (e) => {
        e.preventDefault();
        openPanelTab('chat');
      });
    }
    if (retryBtn) {
      retryBtn.addEventListener('click', () => loadContext(true));
    }
    document.querySelectorAll('[data-panel-tab]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const tab = btn.getAttribute('data-panel-tab');
        document.querySelectorAll('[data-panel-tab]').forEach((b) => b.classList.toggle('is-active', b === btn));
        document.querySelectorAll('[data-panel-pane]').forEach((pane) => {
          pane.classList.toggle('is-active', pane.getAttribute('data-panel-pane') === tab);
        });
        if (tab === 'chat') startChatPoll();
      });
    });
    global.addEventListener('resize', () => {
      if (!panel || panel.hidden) return;
      const backdropEl = q('mcVcPanelBackdrop');
      if (backdropEl) backdropEl.hidden = !isMobilePanel();
      document.body.classList.toggle('mc-vc-panel-open', isMobilePanel());
    });
  }

  function loadContext(fromRetry) {
    return fetchContext().then((ctx) => {
      contextData = ctx || fallbackContext();
      renderWaiting(ctx || contextData);
      renderInfo(ctx || contextData);
      if (!ctx) {
        const pane = q('mcVcInfoPane');
        if (pane && !q('mcVcInfoRetry')) {
          const note = document.createElement('p');
          note.className = 'mc-vc-info-refresh';
          note.textContent = 'Live details unavailable. Showing session information from this visit.';
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.id = 'mcVcInfoRetry';
          btn.className = 'mc-vc-info-retry';
          btn.textContent = 'Retry loading details';
          btn.addEventListener('click', () => loadContext(true));
          pane.appendChild(note);
          pane.appendChild(btn);
        }
      }
      return ctx;
    });
  }

  function bindSoapAutosave() {
    const form = q('mcVcSoapForm');
    const status = q('mcVcSoapStatus');
    if (!form || IS_PATIENT) return;

    function save() {
      const fd = new FormData(form);
      fd.set('consultation_id', String(CONSULTATION_ID));
      if (!fd.get('patient_id') && PATIENT_ID) {
        fd.set('patient_id', String(PATIENT_ID));
      }
      fd.set('csrf_token', CSRF);
      fd.set('autosave', '1');
      fetch(API + '/app/api/provider/save_clinical_notes.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      })
        .then((r) => r.json())
        .then((data) => {
          if (!status) return;
          status.hidden = false;
          status.textContent = data && data.success ? 'Notes saved' : 'Could not save notes';
          status.className = 'mc-vc-soap-status ' + (data && data.success ? 'is-ok' : 'is-error');
        })
        .catch(() => {});
    }

    form.querySelectorAll('textarea').forEach((ta) => {
      ta.addEventListener('input', () => {
        clearTimeout(soapSaveTimer);
        soapSaveTimer = setTimeout(save, 1200);
      });
    });
  }

  function bindPostCall() {
    const modal = q('mcVcPostCallModal');
    const dismiss = q('mcVcPostCallDismiss');
    const followup = q('mcVcPostCallFollowup');
    if (dismiss && modal) {
      dismiss.addEventListener('click', () => {
        hidePostCall();
        try {
          if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'medconnect:call-dismissed', token: TOKEN }, location.origin);
          }
        } catch (_) {}
      });
    }
    if (followup) {
      followup.addEventListener('click', () => {
        try {
          if (typeof global.openFollowUpModal === 'function') global.openFollowUpModal({ fromCallEnd: true });
        } catch (_) {}
        hidePostCall();
      });
    }
    window.addEventListener('message', (e) => {
      if (e.origin !== location.origin || !e.data) return;
      if (e.data.type === 'medconnect:reset-call-ui') {
        resetCallUi();
      }
    });
  }

  function fillPatientPostCall(summary) {
    const doctorEl = q('mcVcPostCallDoctor');
    const providerEl = q('mcVcPostCallProvider');
    const dateEl = q('mcVcPostCallDate');
    const dateRow = q('mcVcPostCallDateRow');
    const durationEl = q('mcVcPostCallDuration');
    const durationRow = q('mcVcPostCallDurationRow');
    const viewBtn = q('mcVcPostCallViewSession');
    const data = summary || {};
    const doctor = data.provider_name || META.providerName || '';
    const named = doctor ? (/^dr\.?\s/i.test(doctor) ? doctor : ('Dr. ' + doctor)) : '';
    if (doctorEl && named) {
      doctorEl.textContent = named;
    }
    if (providerEl && named) {
      providerEl.textContent = named;
    }
    if (dateEl && dateRow) {
      const label = String(data.date_label || dateEl.textContent || '').trim();
      if (label && label !== '—') {
        dateEl.textContent = label;
        dateRow.hidden = false;
      } else {
        dateRow.hidden = true;
      }
    }
    if (durationEl && durationRow) {
      const liveTimer = document.getElementById('consultDuration');
      const liveLabel = liveTimer ? String(liveTimer.textContent || '').trim() : '';
      const apiLabel = String(data.duration_label || '').trim();
      const label = (liveLabel && liveLabel !== '00:00') ? liveLabel : apiLabel;
      if (label) {
        durationEl.textContent = label;
        durationRow.hidden = false;
      } else {
        durationEl.textContent = '—';
        durationRow.hidden = false;
      }
    }
    if (viewBtn && data.detail_url) {
      viewBtn.setAttribute('href', data.detail_url);
    }
  }

  function fetchSessionSummary() {
    const id = Number(CONSULTATION_ID || 0);
    if (!id || !API) return Promise.resolve(null);
    return fetch(API + '/app/api/consultations/session_summary.php?consultation_id=' + encodeURIComponent(String(id)), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-MC-No-Loader': '1' },
    }).then((res) => res.json()).then((data) => {
      if (!data || !data.success) return null;
      return data;
    }).catch(() => null);
  }

  function showPostCall() {
    if (!callEnded && !window.__mcCallEnded) return;
    if (postCallShown) return;
    const modal = q('mcVcPostCallModal');
    if (!modal) return;
    postCallShown = true;
    modal.hidden = false;
    document.body.classList.add('mc-vc-call-ended', 'is-ended-consultation');
    document.body.classList.remove('is-active-consultation');
    const endModal = document.getElementById('endCallModal');
    if (endModal) endModal.classList.remove('show');
    const controls = q('mcVcControls');
    if (controls) controls.style.display = 'none';
    const overlay = q('mcVcOverlay');
    if (overlay) {
      overlay.classList.remove('is-visible');
      overlay.setAttribute('aria-hidden', 'true');
    }
    const gate = q('mediaPermissionGate');
    if (gate) gate.classList.add('is-hidden');
    const panelToggle = q('mcVcPanelToggle');
    if (panelToggle) panelToggle.hidden = true;
    const moreMenu = q('mcVcMoreMenu');
    if (moreMenu) moreMenu.hidden = true;
    if (global.consultUi && typeof global.consultUi.closeMoreMenu === 'function') {
      global.consultUi.closeMoreMenu();
    }
    if (global.consultUi && typeof global.consultUi.stopMonitors === 'function') {
      global.consultUi.stopMonitors();
    }
    setPanelOpen(false);
    stopChatPoll();
    if (IS_PATIENT) {
      fillPatientPostCall(null);
      fetchSessionSummary().then((summary) => {
        if (summary) fillPatientPostCall(summary);
      });
    }
  }

  function bindShellBridge() {
    window.addEventListener('message', (e) => {
      if (e.origin !== location.origin || !e.data) return;
      const type = e.data.type;
      if (type === 'medconnect:shell-mode') {
        document.body.setAttribute('data-shell-mode', e.data.mode || '');
        if (e.data.ended) {
          document.body.classList.add('mc-vc-call-ended', 'is-ended-consultation');
          document.body.classList.remove('is-active-consultation');
          window.__mcCallEnded = true;
        }
        return;
      }
      if (type === 'medconnect:shell-toggle-audio' && typeof global.toggleAudio === 'function') global.toggleAudio();
      if (type === 'medconnect:shell-toggle-video' && typeof global.toggleVideo === 'function') global.toggleVideo();
      if (type === 'medconnect:shell-end-call' || type === 'medconnect:shell-leave-fast') {
        if (typeof global.leaveCallFast === 'function') global.leaveCallFast();
        else if (typeof global.endCall === 'function') global.endCall(true);
      }
    });

    const embedded = window.parent && window.parent !== window;
    if (embedded) {
      const minBtn = q('mcVcMinimizeBtn');
      if (minBtn) {
        minBtn.addEventListener('click', () => {
          try {
            window.parent.postMessage({ type: 'medconnect:minimize-video', token: TOKEN }, location.origin);
          } catch (_) {}
        });
      }
    } else if (global.McSessionVideoShell && !IS_PATIENT) {
      // Standalone provider: offer browse portal via shell if active elsewhere
    }
  }

  function bindWaitingRetry() {
    const btn = q('mcVcWaitingRetry');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const retry = q('retryConnectBtn');
      if (retry) retry.click();
    });
  }

  function init() {
    if (document.documentElement.dataset.mcVcEnhInit === '1') return;
    document.documentElement.dataset.mcVcEnhInit = '1';
    hidePostCall();
    trackControlsHeight();
    bindPanelTabs();
    bindChat();
    bindSoapAutosave();
    bindPostCall();
    bindShellBridge();
    bindWaitingRetry();
    watchCallStatusForWaiting();
    enhanceNetworkMonitor();

    loadContext(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  global.McVideoRoomEnhancements = {
    refreshContext: fetchContext,
    showPostCall: showPostCall,
    hidePostCall: hidePostCall,
    markCallEnded: markCallEnded,
    resetCallUi: resetCallUi,
    setWaitingRetryVisible: setWaitingRetryVisible,
    closePanel: function () { setPanelOpen(false); },
    openPanelTab: openPanelTab,
  };
})(window);
