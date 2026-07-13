/**
 * medConnect — Quick Messages Panel (drawer / mobile sheet)
 * Reuses existing message APIs: conversations.php, list.php, send.php, events.php, unread_count.php
 */
(function () {
  'use strict';

  const PANEL_SELECTOR = '[data-msgqp]';
  const OVERLAY_SELECTOR = '[data-msgqp-overlay]';
  const FAB_SELECTOR = '[data-messages-fab]';

  const API_CONV = '/app/api/messages/conversations.php';
  const API_LIST = '/app/api/messages/list.php';
  const API_SEND = '/app/api/messages/send.php';
  const API_MARK_READ = '/app/api/messages/mark_read.php';
  const API_THREAD_ACTION = '/app/api/messages/thread_action.php';

  const POLL_CONV_MS = 5000;

  function getAssetBase() {
    const body = document.body;
    if (body && body.dataset.assetBase) return body.dataset.assetBase;
    const root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset.assetBase) return root.dataset.assetBase;
    return '';
  }

  function getCsrf() {
    const body = document.body;
    if (body && body.dataset.csrf) return body.dataset.csrf;
    const root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset.csrf) return root.dataset.csrf;
    return '';
  }

  function formatTime(isoOrSql) {
    const raw = String(isoOrSql || '').trim();
    if (!raw) return '';
    const d = new Date(raw.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '';
    const now = Date.now();
    const diff = Math.max(0, now - d.getTime());
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'now';
    if (mins < 60) return `${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h`;
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
  }

  function escapeHtml(v) {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function badgeText(n) {
    const x = Math.max(0, parseInt(n, 10) || 0);
    if (!x) return '';
    return x > 99 ? '99+' : String(x);
  }

  function setOpen(panel, overlay, open) {
    if (!panel || !overlay) return;
    panel.hidden = !open;
    overlay.hidden = !open;
    overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
    panel.classList.toggle('is-open', open);
    overlay.classList.toggle('is-open', open);
    document.body.classList.toggle('mc-msgqp-open', open);
  }

  function initSwipeToClose(panel, closeFn) {
    if (!panel) return;
    const isMobile = () => window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
    let startY = 0;
    let startT = 0;
    let dragging = false;

    panel.addEventListener('pointerdown', (e) => {
      if (!isMobile()) return;
      if (e.pointerType === 'mouse') return;
      dragging = true;
      startY = e.clientY;
      startT = Date.now();
      panel.setPointerCapture(e.pointerId);
    });

    panel.addEventListener('pointermove', (e) => {
      if (!dragging || !isMobile()) return;
      const dy = Math.max(0, e.clientY - startY);
      if (dy < 6) return;
      panel.style.transition = 'none';
      panel.style.transform = `translateY(${dy}px)`;
    });

    function end(e) {
      if (!dragging) return;
      dragging = false;
      panel.style.transition = '';
      const dy = Math.max(0, e.clientY - startY);
      const dt = Math.max(1, Date.now() - startT);
      const velocity = dy / dt; // px/ms
      panel.style.transform = '';
      if (dy > 140 || velocity > 0.9) {
        closeFn();
      }
    }

    panel.addEventListener('pointerup', end);
    panel.addEventListener('pointercancel', end);
  }

  function renderConversationCard(item) {
    const unread = Math.max(0, parseInt(item.unread, 10) || 0);
    const badge = badgeText(unread);
    const time = formatTime(item.last_at);
    const cid = escapeHtml(item.consultation_id);
    const archived = Number(item.is_archived) === 1 ? '1' : '0';
    return `
      <div class="mc-msgqp__card" data-msgqp-card data-cid="${cid}" data-archived="${archived}">
        <button type="button" class="mc-msgqp__card-main" data-msgqp-open-thread data-cid="${cid}">
          <div class="mc-msgqp__avatar" aria-hidden="true">
            ${escapeHtml(item.initials || 'MC')}
            <span class="mc-msgqp__dot" data-msgqp-dot></span>
          </div>
          <div class="mc-msgqp__card-text">
            <div class="mc-msgqp__name">${escapeHtml(item.name || '')}</div>
            <div class="mc-msgqp__preview">${escapeHtml(item.preview || '')}</div>
          </div>
          <div class="mc-msgqp__right">
            <div class="mc-msgqp__time">${escapeHtml(time)}</div>
            ${unread > 0 ? `<div class="mc-msgqp__badge">${escapeHtml(badge)}</div>` : ''}
          </div>
        </button>
        <button type="button" class="mc-msgqp__kebab" data-msgqp-kebab data-cid="${cid}" aria-label="Conversation actions" title="More actions">
          <span class="mc-msgqp__kebab-dots" aria-hidden="true"></span>
        </button>
      </div>
    `;
  }

  function renderMessageBubble(msg, currentUserId) {
    const mine = Number(msg.sender_id) === Number(currentUserId);
    const text = escapeHtml(msg.message || '');
    const time = escapeHtml(msg.time || '');
    return `
      <div class="mc-msgqp__bubble ${mine ? 'is-mine' : ''}">
        <div>
          <div class="mc-msgqp__bubbletext">${text}</div>
          <div class="mc-msgqp__bubbletime" style="${mine ? 'text-align:right' : ''}">${time}</div>
        </div>
      </div>
    `;
  }

  function init() {
    const panel = document.querySelector(PANEL_SELECTOR);
    const overlay = document.querySelector(OVERLAY_SELECTOR);
    const fab = document.querySelector(FAB_SELECTOR);
    if (!panel || !overlay || !fab) return;

    // Prevent duplicate bindings if this script is loaded twice.
    if (fab.dataset.msgqpBound === '1') return;
    fab.dataset.msgqpBound = '1';

    const listEl = panel.querySelector('[data-msgqp-items]');
    const loadingEl = panel.querySelector('[data-msgqp-loading]');
    const emptyEl = panel.querySelector('[data-msgqp-empty]');
    const unreadEl = panel.querySelector('[data-msgqp-unread]');
    const closeBtn = panel.querySelector('[data-msgqp-close]');

    const listSection = panel.querySelector('[data-msgqp-list]');
    const threadSection = panel.querySelector('[data-msgqp-thread]');
    const backBtn = panel.querySelector('[data-msgqp-back]');
    const peerNameEl = panel.querySelector('[data-msgqp-peername]');
    const messagesEl = panel.querySelector('[data-msgqp-messages]');
    const composer = panel.querySelector('[data-msgqp-composer]');
    const input = panel.querySelector('[data-msgqp-input]');
    const sendBtn = panel.querySelector('[data-msgqp-send]');

    const assetBase = getAssetBase();
    const csrf = getCsrf();
    const currentUserId = Number(panel.dataset.userId || 0) || 0;

    let isOpen = false;
    let pollTimer = null;
    let activeCid = 0;
    let conversations = [];
    let activeBox = 'inbox';
    let menuRoot = null;
    let activeMenuKebab = null;

    function ensureMenuRoot() {
      if (menuRoot) return menuRoot;
      menuRoot = document.createElement('div');
      menuRoot.className = 'mc-msgqp-menu-root';
      menuRoot.setAttribute('data-msgqp-menu-root', '1');
      menuRoot.hidden = true;
      document.body.appendChild(menuRoot);
      return menuRoot;
    }

    function closeCardMenu() {
      if (!menuRoot) return;
      menuRoot.hidden = true;
      menuRoot.innerHTML = '';
      activeMenuKebab = null;
    }

    function buildCardMenuHtml(isArchived) {
      const archiveLabel = isArchived ? 'Restore' : 'Archive';
      const archiveAction = isArchived ? 'restore' : 'archive';
      return (
        '<div class="mc-msgqp-menu" role="menu" aria-label="Conversation actions">' +
          '<button type="button" class="mc-msgqp-menu__item" role="menuitem" data-msgqp-menu-action="open">Open</button>' +
          '<button type="button" class="mc-msgqp-menu__item" role="menuitem" data-msgqp-menu-action="' + archiveAction + '">' + archiveLabel + '</button>' +
          '<button type="button" class="mc-msgqp-menu__item mc-msgqp-menu__item--danger" role="menuitem" data-msgqp-menu-action="delete">Delete</button>' +
        '</div>'
      );
    }

    function positionCardMenu(root, anchor) {
      const rect = anchor.getBoundingClientRect();
      root.hidden = false;
      const menu = root.querySelector('.mc-msgqp-menu');
      if (!menu) return;
      const margin = 8;
      const menuRect = menu.getBoundingClientRect();
      let left = rect.right - menuRect.width;
      let top = rect.bottom + 6;
      left = Math.max(margin, Math.min(left, window.innerWidth - menuRect.width - margin));
      top = Math.max(margin, Math.min(top, window.innerHeight - menuRect.height - margin));
      root.style.left = left + 'px';
      root.style.top = top + 'px';
    }

    function openCardMenu(kebab, cid) {
      const root = ensureMenuRoot();
      const card = kebab.closest('[data-msgqp-card]');
      const isArchived = card && String(card.getAttribute('data-archived') || '0') === '1';

      if (activeMenuKebab === kebab && !root.hidden) {
        closeCardMenu();
        return;
      }

      activeMenuKebab = kebab;
      root.innerHTML = buildCardMenuHtml(isArchived);
      positionCardMenu(root, kebab);

      root.querySelectorAll('[data-msgqp-menu-action]').forEach((btn) => {
        btn.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          const action = String(btn.getAttribute('data-msgqp-menu-action') || '');
          closeCardMenu();
          if (action === 'open') openThread(cid);
          else threadAction(cid, action);
        });
      });
    }

    function syncGlobalUnread(count) {
      const n = Math.max(0, parseInt(count, 10) || 0);
      if (window.MedConnectUnreadService) {
        window.MedConnectUnreadService.setUnread(n, 'quick-panel');
      } else {
        window.dispatchEvent(new CustomEvent('medconnect:messages-unread', {
          detail: { unread_count: n, source: 'quick-panel' },
        }));
      }
      if (window.MedConnectMessagesFab && window.MedConnectMessagesFab.applyUnread) {
        window.MedConnectMessagesFab.applyUnread(n);
      }
      return n;
    }

    function setUnreadHeader(total) {
      const n = Math.max(0, parseInt(total, 10) || 0);
      if (!unreadEl) return;
      if (n <= 0) {
        unreadEl.hidden = true;
        unreadEl.textContent = '0 Unread';
        unreadEl.setAttribute('aria-hidden', 'true');
        return;
      }
      unreadEl.hidden = false;
      unreadEl.textContent = `${n} Unread`;
      unreadEl.setAttribute('aria-hidden', 'false');
    }

    function setThreadView(inThread) {
      panel.classList.toggle('is-thread-view', !!inThread);
      if (listSection) listSection.hidden = false;
      if (threadSection) threadSection.hidden = !inThread;
    }

    function clearConversationUnreadLocal(cid) {
      const id = Number(cid) || 0;
      if (!id) return;
      const item = conversations.find((c) => Number(c.consultation_id) === id);
      if (item) item.unread = 0;
      const card = listEl && listEl.querySelector(`[data-msgqp-card][data-cid="${id}"]`);
      if (card) {
        const badge = card.querySelector('.mc-msgqp__badge');
        if (badge) badge.remove();
      }
    }

    async function markThreadRead(cid) {
      if (!cid || !csrf) return null;
      try {
        const body = new URLSearchParams({
          consultation_id: String(cid),
          csrf_token: csrf,
        });
        const res = await fetch(assetBase + API_MARK_READ, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
          body,
        });
        const data = await res.json();
        if (data && data.success) {
          clearConversationUnreadLocal(cid);
          if (typeof data.unread_count !== 'undefined') {
            const n = syncGlobalUnread(data.unread_count);
            setUnreadHeader(n);
          }
        }
        return data;
      } catch (_) {
        return null;
      }
    }

    async function loadConversations() {
      if (!listEl) return;
      if (loadingEl) loadingEl.hidden = false;
      if (emptyEl) emptyEl.hidden = true;
      try {
        const res = await fetch(assetBase + API_CONV + '?limit=15&box=' + encodeURIComponent(activeBox) + '&_=' + Date.now(), {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        if (!data || !data.success) throw new Error('bad');

        conversations = Array.isArray(data.items) ? data.items : [];
        const totalUnread = syncGlobalUnread(data.unread_count || 0);
        setUnreadHeader(totalUnread);

        if (!conversations.length) {
          listEl.innerHTML = '';
          if (emptyEl) emptyEl.hidden = false;
        } else {
          // Move unread / latest to top (server already sorts by last_at, but keep stable)
          listEl.innerHTML = conversations.map(renderConversationCard).join('');
        }
      } catch (_) {
        // leave old UI as-is
      } finally {
        if (loadingEl) loadingEl.hidden = true;
      }
    }

    function syncPanelToFab() {
      if (window.MedConnectMessagesFab && typeof window.MedConnectMessagesFab.syncQuickPanel === 'function') {
        window.MedConnectMessagesFab.syncQuickPanel();
      }
    }

    function open() {
      if (isOpen) return;
      isOpen = true;
      activeCid = 0;
      setThreadView(false);
      setOpen(panel, overlay, true);
      syncPanelToFab();
      loadConversations();
      pollTimer = window.setInterval(loadConversations, POLL_CONV_MS);
    }

    function close() {
      if (!isOpen) return;
      isOpen = false;
      closeCardMenu();
      setOpen(panel, overlay, false);
      if (pollTimer) window.clearInterval(pollTimer);
      pollTimer = null;
      activeCid = 0;
      setThreadView(false);
    }

    // Expose a small public API so the FAB can always open it,
    // even if another script needs to trigger it.
    window.MedConnectQuickMessagesPanel = {
      open,
      close,
      toggle() { isOpen ? close() : open(); },
      isOpen() { return isOpen; },
    };

    async function openThread(cid) {
      activeCid = Number(cid) || 0;
      const item = conversations.find((c) => Number(c.consultation_id) === activeCid);
      if (peerNameEl) peerNameEl.textContent = item ? item.name : 'Conversation';
      if (messagesEl) messagesEl.innerHTML = '';

      setThreadView(true);

      try {
        const [listRes] = await Promise.all([
          fetch(assetBase + API_LIST + '?consultation_id=' + encodeURIComponent(activeCid) + '&_=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
          }),
          markThreadRead(activeCid),
        ]);
        const data = await listRes.json();
        if (!data || !data.success) return;

        const msgs = Array.isArray(data.messages) ? data.messages : [];
        if (messagesEl) {
          messagesEl.innerHTML = msgs.map((m) => renderMessageBubble(m, currentUserId)).join('');
          messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        if (typeof data.unread_count !== 'undefined') {
          const n = syncGlobalUnread(data.unread_count);
          setUnreadHeader(n);
        }

        window.setTimeout(loadConversations, 300);
      } catch (_) {}
    }

    function showListView() {
      activeCid = 0;
      setThreadView(false);
      loadConversations();
    }

    async function sendMessage(text) {
      const message = String(text || '').trim();
      if (!activeCid || !message) return;
      if (!sendBtn) return;

      sendBtn.disabled = true;
      try {
        const body = new URLSearchParams({
          consultation_id: String(activeCid),
          message,
          csrf_token: csrf,
        });
        const res = await fetch(assetBase + API_SEND, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
          body,
        });
        const data = await res.json();
        if (!data || !data.success) return;

        if (input) input.value = '';
        // Append locally
        if (messagesEl && data.data) {
          messagesEl.insertAdjacentHTML('beforeend', renderMessageBubble(data.data, currentUserId));
          messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        // Update list ordering + unread (sending doesn't increase unread for self)
        window.setTimeout(loadConversations, 200);
      } catch (_) {
        /* ignore */
      } finally {
        sendBtn.disabled = false;
      }
    }

    // FAB toggles panel (prevent navigation)
    fab.addEventListener('click', (e) => {
      if (fab.dataset.suppressClick === '1') {
        e.preventDefault();
        return;
      }
      e.preventDefault();
      isOpen ? close() : open();
    });

    window.addEventListener('medconnect:fab-moved', () => {
      if (isOpen) syncPanelToFab();
    });

    // Filter tabs
    panel.querySelectorAll('[data-msgqp-filter]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const next = String(btn.getAttribute('data-msgqp-filter') || 'inbox');
        activeBox = (next === 'archived' || next === 'all') ? next : 'inbox';
        panel.querySelectorAll('[data-msgqp-filter]').forEach((b) => {
          const isActive = String(b.getAttribute('data-msgqp-filter')) === activeBox;
          b.classList.toggle('is-active', isActive);
          b.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        loadConversations();
      });
    });

    async function threadAction(consultationId, action) {
      const body = new URLSearchParams({
        consultation_id: String(consultationId),
        action: String(action),
        csrf_token: csrf,
      });
      const res = await fetch(assetBase + API_THREAD_ACTION, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body,
      });
      const data = await res.json().catch(() => null);
        if (data && data.success) {
          if (typeof data.unread_count !== 'undefined') {
            const n = syncGlobalUnread(data.unread_count);
            setUnreadHeader(n);
          }
          loadConversations();
        }
    }

    // Close actions
    if (closeBtn) closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', close);
    document.addEventListener('keydown', (e) => {
      if (!isOpen) return;
      if (e.key === 'Escape') {
        if (menuRoot && !menuRoot.hidden) {
          closeCardMenu();
          return;
        }
        close();
      }
    });

    document.addEventListener('click', (e) => {
      if (!menuRoot || menuRoot.hidden) return;
      const inside = menuRoot.contains(e.target);
      const kebab = e.target && e.target.closest ? e.target.closest('[data-msgqp-kebab]') : null;
      if (!inside && !kebab) closeCardMenu();
    });

    window.addEventListener('resize', closeCardMenu);
    window.addEventListener('scroll', closeCardMenu, true);

    if (backBtn) backBtn.addEventListener('click', showListView);

    // Delegate open thread
    panel.addEventListener('click', (e) => {
      const kebab = e.target && e.target.closest ? e.target.closest('[data-msgqp-kebab]') : null;
      if (kebab) {
        e.preventDefault();
        e.stopPropagation();
        const cid = Number(kebab.getAttribute('data-cid') || 0) || 0;
        if (cid) openCardMenu(kebab, cid);
        return;
      }
      const btn = e.target && e.target.closest ? e.target.closest('[data-msgqp-open-thread]') : null;
      if (!btn) return;
      const cid = btn.getAttribute('data-cid');
      if (cid) openThread(cid);
    });

    // Composer submit
    if (composer) {
      composer.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!input) return;
        sendMessage(input.value);
      });
    }

    initSwipeToClose(panel, close);

    // Keep header unread in sync with global unread polling
    window.addEventListener('medconnect:messages-unread', (event) => {
      if (event && event.detail && typeof event.detail.unread_count !== 'undefined') {
        setUnreadHeader(event.detail.unread_count);
        if (isOpen && !activeCid) window.setTimeout(loadConversations, 250);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

