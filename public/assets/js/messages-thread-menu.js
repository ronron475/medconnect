/**
 * MedConnect — Messages thread actions dropdown (Archive/Restore/Delete)
 * Used on patient/messages.php and provider/messages.php
 */
(function () {
  'use strict';

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function getBox() {
    const url = new URL(window.location.href);
    const box = String(url.searchParams.get('box') || 'inbox').toLowerCase();
    return (box === 'archived' || box === 'all') ? box : 'inbox';
  }

  function escapeHtml(v) {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function buildMenuHtml(isArchived) {
    const archiveLabel = isArchived ? 'Restore' : 'Archive';
    const archiveAction = isArchived ? 'restore' : 'archive';
    return (
      '<div class="mc-threadmenu" role="menu" aria-label="Conversation actions">' +
        '<button type="button" class="mc-threadmenu__item" role="menuitem" data-action="open">Open</button>' +
        '<button type="button" class="mc-threadmenu__item" role="menuitem" data-action="' + escapeHtml(archiveAction) + '">' + escapeHtml(archiveLabel) + '</button>' +
        '<button type="button" class="mc-threadmenu__item mc-threadmenu__item--danger" role="menuitem" data-action="delete">Delete</button>' +
      '</div>'
    );
  }

  function ensureMenuRoot() {
    let root = qs('[data-threadmenu-root]');
    if (root) return root;
    root = document.createElement('div');
    root.setAttribute('data-threadmenu-root', '1');
    root.style.position = 'fixed';
    root.style.zIndex = '100050';
    root.hidden = true;
    document.body.appendChild(root);
    return root;
  }

  function ensureConfirmRoot() {
    let overlay = qs('[data-mc-confirm-overlay]');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'mc-confirm-overlay';
    overlay.setAttribute('data-mc-confirm-overlay', '1');
    overlay.hidden = true;
    document.body.appendChild(overlay);
    return overlay;
  }

  function ensureToastRoot() {
    let el = qs('[data-mc-toast-root]');
    if (el) return el;
    el = document.createElement('div');
    el.className = 'mc-toast-root';
    el.setAttribute('data-mc-toast-root', '1');
    document.body.appendChild(el);
    return el;
  }

  let activeToastTimer = null;
  function showUndoToast({ message, undoLabel, onUndo, timeoutMs = 6000 }) {
    const root = ensureToastRoot();
    root.innerHTML =
      '<div class="mc-toast" role="status" aria-live="polite">' +
        '<div class="mc-toast__msg">' + escapeHtml(message) + '</div>' +
        '<div class="mc-toast__actions">' +
          '<button type="button" class="mc-toast__btn" data-toast-undo>' + escapeHtml(undoLabel || 'Undo') + '</button>' +
          '<button type="button" class="mc-toast__btn mc-toast__btn--ghost" data-toast-close aria-label="Close">×</button>' +
        '</div>' +
      '</div>';

    const toast = qs('.mc-toast', root);
    if (!toast) return;
    toast.classList.add('is-open');

    function clear() {
      if (activeToastTimer) {
        window.clearTimeout(activeToastTimer);
        activeToastTimer = null;
      }
      root.innerHTML = '';
    }

    const undoBtn = qs('[data-toast-undo]', root);
    const closeBtn = qs('[data-toast-close]', root);
    if (undoBtn) {
      undoBtn.addEventListener('click', async () => {
        try { await (onUndo && onUndo()); } catch (_) {}
        clear();
      }, { once: true });
    }
    if (closeBtn) closeBtn.addEventListener('click', clear, { once: true });

    activeToastTimer = window.setTimeout(clear, Math.max(2500, timeoutMs));
  }

  function animateReinsertRow(row) {
    if (!row || !row.classList) return;
    row.classList.remove('mc-row-reinsert');
    // Force reflow so the animation re-triggers reliably.
    void row.offsetHeight;
    row.classList.add('mc-row-reinsert');
    window.setTimeout(() => {
      try { row.classList.remove('mc-row-reinsert'); } catch (_) {}
    }, 300);
  }

  function confirmDelete({ title, description }) {
    if (global.McModal && typeof global.McModal.confirm === 'function') {
      return global.McModal.confirm({
        title: title || 'Delete conversation',
        message: description || 'This action cannot be undone.',
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        danger: true,
        showLogo: false,
        icon: 'warning',
      });
    }

    const overlay = ensureConfirmRoot();
    return new Promise((resolve) => {
      overlay.innerHTML =
        '<div class="mc-confirm" role="dialog" aria-modal="true" aria-label="' + escapeHtml(title) + '">' +
          '<h3 class="mc-confirm__title">' + escapeHtml(title) + '</h3>' +
          '<p class="mc-confirm__desc">' + escapeHtml(description) + '</p>' +
          '<div class="mc-confirm__actions">' +
            '<button type="button" class="mc-confirm__btn" data-confirm-cancel>Cancel</button>' +
            '<button type="button" class="mc-confirm__btn mc-confirm__btn--danger" data-confirm-ok>Delete</button>' +
          '</div>' +
        '</div>';

      function close(result) {
        overlay.hidden = true;
        overlay.innerHTML = '';
        document.removeEventListener('keydown', onKey);
        resolve(result);
      }

      function onKey(e) {
        if (e.key === 'Escape') close(false);
      }

      overlay.hidden = false;
      const cancelBtn = qs('[data-confirm-cancel]', overlay);
      const okBtn = qs('[data-confirm-ok]', overlay);
      if (cancelBtn) cancelBtn.focus();

      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close(false);
      }, { once: true });

      if (cancelBtn) cancelBtn.addEventListener('click', () => close(false), { once: true });
      if (okBtn) okBtn.addEventListener('click', () => close(true), { once: true });
      document.addEventListener('keydown', onKey);
    });
  }

  function positionMenu(root, anchorEl) {
    const a = anchorEl.getBoundingClientRect();
    const margin = 8;
    // Show first so we can measure
    root.hidden = false;
    const m = root.getBoundingClientRect();
    let left = a.right - m.width;
    let top = a.bottom + 8;
    left = Math.max(margin, Math.min(left, window.innerWidth - m.width - margin));
    top = Math.max(margin, Math.min(top, window.innerHeight - m.height - margin));
    root.style.left = left + 'px';
    root.style.top = top + 'px';
  }

  function init() {
    const threadActionUrl = window.MedConnectThreadActionUrl;
    const csrfToken = window.MedConnectCsrfToken;
    if (!threadActionUrl || !csrfToken) return;

    const root = ensureMenuRoot();
    let activeKebab = null;
    let activeRow = null;

    function closeMenu() {
      root.hidden = true;
      root.innerHTML = '';
      activeKebab = null;
      activeRow = null;
    }

    async function postThreadAction(consultationId, action) {
      const fd = new URLSearchParams({
        consultation_id: String(consultationId || 0),
        action: String(action || ''),
        csrf_token: csrfToken,
      });
      const res = await fetch(threadActionUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: fd,
      });
      return await res.json();
    }

    function removeRow(row) {
      if (!row) return;
      const next = row.nextElementSibling;
      const prev = row.previousElementSibling;
      row.remove();
      // If removed row was active, try to activate a neighbor.
      if (row.classList.contains('active')) {
        const fallback = (next && next.classList && next.classList.contains('msg-item')) ? next : prev;
        if (fallback && fallback.click) fallback.click();
      }
    }

    function getListScrollContainer(row) {
      if (!row) return null;
      // Patient uses ".msg-list" (div), provider uses "#messageList" (div)
      const list = row.closest ? row.closest('.msg-list') : null;
      return list || (row.parentElement || null);
    }

    function restoreScroll(container, scrollTop) {
      if (!container || typeof scrollTop !== 'number') return;
      // Wait for DOM to settle
      requestAnimationFrame(() => {
        try { container.scrollTop = scrollTop; } catch (_) {}
      });
    }

    async function handleAction(action) {
      if (!activeRow) return;
      const cid = Number(activeRow.getAttribute('data-consultation-id') || 0) || 0;
      if (!cid) return;

      if (action === 'open') {
        activeRow.click();
        closeMenu();
        return;
      }

      if (action === 'delete') {
        const ok = await confirmDelete({
          title: 'Delete conversation?',
          description: 'This will hide the conversation from your inbox. Messages are not permanently removed.',
        });
        if (!ok) {
          closeMenu();
          return;
        }
      }

      try {
        // Optimistic UI: disable menu
        qsa('.mc-threadmenu__item', root).forEach((b) => { b.disabled = true; });
        activeRow.setAttribute('data-thread-busy', '1');

        // Capture UI state for perfect undo
        const rowForUndo = activeRow;
        const rowParent = rowForUndo && rowForUndo.parentElement ? rowForUndo.parentElement : null;
        const rowNext = rowForUndo ? rowForUndo.nextElementSibling : null;
        const rowWasActive = !!(rowForUndo && rowForUndo.classList && rowForUndo.classList.contains('active'));
        const scrollContainer = getListScrollContainer(rowForUndo);
        const scrollTop = scrollContainer ? scrollContainer.scrollTop : 0;

        const json = await postThreadAction(cid, action);
        if (!json || !json.success) {
          closeMenu();
          return;
        }

        const box = getBox();
        const nowArchived = (action === 'archive') ? '1' : (action === 'restore' ? '0' : activeRow.getAttribute('data-archived') || '0');

        const removedRow = (action === 'delete')
          || (action === 'archive' && box === 'inbox')
          || (action === 'restore' && box === 'archived');

        if (removedRow) {
          removeRow(rowForUndo);
        } else {
          // all box: keep row but update state
          rowForUndo.setAttribute('data-archived', nowArchived);
        }
        restoreScroll(scrollContainer, scrollTop);

        // Sync global unread badges everywhere (service -> multi-tab)
        if (typeof json.unread_count !== 'undefined') {
          if (window.MedConnectUnreadService) {
            window.MedConnectUnreadService.setUnread(json.unread_count, 'thread-menu');
          } else {
            window.dispatchEvent(new CustomEvent('medconnect:messages-unread', { detail: { unread_count: json.unread_count, source: 'thread-menu' } }));
          }
        }

        // Undo toast
        if (action === 'delete') {
          showUndoToast({
            message: 'Conversation deleted.',
            undoLabel: 'Undo',
            onUndo: async () => {
              const undoJson = await postThreadAction(cid, 'undelete');
              if (undoJson && undoJson.success) {
                if (rowParent && rowForUndo) {
                  if (rowNext && rowNext.parentElement === rowParent) rowParent.insertBefore(rowForUndo, rowNext);
                  else rowParent.appendChild(rowForUndo);
                }
                rowForUndo.removeAttribute('data-thread-busy');
                animateReinsertRow(rowForUndo);
                restoreScroll(scrollContainer, scrollTop);
                if (rowWasActive && rowForUndo && rowForUndo.click) rowForUndo.click();
                if (typeof undoJson.unread_count !== 'undefined') {
                  if (window.MedConnectUnreadService) {
                    window.MedConnectUnreadService.setUnread(undoJson.unread_count, 'thread-menu');
                  } else {
                    window.dispatchEvent(new CustomEvent('medconnect:messages-unread', { detail: { unread_count: undoJson.unread_count, source: 'thread-menu' } }));
                  }
                }
              }
            },
          });
        } else if (action === 'archive') {
          showUndoToast({
            message: 'Moved to Archived.',
            undoLabel: 'Undo',
            onUndo: async () => {
              const undoJson = await postThreadAction(cid, 'restore');
              if (undoJson && undoJson.success) {
                if (rowParent && rowForUndo) {
                  // If we removed it from inbox view, bring it back.
                  if (removedRow) {
                    if (rowNext && rowNext.parentElement === rowParent) rowParent.insertBefore(rowForUndo, rowNext);
                    else rowParent.appendChild(rowForUndo);
                  }
                  rowForUndo.setAttribute('data-archived', '0');
                  rowForUndo.removeAttribute('data-thread-busy');
                  if (removedRow) animateReinsertRow(rowForUndo);
                  restoreScroll(scrollContainer, scrollTop);
                  if (rowWasActive && rowForUndo && rowForUndo.click) rowForUndo.click();
                }
                if (typeof undoJson.unread_count !== 'undefined') {
                  if (window.MedConnectUnreadService) {
                    window.MedConnectUnreadService.setUnread(undoJson.unread_count, 'thread-menu');
                  } else {
                    window.dispatchEvent(new CustomEvent('medconnect:messages-unread', { detail: { unread_count: undoJson.unread_count, source: 'thread-menu' } }));
                  }
                }
              }
            },
          });
        } else if (action === 'restore') {
          showUndoToast({
            message: 'Restored to Inbox.',
            undoLabel: 'Undo',
            onUndo: async () => {
              const undoJson = await postThreadAction(cid, 'archive');
              if (undoJson && undoJson.success) {
                if (rowParent && rowForUndo) {
                  if (removedRow) {
                    if (rowNext && rowNext.parentElement === rowParent) rowParent.insertBefore(rowForUndo, rowNext);
                    else rowParent.appendChild(rowForUndo);
                  }
                  rowForUndo.setAttribute('data-archived', '1');
                  rowForUndo.removeAttribute('data-thread-busy');
                  if (removedRow) animateReinsertRow(rowForUndo);
                  restoreScroll(scrollContainer, scrollTop);
                  if (rowWasActive && rowForUndo && rowForUndo.click) rowForUndo.click();
                }
                if (typeof undoJson.unread_count !== 'undefined') {
                  if (window.MedConnectUnreadService) {
                    window.MedConnectUnreadService.setUnread(undoJson.unread_count, 'thread-menu');
                  } else {
                    window.dispatchEvent(new CustomEvent('medconnect:messages-unread', { detail: { unread_count: undoJson.unread_count, source: 'thread-menu' } }));
                  }
                }
              }
            },
          });
        }
      } catch (_) {
        // ignore
      } finally {
        try { if (activeRow) activeRow.removeAttribute('data-thread-busy'); } catch (_) {}
        closeMenu();
      }
    }

    // Open menu on kebab
    document.addEventListener('click', (e) => {
      const kebab = e.target && e.target.closest ? e.target.closest('.msg-kebab') : null;
      if (!kebab) return;
      e.preventDefault();
      e.stopPropagation();

      const row = kebab.closest('.msg-item');
      if (!row) return;
      const isArchived = String(row.getAttribute('data-archived') || '0') === '1';

      // Toggle if same kebab
      if (activeKebab === kebab && !root.hidden) {
        closeMenu();
        return;
      }

      activeKebab = kebab;
      activeRow = row;
      root.innerHTML = buildMenuHtml(isArchived);
      positionMenu(root, kebab);

      // Bind menu item clicks
      qsa('[data-action]', root).forEach((btn) => {
        btn.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          handleAction(String(btn.getAttribute('data-action') || ''));
        });
      });
    });

    // Keyboard support for kebab (Enter/Space)
    document.addEventListener('keydown', (e) => {
      const key = e.key;
      if (key !== 'Enter' && key !== ' ') return;
      const target = e.target;
      const kebab = target && target.closest ? target.closest('.msg-kebab') : null;
      if (!kebab) return;
      e.preventDefault();
      kebab.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (root.hidden) return;
      const insideMenu = root.contains(e.target);
      const clickedKebab = e.target && e.target.closest ? e.target.closest('.msg-kebab') : null;
      if (!insideMenu && !clickedKebab) closeMenu();
    });

    // Close on ESC
    document.addEventListener('keydown', (e) => {
      if (root.hidden) return;
      if (e.key === 'Escape') closeMenu();
    });

    window.addEventListener('resize', () => { if (!root.hidden) closeMenu(); });
    window.addEventListener('scroll', () => { if (!root.hidden) closeMenu(); }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

