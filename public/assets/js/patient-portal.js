/**
 * Patient portal — navigation, mobile sidebar, triage submit
 */
(function () {
  'use strict';

  const APP_BASE = window.APP_BASE || '';

  function getCsrfToken() {
    const body = document.body;
    if (body && body.dataset && body.dataset.csrf) {
      return body.dataset.csrf;
    }
    const root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset && root.dataset.csrf) {
      return root.dataset.csrf;
    }
    return '';
  }

  window.switchView = function switchView(viewId) {
    document.querySelectorAll('.view-container').forEach((v) => v.classList.remove('active'));
    const activeView = document.getElementById('view-' + viewId);
    if (activeView) {
      activeView.classList.add('active');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    if (viewId) {
      const nextHash = '#view-' + viewId;
      if (window.location.hash !== nextHash) {
        window.history.replaceState(null, '', nextHash);
      }
    }

    document.querySelectorAll('.sb-item').forEach((item) => {
      item.classList.toggle('active', item.dataset.view === viewId);
    });

    if (viewId === 'consultations' && typeof window.filterSessions === 'function') {
      window.filterSessions('upcoming');
    }

    if (viewId === 'triage' && typeof window.refreshBookingPicker === 'function') {
      window.refreshBookingPicker();
    }
  };

  function parseConsultDate(dateStr) {
    if (!dateStr) return 0;
    const d = new Date(String(dateStr).split(' ')[0] + 'T00:00:00');
    return d.getTime();
  }

  function consultDateYmd(dateStr) {
    if (!dateStr) return '';
    return String(dateStr).split(' ')[0];
  }

  function localTodayYmd() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function scheduledStartMs(c) {
    const date = consultDateYmd(c.slot_date || c.consult_date);
    if (!date) return 0;
    const time = String(c.slot_start || c.consult_time || '00:00:00');
    const parts = date.split('-').map(Number);
    const timeParts = time.split(':').map(Number);
    if (parts.length !== 3) return 0;
    return new Date(
      parts[0],
      parts[1] - 1,
      parts[2],
      timeParts[0] || 0,
      timeParts[1] || 0,
      timeParts[2] || 0
    ).getTime();
  }

  function formatOpensAt(ms) {
    if (!ms) return 'scheduled time';
    return new Date(ms).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }

  function isBeforeScheduledStart(c) {
    const start = scheduledStartMs(c);
    return start > 0 && Date.now() < start;
  }

  function consultationJoinAccess(c) {
    const status = String(c.status || '')
      .toLowerCase()
      .trim()
      .replace(/\s+/g, '_');

    // Best practice: join only after provider started the live room.
    if (status === 'in_consultation' && c.room_token) {
      return { allowed: true, mode: 'join' };
    }

    if (status === 'in_consultation') {
      return { allowed: false, mode: 'ended' };
    }

    if (isBeforeScheduledStart(c)) {
      return {
        allowed: false,
        mode: 'scheduled_wait',
        opensAt: formatOpensAt(scheduledStartMs(c)),
      };
    }

    const isToday = consultDateYmd(c.consult_date) === localTodayYmd();

    if (isToday && (status === 'scheduled' || status === 'pending' || status === 'waiting')) {
      return { allowed: false, mode: 'waiting' };
    }

    return { allowed: false, mode: 'unavailable' };
  }

  function providerInitials(name) {
    const n = String(name || '').trim();
    if (!n) return 'DR';
    const parts = n.replace(/^dr\.?\s*/i, '').split(/\s+/).filter(Boolean);
    const first = (parts[0] || '').charAt(0);
    const last = (parts[parts.length - 1] || '').charAt(0);
    return (first + last).toUpperCase() || 'DR';
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatConsultWhen(dateStr, timeStr) {
    if (!dateStr) return '—';
    const dateLabel = new Date(String(dateStr) + 'T00:00:00').toLocaleDateString('en-US', {
      month: 'short', day: 'numeric', year: 'numeric',
    });
    if (!timeStr) return dateLabel;
    const tp = String(timeStr).split(':');
    const th = parseInt(tp[0] || '0', 10);
    const tm = String(tp[1] || '00').padStart(2, '0');
    const ampm = th >= 12 ? 'PM' : 'AM';
    const h12 = ((th + 11) % 12) + 1;
    return dateLabel + ' at ' + h12 + ':' + tm + ' ' + ampm;
  }

  function formatConsultTime(timeStr) {
    if (!timeStr) return '';
    const tp = String(timeStr).split(':');
    const th = parseInt(tp[0] || '0', 10);
    const tm = String(tp[1] || '00').padStart(2, '0');
    const ampm = th >= 12 ? 'PM' : 'AM';
    const h12 = ((th + 11) % 12) + 1;
    return h12 + ':' + tm + ' ' + ampm;
  }

  function formatPastSessionDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(String(dateStr) + 'T00:00:00').toLocaleDateString('en-US', {
      month: 'long', day: 'numeric', year: 'numeric',
    });
  }

  function sessionBucket(c) {
    const status = String(c.status || '').toLowerCase().replace(/\s+/g, '_');
    const hasLiveRoom = !!(c.room_token && String(c.room_token).trim());
    if (status === 'in_consultation' && hasLiveRoom) return 'active';
    if (status === 'cancelled' || status === 'canceled') return 'past';
    if (status === 'completed') return 'past';
    if (status === 'in_consultation') return 'past';
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (parseConsultDate(c.consult_date) < today.getTime()) return 'past';
    return 'upcoming';
  }

  function sessionStatusLabel(c, bucket, joinAccess) {
    const status = String(c.status || '').toLowerCase();
    if (status === 'cancelled' || status === 'canceled') return 'Cancelled';
    if (bucket === 'active' || (joinAccess && joinAccess.mode === 'join')) return 'Active';
    if (bucket === 'upcoming') return 'Upcoming';
    return 'Completed';
  }

  function statusBadgeClass(mode, status, bucket) {
    const st = String(status || '').toLowerCase();
    if (st === 'cancelled' || st === 'canceled') return 'psess-status--cancelled';
    if (mode === 'join' || bucket === 'active') return 'psess-status--active';
    if (mode === 'waiting') return 'psess-status--waiting';
    if (mode === 'scheduled_wait') return 'psess-status--scheduled';
    if (bucket === 'upcoming') return 'psess-status--scheduled';
    return 'psess-status--completed';
  }

  function updateSessionMetrics(list) {
    let upcoming = 0;
    let active = 0;
    let past = 0;
    (list || []).forEach((c) => {
      const bucket = sessionBucket(c);
      if (bucket === 'upcoming') upcoming++;
      else if (bucket === 'active') active++;
      else past++;
    });
    const elUp = document.getElementById('psess-metric-upcoming');
    const elActive = document.getElementById('psess-metric-active');
    const elReady = document.getElementById('psess-metric-ready');
    const elPast = document.getElementById('psess-metric-past');
    if (elUp) elUp.textContent = String(upcoming);
    if (elActive) elActive.textContent = String(active);
    if (elReady) elReady.textContent = String(active);
    if (elPast) elPast.textContent = String(past);
  }

  window.filterSessions = function filterSessions(type) {
    const container = document.getElementById('sessions-list');
    const list = window.consultations || [];
    if (!container) return;

    if (type !== 'upcoming' && type !== 'active' && type !== 'past') {
      type = 'upcoming';
    }

    updateSessionMetrics(list);

    const filtered = list.filter((c) => sessionBucket(c) === type);
    const counts = { upcoming: 0, active: 0, past: 0 };
    list.forEach((c) => { counts[sessionBucket(c)] += 1; });

    container.innerHTML = '';

    if (filtered.length === 0) {
      if (type === 'upcoming') {
        container.innerHTML =
          '<div class="psess-empty">' +
          '<div class="psess-empty__icon" aria-hidden="true">' +
          '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 10l4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/></svg>' +
          '</div>' +
          '<p>No upcoming sessions</p>' +
          '<p class="psess-empty__sub">Schedule a video visit with an available provider.</p>' +
          '<div class="psess-empty__actions">' +
          '<a href="' + APP_BASE + '/views/patient/triage.php" class="psess-btn psess-btn--primary">Book Consultation</a>' +
          (counts.past > 0
            ? '<a href="#" class="psess-empty__link" data-psess-switch-tab="past">View ' + counts.past + ' past session' + (counts.past !== 1 ? 's' : '') + ' →</a>'
            : '<a href="' + APP_BASE + '/views/patient/my_health.php" class="psess-empty__link">Browse My Health records →</a>') +
          '</div></div>';
      } else if (type === 'active') {
        container.innerHTML =
          '<div class="psess-empty">' +
          '<div class="psess-empty__icon" aria-hidden="true">' +
          '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 10l4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/></svg>' +
          '</div>' +
          '<p>No active consultation</p>' +
          '<p class="psess-empty__sub">When your doctor starts the video room, the join button appears here.</p>' +
          '</div>';
      } else {
        container.innerHTML =
          '<div class="psess-empty">' +
          '<div class="psess-empty__icon" aria-hidden="true">' +
          '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
          '</div>' +
          '<p>No past sessions yet</p>' +
          '<p class="psess-empty__sub">Completed video consultations will appear here. Medical records stay in My Health.</p>' +
          '<div class="psess-empty__actions">' +
          '<a href="' + APP_BASE + '/views/patient/triage.php" class="psess-btn psess-btn--primary">Book Consultation</a>' +
          '<a href="' + APP_BASE + '/views/patient/my_health.php" class="psess-empty__link">Go to My Health →</a>' +
          '</div></div>';
      }
      container.querySelectorAll('[data-psess-switch-tab]').forEach((link) => {
        link.addEventListener('click', (e) => {
          e.preventDefault();
          window.filterSessions(link.getAttribute('data-psess-switch-tab') || 'past');
        });
      });
      updateJoinHint(type === 'upcoming' || type === 'active' ? filtered : []);
      return;
    }

    filtered.forEach((c) => {
      const bucket = sessionBucket(c);
      const joinAccess = (type === 'upcoming' || type === 'active')
        ? consultationJoinAccess(c)
        : { allowed: false, mode: 'past' };

      let actionBtn = '';
      if (type === 'upcoming' || type === 'active') {
        const canCancel =
          ['pending', 'scheduled'].includes(String(c.status || '').toLowerCase());
        let primary = '';
        if (joinAccess.allowed) {
          primary =
            '<button type="button" class="psess-btn psess-btn--primary" data-mc-video-join data-token="' +
            escapeHtml(c.room_token) +
            '" data-consultation-id="' + escapeHtml(String(c.id || '')) +
            '" data-label="Consultation with ' + escapeHtml(c.provider_name || 'provider') +
            '">Join Video Call</button>';
        } else if (joinAccess.mode === 'scheduled_wait') {
          primary =
            '<button type="button" class="psess-btn psess-btn--outline" disabled>Opens ' +
            (joinAccess.opensAt || 'at scheduled time') + '</button>';
        } else if (joinAccess.mode === 'waiting') {
          primary =
            '<button type="button" class="psess-btn psess-btn--outline psess-waiting-pulse" disabled>Waiting for Provider</button>';
        } else {
          primary =
            '<button type="button" class="psess-btn psess-btn--outline" disabled>Not Available Yet</button>';
        }
        const cancelBtn = canCancel
          ? '<button type="button" class="psess-btn psess-btn--danger" data-cancel-consult="' +
            escapeHtml(String(c.id || '')) +
            '">Cancel visit</button>'
          : '';
        actionBtn = '<div class="psess-card__actions">' + primary + cancelBtn + '</div>';
      } else {
        const viewUrl = APP_BASE + '/views/patient/consultation_detail.php?id=' + encodeURIComponent(String(c.id || '')) + '&from=sessions';
        actionBtn =
          '<div class="psess-card__actions">' +
          '<a href="' + viewUrl + '" class="psess-btn psess-btn--primary">View Consultation</a>' +
          '</div>';
      }

      const dateLabel = type === 'past'
        ? formatPastSessionDate(c.consult_date)
        : (c.consult_date
          ? new Date(c.consult_date + 'T00:00:00').toLocaleDateString('en-US', {
              month: 'short', day: 'numeric', year: 'numeric',
            })
          : '—');
      const timeLabel = formatConsultTime(c.consult_time);
      const statusLabel = sessionStatusLabel(c, bucket, joinAccess);

      let rescheduleBanner = '';
      const pending = c.pending_reschedule;
      if (pending && pending.status === 'pending_patient') {
        const oldWhen = formatConsultWhen(pending.old_date, pending.old_time);
        const newWhen = formatConsultWhen(pending.new_date, pending.new_time);
        rescheduleBanner =
          '<div class="psess-reschedule-banner" role="alert">' +
          '<strong>Reschedule request from ' + escapeHtml(c.provider_name || 'your doctor') + '</strong>' +
          '<p>Current confirmed time: <strong>' + escapeHtml(oldWhen) + '</strong></p>' +
          '<p>Proposed new time: <strong>' + escapeHtml(newWhen) + '</strong></p>' +
          (pending.reason ? '<p class="psess-reschedule-reason">Reason: ' + escapeHtml(pending.reason) + '</p>' : '') +
          '<div class="psess-reschedule-actions">' +
          '<button type="button" class="psess-btn psess-btn--primary" data-reschedule-accept="' + escapeHtml(String(pending.id)) + '">Accept new time</button>' +
          '<button type="button" class="psess-btn psess-btn--outline" data-reschedule-decline="' + escapeHtml(String(pending.id)) + '">Keep original time</button>' +
          '</div></div>';
      }

      const cardMod =
        joinAccess.mode === 'join' ? ' psess-card--ready'
          : joinAccess.mode === 'waiting' ? ' psess-card--waiting' : '';

      const typeLine = (function () {
        if (type !== 'past') {
          return escapeHtml(c.consult_type || 'General Consultation');
        }
        const vh = c.video_history || {};
        if (vh.show_completed_details) {
          return 'Medical Video Consultation';
        }
        return escapeHtml(c.consult_type || 'Consultation');
      })();

      let extraMeta = '';
      if (type === 'past') {
        const vh = c.video_history || {};
        if (vh.show_completed_details) {
          extraMeta +=
            '<p class="psess-card__meta"><span>Video consultation</span> Completed</p>';
          if (vh.started_label) {
            extraMeta +=
              '<p class="psess-card__meta"><span>Started</span> ' + escapeHtml(vh.started_label) + '</p>';
          }
          if (vh.ended_label) {
            extraMeta +=
              '<p class="psess-card__meta"><span>Ended</span> ' + escapeHtml(vh.ended_label) + '</p>';
          }
          const dur = c.duration_label || vh.duration_label || '';
          if (dur) {
            extraMeta +=
              '<p class="psess-card__meta"><span>Duration</span> ' + escapeHtml(dur) + '</p>';
          }
        } else {
          const vLabel = vh.video_status_label
            || (String(c.status || '').toLowerCase() === 'in_consultation' ? 'In progress' : 'Not started');
          extraMeta +=
            '<p class="psess-card__meta"><span>Video consultation</span> ' + escapeHtml(vLabel) + '</p>';
          if (c.duration_label) {
            extraMeta +=
              '<p class="psess-card__meta"><span>Duration</span> ' + escapeHtml(c.duration_label) + '</p>';
          }
        }
        if (c.chief_complaint) {
          extraMeta +=
            '<p class="psess-card__meta"><span>Patient Complaint</span> ' + escapeHtml(c.chief_complaint) + '</p>';
        }
      }

      const consultIdLine = type === 'past' && c.id
        ? '<p class="psess-card__type">Consultation #' + escapeHtml(String(c.id)) + '</p>'
        : '';

      container.innerHTML +=
        '<article class="psess-card' + cardMod + '" data-consult-id="' + (c.id || '') + '">' +
        '<div class="psess-card__main">' +
        '<div class="psess-card__avatar">' + providerInitials(c.provider_name) + '</div>' +
        '<div class="psess-card__info">' +
        '<h3>' + escapeHtml(c.provider_name || 'Healthcare Provider') + '</h3>' +
        consultIdLine +
        '<p class="psess-card__type">' + typeLine + '</p>' +
        '<p class="psess-card__datetime">' +
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>' +
        dateLabel + (timeLabel ? (type === 'past' ? '</p><p class="psess-card__datetime psess-card__datetime--time">' + timeLabel : ' · ' + timeLabel) : '') +
        '</p>' +
        extraMeta +
        rescheduleBanner +
        '</div></div>' +
        '<div class="psess-card__aside">' +
        '<span class="psess-status ' + statusBadgeClass(joinAccess.mode, c.status, bucket) + '">' + escapeHtml(statusLabel) + '</span>' +
        actionBtn +
        '</div></article>';
    });

    updateJoinHint(type === 'upcoming' || type === 'active' ? filtered : []);
  };

  async function cancelPatientConsultation(consultationId) {
    const id = parseInt(String(consultationId || '0'), 10);
    if (!id) return;
    if (!window.confirm(
      'Cancel this video visit?\n\nThe doctor’s time slot will become available immediately for other patients.\nYour care tips (if any) will stay available.'
    )) {
      return;
    }

    const csrf = getCsrfToken();
    if (!csrf) {
      window.alert('Security token missing. Please refresh the page and try again.');
      return;
    }

    try {
      const fd = new FormData();
      fd.set('consultation_id', String(id));
      fd.set('csrf_token', csrf);
      fd.set('reason', 'Cancelled from My Sessions');
      const res = await fetch(APP_BASE + '/app/api/patient/cancel_consultation.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.success) {
        window.alert((data && data.message) || 'Could not cancel appointment.');
        return;
      }
      if (Array.isArray(window.consultations)) {
        window.consultations = window.consultations.map((c) =>
          Number(c.id) === id ? Object.assign({}, c, { status: 'cancelled' }) : c
        );
      }
      window.alert(data.message || 'Appointment cancelled. Slot freed.');
      window.filterSessions('upcoming');
    } catch (_) {
      window.alert('Network error. Please try again.');
    }
  }

  document.addEventListener('click', (e) => {
    const btn = e.target && e.target.closest ? e.target.closest('[data-cancel-consult]') : null;
    if (!btn) return;
    e.preventDefault();
    cancelPatientConsultation(btn.getAttribute('data-cancel-consult'));
  });

  async function respondReschedule(rescheduleId, action) {
    const id = parseInt(String(rescheduleId || '0'), 10);
    if (!id) return;

    const confirmMsg = action === 'accept'
      ? 'Accept the new appointment time proposed by your doctor?'
      : 'Keep your original appointment time and decline this reschedule request?';
    if (!window.confirm(confirmMsg)) return;

    const csrf = getCsrfToken();
    if (!csrf) {
      window.alert('Security token missing. Please refresh the page and try again.');
      return;
    }

    try {
      const fd = new FormData();
      fd.set('reschedule_id', String(id));
      fd.set('action', action);
      fd.set('csrf_token', csrf);
      const res = await fetch(APP_BASE + '/app/api/patient/respond_reschedule.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.success) {
        window.alert((data && data.message) || 'Could not update reschedule request.');
        return;
      }
      window.alert(data.message || 'Response saved.');
      window.location.reload();
    } catch (_) {
      window.alert('Network error. Please try again.');
    }
  }

  document.addEventListener('click', (e) => {
    const acceptBtn = e.target && e.target.closest ? e.target.closest('[data-reschedule-accept]') : null;
    if (acceptBtn) {
      e.preventDefault();
      respondReschedule(acceptBtn.getAttribute('data-reschedule-accept'), 'accept');
      return;
    }
    const declineBtn = e.target && e.target.closest ? e.target.closest('[data-reschedule-decline]') : null;
    if (declineBtn) {
      e.preventDefault();
      respondReschedule(declineBtn.getAttribute('data-reschedule-decline'), 'decline');
    }
  });

  function updateJoinHint(list) {
    const hint = document.getElementById('consult-join-hint');
    const banner = document.getElementById('psess-live-banner');
    const bannerTitle = document.getElementById('psess-live-banner-title');
    const bannerSub = document.getElementById('psess-live-banner-sub');
    const waiting = (list || []).some((c) => consultationJoinAccess(c).mode === 'waiting');
    const ready = (list || []).some((c) => consultationJoinAccess(c).allowed);

    if (banner && bannerTitle) {
      if (ready) {
        banner.hidden = false;
        banner.className = 'psess-live-banner psess-live-banner--ready';
        bannerTitle.textContent = 'Your provider started the room';
        if (bannerSub) bannerSub.textContent = 'Click Join Video Call on your session card when you are ready.';
      } else if (waiting) {
        banner.hidden = false;
        banner.className = 'psess-live-banner';
        bannerTitle.textContent = 'Waiting for your provider';
        if (bannerSub) bannerSub.textContent = 'Join will unlock automatically when they start the video room.';
      } else {
        banner.hidden = true;
      }
    }

    if (!hint) return;
    if (ready) {
      hint.hidden = false;
      hint.textContent = 'Your provider started the room — click Join Video Call when you are ready.';
    } else if (waiting) {
      hint.hidden = false;
      hint.textContent = 'Checking for your provider… Join will appear automatically when they start.';
    } else {
      hint.hidden = true;
      hint.textContent = '';
    }
  }

  async function refreshConsultationStatus() {
    if (!document.getElementById('sessions-list')) return;
    try {
      const res = await fetch(APP_BASE + '/app/api/consultations/consultation_status.php', {
        credentials: 'same-origin',
        cache: 'no-store',
      });
      const json = await res.json();
      if (!json || !json.success || !Array.isArray(json.items)) return;

      const byId = {};
      (window.consultations || []).forEach((c) => {
        byId[String(c.id)] = c;
      });
      json.items.forEach((item) => {
        const id = String(item.id);
        byId[id] = Object.assign({}, byId[id] || {}, item);
      });
      window.consultations = Object.keys(byId).map((k) => byId[k]);

      const tab = document.querySelector('.psess-tab.is-active') || document.querySelector('.tab-btn.active');
      const type = tab?.dataset?.sessTab || (tab && /past/i.test(tab.textContent || '') ? 'past' : 'upcoming');
      if (typeof window.filterSessions === 'function') {
        window.filterSessions(type);
      }
    } catch (_) {
      /* non-fatal */
    }
  }

  // Live poll while waiting for provider to start the room.
  if (document.getElementById('sessions-list')) {
    refreshConsultationStatus();
    setInterval(refreshConsultationStatus, 5000);
  }

  // Keep tab highlight in sync when filterSessions is called from elsewhere.
  const _origFilterSessions = window.filterSessions;
  window.filterSessions = function filterSessionsWrapped(type) {
    _origFilterSessions(type);
    document.querySelectorAll('.psess-tab').forEach((btn) => {
      const match = (btn.dataset.sessTab || '') === type;
      btn.classList.toggle('is-active', match);
      btn.setAttribute('aria-selected', match ? 'true' : 'false');
    });
    document.querySelectorAll('.tab-btn').forEach((btn) => {
      btn.classList.toggle('active', btn.innerText.toLowerCase().includes(type));
    });
  };

  window.filterRecords = function filterRecords(type) {
    document.querySelectorAll('#records-tbody tr[data-type]').forEach((row) => {
      row.style.display = type === 'all' || row.dataset.type === type ? '' : 'none';
    });
  };

  let bookingPickerReady = false;

  function initBookingPicker() {
    const providerSelect = document.getElementById('booking_provider');
    const dateInput = document.getElementById('booking_date');
    const dateDisplay = document.getElementById('booking_date_display');
    const slotsWrap = document.getElementById('bookingSlotsWrap');
    const slotInput = document.getElementById('booking_slot_id');
    if (!providerSelect || !dateInput || !slotsWrap || !slotInput) return;
    if (bookingPickerReady) return;
    bookingPickerReady = true;

    const clearSlots = (message) => {
      slotInput.value = '';
      slotsWrap.innerHTML = '<p class="text-xs text-muted">' + message + '</p>';
    };

    const bookingBlocked = window.BOOKING_BLOCKED_IN_CONSULTATION === true;
    if (bookingBlocked) {
      providerSelect.disabled = true;
      clearSlots('Booking is unavailable while your consultation is in progress. Finish the visit first.');
      return;
    }

    const futureAppointmentLabel = window.BOOKING_FUTURE_APPOINTMENT_LABEL || '';
    const hasScheduledFollowup = window.PATIENT_HAS_SCHEDULED_FOLLOWUP === true;
    if (futureAppointmentLabel !== '' || hasScheduledFollowup) {
      const parts = [];
      if (futureAppointmentLabel !== '') {
        parts.push('You have an appointment scheduled for ' + futureAppointmentLabel + '.');
      }
      if (hasScheduledFollowup) {
        parts.push('Your doctor also scheduled a follow-up — that visit stays on your record.');
      }
      parts.push('Enter a new patient complaint below to start a separate consultation for a different health concern.');
      const followupAlertEl = document.getElementById('triageFormAlert');
      if (followupAlertEl) {
        showTriageAlert(followupAlertEl, 'success', parts.join(' '));
      }
    }

    const parseSlotTimeParts = (raw) => {
      const value = String(raw || '00:00:00').trim();
      const timeOnly = value.includes(' ') ? value.split(' ').pop() : value;
      const parts = timeOnly.split(':').map(Number);
      return parts.length >= 2 && !Number.isNaN(parts[0]) && !Number.isNaN(parts[1]) ? parts : null;
    };

    const isSlotStartInFuture = (slot) => {
      const parts = String(slot.slot_date || '').split('-').map(Number);
      const timeParts = parseSlotTimeParts(slot.start_time);
      if (parts.length !== 3 || !timeParts) {
        return false;
      }
      const slotStart = new Date(parts[0], parts[1] - 1, parts[2], timeParts[0], timeParts[1], timeParts[2] || 0);
      return slotStart.getTime() > Date.now();
    };

    const isSlotBookable = (slot) => {
      if (slot.bookable === true) {
        return true;
      }
      if (slot.bookable === false) {
        return false;
      }
      return isSlotStartInFuture(slot);
    };

    const resolveProviderId = () => {
      const lockedId = window.BOOKING_LOCKED_PROVIDER_ID;
      if (lockedId) {
        return String(lockedId);
      }
      if (providerSelect.disabled) {
        const hiddenProvider = document.querySelector('#patientTriageForm input[type="hidden"][name="provider_id"]');
        if (hiddenProvider && hiddenProvider.value) {
          return String(hiddenProvider.value);
        }
      }
      if (providerSelect.value) {
        return String(providerSelect.value);
      }
      if (providerSelect.options.length === 2 && providerSelect.options[1]) {
        return String(providerSelect.options[1].value || '');
      }
      return '';
    };

    slotsWrap.addEventListener('click', (e) => {
      const btn = e.target.closest('.booking-slot-btn');
      if (!btn || !slotsWrap.contains(btn)) return;
      if (btn.disabled || btn.classList.contains('is-past') || btn.getAttribute('aria-disabled') === 'true') {
        return;
      }

      slotsWrap.querySelectorAll('.booking-slot-btn.is-selected').forEach((el) => {
        el.classList.remove('is-selected');
        el.setAttribute('aria-pressed', 'false');
      });
      btn.classList.add('is-selected');
      btn.setAttribute('aria-pressed', 'true');
      slotInput.value = btn.dataset.slotId || '';
    });

    const renderSlots = (slots) => {
      slotInput.value = '';
      const bookableSlots = slots.filter((slot) => isSlotBookable(slot));

      if (!slots.length) {
        clearSlots('No appointment slots were generated for today. Ask the provider to enable today in their schedule.');
        return;
      }

      slotsWrap.innerHTML = '';

      if (!bookableSlots.length) {
        const note = document.createElement('p');
        note.className = 'text-xs text-muted';
        note.textContent =
          'All of today\'s slots have passed. Past times are shown below but cannot be selected.';
        slotsWrap.appendChild(note);
      }

      const grid = document.createElement('div');
      grid.className = 'booking-slots-grid';

      slots.forEach((slot) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        const isBookable = isSlotBookable(slot);
        btn.className = 'booking-slot-btn' + (isBookable ? '' : ' is-past');
        const baseLabel = String(slot.label || '').replace(/\s*\(passed\)\s*$/i, '');
        btn.textContent = isBookable ? baseLabel : baseLabel + ' (passed)';
        btn.dataset.slotId = String(slot.id);
        btn.disabled = !isBookable;
        btn.setAttribute('aria-disabled', isBookable ? 'false' : 'true');
        btn.setAttribute('aria-pressed', 'false');

        grid.appendChild(btn);
      });

      slotsWrap.appendChild(grid);

      // Urgent post-registration: auto-select earliest bookable slot
      try {
        const preferEarliest = sessionStorage.getItem('medconnect_prefer_earliest_slot') === '1';
        if (preferEarliest) {
          const firstBookable = slotsWrap.querySelector('.booking-slot-btn:not(.is-past):not([disabled])');
          if (firstBookable) {
            firstBookable.click();
            firstBookable.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        }
      } catch (_) { /* ignore */ }
    };

    const localTodayYmd = () => {
      const now = new Date();
      const y = now.getFullYear();
      const m = String(now.getMonth() + 1).padStart(2, '0');
      const d = String(now.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    };

    const formatSlotDateLabel = (slotDate) => {
      const parts = String(slotDate).split('-').map(Number);
      if (parts.length !== 3 || parts.some((n) => Number.isNaN(n))) {
        return slotDate;
      }
      const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
      const label = dateObj.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
      });
      return slotDate === localTodayYmd() ? `Today — ${label}` : label;
    };

    const setTodayDisplay = (today) => {
      dateInput.value = today;
      if (dateDisplay) {
        dateDisplay.textContent = formatSlotDateLabel(today);
        dateDisplay.dataset.today = today;
      }
    };

    const loadTodayBooking = async (providerId) => {
      const today = dateInput.value || dateDisplay?.dataset.today || localTodayYmd();
      setTodayDisplay(today);
      clearSlots('Loading today\'s available slots…');

      try {
        await loadSlots(providerId, today);
      } catch {
        clearSlots('Could not load today\'s appointment slots.');
      }
    };

    const loadSlots = async (providerId, date) => {
      const today = dateInput.value || dateDisplay?.dataset.today || localTodayYmd();
      if (date !== today) {
        clearSlots('Appointments can only be booked for today.');
        return;
      }

      clearSlots('Loading available slots…');
      const url =
        APP_BASE +
        '/app/api/appointments/get_available_slots.php?provider_id=' +
        encodeURIComponent(providerId) +
        '&date=' +
        encodeURIComponent(today) +
        '&_=' +
        Date.now();

      const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json();
      const slots = data.data?.slots || data.slots || [];

      if (!data.success) {
        clearSlots(data.message || 'Could not load today\'s slots.');
        return;
      }

      if (!slots.length) {
        clearSlots(
          'No slots for today. The doctor has not opened today\'s schedule yet or all clinic hours have passed.'
        );
        return;
      }

      renderSlots(slots);
    };

    providerSelect.addEventListener('change', () => {
      const providerId = resolveProviderId();
      if (!providerId) {
        clearSlots('Select a provider to load today\'s available slots.');
        return;
      }
      loadTodayBooking(providerId);
    });

    const initialProviderId = resolveProviderId();
    if (initialProviderId) {
      loadTodayBooking(initialProviderId);
    } else if (providerSelect.options.length === 2) {
      providerSelect.selectedIndex = 1;
      const fallbackId = resolveProviderId();
      if (fallbackId) {
        loadTodayBooking(fallbackId);
      }
    }
  }

  window.refreshBookingPicker = function refreshBookingPicker() {
    const providerSelect = document.getElementById('booking_provider');
    if (!providerSelect || !bookingPickerReady) {
      return;
    }

    const lockedId = window.BOOKING_LOCKED_PROVIDER_ID;
    let providerId = lockedId ? String(lockedId) : providerSelect.value;
    if (!providerId && providerSelect.options.length === 2) {
      providerSelect.selectedIndex = 1;
      providerId = lockedId ? String(lockedId) : (providerSelect.options[1]?.value || '');
    }
    if (!providerId) {
      return;
    }

    providerSelect.dispatchEvent(new Event('change'));
  };

  function showTriageAlert(alertEl, type, message) {
    if (!alertEl) return;
    alertEl.className = 'patient-triage-alert patient-triage-alert--' + type + ' is-visible';
    alertEl.textContent = message;
    alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  const BOOKING_OVERLAY_STEPS = [
    'Reviewing your health concern…',
    'Confirming provider availability…',
    'Reserving your time slot…',
    'Finalizing your appointment…',
  ];

  let bookingOverlayTimer = null;

  function paintBookingOverlaySteps(active) {
    const steps = document.getElementById('patient-booking-overlay-steps');
    if (!steps) return;
    steps.innerHTML = BOOKING_OVERLAY_STEPS.map((label, idx) => {
      const done = idx < active;
      const current = idx === active;
      const icon = done ? '✓' : current ? '…' : '○';
      return (
        '<li class="patient-booking-overlay__step' +
        (done ? ' is-done' : '') +
        (current ? ' is-active' : '') +
        '"><span aria-hidden="true">' + icon + '</span> ' + label + '</li>'
      );
    }).join('');
  }

  function showBookingOverlay(visible, options) {
    // Global full-screen loader is auth-only; use the local booking overlay.
    const overlay = document.getElementById('patient-booking-overlay');
    if (!overlay) return;
    if (visible) {
      overlay.hidden = false;
      overlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('patient-booking-overlay-open');
      let active = 0;
      paintBookingOverlaySteps(active);
      if (bookingOverlayTimer) clearInterval(bookingOverlayTimer);
      bookingOverlayTimer = setInterval(() => {
        active = Math.min(active + 1, BOOKING_OVERLAY_STEPS.length - 1);
        paintBookingOverlaySteps(active);
      }, 850);
    } else {
      if (bookingOverlayTimer) {
        clearInterval(bookingOverlayTimer);
        bookingOverlayTimer = null;
      }
      overlay.hidden = true;
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('patient-booking-overlay-open');
    }
  }

  function initAlternateBookingProvider() {
    const btn = document.getElementById('btnRequestAlternateProvider');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      const statusEl = document.getElementById('bookingAlternateStatus');
      const csrf = getCsrfToken();
      if (!csrf) {
        if (statusEl) {
          statusEl.hidden = false;
          statusEl.textContent = 'Session expired. Refresh the page and try again.';
        }
        return;
      }

      btn.disabled = true;
      const prevLabel = btn.textContent;
      btn.textContent = 'Finding next available doctor…';
      if (statusEl) {
        statusEl.hidden = true;
        statusEl.textContent = '';
      }

      try {
        const fd = new FormData();
        fd.set('csrf_token', csrf);
        const res = await fetch(
          APP_BASE + '/app/api/patient/request_alternate_booking_provider.php',
          {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-MC-No-Loader': '1' },
          }
        );
        const data = await res.json().catch(() => null);
        if (data && data.success) {
          window.location.reload();
          return;
        }
        if (statusEl) {
          statusEl.hidden = false;
          statusEl.textContent = (data && data.message) || 'Could not switch doctors. Please try again.';
        }
      } catch (_) {
        if (statusEl) {
          statusEl.hidden = false;
          statusEl.textContent = 'Network error. Please try again.';
        }
      } finally {
        btn.disabled = false;
        btn.textContent = prevLabel;
      }
    });
  }

  function initComplaintEvidence() {
    const input = document.getElementById('supporting_evidence');
    const chooseBtn = document.getElementById('btnChooseEvidence');
    const removeBtn = document.getElementById('btnRemoveEvidence');
    const filenameEl = document.getElementById('evidenceFilename');
    const previewEl = document.getElementById('evidencePreview');
    const complaintEl = document.getElementById('chief_complaint');
    const evidenceSection = document.getElementById('complaintEvidenceSection');
    if (!input || !chooseBtn) return;

    const IMAGE_MAX = 5 * 1024 * 1024;
    const VIDEO_MAX = 25 * 1024 * 1024;
    const ALLOWED = new Set([
      'image/jpeg',
      'image/png',
      'image/webp',
      'video/mp4',
      'video/webm',
    ]);

    let previewUrl = null;
    let triageLevel = null;
    let triageComplaint = '';

    function complaintText() {
      return String(complaintEl && complaintEl.value ? complaintEl.value : '').trim();
    }

    function clearTriageState() {
      triageLevel = null;
      triageComplaint = '';
    }

    function resolveTriageLevel(assessment) {
      if (!assessment || typeof assessment !== 'object') return null;
      const triage = assessment.triage && typeof assessment.triage === 'object' ? assessment.triage : {};
      const explicit = String(assessment.triage_level || '').toLowerCase();
      if (explicit === 'non_urgent' || explicit === 'urgent' || explicit === 'emergency') {
        return explicit;
      }
      const classification = String(triage.triage_classification || '').toUpperCase();
      if (classification === 'EMERGENCY') return 'emergency';
      if (classification === 'URGENT') return 'urgent';
      const dbLevel = String(triage.db_level || assessment.db_level || '').toLowerCase();
      if (dbLevel === '1' || dbLevel === 'high' || dbLevel === 'emergency') return 'emergency';
      if (dbLevel === '2' || dbLevel === 'urgent') return 'urgent';
      return 'non_urgent';
    }

    function applyAssessment(assessment) {
      const complaint = complaintText();
      if (!complaint || !assessment) return;
      const level = resolveTriageLevel(assessment);
      if (!level) return;
      triageComplaint = complaint;
      triageLevel = level;
      syncEvidenceSection();
    }

    function hasValidComplaint() {
      return complaintText().length > 0;
    }

    function canShowEvidence() {
      return (triageLevel === 'non_urgent' || triageLevel === 'urgent')
        && hasValidComplaint()
        && complaintText() === triageComplaint;
    }

    function canUseEvidenceUpload() {
      return canShowEvidence() && input && !input.disabled;
    }

    function syncEvidenceSection() {
      if (!evidenceSection) return;
      const show = canShowEvidence();
      evidenceSection.hidden = !show;
      evidenceSection.classList.toggle('complaint-evidence-group--collapsed', !show);
      evidenceSection.setAttribute('aria-hidden', show ? 'false' : 'true');
      if (show) {
        evidenceSection.removeAttribute('inert');
      } else {
        evidenceSection.setAttribute('inert', '');
      }
      input.disabled = !show;
      if (show) {
        input.removeAttribute('tabindex');
      } else {
        input.setAttribute('tabindex', '-1');
      }
      chooseBtn.disabled = !show;
      if (removeBtn) removeBtn.disabled = !show;
      if (!show) resetEvidence();
    }

    function onComplaintChanged() {
      if (complaintText() !== triageComplaint) {
        clearTriageState();
      }
      syncEvidenceSection();
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
      input.value = '';
      clearPreview();
      if (filenameEl) {
        filenameEl.textContent = '';
        filenameEl.hidden = true;
      }
      if (removeBtn) removeBtn.hidden = true;
    }

    chooseBtn.addEventListener('click', (e) => {
      if (!canUseEvidenceUpload()) {
        e.preventDefault();
        e.stopPropagation();
        resetEvidence();
        return;
      }
      input.click();
    });

    if (removeBtn) {
      removeBtn.addEventListener('click', () => {
        if (!canUseEvidenceUpload()) {
          resetEvidence();
          return;
        }
        resetEvidence();
      });
    }

    input.addEventListener('click', (e) => {
      if (!canUseEvidenceUpload()) {
        e.preventDefault();
        e.stopPropagation();
        resetEvidence();
      }
    });

    input.addEventListener('change', () => {
      if (!canUseEvidenceUpload()) {
        resetEvidence();
        return;
      }
      clearPreview();
      const file = input.files && input.files[0];
      if (!file) {
        resetEvidence();
        return;
      }

      if (!ALLOWED.has(file.type)) {
        resetEvidence();
        const alertEl = document.getElementById('triageFormAlert');
        showTriageAlert(alertEl, 'error', 'Please choose a JPG, PNG, WEBP photo or MP4/WebM video.');
        return;
      }

      const isVideo = file.type.startsWith('video/');
      const maxSize = isVideo ? VIDEO_MAX : IMAGE_MAX;
      if (file.size > maxSize) {
        resetEvidence();
        const alertEl = document.getElementById('triageFormAlert');
        showTriageAlert(
          alertEl,
          'error',
          isVideo ? 'Video must be 25 MB or smaller.' : 'Photo must be 5 MB or smaller.'
        );
        return;
      }

      if (filenameEl) {
        filenameEl.textContent = file.name;
        filenameEl.hidden = false;
      }
      if (removeBtn) removeBtn.hidden = false;

      if (previewEl) {
        previewUrl = URL.createObjectURL(file);
        if (isVideo) {
          previewEl.innerHTML = `<video src="${previewUrl}" controls playsinline muted></video>`;
        } else {
          previewEl.innerHTML = `<img src="${previewUrl}" alt="Supporting evidence preview">`;
        }
        previewEl.hidden = false;
      }
    });

    if (complaintEl) {
      complaintEl.addEventListener('input', onComplaintChanged);
      complaintEl.addEventListener('change', onComplaintChanged);
      complaintEl.addEventListener('paste', () => {
        window.setTimeout(onComplaintChanged, 0);
      });
    }

    document.addEventListener('mc:assessment-complete', (event) => {
      const assessment = event && event.detail ? event.detail : null;
      if (!assessment) return;
      const level = resolveTriageLevel(assessment);
      triageComplaint = complaintText();
      triageLevel = level === 'emergency' ? null : level;
      syncEvidenceSection();
    });

    syncEvidenceSection();

    if (complaintEl && complaintEl.hasAttribute('readonly') && hasValidComplaint()) {
      if (window.MedConnectAssessment && typeof window.MedConnectAssessment.run === 'function') {
        window.MedConnectAssessment.run({ skipAnimation: true });
      }
    }
  }

  function initTriageForm() {
    const form = document.getElementById('patientTriageForm');
    if (!form) return;

    initBookingPicker();
    initAlternateBookingProvider();
    initComplaintEvidence();

    const alertEl = document.getElementById('triageFormAlert');
    const submitBtn = form.querySelector('button[type="submit"]');

    // Do not prefill current complaint from registration — registration text is reference-only in the UI.
    try {
      sessionStorage.removeItem('medconnect_pending_chief_complaint');
    } catch (_) { /* ignore */ }

    try {
      const blockTele = sessionStorage.getItem('medconnect_block_telemedicine') === '1'
        || String(window.REGISTRATION_URGENCY || '').toUpperCase() === 'EMERGENCY';
      if (blockTele) {
        showTriageAlert(
          alertEl,
          'error',
          'Emergency symptoms were flagged. Teleconsultation is not available — please go to the nearest hospital or ER. A hospital referral has been (or will be) recorded for your care team. You do not need to pick a time slot.'
        );
        const providerSelect = document.getElementById('booking_provider');
        if (providerSelect) providerSelect.disabled = true;
        // Keep submit available so a missing referral can still be recorded without a slot.
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Confirm Emergency Referral';
        }
      }

      const preferEarliest = sessionStorage.getItem('medconnect_prefer_earliest_slot') === '1';
      if (preferEarliest) {
        showTriageAlert(
          alertEl,
          'success',
          'Based on your registration review, the earliest available slot will be selected. Confirm below or choose another time if needed.'
        );
        setTimeout(() => {
          if (typeof window.refreshBookingPicker === 'function') {
            window.refreshBookingPicker();
          }
        }, 400);
      }
    } catch (_) { /* ignore */ }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const complaint = (form.querySelector('#chief_complaint')?.value || '').trim();
      const slotId = document.getElementById('booking_slot_id')?.value || '';
      const blockTele = sessionStorage.getItem('medconnect_block_telemedicine') === '1'
        || String(window.REGISTRATION_URGENCY || '').toUpperCase() === 'EMERGENCY';
      const reviewFirstAllowed = window.TRIAGE_REVIEW_FIRST_ALLOWED === true;

      if (!complaint) {
        const complaintField = form.querySelector('#chief_complaint');
        const isLocked = complaintField && complaintField.hasAttribute('readonly');
        showTriageAlert(
          alertEl,
          'error',
          isLocked
            ? 'Your patient complaint is not available. Please contact the health office.'
            : 'Please describe your symptoms or concern.'
        );
        return;
      }

      if (!slotId && !blockTele && !reviewFirstAllowed) {
        showTriageAlert(alertEl, 'error', 'Please select an available appointment slot.');
        return;
      }

      const fd = new FormData(form);
      // Slot optional for emergency (server creates hospital referral). Required for normal booking.
      fd.set('slot_id', slotId || '0');
      if (!complaint) {
        fd.delete('supporting_evidence');
      } else {
        const evidenceInput = document.getElementById('supporting_evidence');
        const evidenceFile = evidenceInput && !evidenceInput.disabled && evidenceInput.files
          ? evidenceInput.files[0]
          : null;
        if (!evidenceFile) {
          fd.delete('supporting_evidence');
        }
      }
      const csrfToken = getCsrfToken();
      if (csrfToken) {
        fd.set('csrf_token', csrfToken);
      }

      showBookingOverlay(true);

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.dataset.originalText = submitBtn.textContent;
        submitBtn.textContent = 'Booking…';
      }

      try {
        const res = await fetch(APP_BASE + '/app/api/patient/submit_triage.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        const raw = await res.text();
        let data;
        try {
          data = raw ? JSON.parse(raw) : null;
        } catch {
          showBookingOverlay(false);
          showTriageAlert(
            alertEl,
            'error',
            res.status >= 500
              ? 'Server error while booking. Please refresh and try again.'
              : 'Unexpected server response. Please refresh the page and try again.'
          );
          return;
        }

        if (!data || typeof data !== 'object') {
          showBookingOverlay(false);
          showTriageAlert(alertEl, 'error', 'Unexpected server response. Please try again.');
          return;
        }

        if (data.success) {
          const booked = data.booked !== false && !data.awaiting_provider_review;
          const emergency = data.emergency === true;
          const awaitingReview = data.awaiting_provider_review === true;
          try {
            sessionStorage.removeItem('medconnect_pending_nlp_result');
            sessionStorage.removeItem('medconnect_prefer_earliest_slot');
            sessionStorage.removeItem('medconnect_post_reg_urgency');
            sessionStorage.removeItem('medconnect_pending_chief_complaint');
            if (emergency) {
              sessionStorage.setItem('medconnect_block_telemedicine', '1');
            }
          } catch (_) { /* ignore */ }
          showBookingOverlay(false);
          if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
          if (emergency) {
            const emMsg =
              data.message ||
              'Emergency symptoms detected. Please seek care at the nearest hospital or emergency department instead of booking an online consultation.';
            showTriageAlert(alertEl, 'error', emMsg);
            if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showEmergency === 'function') {
              window.mcPatientUrgencyModal.showEmergency(emMsg);
            }
            if (submitBtn) submitBtn.disabled = true;
            const providerSelect = document.getElementById('booking_provider');
            if (providerSelect) providerSelect.disabled = true;
          } else if (awaitingReview) {
            showTriageAlert(
              alertEl,
              'success',
              data.message ||
                'Your case is currently being reviewed by a healthcare provider. Please wait while your guidance is being prepared.'
            );
            if (submitBtn) submitBtn.disabled = false;
          } else {
            showTriageAlert(
              alertEl,
              booked ? 'success' : 'error',
              booked
                ? (data.message || 'Your appointment has been booked successfully.')
                : (data.message || 'Your visit was recorded, but the slot could not be booked.')
            );
            if (booked) {
              setTimeout(() => window.location.reload(), 1600);
            }
          }
        } else {
          showBookingOverlay(false);
          showTriageAlert(alertEl, 'error', data.message || 'Could not book your appointment.');
        }
      } catch {
        showBookingOverlay(false);
        showTriageAlert(alertEl, 'error', 'Network error. Please try again.');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = submitBtn.dataset.originalText || 'Book Appointment';
        }
      }
    });
  }

  function syncSidebarFromRoute() {
    const pathMatch = window.location.pathname.match(/\/views\/patient\/([^/?#]+)/);
    if (!pathMatch) return false;

    const pageFile = pathMatch[1];
    const pageStem = pageFile.replace(/\.php$/i, '');
    document.querySelectorAll('.sb-item').forEach((item) => {
      item.classList.toggle('active', item.dataset.view === pageStem);
    });
    return true;
  }

  function scrollToDashboardAnchor() {
    const hash = (window.location.hash || '').replace('#', '');
    if (hash === 'action-items') {
      const el = document.getElementById('dashboardActionItems');
      if (el) {
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
      }
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    syncSidebarFromRoute();
    initTriageForm();
    scrollToDashboardAnchor();
    window.addEventListener('hashchange', scrollToDashboardAnchor);
  });
})();
