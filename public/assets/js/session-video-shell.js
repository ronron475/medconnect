/**
 * medConnect — Persistent video consultation shell (PiP across portal pages).
 * One iframe → video_room.php. WebRTC stays inside the iframe.
 */
(function (global) {
  'use strict';

  const STORAGE_KEY = 'mc_video_shell_v1';
  const SHELL_ID = 'mcGlobalVideoShell';
  const FRAME_ID = 'mcGlobalVideoFrame';

  function assetBase() {
    return String(
      (document.body && document.body.dataset.assetBase) ||
      global.APP_BASE ||
      global.ASSET_BASE ||
      ''
    ).replace(/\/$/, '');
  }

  function isVideoRoomPage() {
    return /\/views\/consultation\/video_room\.php/i.test(global.location.pathname || '');
  }

  function readState() {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  }

  function writeState(patch) {
    const prev = readState() || {};
    const next = Object.assign({}, prev, patch);
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    return next;
  }

  function clearState() {
    sessionStorage.removeItem(STORAGE_KEY);
  }

  function shellEl() {
    return document.getElementById(SHELL_ID);
  }

  function frameEl() {
    return document.getElementById(FRAME_ID);
  }

  function roomUrl(token, bust) {
    let url = assetBase() + '/views/consultation/video_room.php?token=' + encodeURIComponent(token) + '&embedded=1';
    if (bust) url += '&_mc=' + Date.now();
    return url;
  }

  function extractToken(urlOrToken) {
    const raw = String(urlOrToken || '');
    if (!raw) return '';
    if (raw.indexOf('token=') === -1) return raw;
    try {
      const u = new URL(raw, global.location.origin);
      return u.searchParams.get('token') || '';
    } catch (_) {
      const match = raw.match(/[?&]token=([^&]+)/);
      return match ? decodeURIComponent(match[1]) : '';
    }
  }

  function postToShellFrame(message) {
    postToFrame(message);
  }

  function setMode(mode) {
    const shell = shellEl();
    if (!shell) return;
    shell.dataset.mode = mode;
    shell.hidden = mode === 'hidden';
    shell.setAttribute('aria-hidden', mode === 'hidden' ? 'true' : 'false');
    shell.classList.toggle('is-pip', mode === 'pip');
    shell.classList.toggle('is-fullscreen', mode === 'fullscreen');
    shell.classList.toggle('is-docked', mode === 'docked');
    document.body.classList.toggle('mc-video-shell-active', mode !== 'hidden');
    document.body.classList.toggle('mc-video-shell-pip', mode === 'pip');
    document.body.classList.toggle('mc-video-shell-fullscreen', mode === 'fullscreen');
    writeState({ mode: mode });
  }

  function postToFrame(message) {
    const frame = frameEl();
    if (!frame || !frame.contentWindow) return false;
    try {
      const win = frame.contentWindow;
      const type = message && message.type;
      if (type === 'medconnect:shell-leave-fast' || type === 'medconnect:shell-end-call') {
        if (typeof win.leaveCallFast === 'function') {
          win.leaveCallFast();
          return true;
        }
        if (typeof win.endCall === 'function') {
          win.endCall(true);
          return true;
        }
      }
      if (type === 'medconnect:shell-toggle-audio' && typeof win.toggleAudio === 'function') {
        win.toggleAudio();
        return true;
      }
      if (type === 'medconnect:shell-toggle-video' && typeof win.toggleVideo === 'function') {
        win.toggleVideo();
        return true;
      }
      win.postMessage(message, global.location.origin);
      return true;
    } catch (_) {
      return false;
    }
  }

  function leaveFromShell() {
    let handled = false;
    try {
      const win = frameEl() && frameEl().contentWindow;
      if (win && typeof win.leaveCallFast === 'function') {
        win.leaveCallFast();
        handled = true;
      } else if (win && typeof win.endCall === 'function') {
        win.endCall(true);
        handled = true;
      }
    } catch (_) { /* ignore */ }

    if (handled) return;

    postLeaveApiFromShell().finally(() => {
      const st = readState();
      closeShell();
      if (st && st.consultationId) {
        global.location.href = assetBase() + '/views/provider/consultation_session.php?id=' + encodeURIComponent(st.consultationId);
        return;
      }
      global.location.href = assetBase() + '/views/patient/consultations.php';
    });
  }

  function postLeaveApiFromShell() {
    const st = readState();
    if (!st || !st.token) return Promise.resolve(false);
    const csrf = String((document.body && document.body.dataset.csrf) || '');
    return fetch(assetBase() + '/app/api/consultations/end_video.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'token=' + encodeURIComponent(st.token) + '&csrf_token=' + encodeURIComponent(csrf),
      credentials: 'same-origin',
    }).catch(() => null).then(() => true);
  }

  function bindShellAction(btn) {
    if (!btn || btn.dataset.shellBound) return;
    btn.dataset.shellBound = '1';
    const action = btn.getAttribute('data-shell-action');
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (action === 'mute') postToFrame({ type: 'medconnect:shell-toggle-audio' });
      if (action === 'camera') postToFrame({ type: 'medconnect:shell-toggle-video' });
      if (action === 'end') leaveFromShell();
    });
  }

  function initDrag(shell) {
    if (!shell || shell.dataset.dragInit) return;
    shell.dataset.dragInit = '1';
    const handle = document.getElementById('mcGlobalVideoHandle');
    if (!handle) return;

    let dragging = false;
    let sx = 0;
    let sy = 0;
    let ox = 0;
    let oy = 0;

    function down(e) {
      if (!shell.classList.contains('is-pip')) return;
      if (e.target.closest('button')) return;
      dragging = true;
      const rect = shell.getBoundingClientRect();
      ox = rect.left;
      oy = rect.top;
      sx = e.clientX;
      sy = e.clientY;
      try { handle.setPointerCapture(e.pointerId); } catch (_) {}
      e.preventDefault();
    }

    function move(e) {
      if (!dragging) return;
      const x = ox + (e.clientX - sx);
      const y = oy + (e.clientY - sy);
      const pad = 8;
      const bottomReserve = global.matchMedia('(max-width: 720px)').matches ? 80 : pad;
      const maxX = global.innerWidth - shell.offsetWidth - pad;
      const maxY = global.innerHeight - shell.offsetHeight - bottomReserve;
      shell.style.left = Math.max(pad, Math.min(maxX, x)) + 'px';
      shell.style.top = Math.max(pad, Math.min(maxY, y)) + 'px';
      shell.style.right = 'auto';
      shell.style.bottom = 'auto';
    }

    function up(e) {
      if (!dragging) return;
      dragging = false;
      try { handle.releasePointerCapture(e.pointerId); } catch (_) {}
      const rect = shell.getBoundingClientRect();
      writeState({ pipLeft: rect.left, pipTop: rect.top });
    }

    handle.addEventListener('pointerdown', down);
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', up);
    handle.addEventListener('pointercancel', up);
  }

  function bindNavigationPreserve() {
    document.addEventListener('click', (e) => {
      const link = e.target.closest('a[href]');
      if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
      const href = String(link.getAttribute('href') || '');
      if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
      const st = readState();
      if (!st || !st.token) return;
      const shell = shellEl();
      if (!shell || shell.hidden) return;
      if (shell.parentElement && shell.parentElement !== document.body) {
        document.body.appendChild(shell);
        minimize();
      } else if (shell.dataset.mode === 'fullscreen') {
        minimize();
      }
    }, true);
  }

  function bindChrome() {
    const expandBtn = document.getElementById('mcGlobalVideoExpand');
    const minBtn = document.getElementById('mcGlobalVideoMinimize');
    if (expandBtn) {
      expandBtn.addEventListener('click', () => {
        const st = readState();
        if (st && st.mode === 'fullscreen') {
          minimize();
        } else {
          maximize();
        }
      });
    }
    if (minBtn) {
      minBtn.addEventListener('click', () => minimize());
    }

    document.querySelectorAll('[data-shell-action]').forEach((btn) => {
      bindShellAction(btn);
    });
    const leaveBtn = document.getElementById('mcGlobalVideoLeave');
    if (leaveBtn) bindShellAction(leaveBtn);
  }

  function open(token, consultationId, options) {
    options = options || {};
    const shell = shellEl();
    const frame = frameEl();
    if (!shell || !frame || !token) return false;

    const url = roomUrl(token, true);
    const alreadySame = frame.src && frame.src.indexOf('token=' + encodeURIComponent(token)) >= 0;
    if (!options.skipReload || !alreadySame || frame.src === 'about:blank' || !frame.src) {
      frame.src = url;
    }

    writeState({
      token: token,
      consultationId: consultationId || null,
      mode: options.mode || 'fullscreen',
      label: options.label || 'Video consultation',
    });

    const label = document.getElementById('mcGlobalVideoHandleLabel');
    if (label && options.label) label.textContent = options.label;

    const toolbar = document.getElementById('mcGlobalVideoToolbar');
    if (toolbar) toolbar.hidden = false;

    initDrag(shell);
    setMode(options.mode || 'fullscreen');

    const st = readState();
    if (st && st.mode === 'pip' && typeof st.pipLeft === 'number') {
      shell.style.left = st.pipLeft + 'px';
      shell.style.top = st.pipTop + 'px';
    }

    return true;
  }

  function minimize() {
    const shell = shellEl();
    if (!shell) return;
    if (shell.classList.contains('is-docked')) {
      global.dispatchEvent(new CustomEvent('medconnect:video-shell-scroll-away', { detail: readState() }));
      return;
    }
    setMode('pip');
    if (!shell.style.left) {
      shell.style.right = '16px';
      shell.style.bottom = '96px';
      shell.style.left = 'auto';
      shell.style.top = 'auto';
    }
    const expandBtn = document.getElementById('mcGlobalVideoExpand');
    if (expandBtn) expandBtn.textContent = '⤢';
  }

  function maximize() {
    const shell = shellEl();
    if (!shell) return;
    shell.style.left = '';
    shell.style.top = '';
    shell.style.right = '';
    shell.style.bottom = '';
    setMode('fullscreen');
    const expandBtn = document.getElementById('mcGlobalVideoExpand');
    if (expandBtn) expandBtn.textContent = '—';
  }

  function dock(container) {
    const shell = shellEl();
    if (!shell || !container) return;
    container.appendChild(shell);
    setMode('docked');
  }

  function undock() {
    const shell = shellEl();
    if (!shell) return;
    if (shell.parentElement !== document.body) {
      document.body.appendChild(shell);
    }
    maximize();
  }

  function closeShell() {
    const frame = frameEl();
    if (frame) frame.src = 'about:blank';
    setMode('hidden');
    clearState();
  }

  function restoreFromStorage() {
    if (isVideoRoomPage()) return;
    const st = readState();
    if (!st || !st.token) return;
    const onProviderSession = /\/views\/provider\/consultation_session\.php/i.test(global.location.pathname || '');
    if (onProviderSession && st.mode === 'docked') {
      return;
    }
    const frame = frameEl();
    const alreadyLoaded = frame && frame.src && frame.src.indexOf(st.token) >= 0 && frame.src !== 'about:blank';
    open(st.token, st.consultationId, {
      mode: st.mode || 'pip',
      label: st.label,
      skipReload: alreadyLoaded,
    });
  }

  function handleMessage(event) {
    if (event.origin !== global.location.origin || !event.data) return;
    const type = event.data.type;
    if (!type || !String(type).startsWith('medconnect:')) return;

    if (type === 'medconnect:minimize-video') {
      const shell = shellEl();
      if (shell && shell.classList.contains('is-docked')) {
        global.dispatchEvent(new CustomEvent('medconnect:video-shell-scroll-away', { detail: readState() }));
        return;
      }
      minimize();
      return;
    }
    if (type === 'medconnect:maximize-video') {
      maximize();
      return;
    }
    if (type === 'medconnect:call-completed') {
      global.dispatchEvent(new CustomEvent('medconnect:video-shell-completed', { detail: event.data }));
      return;
    }
    if (type === 'medconnect:call-left') {
      closeShell();
      global.dispatchEvent(new CustomEvent('medconnect:video-shell-left', { detail: event.data }));
      return;
    }
    if (type === 'medconnect:call-ended') {
      postToFrame({ type: 'medconnect:reset-call-ui' });
      closeShell();
      global.dispatchEvent(new CustomEvent('medconnect:video-shell-ended', { detail: event.data }));
      return;
    }
    if (type === 'medconnect:call-dismissed') {
      closeShell();
      return;
    }
    if (type === 'medconnect:session-extended') {
      global.dispatchEvent(new CustomEvent('medconnect:session-extended', { detail: event.data }));
    }
  }

  function joinConsultation(tokenOrUrl, consultationId, options) {
    const token = extractToken(tokenOrUrl);
    if (!token) return false;
    if (isVideoRoomPage()) {
      global.location.href = assetBase() + '/views/consultation/video_room.php?token=' + encodeURIComponent(token);
      return true;
    }
    return open(token, consultationId, options || { mode: 'fullscreen', label: 'Video consultation' });
  }

  function bindJoinTriggers() {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-mc-video-join]');
      if (!btn) return;
      e.preventDefault();
      const token = btn.getAttribute('data-token') || btn.getAttribute('data-room-token') || '';
      const consultId = btn.getAttribute('data-consultation-id') || '';
      const label = btn.getAttribute('data-label') || 'Video consultation';
      joinConsultation(token, consultId ? parseInt(consultId, 10) : null, { mode: 'fullscreen', label: label });
    });
  }

  global.McSessionVideoShell = {
    open: open,
    minimize: minimize,
    maximize: maximize,
    dock: dock,
    undock: undock,
    close: closeShell,
    join: joinConsultation,
    extractToken: extractToken,
    postToFrame: postToShellFrame,
    getState: readState,
    isActive: function () {
      const st = readState();
      return !!(st && st.token && st.mode && st.mode !== 'hidden');
    },
    joinUrl: function (token) { return roomUrl(token, false); },
  };

  global.addEventListener('message', handleMessage);

  document.addEventListener('DOMContentLoaded', function () {
    bindChrome();
    bindJoinTriggers();
    bindNavigationPreserve();
    restoreFromStorage();
  });
})(window);
