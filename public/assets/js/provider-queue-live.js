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

  function videoIcon() {
    return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 10l4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/></svg>';
  }

  function monitorIcon() {
    return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>';
  }

  function renderActions(item) {
    const allowed = !!item.session_allowed;
    const sessionUrl = item.session_url || (base + '/views/provider/consultation_session.php?id=' + item.id);
    const hasRoom = !!(item.room_token || item.status === 'in_consultation');
    let html = '<div class="queue-actions">';

    if (allowed) {
      const label = hasRoom ? 'Enter Session' : 'Open &amp; Start';
      html +=
        '<a href="' + escapeHtml(sessionUrl) + '" class="queue-btn primary queue-btn--live-ready">' +
        videoIcon() + ' ' + label + '</a>';
      if (item.live_room_url) {
        html +=
          '<a href="' + escapeHtml(item.live_room_url) + '" class="queue-btn">' +
          monitorIcon() + ' Live Room</a>';
      }
    } else {
      const reason = item.session_reason || 'This session cannot be opened right now.';
      const label = item.opens_at_label
        ? 'Opens at ' + escapeHtml(item.opens_at_label)
        : 'Opens at Schedule';
      html +=
        '<button type="button" class="queue-btn primary is-disabled queue-open-session-blocked" ' +
        'data-reason="' + escapeHtml(reason) + '" title="' + escapeHtml(reason) + '">' +
        videoIcon() + ' ' + label + '</button>';
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
      body = '<strong>' + name + ' chose Join Early.</strong><p>You can open their session now. Their scheduled queue position is unchanged.</p>';
    } else if (next.early_start_response === 'keep_time') {
      body = '<strong>' + name + ' chose Keep Scheduled Time.</strong><p>This is not a missed visit. Open the session' + when + '.</p>';
    } else if (next.can_offer_early && !activeVideoId && sessionStorage.getItem('mc-keep-next-' + next.id) !== '1') {
      body = '<strong>Ready for Next Patient</strong><p>' + name + ' is scheduled' + when + '.</p>' +
        '<button type="button" class="queue-btn primary" id="readyForNextBtn">Ready for Next Patient</button>';
    } else if (next.can_offer_early && sessionStorage.getItem('mc-keep-next-' + next.id) === '1') {
      body = '<strong>Keeping scheduled time</strong><p>' + name + ' stays' + when + '.</p>';
    } else if (next.early_start_response_label) {
      body = '<strong>Next: ' + name + '</strong><p>' + escapeHtml(next.early_start_response_label) + '.</p>';
    } else if (activeVideoId) {
      body = '<strong>Next in queue: ' + name + '</strong><p>They stay in scheduled order while the current consultation continues.</p>';
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
        openEarlyStartConfirm(next, function () {
          offerNextPatient(btn);
        });
      });
    }
  }

  function openEarlyStartConfirm(next, onOffer) {
    const existing = document.getElementById('mcEarlyStartConfirm');
    if (existing) existing.remove();
    const prev = String(next.previous_patient_name || '').trim();
    const name = String(next.patient_name || 'the next patient');
    const when = String(next.scheduled_label || 'the scheduled time');
    const endedLine = prev
      ? ('Current consultation with ' + prev + (next.previous_ended_early ? ' has ended early.' : ' has ended.'))
      : 'The current consultation has ended.';
    const wrap = document.createElement('div');
    wrap.id = 'mcEarlyStartConfirm';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-labelledby', 'mcEarlyStartTitle');
    wrap.style.cssText = 'position:fixed;inset:0;z-index:100400;background:rgba(2,6,23,.55);display:flex;align-items:center;justify-content:center;padding:16px;';
    wrap.innerHTML =
      '<div style="width:min(440px,100%);background:#fff;color:#0f172a;border-radius:16px;padding:22px 22px 16px;box-shadow:0 24px 60px rgba(0,0,0,.28);">' +
      '<h2 id="mcEarlyStartTitle" style="margin:0 0 8px;font-size:18px;">Ready for Next Patient?</h2>' +
      '<p style="margin:0 0 8px;line-height:1.45;">' + escapeHtml(endedLine) + '</p>' +
      '<p style="margin:0 0 4px;"><strong>Next patient:</strong> ' + escapeHtml(name) + '</p>' +
      '<p style="margin:0 0 16px;"><strong>Scheduled:</strong> ' + escapeHtml(when) + '</p>' +
      '<p style="margin:0 0 16px;">Would you like to offer an early start?</p>' +
      '<div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;">' +
      '<button type="button" data-early-choice="cancel" class="queue-btn">Cancel</button>' +
      '<button type="button" data-early-choice="keep" class="queue-btn">Keep Scheduled Time</button>' +
      '<button type="button" data-early-choice="offer" class="queue-btn primary">Offer Early Start</button>' +
      '</div></div>';
    wrap.addEventListener('click', function (event) {
      const choice = event.target && event.target.getAttribute ? event.target.getAttribute('data-early-choice') : '';
      if (event.target === wrap) {
        wrap.remove();
        return;
      }
      if (choice === 'cancel') {
        wrap.remove();
        return;
      }
      if (choice === 'keep') {
        if (next.id) sessionStorage.setItem('mc-keep-next-' + next.id, '1');
        wrap.remove();
        renderQueueTiming(next, 0);
        return;
      }
      if (choice === 'offer') {
        wrap.remove();
        onOffer();
      }
    });
    document.body.appendChild(wrap);
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
