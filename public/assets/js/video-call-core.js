/**
 * medConnect video call helpers — status, mic/cam indicators, remote audio unlock.
 * Keeps PeerJS call ownership in webrtc-peer-call.js; this module is UI + media helpers only.
 */
(function (global) {
  'use strict';

  const STATUS = Object.freeze({
    CONNECTING: 'connecting',
    WAITING_PROVIDER: 'waiting_provider',
    WAITING_PATIENT: 'waiting_patient',
    CONNECTED: 'connected',
    RECONNECTING: 'reconnecting',
    ENDED: 'ended',
    PERMISSION: 'permission',
  });

  const STATUS_LABELS = {
    connecting: 'Connecting…',
    waiting_provider: 'Waiting for Healthcare Provider…',
    waiting_patient: 'Waiting for Patient…',
    connected: 'Connected',
    reconnecting: 'Reconnecting…',
    ended: 'Consultation Ended',
    permission: 'Allow camera & microphone…',
  };

  /**
   * One stable audio-only MediaStream is reused for the whole call. Re-assigning
   * audioEl.srcObject aborts any in-flight play() promise, which browsers surface as
   * an AbortError that is indistinguishable from a blocked autoplay — so the element
   * would be treated as "blocked" and left silent. Mutating one stream in place
   * avoids that entirely.
   */
  let remoteAudioStream = null;

  const diagnostics = {
    remoteAudioTracks: 0,
    remoteVideoTracks: 0,
    audioElementPlaying: false,
    audioPlaybackVia: 'none',
    lastPlayError: '',
    connectionState: '',
    iceConnectionState: '',
    signalingState: '',
  };

  function debugEnabled() {
    try {
      if (/[?&]mcdebug=1/.test(global.location.search || '')) return true;
      return global.localStorage && global.localStorage.getItem('mc_webrtc_debug') === '1';
    } catch (e) {
      return false;
    }
  }

  function debugLog() {
    if (!debugEnabled() || typeof console === 'undefined' || !console.debug) return;
    console.debug.apply(console, ['[McVideoCallCore]'].concat(Array.prototype.slice.call(arguments)));
  }

  function ensureRemoteAudioEl() {
    let el = document.getElementById('remoteAudio');
    if (!el) {
      el = document.createElement('audio');
      el.id = 'remoteAudio';
      el.autoplay = true;
      el.playsInline = true;
      el.setAttribute('playsinline', '');
      el.setAttribute('webkit-playsinline', '');
      el.preload = 'auto';
      el.style.display = 'none';
      document.body.appendChild(el);
    }
    return el;
  }

  /** Mutate the stable remote audio stream in place to match the incoming audio tracks. */
  function syncRemoteAudioStream(audioTracks) {
    if (!remoteAudioStream) remoteAudioStream = new MediaStream();
    const existing = remoteAudioStream.getAudioTracks();

    existing.forEach((track) => {
      const stillPresent = audioTracks.some((t) => t.id === track.id);
      if (!stillPresent || track.readyState === 'ended') {
        try { remoteAudioStream.removeTrack(track); } catch (e) {}
      }
    });

    audioTracks.forEach((track) => {
      const alreadyAdded = remoteAudioStream.getAudioTracks().some((t) => t.id === track.id);
      if (!alreadyAdded) {
        try { remoteAudioStream.addTrack(track); } catch (e) {}
      }
    });

    return remoteAudioStream;
  }

  /**
   * Attach remote MediaStream for viewing + reliable Chrome audio playback.
   * Uses an audio-only MediaStream on #remoteAudio, and falls back to unmuted <video>.
   */
  function attachRemoteMedia(stream, options = {}) {
    const videoEl = options.videoEl || document.getElementById('remoteVideo');
    const audioEl = ensureRemoteAudioEl();
    const enableSoundBtn = options.enableSoundBtn || document.getElementById('enableSoundBtn');

    const audioTracks = stream.getAudioTracks ? stream.getAudioTracks() : [];
    audioTracks.forEach((t) => {
      try { t.enabled = true; } catch (e) {}
    });

    diagnostics.remoteAudioTracks = audioTracks.length;
    diagnostics.remoteVideoTracks = stream.getVideoTracks ? stream.getVideoTracks().length : 0;

    if (!stream._mcTrackListener) {
      stream._mcTrackListener = true;
      stream.addEventListener('addtrack', (ev) => {
        if (ev.track && ev.track.kind === 'audio') {
          try { ev.track.enabled = true; } catch (e) {}
          attachRemoteMedia(stream, options);
        }
      });
    }

    if (videoEl) {
      if (videoEl.srcObject !== stream) videoEl.srcObject = stream;
      videoEl.playsInline = true;
      // Start muted so Chrome allows autoplay; unlockRemoteAudio unmutes after gesture / permission.
      videoEl.muted = true;
      if (videoEl.paused) {
        const vp = videoEl.play();
        if (vp && typeof vp.catch === 'function') vp.catch(() => {});
      }
    }

    // Audio element must use audio tracks only — some Chrome builds ignore audio on mixed streams.
    const audioStream = syncRemoteAudioStream(audioTracks);
    if (audioEl.srcObject !== audioStream) audioEl.srcObject = audioStream;
    audioEl.muted = false;
    audioEl.volume = 1;

    // Nothing to play yet. Stay silent rather than reporting success: the ontrack /
    // addtrack handlers re-enter here once the audio track actually arrives.
    if (!audioTracks.length) {
      diagnostics.audioPlaybackVia = 'none';
      diagnostics.audioElementPlaying = false;
      debugLog('remote stream has no audio track yet', { videoTracks: diagnostics.remoteVideoTracks });
      return Promise.resolve(false);
    }

    const tryPlayAudio = () => {
      if (!audioEl.paused) return Promise.resolve(true);
      const p = audioEl.play();
      if (p && typeof p.catch === 'function') {
        return p.then(() => true).catch((err) => {
          diagnostics.lastPlayError = (err && err.name) || 'PlayError';
          return false;
        });
      }
      return Promise.resolve(true);
    };

    const tryUnmuteVideo = () => {
      if (!videoEl || !videoEl.srcObject) return Promise.resolve(false);
      videoEl.muted = false;
      videoEl.volume = 1;
      const p = videoEl.play();
      if (p && typeof p.catch === 'function') {
        return p.then(() => true).catch((err) => {
          diagnostics.lastPlayError = (err && err.name) || 'PlayError';
          videoEl.muted = true;
          return false;
        });
      }
      return Promise.resolve(true);
    };

    return tryPlayAudio().then((audioOk) => {
      if (audioOk) {
        // Avoid double playback: keep video muted when dedicated audio works.
        if (videoEl) videoEl.muted = true;
        if (enableSoundBtn) enableSoundBtn.hidden = true;
        diagnostics.audioPlaybackVia = 'audio-element';
        diagnostics.audioElementPlaying = true;
        debugLog('remote audio playing via #remoteAudio', { tracks: audioTracks.length });
        return true;
      }
      return tryUnmuteVideo().then((videoOk) => {
        if (videoOk) {
          try { audioEl.pause(); } catch (e) {}
          if (enableSoundBtn) enableSoundBtn.hidden = true;
          diagnostics.audioPlaybackVia = 'video-element';
          diagnostics.audioElementPlaying = true;
          debugLog('remote audio playing via unmuted #remoteVideo');
          return true;
        }
        if (enableSoundBtn) enableSoundBtn.hidden = false;
        diagnostics.audioPlaybackVia = 'blocked';
        diagnostics.audioElementPlaying = false;
        debugLog('remote audio blocked', { lastPlayError: diagnostics.lastPlayError });
        return false;
      });
    });
  }

  function unlockRemoteAudio(options = {}) {
    const audioEl = ensureRemoteAudioEl();
    const videoEl = document.getElementById('remoteVideo');
    const enableSoundBtn = options.enableSoundBtn || document.getElementById('enableSoundBtn');

    if (videoEl && videoEl.srcObject && videoEl.srcObject.getAudioTracks) {
      syncRemoteAudioStream(videoEl.srcObject.getAudioTracks());
    }
    if (remoteAudioStream && audioEl.srcObject !== remoteAudioStream) {
      audioEl.srcObject = remoteAudioStream;
    }

    const hasAudioTrack = !!(remoteAudioStream && remoteAudioStream.getAudioTracks().length);
    if (!hasAudioTrack && !(videoEl && videoEl.srcObject)) {
      return Promise.resolve(false);
    }

    audioEl.muted = false;
    audioEl.volume = 1;

    const playAudio = () => {
      if (!hasAudioTrack) return Promise.resolve(false);
      if (!audioEl.paused) return Promise.resolve(true);
      const p = audioEl.play();
      if (p && typeof p.catch === 'function') {
        return p.then(() => true).catch((err) => {
          diagnostics.lastPlayError = (err && err.name) || 'PlayError';
          return false;
        });
      }
      return Promise.resolve(true);
    };

    const playVideo = () => {
      if (!videoEl || !videoEl.srcObject) return Promise.resolve(false);
      videoEl.muted = false;
      videoEl.volume = 1;
      const p = videoEl.play();
      if (p && typeof p.catch === 'function') {
        return p.then(() => true).catch(() => {
          videoEl.muted = true;
          return false;
        });
      }
      return Promise.resolve(true);
    };

    return playAudio().then((ok) => {
      if (ok) {
        if (videoEl) videoEl.muted = true;
        if (enableSoundBtn) enableSoundBtn.hidden = true;
        diagnostics.audioPlaybackVia = 'audio-element';
        diagnostics.audioElementPlaying = true;
        return true;
      }
      return playVideo().then((vOk) => {
        if (vOk) {
          try { audioEl.pause(); } catch (e) {}
          if (enableSoundBtn) enableSoundBtn.hidden = true;
          diagnostics.audioPlaybackVia = 'video-element';
          diagnostics.audioElementPlaying = true;
          return true;
        }
        if (enableSoundBtn) enableSoundBtn.hidden = false;
        diagnostics.audioPlaybackVia = 'blocked';
        diagnostics.audioElementPlaying = false;
        debugLog('unlock failed', { lastPlayError: diagnostics.lastPlayError });
        return false;
      });
    });
  }

  /**
   * Snapshot of the real media pipeline. Developer-facing only:
   * enable with ?mcdebug=1 or localStorage.mc_webrtc_debug = '1'.
   */
  function getDiagnostics(peerConnection, localStream) {
    const audioEl = document.getElementById('remoteAudio');
    const videoEl = document.getElementById('remoteVideo');
    const localAudio = localStream && localStream.getAudioTracks ? localStream.getAudioTracks()[0] : null;
    const remoteAudio = remoteAudioStream ? remoteAudioStream.getAudioTracks()[0] : null;

    if (peerConnection) {
      diagnostics.connectionState = peerConnection.connectionState || '';
      diagnostics.iceConnectionState = peerConnection.iceConnectionState || '';
      diagnostics.signalingState = peerConnection.signalingState || '';
    }

    return {
      connectionState: diagnostics.connectionState,
      iceConnectionState: diagnostics.iceConnectionState,
      signalingState: diagnostics.signalingState,
      localAudioTrack: localAudio
        ? { enabled: localAudio.enabled, muted: localAudio.muted, readyState: localAudio.readyState, label: localAudio.label }
        : null,
      remoteAudioTrack: remoteAudio
        ? { enabled: remoteAudio.enabled, muted: remoteAudio.muted, readyState: remoteAudio.readyState }
        : null,
      remoteAudioTracks: diagnostics.remoteAudioTracks,
      remoteVideoTracks: diagnostics.remoteVideoTracks,
      remoteStreamExists: !!(videoEl && videoEl.srcObject),
      audioElement: audioEl
        ? { paused: audioEl.paused, muted: audioEl.muted, volume: audioEl.volume, hasSrc: !!audioEl.srcObject }
        : null,
      videoElementMuted: videoEl ? videoEl.muted : null,
      audioPlaybackVia: diagnostics.audioPlaybackVia,
      lastPlayError: diagnostics.lastPlayError,
    };
  }

  function getAudioConstraints() {
    return {
      echoCancellation: true,
      noiseSuppression: true,
      autoGainControl: true,
    };
  }

  function micStateFromStream(stream) {
    if (!stream) return 'unavailable';
    const track = stream.getAudioTracks()[0];
    if (!track) return 'unavailable';
    if (track.readyState === 'ended') return 'unavailable';
    return track.enabled ? 'on' : 'off';
  }

  function camStateFromStream(stream) {
    if (!stream) return 'unavailable';
    const track = stream.getVideoTracks()[0];
    if (!track) return 'unavailable';
    if (track.readyState === 'ended') return 'unavailable';
    return track.enabled ? 'on' : 'off';
  }

  function updateMediaStatusUI(stream, extras = {}) {
    const micEl = document.getElementById('mediaStatusMic');
    const camEl = document.getElementById('mediaStatusCam');
    const connEl = document.getElementById('mediaStatusConn');
    const callStatus = document.getElementById('callStatus');

    const mic = extras.micPermissionDenied ? 'denied' : micStateFromStream(stream);
    const cam = camStateFromStream(stream);

    if (micEl) {
      if (mic === 'on') micEl.textContent = '🎤 Microphone On';
      else if (mic === 'off') micEl.textContent = '🔇 Microphone Muted';
      else if (mic === 'denied') micEl.textContent = '⚠ Mic Permission Denied';
      else micEl.textContent = '⚠ Microphone Unavailable';
      micEl.dataset.state = mic;
      micEl.classList.toggle('is-off', mic === 'off' || mic === 'denied' || mic === 'unavailable');
    }

    if (camEl) {
      if (cam === 'on') camEl.textContent = '📷 Camera On';
      else if (cam === 'off') camEl.textContent = '📷 Camera Disabled';
      else camEl.textContent = '📷 Camera Unavailable';
      camEl.dataset.state = cam;
      camEl.classList.toggle('is-off', cam !== 'on');
    }

    if (connEl && extras.connectionLabel) {
      connEl.textContent = extras.connectionLabel;
      connEl.dataset.state = extras.connectionState || '';
    }

    if (callStatus && extras.callStatusText) {
      callStatus.textContent = extras.callStatusText;
    }
  }

  function connectionLabelFor(role, statusKey) {
    if (statusKey === STATUS.CONNECTED) return '● Good Connection';
    if (statusKey === STATUS.RECONNECTING) return '◌ Reconnecting…';
    if (statusKey === STATUS.WAITING_PROVIDER) return '◌ Waiting for Healthcare Provider';
    if (statusKey === STATUS.WAITING_PATIENT) return '◌ Waiting for Patient';
    if (statusKey === STATUS.ENDED) return '○ Consultation Ended';
    return '◌ Connecting…';
  }

  function setCallPhase(role, statusKey, overrides = {}) {
    const label = overrides.callStatusText || STATUS_LABELS[statusKey] || statusKey;
    updateMediaStatusUI(overrides.stream || null, {
      callStatusText: label,
      connectionLabel: overrides.connectionLabel || connectionLabelFor(role, statusKey),
      connectionState: statusKey,
      micPermissionDenied: overrides.micPermissionDenied,
    });
    const dot = document.querySelector('.live-dot');
    if (dot) {
      dot.style.background = statusKey === STATUS.CONNECTED ? '#22c55e' : '#ef4444';
    }
    return label;
  }

  function stopStreamTracks(stream) {
    if (!stream) return;
    try {
      stream.getTracks().forEach((t) => {
        try { t.stop(); } catch (e) {}
      });
    } catch (e) {}
  }

  global.McVideoCallCore = {
    STATUS,
    STATUS_LABELS,
    attachRemoteMedia,
    unlockRemoteAudio,
    getAudioConstraints,
    micStateFromStream,
    camStateFromStream,
    updateMediaStatusUI,
    setCallPhase,
    connectionLabelFor,
    stopStreamTracks,
    ensureRemoteAudioEl,
    getDiagnostics,
    debugEnabled,
  };
})(window);
