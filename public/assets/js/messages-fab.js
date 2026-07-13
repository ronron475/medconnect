/**
 * medConnect — Floating Messages quick-access button
 * Patient & provider authenticated portal pages.
 */
(function (global) {
  'use strict';

  // Prefer the centralized unread service when available.
  const POLL_MS = 5000;
  const API_PATH = '/app/api/messages/unread_count.php';

  function getAssetBase() {
    const body = document.body;
    if (body && body.dataset.assetBase) return body.dataset.assetBase;
    const root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset.assetBase) return root.dataset.assetBase;
    return '';
  }

  function formatBadge(count) {
    const n = Math.max(0, parseInt(count, 10) || 0);
    if (n <= 0) return '';
    return n > 99 ? '99+' : String(n);
  }

  function tooltipLabel(count) {
    const n = Math.max(0, parseInt(count, 10) || 0);
    return n > 0 ? `Messages (${n} unread)` : 'Messages';
  }

  function applyUnread(count) {
    const n = Math.max(0, parseInt(count, 10) || 0);
    const text = formatBadge(n);

    document.querySelectorAll('[data-messages-fab]').forEach((fab) => {
      const badge = fab.querySelector('[data-messages-fab-badge]');
      const label = tooltipLabel(n);

      fab.setAttribute('aria-label', label);
      fab.setAttribute('title', label);
      fab.setAttribute('data-unread', String(n));
      fab.classList.toggle('has-unread', n > 0);

      if (badge) {
        badge.textContent = text;
        badge.hidden = n <= 0;
        badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
      }
    });

    document.querySelectorAll('[data-nav-messages-badge]').forEach((badge) => {
      badge.textContent = text;
      badge.hidden = n <= 0;
      badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
    });

    document.querySelectorAll('[data-nav-messages]').forEach((link) => {
      const label = tooltipLabel(n);
      link.setAttribute('aria-label', n > 0 ? label : 'Messages');
    });
  }

  let pollTimer = null;
  let fetchInFlight = false;

  async function refreshUnread() {
    if (fetchInFlight) return;
    fetchInFlight = true;
    try {
      const base = getAssetBase();
      const res = await fetch(base + API_PATH + '?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      const data = await res.json();
      if (data && data.success) {
        applyUnread(data.unread_count || 0);
      }
    } catch (_) {
      /* silent — FAB remains usable */
    } finally {
      fetchInFlight = false;
    }
  }

  function startPolling() {
    if (pollTimer) return;
    refreshUnread();
    pollTimer = global.setInterval(refreshUnread, POLL_MS);
  }

  function stopPolling() {
    if (!pollTimer) return;
    global.clearInterval(pollTimer);
    pollTimer = null;
  }

  function localToggleQuickPanel() {
    const panel = document.querySelector('[data-msgqp]');
    const overlay = document.querySelector('[data-msgqp-overlay]');
    if (!panel || !overlay) return false;
    const isOpen = panel.classList.contains('is-open') && panel.hidden === false;
    const nextOpen = !isOpen;
    panel.hidden = !nextOpen;
    overlay.hidden = !nextOpen;
    overlay.setAttribute('aria-hidden', nextOpen ? 'false' : 'true');
    panel.classList.toggle('is-open', nextOpen);
    overlay.classList.toggle('is-open', nextOpen);
    document.body.classList.toggle('mc-msgqp-open', nextOpen);
    return true;
  }

  function bindFabClickOnce() {
    if (document.documentElement.dataset.mcFabClickBound === '1') return;
    document.documentElement.dataset.mcFabClickBound = '1';

    // Capture-phase click so other scripts can't cancel it first.
    document.addEventListener('click', (e) => {
      const target = e.target;
      const fab = target && target.closest ? target.closest('[data-messages-fab]') : null;
      if (!fab) return;
      if (fab.dataset.suppressClick === '1') {
        e.preventDefault();
        return;
      }

      // If quick panel exists, always toggle it (micro-chat behavior).
      if (global.MedConnectQuickMessagesPanel && typeof global.MedConnectQuickMessagesPanel.toggle === 'function') {
        e.preventDefault();
        toggleQuickPanel();
        return;
      }
      if (localToggleQuickPanel()) {
        e.preventDefault();
      }
    }, true);
  }

  const STORAGE_KEY = 'mc-messages-fab-pos';
  const DRAG_THRESHOLD = 3;
  const VIEW_MARGIN = 12;
  const PANEL_GAP = 18;

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function isMobileViewport() {
    return global.matchMedia('(max-width: 767px)').matches;
  }

  function getFabSize(fab) {
    const rect = fab.getBoundingClientRect();
    return {
      w: rect.width || parseFloat(getComputedStyle(fab).width) || 56,
      h: rect.height || parseFloat(getComputedStyle(fab).height) || 56,
    };
  }

  function syncQuickPanelToFab(fab) {
    const panel = document.querySelector('[data-msgqp]');
    if (!panel || !fab) return;

    if (isMobileViewport() || (!fab.classList.contains('is-positioned') && fab.dataset.customPos !== 'true')) {
      panel.classList.remove('is-fab-anchored');
      panel.style.left = '';
      panel.style.right = '';
      panel.style.bottom = '';
      panel.style.top = '';
      panel.style.transformOrigin = '';
      return;
    }

    const fabRect = fab.getBoundingClientRect();
    const panelW = panel.offsetWidth || 380;
    const panelH = panel.offsetHeight || 560;
    const margin = VIEW_MARGIN;
    const gap = PANEL_GAP;

    let left = fabRect.right - panelW;
    left = clamp(left, margin, global.innerWidth - panelW - margin);

    let top = fabRect.top - panelH - gap;
    if (top < margin) {
      top = fabRect.bottom + gap;
      if (top + panelH > global.innerHeight - margin) {
        top = clamp(fabRect.top - panelH - gap, margin, global.innerHeight - panelH - margin);
      }
    }

    panel.classList.add('is-fab-anchored');
    panel.style.left = left + 'px';
    panel.style.right = 'auto';
    panel.style.top = top + 'px';
    panel.style.bottom = 'auto';

    const originX = clamp(fabRect.left + fabRect.width / 2 - left, 0, panelW);
    const originY = top < fabRect.top ? panelH : 0;
    panel.style.transformOrigin = originX + 'px ' + originY + 'px';
  }

  function applyFabPosition(fab, left, top, persist) {
    const size = getFabSize(fab);
    const maxLeft = global.innerWidth - size.w - VIEW_MARGIN;
    const maxTop = global.innerHeight - size.h - VIEW_MARGIN;
    const x = clamp(left, VIEW_MARGIN, maxLeft);
    const y = clamp(top, VIEW_MARGIN, maxTop);

    fab.style.right = 'auto';
    fab.style.bottom = 'auto';
    fab.style.left = x + 'px';
    fab.style.top = y + 'px';
    fab.classList.add('is-positioned');

    syncQuickPanelToFab(fab);

    if (persist) {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ left: x, top: y }));
      } catch (_) { /* ignore */ }
    }

    global.dispatchEvent(new CustomEvent('medconnect:fab-moved', {
      detail: { left: x, top: y },
    }));

    return { left: x, top: y };
  }

  function restoreFabPosition(fab) {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return false;
      const pos = JSON.parse(raw);
      if (typeof pos.left !== 'number' || typeof pos.top !== 'number') return false;
      applyFabPosition(fab, pos.left, pos.top, false);
      return true;
    } catch (_) {
      return false;
    }
  }

  function toggleQuickPanel() {
    if (global.MedConnectQuickMessagesPanel && typeof global.MedConnectQuickMessagesPanel.toggle === 'function') {
      global.MedConnectQuickMessagesPanel.toggle();
      return;
    }
    localToggleQuickPanel();
  }

  function bindFabDrag(fab) {
    if (global.MedConnectDraggableFab && typeof global.MedConnectDraggableFab.init === 'function') {
      global.MedConnectDraggableFab.init({
        handle: fab,
        wrap: fab,
        storageKey: STORAGE_KEY,
        margin: VIEW_MARGIN,
        threshold: DRAG_THRESHOLD,
        clickSuppressMs: 320,
        dragBodyClass: 'mc-messages-fab-dragging',
        onTap: () => {
          fab.dataset.suppressClick = '1';
          global.setTimeout(() => {
            delete fab.dataset.suppressClick;
          }, 320);
          toggleQuickPanel();
        },
        onDragEnd: () => syncQuickPanelToFab(fab),
      });
      global.addEventListener('resize', () => {
        if (fab.dataset.customPos === 'true') syncQuickPanelToFab(fab);
      });
      return;
    }

    let dragging = false;
    let pointerId = null;
    let startX = 0;
    let startY = 0;
    let startLeft = 0;
    let startTop = 0;

    function endDrag(e) {
      if (pointerId === null || (e && e.pointerId !== pointerId)) return;
      if (dragging) {
        applyFabPosition(fab, parseFloat(fab.style.left) || 0, parseFloat(fab.style.top) || 0, true);
        fab.dataset.suppressClick = '1';
        global.setTimeout(() => {
          delete fab.dataset.suppressClick;
        }, 320);
      }
      dragging = false;
      pointerId = null;
      fab.classList.remove('is-dragging');
      if (e) {
        try { fab.releasePointerCapture(e.pointerId); } catch (_) { /* ignore */ }
      }
    }

    fab.addEventListener('pointerdown', (e) => {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      e.preventDefault();
      const rect = fab.getBoundingClientRect();
      startX = e.clientX;
      startY = e.clientY;
      startLeft = rect.left;
      startTop = rect.top;
      dragging = false;
      pointerId = e.pointerId;
      try { fab.setPointerCapture(pointerId); } catch (_) { /* ignore */ }
    });

    fab.addEventListener('pointermove', (e) => {
      if (pointerId === null || e.pointerId !== pointerId) return;
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;
      if (!dragging && (Math.abs(dx) > DRAG_THRESHOLD || Math.abs(dy) > DRAG_THRESHOLD)) {
        dragging = true;
        fab.classList.add('is-dragging');
      }
      if (!dragging) return;
      e.preventDefault();
      applyFabPosition(fab, startLeft + dx, startTop + dy, false);
    });

    fab.addEventListener('pointerup', endDrag);
    fab.addEventListener('pointercancel', endDrag);

    global.addEventListener('resize', () => {
      if (!fab.classList.contains('is-positioned')) return;
      const rect = fab.getBoundingClientRect();
      applyFabPosition(fab, rect.left, rect.top, true);
    });
  }

  function init() {
    const fab = document.querySelector('[data-messages-fab]');
    if (!fab) return;

    bindFabClickOnce();
    bindFabDrag(fab);

    // MedConnectDraggableFab restores position; legacy path uses restoreFabPosition.
    if (fab.dataset.customPos !== 'true') {
      restoreFabPosition(fab);
    }

    const initial = parseInt(fab.getAttribute('data-unread') || '0', 10) || 0;
    applyUnread(initial);

    // If service exists, do not start a second poller.
    const svc = global.MedConnectUnreadService;
    if (!svc) {
      document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
          stopPolling();
        } else {
          startPolling();
        }
      });
    }

    global.addEventListener('medconnect:messages-unread', (event) => {
      if (event && event.detail && typeof event.detail.unread_count !== 'undefined') {
        applyUnread(event.detail.unread_count);
      } else {
        refreshUnread();
      }
    });

    if (!document.hidden && !svc) {
      startPolling();
    }
  }

  function bootMessagesFab() {
    init();
  }

  global.MedConnectMessagesFab = {
    refresh: refreshUnread,
    applyUnread,
    syncQuickPanel: () => {
      const fab = document.querySelector('[data-messages-fab]');
      if (fab) syncQuickPanelToFab(fab);
    },
  };

  if (global.MedConnectDraggableFab) {
    bootMessagesFab();
  } else {
    global.addEventListener('load', bootMessagesFab, { once: true });
  }
})(window);
