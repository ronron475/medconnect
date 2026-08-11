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

    function providerIsMain() {
      // Default: provider feed on the large main view for both roles.
      // Patient sees the doctor large; provider sees themselves large with patient in PiP.
      if (swapped) return false;
      return true;
    }

    function mountVideos() {
      const mainSlot = els.mainSlot;
      const pipSlot = els.pipSlot;
      const localV = getLocalVideo();
      const remoteV = getRemoteVideo();
      if (!mainSlot || !pipSlot || !localV || !remoteV) return;

      const providerVideo = isProvider ? localV : remoteV;
      const patientVideo = isProvider ? remoteV : localV;

      mainSlot.innerHTML = '';
      pipSlot.innerHTML = '';

      if (providerIsMain()) {
        mainSlot.appendChild(providerVideo);
        pipSlot.appendChild(patientVideo);
        if (els.mainLabel) els.mainLabel.textContent = providerName;
        if (els.pipLabel) els.pipLabel.textContent = patientName;
      } else {
        mainSlot.appendChild(patientVideo);
        pipSlot.appendChild(providerVideo);
        if (els.mainLabel) els.mainLabel.textContent = patientName;
        if (els.pipLabel) els.pipLabel.textContent = providerName;
      }

      providerVideo.style.display = 'block';
      patientVideo.style.display = 'block';

      // Enable sound button stays on main remote area
      const enableBtn = q('enableSoundBtn');
      if (enableBtn && remoteV.parentElement !== mainSlot) {
        mainSlot.appendChild(enableBtn);
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
        // On phones, prefer top corners so PiP does not cover controls.
        if (isMobile && String(saved).indexOf('bottom') === 0) {
          pip.setAttribute('data-corner', 'top-right');
        } else {
          pip.setAttribute('data-corner', saved);
        }
      } else if (isMobile) {
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

    function showControlsTemporarily() {
      if (!els.root) return;
      els.root.classList.remove('controls-hidden');
      els.root.classList.add('controls-visible');
      controlsHidden = false;
      clearTimeout(controlsTimer);
      if (isFullscreen || document.fullscreenElement) {
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

    function toggleFullscreen() {
      const target = els.root;
      if (!target) return;

      if (!document.fullscreenElement && !isFullscreen) {
        const req = target.requestFullscreen || target.webkitRequestFullscreen;
        if (req) {
          req.call(target).catch(() => {
            isFullscreen = true;
            target.classList.add('is-fullscreen');
            showControlsTemporarily();
          });
        } else {
          isFullscreen = true;
          target.classList.add('is-fullscreen');
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
      }
    }

    document.addEventListener('fullscreenchange', () => {
      if (!els.root) return;
      if (!document.fullscreenElement) {
        isFullscreen = false;
        els.root.classList.remove('is-fullscreen', 'controls-hidden');
      }
    });

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
        const newStream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: facingMode } },
          audio: false,
        });
        const newTrack = newStream.getVideoTracks()[0];
        if (!newTrack) return;

        const senderReplace = window.__mcReplaceVideoTrack;
        if (typeof senderReplace === 'function') {
          await senderReplace(newTrack);
        }

        stream.removeTrack(videoTrack);
        videoTrack.stop();
        stream.addTrack(newTrack);
        localV.srcObject = stream;
        mountVideos();
      } catch (e) {
        console.warn('Camera switch failed:', e);
      }
    }

    function toggleSpeaker() {
      speakerOn = !speakerOn;
      const audioEl = q('remoteAudio');
      const remoteV = getRemoteVideo();
      if (audioEl) {
        audioEl.muted = !speakerOn;
        if (speakerOn) {
          try { audioEl.play(); } catch (e) {}
        }
      }
      if (remoteV) {
        remoteV.muted = !speakerOn;
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

    let connectionFailed = false;

    function setRetryVisible(show) {
      const retryBtn = q('retryConnectBtn');
      const waitingRetry = q('mcVcWaitingRetry');
      if (retryBtn) retryBtn.hidden = !show;
      if (waitingRetry) waitingRetry.hidden = !show;
    }

    function setOverlay(title, sub, visible, options) {
      options = options || {};
      if (els.overlay) {
        if (els.overlayTitle) els.overlayTitle.textContent = title || '';
        if (els.overlaySub) els.overlaySub.textContent = sub || '';
        els.overlay.classList.toggle('is-visible', !!visible);
        els.overlay.setAttribute('aria-hidden', visible ? 'false' : 'true');
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
      const t = String(text || '').toLowerCase();
      if (t.indexOf('waiting for healthcare') >= 0 || t.indexOf('waiting for provider') >= 0 || t.indexOf('waiting for doctor') >= 0) {
        setOverlay(
          isPatient ? 'Waiting for Healthcare Provider…' : 'Waiting for Patient…',
          isPatient
            ? 'Your doctor will connect shortly. This happens automatically — no action needed.'
            : 'The patient can join from their dashboard. Connection starts automatically when they arrive.',
          true,
          { showRetry: false }
        );
      } else if (t.indexOf('waiting for patient') >= 0) {
        setOverlay(
          'Waiting for Patient…',
          'The patient can join from their dashboard. Connection starts automatically when they arrive.',
          true,
          { showRetry: false }
        );
      } else if (t.indexOf('connecting') >= 0) {
        setOverlay('Connecting…', 'Establishing a secure consultation channel.', true, { showRetry: false });
      } else if (t.indexOf('reconnecting') >= 0) {
        setOverlay('Reconnecting…', 'Temporary network interruption — your call will resume automatically.', true, { showRetry: false });
      } else if (t.indexOf('poor network') >= 0) {
        setOverlay('Poor Network Connection', 'Move closer to your router or switch networks if possible.', true, { showRetry: false });
      } else if (t.indexOf('ended') >= 0 || t.indexOf('consultation ended') >= 0) {
        setOverlay('Consultation Ended', 'Thank you for using medConnect.', true, { showRetry: false });
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
        if (window.__mcCurrentCall && window.__mcCurrentCall.peerConnection) {
          return window.__mcCurrentCall.peerConnection;
        }
      } catch (e) {}
      return null;
    }

    function startNetworkMonitor() {
      if (networkInterval) clearInterval(networkInterval);
      networkInterval = setInterval(async () => {
        const pc = getPeerConnection();
        const netEl = els.networkPill || q('mediaStatusConn');
        if (!pc || !netEl) return;

        try {
          const stats = await pc.getStats();
          let packetsLost = 0;
          let packetsReceived = 0;
          let jitter = 0;

          stats.forEach((report) => {
            if (report.type === 'inbound-rtp' && report.kind === 'video') {
              packetsLost += report.packetsLost || 0;
              packetsReceived += report.packetsReceived || 0;
              jitter = report.jitter || 0;
            }
          });

          const total = packetsLost + packetsReceived;
          const lossRate = total > 0 ? packetsLost / total : 0;
          let level = 'good';
          let label = '● Good Connection';

          if (lossRate > 0.08 || jitter > 0.05) {
            level = 'poor';
            label = '◌ Poor Network Connection';
          } else if (lossRate > 0.02 || jitter > 0.02) {
            level = 'fair';
            label = '◌ Fair Connection';
          }

          netEl.textContent = label;
          netEl.dataset.state = level;
          netEl.dataset.level = level;

          if (level === 'poor' && els.overlay && !els.overlay.classList.contains('is-visible')) {
            const statusEl = q('callStatus');
            if (statusEl && /connected/i.test(statusEl.textContent)) {
              setOverlay('Poor Network Connection', 'Video quality may be reduced. Stay on the call — we are reconnecting if needed.', true);
              setTimeout(() => {
                if (/connected/i.test(statusEl.textContent)) setOverlay('', '', false);
              }, 4000);
            }
          }
        } catch (e) {}
      }, 5000);
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
    }

    function init() {
      const root = q('mcVideoConsultRoot');
      if (!root) return;

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
          if (nm) nm.textContent = providerName;
          if (sub) sub.textContent = providerSpecialty;
        } else {
          const av = remoteParticipant.querySelector('.mc-vc-avatar');
          const nm = remoteParticipant.querySelector('.mc-vc-participant-name');
          const sub = remoteParticipant.querySelector('.mc-vc-participant-sub');
          if (av) av.textContent = patientInitials;
          if (nm) nm.textContent = patientName;
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
      showControlsTemporarily,
      updateOverlayFromStatus,
      setOverlay,
      setConnectionFailed,
      setRetryVisible,
      startDurationTimer,
      getIsFloating: () => isFloating,
    };
  }

  global.McVideoConsultationUi = {
    createController,
    CORNERS,
  };
})(window);
