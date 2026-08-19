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
      el.setAttribute('autoplay', '');
      el.preload = 'auto';
      // iOS Safari will not play audio elements with display:none.
      el.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0.01;pointer-events:none;left:0;bottom:0;';
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
      videoEl.setAttribute('playsinline', '');
      videoEl.setAttribute('webkit-playsinline', '');
      videoEl.autoplay = true;
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

  function isMobileViewport() {
    try {
      return !!(global.matchMedia && global.matchMedia('(max-width: 768px)').matches);
    } catch (e) {
      return false;
    }
  }

  function getAudioConstraints() {
    return {
      echoCancellation: true,
      noiseSuppression: true,
      autoGainControl: true,
      channelCount: 1,
    };
  }

  /**
   * Telemedicine capture: prefer real-time over 1080p. `ideal` (not exact) avoids
   * OverconstrainedError on phones that cannot hit a given size/fps.
   */
  function getVideoConstraints(overrides) {
    const mobile = isMobileViewport();
    const constraints = {
      facingMode: { ideal: 'user' },
      width: { max: 1280, ideal: mobile ? 480 : 640 },
      height: { max: 720, ideal: mobile ? 360 : 480 },
      frameRate: { max: 24, ideal: mobile ? 15 : 20 },
    };
    if (overrides && typeof overrides === 'object') {
      Object.keys(overrides).forEach((key) => {
        constraints[key] = overrides[key];
      });
    }
    return constraints;
  }

  function hintLiveTracks(stream) {
    if (!stream || !stream.getTracks) return;
    stream.getTracks().forEach((track) => {
      try {
        if (track.kind === 'audio') track.contentHint = 'speech';
        if (track.kind === 'video') track.contentHint = 'motion';
      } catch (e) {}
    });
  }

  function applyCaptureConstraints(stream) {
    if (!stream || !stream.getVideoTracks) return Promise.resolve();
    const track = stream.getVideoTracks()[0];
    if (!track || typeof track.applyConstraints !== 'function') return Promise.resolve();
    hintLiveTracks(stream);
    return track.applyConstraints(getVideoConstraints()).catch(() => {});
  }

  /** Cap SDP video bandwidth (kbps). PeerJS can apply this via sdpTransform. */
  function constrainCallSdp(sdp) {
    if (typeof sdp !== 'string' || sdp.indexOf('m=video') === -1) return sdp;
    if (/m=video[\s\S]*?b=AS:/i.test(sdp)) return sdp;
    return sdp.replace(/(m=video[^\r\n]*\r?\n)/, '$1b=AS:750\r\n');
  }

  function applySenderEncodings(pc, options) {
    if (!pc || typeof pc.getSenders !== 'function') return Promise.resolve();
    const opts = options || {};
    const videoMaxBitrate = opts.videoMaxBitrate || 700000;
    const videoMaxFramerate = opts.videoMaxFramerate || 20;
    const scale = opts.scaleResolutionDownBy || 1;
    const tasks = [];

    pc.getSenders().forEach((sender) => {
      if (!sender || !sender.track || typeof sender.getParameters !== 'function') return;
      try {
        if (sender.track.kind === 'audio') sender.track.contentHint = 'speech';
        if (sender.track.kind === 'video') sender.track.contentHint = 'motion';
      } catch (e) {}
      let params;
      try {
        params = sender.getParameters();
      } catch (e) {
        return;
      }
      if (!params.encodings || !params.encodings.length) params.encodings = [{}];
      if (sender.track.kind === 'video') {
        params.encodings[0].maxBitrate = videoMaxBitrate;
        params.encodings[0].maxFramerate = videoMaxFramerate;
        params.encodings[0].scaleResolutionDownBy = scale;
        params.encodings[0].priority = 'medium';
        params.encodings[0].networkPriority = 'medium';
        params.degradationPreference = 'maintain-framerate';
      } else if (sender.track.kind === 'audio') {
        params.encodings[0].maxBitrate = 48000;
        params.encodings[0].priority = 'high';
        params.encodings[0].networkPriority = 'high';
      }
      tasks.push(sender.setParameters(params).catch(() => {}));
    });

    return Promise.all(tasks).then(() => {});
  }

  function applyReceiverLowLatency(pc) {
    if (!pc || typeof pc.getReceivers !== 'function') return;
    pc.getReceivers().forEach((receiver) => {
      try {
        if ('playoutDelayHint' in receiver) receiver.playoutDelayHint = 0;
      } catch (e) {}
      try {
        if ('jitterBufferTarget' in receiver) receiver.jitterBufferTarget = 0;
      } catch (e) {}
    });
  }

  function applyRtcPerformance(pc, options) {
    applyReceiverLowLatency(pc);
    return applySenderEncodings(pc, options);
  }

  function qualityFromStats(snapshot, iceState, connState) {
    const ice = String(iceState || '');
    const conn = String(connState || '');
    if (ice === 'failed' || conn === 'failed') {
      return { level: 'poor', label: '● Poor Connection' };
    }
    if (ice === 'disconnected' || conn === 'disconnected' || ice === 'checking') {
      return { level: 'reconnecting', label: '◌ Reconnecting…' };
    }
    const loss = snapshot && typeof snapshot.lossRate === 'number' ? snapshot.lossRate : 0;
    const jitter = snapshot && typeof snapshot.jitter === 'number' ? snapshot.jitter : 0;
    const rtt = snapshot && typeof snapshot.rtt === 'number' ? snapshot.rtt : 0;
    if (loss > 0.08 || jitter > 0.05 || rtt > 0.45) {
      return { level: 'poor', label: '● Poor Connection' };
    }
    if (loss > 0.02 || jitter > 0.03 || rtt > 0.25) {
      return { level: 'fair', label: '● Fair Connection' };
    }
    return { level: 'good', label: '● Good Connection' };
  }

  function createStatsCollector() {
    let prev = null;
    return function collectRtcStats(pc) {
      if (!pc || typeof pc.getStats !== 'function') return Promise.resolve(null);
      return pc.getStats().then((report) => {
        const byId = {};
        report.forEach((row) => { byId[row.id] = row; });
        const now = {
          ts: Date.now(),
          packetsLost: 0,
          packetsReceived: 0,
          packetsSent: 0,
          bytesReceived: 0,
          bytesSent: 0,
          jitter: 0,
          rtt: 0,
          framesDecoded: 0,
          framesDropped: 0,
          framesReceived: 0,
          fps: 0,
          inboundBitrate: 0,
          outboundBitrate: 0,
          availableIncomingBitrate: 0,
          availableOutgoingBitrate: 0,
          localCandidateType: '',
          remoteCandidateType: '',
          usingTurn: false,
          codec: '',
          width: 0,
          height: 0,
          selectedPairState: '',
          lossRate: 0,
          connectionState: pc.connectionState || '',
          iceConnectionState: pc.iceConnectionState || '',
          signalingState: pc.signalingState || '',
        };

        report.forEach((row) => {
          if (row.type === 'inbound-rtp') {
            now.packetsLost += row.packetsLost || 0;
            now.packetsReceived += row.packetsReceived || 0;
            now.bytesReceived += row.bytesReceived || 0;
            if (typeof row.jitter === 'number') now.jitter = Math.max(now.jitter, row.jitter);
            if (row.kind === 'video') {
              now.framesDecoded = row.framesDecoded || now.framesDecoded;
              now.framesDropped = row.framesDropped || now.framesDropped;
              now.framesReceived = row.framesReceived || now.framesReceived;
              now.fps = row.framesPerSecond || now.fps;
              const codec = row.codecId && byId[row.codecId];
              if (codec && codec.mimeType) now.codec = codec.mimeType;
            }
          }
          if (row.type === 'outbound-rtp') {
            now.packetsSent += row.packetsSent || 0;
            now.bytesSent += row.bytesSent || 0;
            if (row.kind === 'video') {
              now.width = row.frameWidth || now.width;
              now.height = row.frameHeight || now.height;
              now.fps = now.fps || row.framesPerSecond || 0;
            }
          }
          if (row.type === 'media-source' && row.kind === 'video') {
            now.width = now.width || row.width || 0;
            now.height = now.height || row.height || 0;
            now.fps = now.fps || row.framesPerSecond || 0;
          }
          if (row.type === 'candidate-pair' && (row.nominated || row.selected || row.state === 'succeeded')) {
            now.rtt = row.currentRoundTripTime || row.roundTripTime || now.rtt;
            now.availableIncomingBitrate = row.availableIncomingBitrate || now.availableIncomingBitrate;
            now.availableOutgoingBitrate = row.availableOutgoingBitrate || now.availableOutgoingBitrate;
            now.selectedPairState = row.state || now.selectedPairState;
            const local = byId[row.localCandidateId];
            const remote = byId[row.remoteCandidateId];
            if (local) now.localCandidateType = local.candidateType || '';
            if (remote) now.remoteCandidateType = remote.candidateType || '';
            now.usingTurn = now.localCandidateType === 'relay' || now.remoteCandidateType === 'relay';
          }
          if (row.type === 'remote-inbound-rtp' && typeof row.roundTripTime === 'number') {
            now.rtt = row.roundTripTime;
          }
        });

        if (prev && now.ts > prev.ts) {
          const dt = (now.ts - prev.ts) / 1000;
          now.inboundBitrate = Math.max(0, Math.round(8 * (now.bytesReceived - prev.bytesReceived) / dt));
          now.outboundBitrate = Math.max(0, Math.round(8 * (now.bytesSent - prev.bytesSent) / dt));
          const lostDelta = Math.max(0, now.packetsLost - prev.packetsLost);
          const recvDelta = Math.max(0, now.packetsReceived - prev.packetsReceived);
          const sentDelta = Math.max(0, now.packetsSent - prev.packetsSent);
          const totalDelta = lostDelta + recvDelta + sentDelta;
          now.lossRate = totalDelta > 0 ? lostDelta / totalDelta : 0;
        }

        prev = now;
        return now;
      }).catch(() => null);
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
      const liveLevel = connEl.dataset.level;
      const keepStats = extras.connectionState === 'connected'
        && (liveLevel === 'good' || liveLevel === 'fair' || liveLevel === 'poor');
      if (!keepStats) {
        connEl.textContent = extras.connectionLabel;
        connEl.dataset.state = extras.connectionState || '';
      }
    }

    if (callStatus && extras.callStatusText) {
      callStatus.textContent = extras.callStatusText;
    }
  }

  function connectionLabelFor(role, statusKey) {
    if (statusKey === STATUS.CONNECTED) return '● Connected';
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
    getVideoConstraints,
    applyCaptureConstraints,
    hintLiveTracks,
    constrainCallSdp,
    applySenderEncodings,
    applyReceiverLowLatency,
    applyRtcPerformance,
    createStatsCollector,
    qualityFromStats,
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
