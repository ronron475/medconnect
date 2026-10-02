/**
 * medConnect — Provider queue live refresh (auto-unlock sessions at scheduled time).
 */
(function () {
  'use strict';

  const POLL_MS = 5000;
  const base = String(window.APP_BASE || document.body?.dataset?.assetBase || '').replace(/\/$/, '');
  const actionCells = document.querySelectorAll('[data-queue-action]');
  if (!actionCells.length) return;

  const openTimers = new Map();
  const endTimers = new Map();

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function statusLabel(status) {
    return String(status || '')
      .replace(/_/g, ' ')
      .replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function statusClass(status) {
    switch (status) {
      case 'in_consultation':
        return 'active';
      case 'completed':
        return 'done';
      case 'cancelled':
        return 'muted';
      default:
        return 'waiting';
    }
  }

  function renderActions(item) {
    const allowed = !!item.session_allowed;
    const sessionUrl = item.session_url || (base + '/views/provider/consultation_session.php?id=' + item.id);
    const hasRoom = !!(item.room_token || item.status === 'in_consultation');
    let html = '<div class="queue-actions">';

    if (allowed) {
      const label = item.documentation_pending
        ? 'Continue Assessment'
        : (hasRoom ? 'Enter Session' : 'Open &amp; Start');
      html +=
        '<a href="' + escapeHtml(sessionUrl) + '" class="queue-btn primary queue-btn--live-ready">' +
        label + '</a>';
      if (item.live_room_url) {
        html +=
          '<a href="' + escapeHtml(item.live_room_url) + '" class="queue-btn">Live Room</a>';
      }
    } else {
      const reason = item.session_reason || 'This session cannot be opened right now.';
      const label = item.opens_at_label
        ? 'Opens at ' + escapeHtml(item.opens_at_label)
        : 'Opens at Schedule';
      html +=
        '<button type="button" class="queue-btn queue-btn--opens queue-open-session-blocked" ' +
        'data-reason="' + escapeHtml(reason) + '" title="' + escapeHtml(reason) + '">' +
        label + '</button>';
    }

    html += '</div>';
    return html;
  }

  function bindBlockedButtons(root) {
    (root || document).querySelectorAll('.queue-open-session-blocked').forEach((btn) => {
      if (btn.dataset.boundAlert) return;
      btn.dataset.boundAlert = '1';
      btn.addEventListener('click', () => {
        if (typeof window.openProviderSessionAlert === 'function') {
          window.openProviderSessionAlert(btn.dataset.reason || 'This session cannot be opened right now.');
        }
      });
    });
  }

  function updateStats(stats) {
    if (!stats) return;
    const map = {
      today: document.querySelector('[data-queue-stat="today"]'),
      waiting: document.querySelector('[data-queue-stat="waiting"]'),
      active: document.querySelector('[data-queue-stat="active"]'),
      completed: document.querySelector('[data-queue-stat="completed"]'),
    };
    Object.keys(map).forEach((key) => {
      if (map[key] && typeof stats[key] !== 'undefined') {
        map[key].textContent = String(stats[key]);
      }
    });
  }

  function scheduleUnlock(item) {
    if (!item || !item.id || !item.scheduled_start || item.session_allowed) return;
    if (openTimers.has(item.id)) return;

    const delay = (item.scheduled_start * 1000) - Date.now() + 800;
    if (delay <= 0) return;

    const timer = window.setTimeout(() => {
      openTimers.delete(item.id);
      refreshQueueStatus();
    }, Math.min(delay, 24 * 60 * 60 * 1000));

    openTimers.set(item.id, timer);
  }

  function scheduleSlotEnd(item) {
    if (!item || !item.id || !item.scheduled_end) return;
    if (item.status !== 'in_consultation') return;
    if (endTimers.has(item.id)) return;

    const delay = (item.scheduled_end * 1000) - Date.now() + 800;
    if (delay <= 0) {
      refreshQueueStatus();
      return;
    }

    const timer = window.setTimeout(() => {
      endTimers.delete(item.id);
      refreshQueueStatus();
    }, Math.min(delay, 24 * 60 * 60 * 1000));

    endTimers.set(item.id, timer);
  }

  function updateWaitLabel(item) {
    const cell = document.querySelector('[data-queue-wait="' + item.id + '"]');
    if (!cell) return;
    const el = cell.querySelector('[data-queue-wait-label]');
    if (!el) return;
    const label = item.waiting_label || '';
    el.textContent = label;
    el.hidden = label === '';
  }

  function updateStatusCell(item) {
    const cell = document.querySelector('[data-queue-status="' + item.id + '"]');
    if (!cell) return;

    const badge = cell.querySelector('.queue-badge');
    if (!badge) return;

    const nextLabel = item.status_label || statusLabel(item.status);
    const nextClass = statusClass(item.status);

    badge.textContent = nextLabel;
    badge.className = 'queue-badge ' + nextClass;
  }

  function applyItem(item) {
    const cell = document.querySelector('[data-queue-action="' + item.id + '"]');
    if (!cell) return;

    const wasBlocked = cell.querySelector('.queue-open-session-blocked');
    cell.innerHTML = renderActions(item);
    bindBlockedButtons(cell);
    updateStatusCell(item);
    updateWaitLabel(item);

    if (item.session_allowed && wasBlocked) {
      cell.classList.add('queue-action--just-opened');
      window.setTimeout(() => cell.classList.remove('queue-action--just-opened'), 2400);
    }

    scheduleUnlock(item);
    scheduleSlotEnd(item);
  }

  function renderQueueTiming(next, activeVideoId) {
    const banner = document.getElementById('queueTimingBanner');
    if (!banner) return;
    if (!next) {
      banner.hidden = true;
      banner.innerHTML = '';
      return;
    }
    const name = escapeHtml(next.patient_name || 'The next patient');
    const when = next.scheduled_label ? ' at ' + escapeHtml(next.scheduled_label) : '';
    let body = '';
    if (next.early_start_response === 'join_early') {
      body = '<strong>' + name + ' chose Start Early.</strong><p>You can open their session now. Their scheduled time' + when + ' is unchanged.</p>';
    } else if (next.early_start_response === 'keep_time') {
      body = '<strong>' + name + ' chose Keep Scheduled Time.</strong><p>This is not a missed visit. Open the session' + when + '.</p>';
    } else if (next.can_offer_early && !activeVideoId) {
      body = '<strong>Ready for Next Patient Early</strong><p>' + name + ' is scheduled' + when + '.</p>' +
        '<button type="button" class="queue-btn primary" id="readyForNextBtn">Ready for Next Patient Early</button>';
    } else if (next.early_start_response_label) {
      body = '<strong>Next: ' + name + '</strong><p>' + escapeHtml(next.early_start_response_label) + '.</p>';
    } else if (activeVideoId) {
      body = '<strong>Previous consultation in progress</strong><p>Next patient is waiting: ' + name + when + '.</p>';
    } else {
      banner.hidden = true;
      banner.innerHTML = '';
      return;
    }
    banner.hidden = false;
    banner.innerHTML = '<div class="queue-panel-header"><div class="queue-panel-title">Queue timing</div></div><div style="padding:12px 16px;">' + body + '</div>';
    const btn = document.getElementById('readyForNextBtn');
    if (btn) {
      btn.addEventListener('click', function () {
        offerNextPatient(btn);
      });
    }
  }

  async function offerNextPatient(btn) {
    btn.disabled = true;
    const body = new FormData();
    const csrf = document.body?.dataset?.csrf || '';
    if (csrf) body.set('csrf_token', csrf);
    try {
      const res = await fetch(base + '/app/api/provider/ready_for_next.php', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const json = await res.json();
      const banner = document.getElementById('queueTimingBanner');
      if (banner && json && json.message) {
        const note = document.createElement('p');
        note.textContent = json.message;
        banner.appendChild(note);
      }
      refreshQueueStatus();
    } catch (_) {
      btn.disabled = false;
    }
  }

  document.addEventListener('medconnect:notifications-arrived', function () {
    refreshQueueStatus();
  });

  async function refreshQueueStatus() {
    try {
      const res = await fetch(base + '/app/api/provider/queue_status.php?_=' + Date.now(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-MC-No-Loader': '1' },
        cache: 'no-store',
      });
      const data = await res.json();
      if (!data || !data.success) return;

      const items = data.items || (data.data && data.data.items) || [];
      const stats = data.stats || (data.data && data.data.stats) || null;
      items.forEach(applyItem);
      updateStats(stats);
      renderQueueTiming(data.next_patient || (data.data && data.data.next_patient) || null, data.active_video_consultation_id || 0);
    } catch (_) {
      /* silent retry on next poll */
    }
  }

  bindBlockedButtons(document);
  actionCells.forEach((cell) => {
    const id = parseInt(cell.getAttribute('data-queue-action') || '0', 10);
    const start = parseInt(cell.getAttribute('data-scheduled-start') || '0', 10);
    const end = parseInt(cell.getAttribute('data-scheduled-end') || '0', 10);
    if (id && start > 0) {
      scheduleUnlock({ id: id, scheduled_start: start, session_allowed: false });
    }
    if (id && end > 0) {
      scheduleSlotEnd({ id: id, scheduled_end: end, status: 'in_consultation' });
    }
  });

  refreshQueueStatus();
  window.setInterval(function () {
    if (document.hidden) return;
    if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
    refreshQueueStatus();
  }, POLL_MS);

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshQueueStatus();
  });
  document.addEventListener('medconnect:live-sync', (ev) => {
    const changed = (ev.detail && ev.detail.changed) || [];
    if (changed.indexOf('queue') !== -1 || changed.indexOf('appointments') !== -1) {
      refreshQueueStatus();
    }
  });

  window.MedConnectProviderQueueLive = { refresh: refreshQueueStatus };
})();
