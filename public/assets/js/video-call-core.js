/**
 * medConnect video call helpers — status, mic/cam indicators, remote audio unlock.
 * Keeps PeerJS call ownership in video_room.php; this module is UI + media helpers only.
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
    waiting_provider: 'Waiting for Provider…',
    waiting_patient: 'Waiting for Patient…',
    connected: 'Connected',
    reconnecting: 'Reconnecting…',
    ended: 'Call Ended',
    permission: 'Allow camera & microphone…',
  };

  function ensureRemoteAudioEl() {
    let el = document.getElementById('remoteAudio');
    if (!el) {
      el = document.createElement('audio');
      el.id = 'remoteAudio';
      el.autoplay = true;
      el.playsInline = true;
      el.setAttribute('playsinline', '');
      el.style.display = 'none';
      document.body.appendChild(el);
    }
    return el;
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

    if (videoEl) {
      videoEl.srcObject = stream;
      videoEl.playsInline = true;
      // Start muted so Chrome allows autoplay; unlockRemoteAudio unmutes after gesture / permission.
      videoEl.muted = true;
      const vp = videoEl.play();
      if (vp && typeof vp.catch === 'function') vp.catch(() => {});
    }

    // Audio element must use audio tracks only — some Chrome builds ignore audio on mixed streams.
    try {
      audioEl.srcObject = audioTracks.length
        ? new MediaStream(audioTracks)
        : stream;
    } catch (e) {
      audioEl.srcObject = stream;
    }
    audioEl.muted = false;
    audioEl.volume = 1;

    const tryPlayAudio = () => {
      const p = audioEl.play();
      if (p && typeof p.catch === 'function') {
        return p.then(() => true).catch(() => false);
      }
      return Promise.resolve(true);
    };

    const tryUnmuteVideo = () => {
      if (!videoEl) return Promise.resolve(false);
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

    return tryPlayAudio().then((audioOk) => {
      if (audioOk) {
        // Avoid double playback: keep video muted when dedicated audio works.
        if (videoEl) videoEl.muted = true;
        if (enableSoundBtn) enableSoundBtn.hidden = true;
        return true;
      }
      return tryUnmuteVideo().then((videoOk) => {
        if (videoOk) {
          try { audioEl.pause(); } catch (e) {}
          if (enableSoundBtn) enableSoundBtn.hidden = true;
          return true;
        }
        if (enableSoundBtn) enableSoundBtn.hidden = false;
        return false;
      });
    });
  }

  function unlockRemoteAudio(options = {}) {
    const audioEl = ensureRemoteAudioEl();
    const videoEl = document.getElementById('remoteVideo');
    const enableSoundBtn = options.enableSoundBtn || document.getElementById('enableSoundBtn');

    if (!audioEl.srcObject && videoEl && videoEl.srcObject) {
      const tracks = videoEl.srcObject.getAudioTracks ? videoEl.srcObject.getAudioTracks() : [];
      try {
        audioEl.srcObject = tracks.length ? new MediaStream(tracks) : videoEl.srcObject;
      } catch (e) {
        audioEl.srcObject = videoEl.srcObject;
      }
    }
    if (!audioEl.srcObject && !(videoEl && videoEl.srcObject)) {
      return Promise.resolve(false);
    }

    audioEl.muted = false;
    audioEl.volume = 1;

    const playAudio = () => {
      if (!audioEl.srcObject) return Promise.resolve(false);
      const p = audioEl.play();
      if (p && typeof p.catch === 'function') {
        return p.then(() => true).catch(() => false);
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
        return true;
      }
      return playVideo().then((vOk) => {
        if (vOk) {
          try { audioEl.pause(); } catch (e) {}
          if (enableSoundBtn) enableSoundBtn.hidden = true;
          return true;
        }
        if (enableSoundBtn) enableSoundBtn.hidden = false;
        return false;
      });
    });
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
      else if (mic === 'off') micEl.textContent = '🔇 Microphone Off';
      else if (mic === 'denied') micEl.textContent = '⚠ Microphone Permission Denied';
      else micEl.textContent = '⚠ Microphone Unavailable';
      micEl.dataset.state = mic;
      micEl.classList.toggle('is-off', mic === 'off' || mic === 'denied' || mic === 'unavailable');
    }

    if (camEl) {
      if (cam === 'on') camEl.textContent = '📷 Camera On';
      else if (cam === 'off') camEl.textContent = '📷 Camera Off';
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
    if (statusKey === STATUS.RECONNECTING) return '◌ Poor Network / Reconnecting';
    if (statusKey === STATUS.WAITING_PROVIDER) return '◌ Waiting for Provider';
    if (statusKey === STATUS.WAITING_PATIENT) return '◌ Waiting for Patient';
    if (statusKey === STATUS.ENDED) return '○ Call Ended';
    return '◌ Connecting';
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
  };
})(window);
