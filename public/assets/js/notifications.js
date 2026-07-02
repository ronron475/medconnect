/**
 * MedConnect Notification Center — real-time bell, dropdown, polling
 */
(function () {
  'use strict';

  const POLL_INTERVAL = 30000;

  let lastId = 0;
  let pollTimer = null;
  let panelOpen = false;

  const IS_TOUCH = ('ontouchstart' in window) || (navigator.maxTouchPoints && navigator.maxTouchPoints > 0);

  function closeAllNotifPanels() {
    document.querySelectorAll('[data-notif-panel].is-open').forEach(function (panel) {
      panel.classList.remove('is-open');
    });
    document.querySelectorAll('[data-notif-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-expanded', 'false');
    });
    panelOpen = false;
  }

  function followNotifLink(link, e) {
    if (!link) return;
    const href = (link.getAttribute('href') || '').trim();
    if (!href || href === '#') {
      if (e) e.preventDefault();
      return;
    }
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    closeAllNotifPanels();
    window.location.assign(link.href);
  }

  function csrf() {
    return document.body.dataset.csrf
      || (document.querySelector('[data-csrf]') && document.querySelector('[data-csrf]').dataset.csrf)
      || '';
  }

  function assetBase() {
    return document.body.dataset.assetBase
      || (document.querySelector('[data-asset-base]') && document.querySelector('[data-asset-base]').dataset.assetBase)
      || '';
  }

  const API_BASE = assetBase() + '/app/api/notifications/';

  function iconSvg(name) {
    const icons = {
      bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
      'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
      'alert-triangle': '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
      'alert-octagon': '<polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
      clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
      shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
      'heart-pulse': '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
      calendar: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
      video: '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
      'share-2': '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
      'map-pin': '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
      settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
      'user-plus': '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
    };
    const paths = icons[name] || icons.bell;
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
  }

  function priorityClass(priority) {
    if (priority === 'emergency' || priority === 'critical') return 'mc-notif-icon--emergency';
    if (priority === 'high') return 'mc-notif-icon--warning';
    if (priority === 'low') return '';
    return '';
  }

  function renderItemBody(n) {
    const priClass = n.priority && n.priority !== 'normal' && n.priority !== 'low'
      ? ' mc-notif-priority mc-notif-priority--' + n.priority : '';
    return (
      '<div class="mc-notif-icon ' + priorityClass(n.priority) + '">' + iconSvg(n.icon || 'bell') + '</div>' +
      '<div class="mc-notif-body">' +
        '<p class="mc-notif-title">' + escapeHtml(n.title) + '</p>' +
        '<p class="mc-notif-message">' + escapeHtml(n.message) + '</p>' +
        '<div class="mc-notif-meta">' +
          '<span>' + escapeHtml(n.time_ago || n.date_label || '') + '</span>' +
          (priClass ? '<span class="' + priClass.trim() + '">' + escapeHtml(n.priority) + '</span>' : '') +
          (!n.is_read ? '<span>Unread</span>' : '') +
        '</div>' +
      '</div>'
    );
  }

  function renderItemActions(n) {
    const readAction = n.is_read ? 'unread' : 'read';
    const readLabel = n.is_read ? 'Mark unread' : 'Mark read';
    const readIcon = n.is_read ? '○' : '✓';
    return (
      '<div class="mc-notif-item-actions">' +
        '<button type="button" data-action="' + readAction + '" title="' + readLabel + '" aria-label="' + readLabel + '">' + readIcon + '</button>' +
        '<button type="button" data-action="delete" title="Delete" aria-label="Delete notification">×</button>' +
      '</div>'
    );
  }

  function renderItem(n, withActions) {
    const unread = !n.is_read ? ' is-unread' : '';
    const url = n.action_url || n.link || '#';
    const body = renderItemBody(n);
    if (withActions) {
      return (
        '<div class="mc-notif-item' + unread + '" data-id="' + n.notification_id + '" role="menuitem">' +
          '<a class="mc-notif-item-link" href="' + escapeAttr(url) + '">' + body + '</a>' +
          renderItemActions(n) +
        '</div>'
      );
    }
    return (
      '<a class="mc-notif-item' + unread + '" href="' + escapeAttr(url) + '" data-id="' + n.notification_id + '" role="menuitem">' +
        body +
      '</a>'
    );
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function escapeAttr(s) {
    return String(s || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function updateBadge(count) {
    document.querySelectorAll('[data-notif-badge]').forEach(function (el) {
      el.textContent = count > 99 ? '99+' : String(count);
      el.dataset.count = String(count);
      el.setAttribute('aria-label', count + ' unread notifications');
    });
    document.querySelectorAll('.pd-notif-dot').forEach(function (el) {
      el.style.display = count > 0 ? 'block' : 'none';
    });
  }

  function renderList(items, withActions) {
    document.querySelectorAll('[data-notif-list]').forEach(function (list) {
      if (!items || !items.length) {
        list.innerHTML = '<div class="mc-notif-empty">No notifications</div>';
        return;
      }
      const showActions = withActions !== undefined ? withActions : list.closest('[data-notif-panel]') !== null;
      list.innerHTML = items.map(function (n) { return renderItem(n, showActions); }).join('');
      bindListActions(list);
    });
  }

  function bindListActions(list) {
    list.querySelectorAll('.mc-notif-item-link').forEach(function (link) {
      link.addEventListener('click', function (e) {
        const item = link.closest('[data-id]');
        if (item) markRead(parseInt(item.dataset.id, 10));
        followNotifLink(link, e);
      });
    });

    list.querySelectorAll('[data-action]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const item = btn.closest('[data-id]');
        if (!item) return;
        handleItemAction(parseInt(item.dataset.id, 10), btn.dataset.action);
      });
    });

    // Whole-row tap target for mobile (avoids missing the inner link).
    list.querySelectorAll('.mc-notif-item[data-id]').forEach(function (row) {
      row.addEventListener('click', function (e) {
        if (e.target.closest('[data-action]') || e.target.closest('.mc-notif-item-link')) return;
        const link = row.querySelector('.mc-notif-item-link');
        if (!link) return;
        if (itemId(row)) markRead(itemId(row));
        followNotifLink(link, e);
      });
    });
  }

  function itemId(row) {
    return parseInt(row.dataset.id, 10) || 0;
  }

  async function handleItemAction(id, action) {
    if (!id || !action) return;
    try {
      const fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('notification_id', String(id));
      if (action === 'delete') {
        fd.append('action', 'delete');
        await fetch(API_BASE + 'manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      } else {
        fd.append('action', action);
        const res = await fetch(API_BASE + 'mark_read.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data.success) updateBadge(data.unread_count || 0);
      }
      loadDropdown();
    } catch (e) { /* silent */ }
  }

  async function fetchJson(url) {
    const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return res.json();
  }

  async function loadDropdown() {
    try {
      const data = await fetchJson(API_BASE + 'list.php?limit=10');
      if (data.success) {
        renderList(data.notifications);
        updateBadge(data.unread_count || 0);
        if (data.notifications.length) {
          lastId = Math.max(lastId, ...data.notifications.map(function (n) { return n.notification_id; }));
        }
      }
    } catch (e) { /* silent */ }
  }

  async function poll() {
    try {
      const url = API_BASE + 'poll.php' + (lastId ? '?since_id=' + lastId : '');
      const data = await fetchJson(url);
      if (data.success) {
        updateBadge(data.unread_count || 0);
        if (data.last_id) lastId = Math.max(lastId, data.last_id);
        if (panelOpen && data.notifications && data.notifications.length) {
          loadDropdown();
        }
      }
    } catch (e) { /* silent */ }
  }

  async function markRead(id) {
    if (!id) return;
    try {
      const fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('notification_id', String(id));
      const res = await fetch(API_BASE + 'mark_read.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) updateBadge(data.unread_count || 0);
    } catch (e) { /* silent */ }
  }

  async function markAllRead() {
    try {
      const fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('action', 'all');
      const res = await fetch(API_BASE + 'mark_read.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) {
        updateBadge(0);
        loadDropdown();
      }
    } catch (e) { /* silent */ }
  }

  function togglePanel(btn, panel) {
    const open = !panel.classList.contains('is-open');
    panel.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    panelOpen = open;
    if (open) loadDropdown();
  }

  function initBell(wrap) {
    const btn = wrap.querySelector('[data-notif-toggle]');
    const panel = wrap.querySelector('[data-notif-panel]');
    if (!btn || !panel) return;

    const TOGGLE_EVENT = IS_TOUCH ? 'pointerup' : 'click';

    btn.style.pointerEvents = 'auto';
    btn.addEventListener(TOGGLE_EVENT, function (e) {
      e.preventDefault();
      e.stopPropagation();
      togglePanel(btn, panel);
    });
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
    });

    const markAllBtn = wrap.querySelector('[data-notif-mark-all]');
    if (markAllBtn) {
      markAllBtn.addEventListener(TOGGLE_EVENT, function (e) {
        e.preventDefault();
        markAllRead();
      });
    }

    const CLOSE_EVENT = IS_TOUCH ? 'pointerdown' : 'click';
    document.addEventListener(CLOSE_EVENT, function (e) {
      if (!wrap.contains(e.target)) {
        panel.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        panelOpen = false;
      }
    }, true);

    btn.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        panel.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        panelOpen = false;
      }
    });
  }

  function initPage() {
    const page = document.querySelector('[data-notif-page]');
    if (!page) return;

    let currentPage = 1;
    const listEl = page.querySelector('[data-notif-page-list]');
    const searchEl = page.querySelector('[data-notif-search]');
    const typeEl = page.querySelector('[data-notif-type]');
    const statusEl = page.querySelector('[data-notif-status]');
    const paginationEl = page.querySelector('[data-notif-pagination]');

    async function loadPage(pageNum) {
      currentPage = pageNum;
      const params = new URLSearchParams({ page: String(pageNum), limit: '15' });
      if (searchEl && searchEl.value.trim()) params.set('search', searchEl.value.trim());
      if (typeEl && typeEl.value) params.set('type', typeEl.value);
      if (statusEl && statusEl.value === 'unread') params.set('unread_only', '1');
      if (statusEl && statusEl.value === 'archived') params.set('status', 'archived');

      const data = await fetchJson(API_BASE + 'list.php?' + params.toString());
      if (!data.success || !listEl) return;

      if (!data.notifications.length) {
        listEl.innerHTML = '<div class="mc-notif-empty">No notifications found</div>';
      } else {
        listEl.innerHTML = data.notifications.map(function (n) {
          return (
            '<div class="mc-notif-item' + (!n.is_read ? ' is-unread' : '') + '" data-id="' + n.notification_id + '">' +
              '<a class="mc-notif-item-link" href="' + escapeAttr(n.action_url || n.link || '#') + '">' +
                renderItemBody(n) +
              '</a>' +
              '<div class="mc-notif-page-actions">' +
                '<button type="button" data-action="' + (n.is_read ? 'unread' : 'read') + '" title="' + (n.is_read ? 'Mark unread' : 'Mark read') + '" aria-label="' + (n.is_read ? 'Mark as unread' : 'Mark as read') + '">' + (n.is_read ? '○' : '✓') + '</button>' +
                '<button type="button" data-action="archive" title="Archive" aria-label="Archive">⊘</button>' +
                '<button type="button" data-action="delete" title="Delete" aria-label="Delete">×</button>' +
              '</div>' +
            '</div>'
          );
        }).join('');
      }

      if (paginationEl && data.pagination) {
        const p = data.pagination;
        paginationEl.innerHTML =
          '<button type="button" data-page="' + (p.page - 1) + '" ' + (p.page <= 1 ? 'disabled' : '') + '>Previous</button>' +
          '<span>Page ' + p.page + ' of ' + p.total_pages + '</span>' +
          '<button type="button" data-page="' + (p.page + 1) + '" ' + (p.page >= p.total_pages ? 'disabled' : '') + '>Next</button>';
      }
      updateBadge(data.unread_count || 0);
    }

    page.addEventListener('click', async function (e) {
      const pagBtn = e.target.closest('[data-page]');
      if (pagBtn && !pagBtn.disabled) {
        loadPage(parseInt(pagBtn.dataset.page, 10));
        return;
      }
      const actionBtn = e.target.closest('[data-action]');
      if (actionBtn) {
        const row = actionBtn.closest('[data-id]');
        if (!row) return;
        const id = row.dataset.id;
        const action = actionBtn.dataset.action;
        const fd = new FormData();
        fd.append('csrf_token', csrf());
        if (action === 'read' || action === 'unread') {
          fd.append('notification_id', id);
          fd.append('action', action);
          const res = await fetch(API_BASE + 'mark_read.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const data = await res.json();
          if (data.success) updateBadge(data.unread_count || 0);
        } else {
          fd.append('notification_id', id);
          fd.append('action', action === 'delete' ? 'delete' : 'archive');
          await fetch(API_BASE + 'manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        }
        loadPage(currentPage);
      }
      const item = e.target.closest('.mc-notif-item[data-id]');
      if (item && !e.target.closest('[data-action]')) {
        const link = item.querySelector('.mc-notif-item-link') || item.querySelector('a');
        if (link) {
          if (itemId(item)) markRead(itemId(item));
          followNotifLink(link, e);
        }
      }
    });

    [searchEl, typeEl, statusEl].forEach(function (el) {
      if (el) el.addEventListener('change', function () { loadPage(1); });
    });
    if (searchEl) {
      let debounce;
      searchEl.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { loadPage(1); }, 350);
      });
    }

    loadPage(1);
  }

  function initWidgets() {
    const containers = document.querySelectorAll('[data-notif-widgets]');
    if (!containers.length) return;

    fetchJson(API_BASE + 'widgets.php').then(function (data) {
      if (!data.success || !data.widgets) return;
      const w = data.widgets;
      containers.forEach(function (container) {
        container.querySelectorAll('[data-widget]').forEach(function (el) {
          const key = el.dataset.widget;
          if (key === 'recent') {
            const list = el.querySelector('[data-notif-list]') || container.querySelector('[data-notif-list]');
            if (list) renderList(w.recent_notifications || [], false);
          } else if (w[key] !== undefined) {
            const val = el.querySelector('.mc-notif-widget-value');
            if (val) val.textContent = w[key];
          }
        });
      });
    }).catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-notif-wrap]').forEach(initBell);
    initPage();
    initWidgets();
    loadDropdown();
    pollTimer = setInterval(poll, POLL_INTERVAL);
  });

  window.MedConnectNotifications = {
    refresh: loadDropdown,
    poll: poll,
    markAllRead: markAllRead,
  };
})();
