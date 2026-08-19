/**
 * medConnect — Telemedicine video consultation UI controller.
 * Layout, PiP drag, fullscreen, minimize, network quality, mobile controls.
 * WebRTC / PeerJS logic in webrtc-peer-call.js; layout in this module.
 */
(function (global) {
  'use strict';

  const CORNERS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
  const CONTROLS_HIDE_MS = 4500;

  function svgIcon(name) {
    const icons = {
      mic: '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4M8 22h8"/>',
      cam: '<path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
      swap: '<path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>',
      fullscreen: '<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>',
      fullscreenExit: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
      minimize: '<path d="M6 9V5a1 1 0 0 1 1-1h4M18 9V5a1 1 0 0 0-1-1h-4M6 15v4a1 1 0 0 0 1 1h4M18 15v4a1 1 0 0 1-1 1h-4"/>',
      maximize: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
      speaker: '<path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>',
      flip: '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    };
    const body = icons[name] || '';
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
  }

  function createController(options) {
    const opts = options || {};
    const isPatient = !!opts.isPatient;
    const isProvider = !isPatient;
    const embedded = !!opts.embedded;
    const providerName = opts.providerName || 'Healthcare Provider';
    const providerSpecialty = opts.providerSpecialty || 'General Medicine';
    const providerInitials = opts.providerInitials || 'HP';
    const patientName = opts.patientName || 'Patient';
    const patientInitials = opts.patientInitials || 'PT';
    const onMinimize = opts.onMinimize || null;
    const onMaximize = opts.onMaximize || null;

    let swapped = false;
    let isFloating = false;
    let isFullscreen = false;
    let controlsHidden = false;
    let controlsTimer = null;
    let durationSeconds = 0;
    let durationInterval = null;
    let networkInterval = null;
    let speakerOn = true;
    let facingMode = 'user';

    const els = {};

    function q(id) {
      return document.getElementById(id);
    }

    function getLocalVideo() {
      return q('localVideo');
    }

    function getRemoteVideo() {
      return q('remoteVideo');
    }

    function remoteIsMain() {
      return !swapped;
    }

    function mountVideos() {
      const mainSlot = els.mainSlot;
      const pipSlot = els.pipSlot;
      const localV = getLocalVideo();
      const remoteV = getRemoteVideo();
      if (!mainSlot || !pipSlot || !localV || !remoteV) return;

      const mainVideo = remoteIsMain() ? remoteV : localV;
      const pipVideo = remoteIsMain() ? localV : remoteV;
      const remoteName = isPatient ? providerName : patientName;
      const localName = isPatient ? patientName : providerName;

      // Move nodes only when needed. innerHTML = '' remounts <video> and on iOS
      // that pauses playback and can drop the remote audio track.
      if (mainSlot.firstElementChild !== mainVideo) {
        mainSlot.appendChild(mainVideo);
      }
      if (pipSlot.firstElementChild !== pipVideo) {
        pipSlot.appendChild(pipVideo);
      }

      mainVideo.style.display = 'block';
      pipVideo.style.display = 'block';
      mainVideo.setAttribute('playsinline', '');
      mainVideo.setAttribute('webkit-playsinline', '');
      pipVideo.setAttribute('playsinline', '');
      pipVideo.setAttribute('webkit-playsinline', '');

      if (els.mainLabel) {
        els.mainLabel.textContent = remoteIsMain() ? remoteName : localName;
        els.mainLabel.title = els.mainLabel.textContent;
      }
      if (els.pipLabel) {
        els.pipLabel.textContent = remoteIsMain() ? localName : remoteName;
        els.pipLabel.title = els.pipLabel.textContent;
      }

      const enableBtn = q('enableSoundBtn');
      if (enableBtn && remoteV.parentElement === mainSlot && enableBtn.parentElement !== mainSlot) {
        mainSlot.appendChild(enableBtn);
      } else if (enableBtn && remoteV.parentElement !== mainSlot && enableBtn.parentElement !== pipSlot) {
        pipSlot.appendChild(enableBtn);
      }
    }

    function swapViews() {
      swapped = !swapped;
      mountVideos();
      showControlsTemporarily();
    }

    function snapCorner(x, y, stageRect) {
      const midX = stageRect.left + stageRect.width / 2;
      const midY = stageRect.top + stageRect.height / 2;
      const corner = (y < midY ? 'top' : 'bottom') + '-' + (x < midX ? 'left' : 'right');
      if (els.pip) {
        els.pip.setAttribute('data-corner', corner);
        try { localStorage.setItem('mc-vc-pip-corner', corner); } catch (e) {}
      }
    }

    function initPipDrag() {
      const pip = els.pip;
      const stage = els.stage;
      if (!pip || !stage) return;

      const saved = (function () {
        try { return localStorage.getItem('mc-vc-pip-corner'); } catch (e) { return null; }
      })();
      const isMobile = window.matchMedia && window.matchMedia('(max-width: 720px)').matches;
      if (saved && CORNERS.indexOf(saved) >= 0) {
        // Prefer top corners so PiP does not cover faces or the control bar.
        if (String(saved).indexOf('bottom') === 0) {
          pip.setAttribute('data-corner', 'top-right');
        } else {
          pip.setAttribute('data-corner', saved);
        }
      } else {
        pip.setAttribute('data-corner', 'top-right');
      }

      function syncPipForViewport() {
        if (!window.matchMedia || !window.matchMedia('(max-width: 720px)').matches) return;
        const corner = pip.getAttribute('data-corner') || '';
        if (corner.indexOf('bottom') === 0) {
          pip.setAttribute('data-corner', 'top-right');
        }
      }
      window.addEventListener('orientationchange', syncPipForViewport);
      window.addEventListener('resize', syncPipForViewport);

      let dragging = false;
      let startX = 0;
      let startY = 0;
      let originLeft = 0;
      let originTop = 0;

      function onPointerDown(e) {
        if (isFloating && e.target.closest('.mc-vc-pip-swap')) return;
        dragging = true;
        pip.classList.add('is-dragging');
        pip.setAttribute('data-corner', '');
        const rect = pip.getBoundingClientRect();
        originLeft = rect.left;
        originTop = rect.top;
        startX = e.clientX;
        startY = e.clientY;
        pip.style.left = originLeft + 'px';
        pip.style.top = originTop + 'px';
        pip.style.right = 'auto';
        pip.style.bottom = 'auto';
        pip.style.position = 'fixed';
        try { pip.setPointerCapture(e.pointerId); } catch (err) {}
        e.preventDefault();
      }

      function onPointerMove(e) {
        if (!dragging) return;
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        pip.style.left = (originLeft + dx) + 'px';
        pip.style.top = (originTop + dy) + 'px';
      }

      function onPointerUp(e) {
        if (!dragging) return;
        dragging = false;
        pip.classList.remove('is-dragging');
        try { pip.releasePointerCapture(e.pointerId); } catch (err) {}
        const stageRect = stage.getBoundingClientRect();
        const pipRect = pip.getBoundingClientRect();
        const cx = pipRect.left + pipRect.width / 2;
        const cy = pipRect.top + pipRect.height / 2;
        pip.style.position = 'absolute';
        pip.style.left = '';
        pip.style.top = '';
        snapCorner(cx, cy, stageRect);
      }

      pip.addEventListener('pointerdown', onPointerDown);
      pip.addEventListener('pointermove', onPointerMove);
      pip.addEventListener('pointerup', onPointerUp);
      pip.addEventListener('pointercancel', onPointerUp);
    }

    function isMobileLayout() {
      return window.matchMedia('(max-width: 768px)').matches;
    }

    function showControlsTemporarily() {
      if (!els.root) return;
      els.root.classList.remove('controls-hidden');
      els.root.classList.add('controls-visible');
      controlsHidden = false;
      clearTimeout(controlsTimer);
      if ((isFullscreen || document.fullscreenElement) && !isMobileLayout()) {
        controlsTimer = setTimeout(() => {
          els.root.classList.add('controls-hidden');
          els.root.classList.remove('controls-visible');
          controlsHidden = true;
        }, CONTROLS_HIDE_MS);
      }
    }

    function bindStageTap() {
      const stage = els.stage;
      if (!stage) return;
      stage.addEventListener('click', () => {
        if (isMobileLayout()) return;
        if (isFullscreen || document.fullscreenElement) {
          if (controlsHidden) {
            showControlsTemporarily();
          } else {
            els.root.classList.add('controls-hidden');
            controlsHidden = true;
          }
        }
      });
    }

    function updateFullscreenBtn() {
      if (!els.fullscreenBtn) return;
      const inApp = embedded && isProvider;
      const label = isFullscreen
        ? (inApp ? 'Restore video' : 'Exit fullscreen')
        : (inApp ? 'Expand video' : 'Enter fullscreen');
      els.fullscreenBtn.setAttribute('aria-label', isFullscreen
        ? (inApp ? 'Restore video consultation' : 'Exit fullscreen')
        : (inApp ? 'Maximize video consultation' : 'Enter fullscreen'));
      els.fullscreenBtn.title = label;
      els.fullscreenBtn.innerHTML = svgIcon(isFullscreen
        ? (inApp ? 'minimize' : 'fullscreenExit')
        : (inApp ? 'maximize' : 'fullscreen'));
    }

    function toggleFullscreen() {
      const target = els.root;
      if (!target) return;

      if (embedded) {
        const entering = !isFullscreen;
        isFullscreen = entering;
        if (isProvider) {
          target.classList.remove('is-fullscreen');
        } else {
          target.classList.toggle('is-fullscreen', entering);
        }
        if (entering) {
          if (onMaximize) onMaximize();
        } else if (onMinimize) {
          onMinimize();
        }
        updateFullscreenBtn();
        showControlsTemporarily();
        return;
      }

      if (!document.fullscreenElement && !isFullscreen) {
        const req = target.requestFullscreen || target.webkitRequestFullscreen;
        if (req) {
          req.call(target).then(() => {
            isFullscreen = true;
            target.classList.add('is-fullscreen');
            updateFullscreenBtn();
            showControlsTemporarily();
          }).catch(() => {
            isFullscreen = true;
            target.classList.add('is-fullscreen');
            updateFullscreenBtn();
            showControlsTemporarily();
          });
        } else {
          isFullscreen = true;
          target.classList.add('is-fullscreen');
          updateFullscreenBtn();
          showControlsTemporarily();
        }
      } else {
        const exit = document.exitFullscreen || document.webkitExitFullscreen;
        if (document.fullscreenElement && exit) {
          exit.call(document);
        }
        isFullscreen = false;
        target.classList.remove('is-fullscreen', 'controls-hidden');
        target.classList.remove('controls-visible');
        updateFullscreenBtn();
      }
    }

    document.addEventListener('fullscreenchange', () => {
      if (!els.root) return;
      isFullscreen = !!document.fullscreenElement;
      els.root.classList.toggle('is-fullscreen', isFullscreen);
      if (!isFullscreen) {
        els.root.classList.remove('controls-hidden');
      }
      updateFullscreenBtn();
    });

    function setMobileFullscreen(expanded) {
      isFullscreen = !!expanded;
      if (els.root) {
        if (embedded && isProvider) {
          els.root.classList.remove('is-fullscreen');
        } else {
          els.root.classList.toggle('is-fullscreen', isFullscreen);
        }
      }
      updateFullscreenBtn();
    }

    function toggleFloating() {
      if (!els.root) return;

      if (embedded) {
        isFloating = !isFloating;
        if (isFloating) {
          if (onMinimize) onMinimize();
        } else {
          if (onMaximize) onMaximize();
        }
        if (els.minimizeBtn) {
          els.minimizeBtn.innerHTML = isFloating ? svgIcon('maximize') : svgIcon('minimize');
          els.minimizeBtn.title = isFloating ? 'Maximize call' : 'Minimize call';
        }
        return;
      }

      isFloating = !isFloating;
      els.root.classList.toggle('is-floating', isFloating);
      els.root.classList.toggle('mc-vc-drag-handle', isFloating);

      if (isFloating) {
        initFloatingDrag(els.root);
        if (embedded && onMinimize) onMinimize();
        else if (!embedded) {
          // Standalone: stay on page but float
        }
        if (els.minimizeBtn) {
          els.minimizeBtn.innerHTML = svgIcon('maximize');
          els.minimizeBtn.title = 'Maximize call';
        }
      } else {
        els.root.style.top = '';
        els.root.style.left = '';
        if (embedded && onMaximize) onMaximize();
        if (els.minimizeBtn) {
          els.minimizeBtn.innerHTML = svgIcon('minimize');
          els.minimizeBtn.title = 'Minimize call';
        }
      }
    }

    function initFloatingDrag(node) {
      if (node.dataset.floatDragBound) return;
      node.dataset.floatDragBound = '1';
      const handle = els.header || node;

      let dragging = false;
      let sx = 0;
      let sy = 0;
      let ox = 0;
      let oy = 0;

      function down(e) {
        if (!node.classList.contains('is-floating')) return;
        if (e.target.closest('.mc-vc-controls') || e.target.closest('button') || e.target.closest('a')) return;
        dragging = true;
        const rect = node.getBoundingClientRect();
        ox = rect.left;
        oy = rect.top;
        sx = e.clientX;
        sy = e.clientY;
        try { handle.setPointerCapture(e.pointerId); } catch (err) {}
        e.preventDefault();
      }

      function move(e) {
        if (!dragging) return;
        const x = ox + (e.clientX - sx);
        const y = oy + (e.clientY - sy);
        node.style.left = Math.max(8, Math.min(window.innerWidth - node.offsetWidth - 8, x)) + 'px';
        node.style.top = Math.max(8, Math.min(window.innerHeight - node.offsetHeight - 8, y)) + 'px';
      }

      function up(e) {
        if (!dragging) return;
        dragging = false;
        try { handle.releasePointerCapture(e.pointerId); } catch (err) {}
      }

      handle.addEventListener('pointerdown', down);
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', up);
      handle.addEventListener('pointercancel', up);
    }

    async function switchCamera() {
      const localV = getLocalVideo();
      if (!localV || !localV.srcObject) return;
      const stream = localV.srcObject;
      const videoTrack = stream.getVideoTracks()[0];
      if (!videoTrack) return;

      facingMode = facingMode === 'user' ? 'environment' : 'user';
      try {
        const videoConstraints = (window.McVideoCallCore && typeof McVideoCallCore.getVideoConstraints === 'function')
          ? McVideoCallCore.getVideoConstraints({ facingMode: { ideal: facingMode } })
          : { facingMode: { ideal: facingMode }, width: { max: 1280, ideal: 640 }, height: { max: 720, ideal: 480 }, frameRate: { max: 24, ideal: 20 } };
        const newStream = await navigator.mediaDevices.getUserMedia({
          video: videoConstraints,
          audio: false,
        });
        const newTrack = newStream.getVideoTracks()[0];
        if (!newTrack) return;

        if (window.McWebrtcPeerCall && typeof McWebrtcPeerCall.replaceLocalVideoTrack === 'function') {
          await McWebrtcPeerCall.replaceLocalVideoTrack(newTrack);
        } else if (typeof window.__mcReplaceVideoTrack === 'function') {
          await window.__mcReplaceVideoTrack(newTrack);
          stream.removeTrack(videoTrack);
          videoTrack.stop();
          stream.addTrack(newTrack);
          localV.srcObject = stream;
        }

        newStream.getTracks().forEach((t) => {
          if (t !== newTrack) t.stop();
        });
        mountVideos();
        if (localV.paused) {
          const p = localV.play();
          if (p && typeof p.catch === 'function') p.catch(() => {});
        }
      } catch (e) {
        console.warn('Camera switch failed:', e);
        facingMode = facingMode === 'user' ? 'environment' : 'user';
      }
    }

    function toggleSpeaker() {
      speakerOn = !speakerOn;
      const audioEl = q('remoteAudio');
      if (audioEl) {
        audioEl.muted = !speakerOn;
        audioEl.volume = speakerOn ? 1 : 0;
        if (speakerOn) {
          try { audioEl.play(); } catch (e) {}
        }
      }
      if (els.speakerBtn) {
        els.speakerBtn.classList.toggle('off', !speakerOn);
        els.speakerBtn.setAttribute('aria-pressed', speakerOn ? 'false' : 'true');
      }
    }

    function formatDuration(sec) {
      const m = Math.floor(sec / 60);
      const s = sec % 60;
      return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function startDurationTimer() {
      if (durationInterval) return;
      durationInterval = setInterval(() => {
        durationSeconds++;
        if (els.durationEl) els.durationEl.textContent = formatDuration(durationSeconds);
      }, 1000);
    }

    function stopMonitors() {
      if (durationInterval) {
        clearInterval(durationInterval);
        durationInterval = null;
      }
      if (networkInterval) {
        clearInterval(networkInterval);
        networkInterval = null;
      }
      if (controlsTimer) {
        clearTimeout(controlsTimer);
        controlsTimer = null;
      }
    }

    let connectionFailed = false;

    function setRetryVisible(show) {
      const retryBtn = q('retryConnectBtn');
      const waitingRetry = q('mcVcWaitingRetry');
      if (retryBtn) retryBtn.hidden = !show;
      if (waitingRetry) waitingRetry.hidden = !show;
    }

    function setOverlay(title, sub, visible, options) {
      options = options || {};
      if (window.__mcCallEnded && visible && !/ended|completed/i.test(String(title || ''))) {
        visible = false;
      }
      if (els.overlay) {
        if (els.overlayTitle) els.overlayTitle.textContent = title || '';
        if (els.overlaySub) els.overlaySub.textContent = sub || '';
        els.overlay.classList.toggle('is-visible', !!visible);
        els.overlay.setAttribute('aria-hidden', visible ? 'false' : 'true');
        const blob = String(title || '') + ' ' + String(sub || '');
        const isStatus = !!visible && options.showRetry !== true && /waiting|connecting|reconnecting|live|connected|poor|fair|muted|disconnected|lost|rejoin/i.test(blob);
        els.overlay.classList.toggle('is-status', isStatus);
      }
      if (options.showRetry === true) {
        connectionFailed = true;
        setRetryVisible(true);
      } else if (options.showRetry === false) {
        connectionFailed = false;
        setRetryVisible(false);
      } else if (!visible) {
        setRetryVisible(false);
      } else {
        setRetryVisible(connectionFailed);
      }
    }

    function setConnectionFailed(failed, message) {
      connectionFailed = !!failed;
      if (failed) {
        setOverlay(
          'Connection interrupted',
          message || 'We could not restore the call automatically. Tap Retry connection to try again.',
          true,
          { showRetry: true }
        );
      } else {
        setRetryVisible(false);
      }
    }

    function updateOverlayFromStatus(text) {
      if (window.__mcCallEnded) {
        setOverlay('', '', false, { showRetry: false });
        return;
      }
      const t = String(text || '').toLowerCase();
      if (t.indexOf('waiting for healthcare') >= 0 || t.indexOf('waiting for provider') >= 0 || t.indexOf('waiting for doctor') >= 0) {
        setOverlay('Waiting for healthcare provider…', '', true, { showRetry: false });
      } else if (t.indexOf('you left') >= 0) {
        setOverlay('You left the consultation', 'This visit is still active. Rejoin to reconnect with your doctor.', true, { showRetry: true });
      } else if (t.indexOf('patient temporarily left') >= 0 || t.indexOf('patient disconnected') >= 0 || t.indexOf('waiting for patient to reconnect') >= 0) {
        setOverlay('Patient temporarily left', 'The consultation is still active. Waiting for the patient to rejoin…', true, { showRetry: false });
      } else if (t.indexOf('waiting for patient') >= 0) {
        setOverlay('Waiting for patient…', '', true, { showRetry: false });
      } else if (t.indexOf('connection lost') >= 0 || t.indexOf('trying to reconnect') >= 0) {
        setOverlay('Connection lost', 'Trying to reconnect…', true, { showRetry: false });
      } else if (t.indexOf('reconnected') >= 0) {
        setOverlay('Patient reconnected', '', true, { showRetry: false });
      } else if (t.indexOf('reconnecting') >= 0 || t.indexOf('connecting') >= 0 || t.indexOf('poor network') >= 0 || t.indexOf('poor connection') >= 0) {
        setOverlay('', '', false, { showRetry: false });
      } else if (t.indexOf('ended') >= 0 || t.indexOf('consultation ended') >= 0) {
        setOverlay('Consultation ended', '', true, { showRetry: false });
      } else if (t.indexOf('connected') >= 0) {
        connectionFailed = false;
        setOverlay('', '', false, { showRetry: false });
        startDurationTimer();
      } else {
        setOverlay('', '', false, { showRetry: false });
      }
    }

    function watchCallStatus() {
      const statusEl = q('callStatus');
      if (!statusEl) return;
      const observer = new MutationObserver(() => {
        updateOverlayFromStatus(statusEl.textContent);
        const connected = /connected/i.test(statusEl.textContent);
        if (els.liveDot) els.liveDot.classList.toggle('is-connected', connected);
      });
      observer.observe(statusEl, { childList: true, characterData: true, subtree: true });
      updateOverlayFromStatus(statusEl.textContent);
    }

    function getPeerConnection() {
      try {
        if (window.McWebrtcPeerCall && typeof McWebrtcPeerCall.getPeerConnection === 'function') {
          const fromPeer = McWebrtcPeerCall.getPeerConnection();
          if (fromPeer) return fromPeer;
        }
        if (window.__mcCurrentCall && window.__mcCurrentCall.peerConnection) {
          return window.__mcCurrentCall.peerConnection;
        }
      } catch (e) {}
      return null;
    }

    function ensureStatsPanel() {
      if (!window.McVideoCallCore || !McVideoCallCore.debugEnabled()) return null;
      let panel = q('mcWebrtcStatsPanel');
      if (panel) return panel;
      panel = document.createElement('pre');
      panel.id = 'mcWebrtcStatsPanel';
      panel.setAttribute('aria-hidden', 'true');
      panel.style.cssText = 'position:fixed;right:8px;bottom:8px;z-index:9999;max-width:min(360px,92vw);max-height:40vh;overflow:auto;margin:0;padding:10px 12px;border-radius:10px;background:rgba(2,6,23,0.88);color:#e2e8f0;font:11px/1.4 ui-monospace,Consolas,monospace;pointer-events:none;';
      document.body.appendChild(panel);
      return panel;
    }

    function startNetworkMonitor() {
      if (networkInterval) return;
      const collect = (window.McVideoCallCore && typeof McVideoCallCore.createStatsCollector === 'function')
        ? McVideoCallCore.createStatsCollector()
        : null;

      networkInterval = setInterval(async () => {
        if (window.__mcCallEnded) return;
        const pc = getPeerConnection();
        const netEl = els.networkPill || q('mediaStatusConn');
        if (!netEl) return;

        const statusEl = q('callStatus');
        const statusText = statusEl ? String(statusEl.textContent || '') : '';
        const mediaLinked = !!(window.McWebrtcPeerCall && McWebrtcPeerCall.hasRemoteStream && McWebrtcPeerCall.hasRemoteStream());
        const looksConnected = mediaLinked || /\bconnected\b/i.test(statusText);

        if (!pc || !looksConnected) {
          if (/reconnecting/i.test(statusText)) {
            netEl.textContent = '◌ Reconnecting…';
            netEl.dataset.state = 'reconnecting';
            netEl.dataset.level = 'reconnecting';
          } else if (/waiting|connecting/i.test(statusText)) {
            netEl.textContent = '◌ Connecting…';
            netEl.dataset.state = 'connecting';
            netEl.dataset.level = 'connecting';
          }
          return;
        }

        try {
          const snapshot = collect ? await collect(pc) : null;
          const ice = pc.iceConnectionState || '';
          const conn = pc.connectionState || '';
          let quality = { level: 'good', label: '● Good Connection' };
          if (window.McVideoCallCore && typeof McVideoCallCore.qualityFromStats === 'function' && snapshot) {
            quality = McVideoCallCore.qualityFromStats(snapshot, ice, conn);
          }
          netEl.textContent = quality.label;
          netEl.dataset.state = quality.level;
          netEl.dataset.level = quality.level;
          window.__mcWebrtcStats = snapshot;

          const panel = ensureStatsPanel();
          if (panel && snapshot) {
            panel.textContent = [
              'ICE ' + ice + ' / PC ' + conn,
              'pair ' + (snapshot.localCandidateType || '?') + ' → ' + (snapshot.remoteCandidateType || '?') + (snapshot.usingTurn ? ' (TURN)' : ''),
              'RTT ' + Math.round((snapshot.rtt || 0) * 1000) + 'ms  jitter ' + (snapshot.jitter || 0).toFixed(3),
              'loss ' + ((snapshot.lossRate || 0) * 100).toFixed(1) + '%  fps ' + Math.round(snapshot.fps || 0),
              (snapshot.width || 0) + '×' + (snapshot.height || 0) + '  ' + Math.round((snapshot.outboundBitrate || 0) / 1000) + ' kbps out',
              'in ' + Math.round((snapshot.inboundBitrate || 0) / 1000) + ' kbps  dropped ' + (snapshot.framesDropped || 0),
              snapshot.codec || '',
            ].filter(Boolean).join('\n');
          }
        } catch (e) {}
      }, 4000);
    }

    function wireControlButtons() {
      if (els.swapBtn) els.swapBtn.addEventListener('click', swapViews);
      if (els.fullscreenBtn) els.fullscreenBtn.addEventListener('click', toggleFullscreen);
      if (els.minimizeBtn) els.minimizeBtn.addEventListener('click', toggleFloating);
      if (els.flipBtn) els.flipBtn.addEventListener('click', switchCamera);
      if (els.speakerBtn) els.speakerBtn.addEventListener('click', toggleSpeaker);
    }

    function bindExistingControls() {
      // Sync mute/video button classes with UI module
      const muteBtn = q('muteAudio');
      const videoBtn = q('toggleVideo');
      if (muteBtn) {
        muteBtn.classList.add('mc-vc-btn');
        if (muteBtn.classList.contains('btn-mute')) muteBtn.classList.remove('btn-mute');
      }
      if (videoBtn) {
        videoBtn.classList.add('mc-vc-btn');
        if (videoBtn.classList.contains('btn-mute')) videoBtn.classList.remove('btn-mute');
      }
      const endBtn = q('endCallBtn');
      if (endBtn) {
        endBtn.classList.add('mc-vc-btn', 'mc-vc-btn--end');
        if (endBtn.classList.contains('btn-end')) endBtn.classList.remove('btn-end');
      }
      const reportBtn = q('violationReportBtn');
      if (reportBtn && !reportBtn.classList.contains('mc-vc-more-item')) {
        reportBtn.classList.add('mc-vc-btn', 'mc-vc-btn--report');
      }
    }

    function bindMoreMenu() {
      const btn = q('mcVcMoreBtn');
      const menu = q('mcVcMoreMenu');
      if (!btn || !menu) return;
      const home = menu.parentElement;

      function placeMenu() {
        const rect = btn.getBoundingClientRect();
        const vw = window.innerWidth || document.documentElement.clientWidth || 0;
        const vh = window.innerHeight || document.documentElement.clientHeight || 0;
        const pad = 8;
        menu.classList.add('is-ported');
        menu.hidden = false;
        const mw = Math.max(menu.offsetWidth || 0, 188);
        const mh = Math.max(menu.offsetHeight || 0, 48);
        let left = rect.right - mw;
        let top = rect.top - mh - 10;
        if (left < pad) left = pad;
        if (left + mw > vw - pad) left = Math.max(pad, vw - mw - pad);
        if (top < pad) {
          top = rect.bottom + 10;
          if (top + mh > vh - pad) top = Math.max(pad, vh - mh - pad);
        }
        menu.style.position = 'fixed';
        menu.style.left = Math.round(left) + 'px';
        menu.style.top = Math.round(top) + 'px';
        menu.style.right = 'auto';
        menu.style.bottom = 'auto';
        menu.style.zIndex = '100200';
      }

      function setOpen(open) {
        if (open) {
          if (document.body.classList.contains('mc-vc-call-ended') || window.__mcCallEnded) {
            return;
          }
          if (menu.parentElement !== document.body) {
            document.body.appendChild(menu);
          }
          placeMenu();
        } else {
          menu.hidden = true;
          menu.classList.remove('is-ported');
          menu.style.left = '';
          menu.style.top = '';
          menu.style.right = '';
          menu.style.bottom = '';
          menu.style.position = '';
          menu.style.zIndex = '';
          if (home && menu.parentElement !== home) {
            home.appendChild(menu);
          }
        }
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      }

      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        setOpen(menu.hidden);
      });
      menu.querySelectorAll('[data-mc-proxy]').forEach((item) => {
        item.addEventListener('click', () => {
          const target = q(item.getAttribute('data-mc-proxy') || '');
          if (target) target.click();
          setOpen(false);
        });
      });
      menu.querySelectorAll('button:not([data-mc-proxy])').forEach((item) => {
        item.addEventListener('click', () => setOpen(false));
      });
      document.addEventListener('click', (e) => {
        if (menu.hidden) return;
        if (menu.contains(e.target) || btn.contains(e.target)) return;
        setOpen(false);
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setOpen(false);
      });
      window.addEventListener('resize', () => {
        if (!menu.hidden) placeMenu();
      });
      window.addEventListener('orientationchange', () => {
        if (!menu.hidden) placeMenu();
      });
      if (typeof MutationObserver === 'function') {
        new MutationObserver(() => {
          if (document.body.classList.contains('mc-vc-call-ended') || document.body.classList.contains('is-ended-consultation')) {
            setOpen(false);
          }
        }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
      }

      els.closeMoreMenu = function () { setOpen(false); };
    }

    function init() {
      const root = q('mcVideoConsultRoot');
      if (!root) return;
      if (root.dataset.mcVcInit === '1') return;
      root.dataset.mcVcInit = '1';

      els.root = root;
      els.stage = q('mcVcStage');
      els.mainSlot = q('mcVcMainSlot');
      els.pipSlot = q('mcVcPipSlot');
      els.pip = q('mcVcPip');
      els.mainLabel = q('mcVcMainLabel');
      els.pipLabel = q('mcVcPipLabel');
      els.header = q('mcVcHeader');
      els.overlay = q('mcVcOverlay');
      els.overlayTitle = q('mcVcOverlayTitle');
      els.overlaySub = q('mcVcOverlaySub');
      els.durationEl = q('consultDuration');
      els.liveDot = q('mcVcLiveDot');
      els.networkPill = q('mediaStatusConn');
      els.swapBtn = q('mcVcSwapBtn');
      els.fullscreenBtn = q('mcVcFullscreenBtn');
      els.minimizeBtn = q('mcVcMinimizeBtn');
      els.flipBtn = q('mcVcFlipBtn');
      els.speakerBtn = q('mcVcSpeakerBtn');

      mountVideos();
      initPipDrag();
      bindStageTap();
      wireControlButtons();
      bindExistingControls();
      bindMoreMenu();
      updateFullscreenBtn();
      watchCallStatus();
      startNetworkMonitor();

      if (els.durationEl) els.durationEl.textContent = '00:00';

      // Provider header shows patient info; patient sees provider
      const remoteParticipant = q('mcVcRemoteParticipant');
      if (remoteParticipant) {
        if (isPatient) {
          const av = remoteParticipant.querySelector('.mc-vc-avatar');
          const nm = remoteParticipant.querySelector('.mc-vc-participant-name');
          const sub = remoteParticipant.querySelector('.mc-vc-participant-sub');
          if (av) av.textContent = providerInitials;
          if (nm) {
            nm.textContent = providerName;
            nm.setAttribute('title', providerName);
          }
          if (sub) sub.textContent = providerSpecialty;
        } else {
          const av = remoteParticipant.querySelector('.mc-vc-avatar');
          const nm = remoteParticipant.querySelector('.mc-vc-participant-name');
          const sub = remoteParticipant.querySelector('.mc-vc-participant-sub');
          if (av) av.textContent = patientInitials;
          if (nm) {
            nm.textContent = patientName;
            nm.setAttribute('title', patientName);
          }
          if (sub) sub.textContent = 'Patient';
        }
      }
    }

    return {
      init,
      mountVideos,
      swapViews,
      toggleFloating,
      toggleFullscreen,
      setMobileFullscreen,
      showControlsTemporarily,
      updateOverlayFromStatus,
      setOverlay,
      setConnectionFailed,
      setRetryVisible,
      startDurationTimer,
      stopMonitors,
      closeMoreMenu: () => { if (typeof els.closeMoreMenu === 'function') els.closeMoreMenu(); },
      getIsFloating: () => isFloating,
    };
  }

  global.McVideoConsultationUi = {
    createController,
    CORNERS,
  };
})(window);
