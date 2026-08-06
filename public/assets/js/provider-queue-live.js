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

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
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

  function applyItem(item) {
    const cell = document.querySelector('[data-queue-action="' + item.id + '"]');
    if (!cell) return;

    const wasBlocked = cell.querySelector('.queue-open-session-blocked');
    cell.innerHTML = renderActions(item);
    bindBlockedButtons(cell);

    if (item.session_allowed && wasBlocked) {
      cell.classList.add('queue-action--just-opened');
      window.setTimeout(() => cell.classList.remove('queue-action--just-opened'), 2400);
    }

    scheduleUnlock(item);
  }

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
    } catch (_) {
      /* silent retry on next poll */
    }
  }

  bindBlockedButtons(document);
  actionCells.forEach((cell) => {
    const id = parseInt(cell.getAttribute('data-queue-action') || '0', 10);
    const start = parseInt(cell.getAttribute('data-scheduled-start') || '0', 10);
    if (id && start > 0) {
      scheduleUnlock({ id: id, scheduled_start: start, session_allowed: false });
    }
  });

  refreshQueueStatus();
  window.setInterval(refreshQueueStatus, POLL_MS);

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshQueueStatus();
  });
})();
