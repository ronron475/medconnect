/**
 * medConnect — Global mobile multitasking + minimized-task system
 *
 * One reusable dock for important active tasks (WebRTC is one type).
 * Preserves form drafts + scroll within a session. Does NOT fake OS floating windows.
 * Native Picture-in-Picture is requested only when the browser supports it.
 */
(function (global) {
  'use strict';

  if (global.MedConnectMultitask) return;

  const TASKS_KEY = 'mc_multitask_v1';
  const DRAFTS_KEY = 'mc_form_drafts_v1';
  const SCROLL_KEY = 'mc_page_scroll_v1';
  const DOCK_ID = 'mcMultitaskDock';

  const tasks = new Map();
  let bootDone = false;
  let draftTimer = null;

  function assetBase() {
    return String(
      (document.body && document.body.dataset.assetBase) ||
      global.APP_BASE ||
      global.ASSET_BASE ||
      ''
    ).replace(/\/$/, '');
  }

  function portalRole() {
    const body = document.body;
    if (!body) return '';
    if (body.dataset.portal) return String(body.dataset.portal);
    if (body.classList.contains('patient-portal')) return 'patient';
    if (body.classList.contains('provider-body')) return 'provider';
    if (body.classList.contains('bhw-body')) return 'bhw';
    if (body.classList.contains('superadmin-body')) return 'superadmin';
    if (body.classList.contains('admin-body')) return 'admin';
    return '';
  }

  function pageKey() {
    return String(global.location.pathname || '') + String(global.location.search || '');
  }

  function escapeCssIdent(value) {
    const s = String(value || '');
    if (global.CSS && typeof global.CSS.escape === 'function') {
      return global.CSS.escape(s);
    }
    return s.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  }

  function readJson(key) {
    try {
      const raw = sessionStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  }

  function writeJson(key, value) {
    try {
      sessionStorage.setItem(key, JSON.stringify(value));
    } catch (_) { /* quota / private mode */ }
  }

  function persistTasks() {
    const list = [];
    tasks.forEach((task) => {
      if (!task || !task.id) return;
      list.push({
        id: task.id,
        type: task.type || 'generic',
        title: task.title || 'Active task',
        subtitle: task.subtitle || '',
        restoreUrl: task.restoreUrl || '',
        minimized: !!task.minimized,
        meta: task.meta || {},
      });
    });
    writeJson(TASKS_KEY, { role: portalRole(), items: list, at: Date.now() });
  }

  function dockEl() {
    return document.getElementById(DOCK_ID);
  }

  function ensureDock() {
    let dock = dockEl();
    if (dock) return dock;
    dock = document.createElement('div');
    dock.id = DOCK_ID;
    dock.className = 'mc-multitask-dock';
    dock.hidden = true;
    dock.setAttribute('aria-live', 'polite');
    dock.innerHTML = '<div class="mc-multitask-dock__list" data-mc-multitask-list></div>';
    document.body.appendChild(dock);
    return dock;
  }

  function renderDock() {
    const dock = ensureDock();
    const list = dock.querySelector('[data-mc-multitask-list]');
    if (!list) return;

    const minimized = [];
    tasks.forEach((task) => {
      if (task && task.minimized) minimized.push(task);
    });

    // Video shell already renders its own PiP chrome — keep a compact restore chip only.
    list.innerHTML = '';
    minimized.forEach((task) => {
      if (task.type === 'webrtc') {
        // Existing session float chrome is the primary restore UI for live calls.
        const shell = document.getElementById('mcGlobalVideoShell');
        if (shell && !shell.hidden && shell.classList.contains('is-pip')) {
          return;
        }
      }
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'mc-multitask-chip' + (task.type === 'webrtc' ? ' mc-multitask-chip--webrtc' : '');
      chip.setAttribute('data-mc-task-id', task.id);
      chip.setAttribute('aria-label', 'Restore ' + (task.title || 'task'));
      chip.innerHTML =
        '<span class="mc-multitask-chip__dot" aria-hidden="true"></span>' +
        '<span class="mc-multitask-chip__text">' +
          '<span class="mc-multitask-chip__title"></span>' +
          (task.subtitle
            ? '<span class="mc-multitask-chip__sub"></span>'
            : '') +
        '</span>' +
        '<span class="mc-multitask-chip__action">Open</span>';
      chip.querySelector('.mc-multitask-chip__title').textContent = task.title || 'Active task';
      const sub = chip.querySelector('.mc-multitask-chip__sub');
      if (sub) sub.textContent = task.subtitle;
      chip.addEventListener('click', function () {
        restore(task.id);
      });
      list.appendChild(chip);
    });

    const show = minimized.length > 0;
    dock.hidden = !show;
    dock.setAttribute('aria-hidden', show ? 'false' : 'true');
    document.body.classList.toggle('mc-multitask-dock-open', show);
  }

  function register(task) {
    if (!task || !task.id) return null;
    const prev = tasks.get(task.id) || {};
    const next = Object.assign({}, prev, task, {
      id: String(task.id),
      type: task.type || prev.type || 'generic',
      title: task.title || prev.title || 'Active task',
      subtitle: task.subtitle != null ? task.subtitle : (prev.subtitle || ''),
      restoreUrl: task.restoreUrl != null ? task.restoreUrl : (prev.restoreUrl || ''),
      minimized: task.minimized != null ? !!task.minimized : !!prev.minimized,
      meta: Object.assign({}, prev.meta || {}, task.meta || {}),
      onRestore: typeof task.onRestore === 'function' ? task.onRestore : prev.onRestore,
      onMinimize: typeof task.onMinimize === 'function' ? task.onMinimize : prev.onMinimize,
      onDismiss: typeof task.onDismiss === 'function' ? task.onDismiss : prev.onDismiss,
    });
    tasks.set(next.id, next);
    persistTasks();
    renderDock();
    return next;
  }

  function unregister(id) {
    const task = tasks.get(String(id || ''));
    if (!task) return;
    tasks.delete(task.id);
    persistTasks();
    renderDock();
  }

  function minimize(id) {
    const task = tasks.get(String(id || ''));
    if (!task) return false;
    task.minimized = true;
    if (typeof task.onMinimize === 'function') {
      try { task.onMinimize(task); } catch (_) { /* ignore */ }
    }
    if (task.type === 'webrtc' && global.McSessionVideoShell && typeof global.McSessionVideoShell.minimize === 'function') {
      try { global.McSessionVideoShell.minimize(); } catch (_) { /* ignore */ }
    }
    persistTasks();
    renderDock();
    return true;
  }

  function restore(id) {
    const task = tasks.get(String(id || ''));
    if (!task) return false;
    task.minimized = false;
    persistTasks();
    renderDock();

    if (typeof task.onRestore === 'function') {
      try {
        task.onRestore(task);
        return true;
      } catch (_) { /* fall through */ }
    }

    if (task.type === 'webrtc' && global.McSessionVideoShell && typeof global.McSessionVideoShell.maximize === 'function') {
      try {
        global.McSessionVideoShell.maximize();
        return true;
      } catch (_) { /* fall through */ }
    }

    if (task.restoreUrl) {
      global.location.href = task.restoreUrl;
      return true;
    }
    return false;
  }

  function dismiss(id) {
    const task = tasks.get(String(id || ''));
    if (!task) return;
    if (typeof task.onDismiss === 'function') {
      try { task.onDismiss(task); } catch (_) { /* ignore */ }
    }
    unregister(id);
  }

  function list() {
    return Array.from(tasks.values()).map((t) => Object.assign({}, t));
  }

  /* ── Form draft persistence (no passwords / sensitive tokens) ── */
  function fieldPersistable(el) {
    if (!el || !el.name) return false;
    const type = String(el.type || '').toLowerCase();
    if (type === 'password' || type === 'file' || type === 'hidden') return false;
    if (el.closest('[data-mc-no-persist]')) return false;
    if (/pass|csrf|token|secret|otp|pin/i.test(el.name)) return false;
    return true;
  }

  function collectDrafts() {
    const scope = document.querySelector('.portal-page-body, .provider-page-body, .adm-content, main') || document.body;
    if (!scope) return {};
    const draft = {};
    scope.querySelectorAll('input, textarea, select').forEach((el) => {
      if (!fieldPersistable(el)) return;
      const key = el.name || el.id;
      if (!key) return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        draft[key] = { t: el.type, v: !!el.checked, val: el.value };
      } else {
        draft[key] = { t: 'text', v: el.value };
      }
    });
    return draft;
  }

  function saveDraftsNow() {
    const all = readJson(DRAFTS_KEY) || {};
    all[pageKey()] = {
      at: Date.now(),
      fields: collectDrafts(),
    };
    writeJson(DRAFTS_KEY, all);
  }

  function scheduleDraftSave() {
    if (draftTimer) global.clearTimeout(draftTimer);
    draftTimer = global.setTimeout(saveDraftsNow, 350);
  }

  function restoreDrafts() {
    const all = readJson(DRAFTS_KEY) || {};
    const entry = all[pageKey()];
    if (!entry || !entry.fields) return;
    const scope = document.querySelector('.portal-page-body, .provider-page-body, .adm-content, main') || document.body;
    if (!scope) return;
    Object.keys(entry.fields).forEach((key) => {
      const data = entry.fields[key];
      if (!data) return;
      const nodes = scope.querySelectorAll('[name="' + escapeCssIdent(key) + '"]');
      nodes.forEach((el) => {
        if (!fieldPersistable(el)) return;
        if (data.t === 'checkbox' || data.t === 'radio') {
          if (String(el.value) === String(data.val != null ? data.val : el.value)) {
            el.checked = !!data.v;
          }
        } else if (el.value === '' || el.dataset.mcDraftRestore === '1') {
          el.value = data.v != null ? String(data.v) : '';
          el.dataset.mcDraftRestore = '1';
        }
      });
    });
  }

  function saveScroll() {
    const all = readJson(SCROLL_KEY) || {};
    all[pageKey()] = {
      x: global.scrollX || 0,
      y: global.scrollY || 0,
      at: Date.now(),
    };
    writeJson(SCROLL_KEY, all);
  }

  function restoreScroll() {
    const all = readJson(SCROLL_KEY) || {};
    const entry = all[pageKey()];
    if (!entry) return;
    global.requestAnimationFrame(function () {
      global.scrollTo(entry.x || 0, entry.y || 0);
    });
  }

  /* ── Native PiP bridge (WebRTC task only; real browser API) ── */
  function requestNativePip() {
    const shell = global.McSessionVideoShell;
    if (!shell || typeof shell.postToFrame !== 'function') return;
    if (!shell.isActive || !shell.isActive()) return;
    shell.postToFrame({ type: 'medconnect:request-native-pip' });
  }

  function exitNativePip() {
    const shell = global.McSessionVideoShell;
    if (!shell || typeof shell.postToFrame !== 'function') return;
    shell.postToFrame({ type: 'medconnect:exit-native-pip' });
  }

  function syncWebrtcTaskFromShell() {
    const shell = global.McSessionVideoShell;
    if (!shell || typeof shell.getState !== 'function') return;
    const st = shell.getState();
    if (!st || !st.token || st.ended) {
      unregister('webrtc-active');
      return;
    }
    const mode = st.mode || 'hidden';
    register({
      id: 'webrtc-active',
      type: 'webrtc',
      title: st.label || 'Video consultation',
      subtitle: mode === 'pip' ? 'Tap to expand' : 'In progress',
      restoreUrl: '',
      minimized: mode === 'pip',
      meta: {
        token: st.token,
        consultationId: st.consultationId || null,
      },
      onRestore: function () {
        if (shell.maximize) shell.maximize();
      },
      onMinimize: function () {
        if (shell.minimize) shell.minimize();
      },
    });
  }

  function hydratePersistedTasks() {
    const bag = readJson(TASKS_KEY);
    if (!bag || !Array.isArray(bag.items)) return;

    // Tasks are per browser tab session — never leak a doctor/patient
    // consultation chip into Admin / Super Admin after role switch.
    const role = portalRole();
    if (bag.role && role && bag.role !== role) {
      writeJson(TASKS_KEY, { role: role, items: [], at: Date.now() });
      return;
    }
    if (role === 'admin' || role === 'superadmin') {
      const kept = bag.items.filter(function (item) {
        return item && item.type && item.type !== 'consultation' && item.type !== 'webrtc';
      });
      if (kept.length !== bag.items.length) {
        writeJson(TASKS_KEY, { role: role, items: kept, at: Date.now() });
      }
      bag.items = kept;
    }

    bag.items.forEach((item) => {
      if (!item || !item.id) return;
      if (item.type === 'webrtc') return; // restored by video shell
      if (!item.minimized && !item.restoreUrl) return;
      // Drop stale restore chips that pointed at read-only detail pages.
      if (item.type === 'consultation' && /consultation_detail\.php/i.test(String(item.restoreUrl || ''))) {
        return;
      }
      register({
        id: item.id,
        type: item.type || 'generic',
        title: item.title || 'Active task',
        subtitle: item.subtitle || '',
        restoreUrl: item.restoreUrl || '',
        minimized: !!item.minimized,
        meta: item.meta || {},
      });
    });
  }

  function clearConsultationTasks(consultId) {
    const want = consultId != null && String(consultId) !== ''
      ? String(consultId)
      : null;
    const ids = [];
    tasks.forEach((task, id) => {
      if (!task || task.type !== 'consultation') return;
      if (want == null) {
        ids.push(id);
        return;
      }
      const metaId = task.meta && (task.meta.consultationId || task.meta.consultation_id);
      const fromId = String(id).replace(/^consultation-/, '');
      if (String(metaId || fromId) === want) ids.push(id);
    });
    ids.forEach(unregister);
  }

  function hasActiveVideoShell() {
    const shell = document.getElementById('mcGlobalVideoShell');
    if (!shell || shell.hidden) return false;
    if (shell.classList.contains('is-ended')) return false;
    return true;
  }

  function registerConsultationContext() {
    const body = document.body;
    if (!body) return;
    let consultId = body.getAttribute('data-consultation-id')
      || body.dataset.consultationId
      || '';
    const path = String(global.location.pathname || '');
    if (!consultId) {
      const m = String(global.location.search || '').match(/[?&](?:id|consultation_id)=(\d+)/i);
      if (m && /consultation_session\.php|video_room\.php|consultation_detail\.php/i.test(path)) {
        consultId = m[1];
      }
    }

    const status = String(
      body.getAttribute('data-consultation-status')
      || body.dataset.consultationStatus
      || ''
    ).toLowerCase();
    const isTerminal = /^(completed|cancelled|canceled|ended)$/.test(status);

    // Past/record pages must never keep a "Return anytime" chip.
    if (/consultation_detail\.php/i.test(path)) {
      if (consultId) clearConsultationTasks(consultId);
      else clearConsultationTasks(null);
      return;
    }

    // Only live session / video room pages register a restore chip.
    const isLiveSessionPage = /consultation_session\.php|video_room\.php/i.test(path);
    if (!isLiveSessionPage || !consultId || isTerminal) {
      if (consultId && isTerminal) {
        clearConsultationTasks(consultId);
        return;
      }
      // Elsewhere: keep minimized only while a live video shell is open; otherwise drop stale chips.
      if (!hasActiveVideoShell()) {
        clearConsultationTasks(null);
        return;
      }
      tasks.forEach((task) => {
        if (task.type === 'consultation' && task.restoreUrl) {
          task.minimized = true;
        }
      });
      persistTasks();
      renderDock();
      return;
    }

    register({
      id: 'consultation-' + consultId,
      type: 'consultation',
      title: 'Consultation #' + consultId,
      subtitle: 'Return anytime',
      restoreUrl: global.location.href,
      minimized: false,
      meta: { consultationId: consultId },
    });
  }

  function bindLifecycle() {
    document.addEventListener('input', function (e) {
      const t = e.target;
      if (!t || !t.matches || !t.matches('input, textarea, select')) return;
      scheduleDraftSave();
    }, true);

    document.addEventListener('change', function (e) {
      const t = e.target;
      if (!t || !t.matches || !t.matches('input, textarea, select')) return;
      scheduleDraftSave();
    }, true);

    global.addEventListener('pagehide', function () {
      saveDraftsNow();
      saveScroll();
      persistTasks();
    });

    global.addEventListener('beforeunload', function () {
      saveDraftsNow();
      saveScroll();
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        saveDraftsNow();
        saveScroll();
        requestNativePip();
      } else {
        exitNativePip();
        syncWebrtcTaskFromShell();
        restoreDrafts();
      }
    });

    global.addEventListener('pageshow', function () {
      restoreDrafts();
      restoreScroll();
      syncWebrtcTaskFromShell();
    });

    global.addEventListener('medconnect:soft-nav', function () {
      restoreDrafts();
      registerConsultationContext();
      syncWebrtcTaskFromShell();
      renderDock();
    });

    function clearConsultFromEvent(detail) {
      unregister('webrtc-active');
      const d = detail || {};
      const id = d.consultation_id || d.consultationId || d.id || (d.data && (d.data.consultation_id || d.data.id));
      clearConsultationTasks(id || null);
    }

    global.addEventListener('medconnect:video-shell-completed', function (e) {
      clearConsultFromEvent(e && e.detail);
    });
    global.addEventListener('medconnect:video-shell-ended', function (e) {
      clearConsultFromEvent(e && e.detail);
    });
    global.addEventListener('medconnect:video-shell-left', function (e) {
      clearConsultFromEvent(e && e.detail);
    });
    global.addEventListener('medconnect:consultation-completed', function (e) {
      const d = (e && e.detail) || {};
      clearConsultationTasks(d.id || d.consultation_id || d.consultationId || null);
    });
    document.addEventListener('medconnect:consultation-completed', function (e) {
      const d = (e && e.detail) || {};
      clearConsultationTasks(d.id || d.consultation_id || d.consultationId || null);
    });
  }

  function boot() {
    if (bootDone) return;
    bootDone = true;
    ensureDock();
    hydratePersistedTasks();
    registerConsultationContext();
    bindLifecycle();
    restoreDrafts();
    restoreScroll();
    // Video shell may boot slightly later.
    global.setTimeout(syncWebrtcTaskFromShell, 0);
    global.setTimeout(syncWebrtcTaskFromShell, 400);
  }

  global.MedConnectMultitask = {
    register: register,
    unregister: unregister,
    minimize: minimize,
    restore: restore,
    dismiss: dismiss,
    list: list,
    syncWebrtc: syncWebrtcTaskFromShell,
    syncContext: registerConsultationContext,
    saveDrafts: saveDraftsNow,
    restoreDrafts: restoreDrafts,
    render: renderDock,
    assetBase: assetBase,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
