/**
 * medConnect WebRTC peer call layer — structure from WebRTC-main/calljs.js (PeerJS).
 * Handles PeerJS init, listenToCall, makeCall, local/remote video, mic/cam toggles.
 * Session UI, TTS, recording, and timers remain in video_room.php.
 */
(function (global) {
  'use strict';

  var peer = null;
  var myStream = null;
  var peerList = [];
  var currentCall = null;
  var pendingIncomingCall = null;
  var outboundCallInFlight = false;
  var callHasRemoteStream = false;
  var dataConn = null;
  var peerReady = false;
  var myPeerJsId = null;
  var peerRetryTimer = null;
  var listeners = {};
  var userMicMuted = false;
  var lastRemoteStream = null;
  var iceRecoveryTimer = null;
  var reconnectTimer = null;
  var reconnectInProgress = false;
  var reconnectAttempts = 0;
  var wiredCalls = typeof WeakSet !== 'undefined' ? new WeakSet() : null;
  var intentionalLeave = false;
  var callStartedAt = 0;
  var MAX_RECONNECT_ATTEMPTS = 6;
  /** Brief ICE `disconnected` often self-heals. Redialing too soon freezes the live call. */
  var ICE_DISCONNECT_GRACE_MS = 8000;
  /** Allow ICE/media to settle before a redial tears the call down. */
  var NEGOTIATION_GRACE_MS = 12000;
  var lastRemoteAttachKey = '';
  var lastRemoteEmitKey = '';
  var qualityTimer = null;
  var poorQualityStreak = 0;
  var goodQualityStreak = 0;
  var currentScale = 1;
  var collectRtcStats = null;

  var config = {
    peerOptions: {},
    useAutoPeerId: false,
    originator: false,
    onRecreate: null,
    onNeedsRedial: null,
  };

  function setIntentionalLeave(value) {
    intentionalLeave = !!value;
    if (intentionalLeave) {
      clearRecoveryTimers();
    }
  }

  function isIntentionalLeave() {
    return intentionalLeave;
  }

  function on(event, fn) {
    if (!listeners[event]) listeners[event] = [];
    listeners[event].push(fn);
  }

  function emit(event, data) {
    (listeners[event] || []).forEach(function (fn) {
      try { fn(data || {}); } catch (e) { console.warn('[McWebrtcPeerCall]', e); }
    });
  }

  function clearTimer(timerRef) {
    if (timerRef) clearTimeout(timerRef);
    return null;
  }

  function isCallWired(call) {
    if (!call) return false;
    if (wiredCalls) return wiredCalls.has(call);
    return !!call._mcWired;
  }

  function markCallWired(call) {
    if (!call) return;
    if (wiredCalls) wiredCalls.add(call);
    else call._mcWired = true;
  }

  /** ZIP: addLocalVideo — attach local stream to #localVideo */
  function addLocalVideo(stream) {
    var video = document.getElementById('localVideo');
    if (!video || !stream) return;
    video.srcObject = stream;
    video.muted = true;
    video.playsInline = true;
    var playPromise = video.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(function () {});
    }
    emit('local-video-attached', { stream: stream });
  }

  function ensureRemoteAudioTracks(stream) {
    if (!stream || !stream.getAudioTracks) return;
    stream.getAudioTracks().forEach(function (track) {
      try { track.enabled = true; } catch (e) {}
    });
  }

  function getOrCreateRemoteStream(call) {
    if (!call) return null;
    if (!call._mcRemoteStream) {
      call._mcRemoteStream = new MediaStream();
    }
    return call._mcRemoteStream;
  }

  function mergeRemoteTrack(call, track) {
    if (!call || !track) return null;
    var stream = getOrCreateRemoteStream(call);
    var exists = stream.getTracks().some(function (t) { return t.id === track.id; });
    if (!exists) {
      try { track.enabled = true; } catch (e) {}
      stream.addTrack(track);
    }
    return stream;
  }

  function logLocalTrackState(stream, context) {
    if (!stream || !stream.getTracks) return;
    stream.getTracks().forEach(function (track) {
      if (typeof console === 'undefined' || !console.debug) return;
      console.debug('[McWebrtcPeerCall] local ' + track.kind + ' track', context || '', {
        enabled: track.enabled,
        readyState: track.readyState,
        muted: track.muted,
      });
    });
  }

  /** ZIP: addRemoteVideo — attach remote stream via McVideoCallCore or #remoteVideo */
  function remoteAttachKey(stream) {
    if (!stream || !stream.getTracks) return '';
    return stream.id + ':' + stream.getTracks().map(function (t) { return t.id; }).join(',');
  }

  function addRemoteVideo(stream) {
    if (!stream) return;
    ensureRemoteAudioTracks(stream);
    lastRemoteStream = stream;
    var key = remoteAttachKey(stream);
    var video = document.getElementById('remoteVideo');
    if (key && key === lastRemoteAttachKey && video && video.srcObject === stream) {
      return;
    }
    lastRemoteAttachKey = key;
    if (global.McVideoCallCore && typeof global.McVideoCallCore.attachRemoteMedia === 'function') {
      global.McVideoCallCore.attachRemoteMedia(stream).then(function (ok) {
        emit('remote-video-attached', { stream: stream, audioUnlocked: ok });
      });
      return;
    }
    if (!video) return;
    if (video.srcObject !== stream) video.srcObject = stream;
    video.playsInline = true;
    var playPromise = video.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(function () {});
    }
    emit('remote-video-attached', { stream: stream, audioUnlocked: true });
  }

  function refreshRemoteMedia() {
    if (lastRemoteStream) addRemoteVideo(lastRemoteStream);
  }

  /** ZIP: toggleVideo */
  function toggleVideo(enabled) {
    if (!myStream) return false;
    var track = myStream.getVideoTracks()[0];
    if (!track) return false;
    var on;
    if (enabled === true || enabled === 'true') on = true;
    else if (enabled === false || enabled === 'false') on = false;
    else on = !track.enabled;
    track.enabled = on;
    return on;
  }

  /** ZIP: toggleAudio */
  function toggleAudio(enabled) {
    if (!myStream) return false;
    var track = myStream.getAudioTracks()[0];
    if (!track) return false;
    var on;
    if (enabled === true || enabled === 'true') on = true;
    else if (enabled === false || enabled === 'false') on = false;
    else on = !track.enabled;
    track.enabled = on;
    userMicMuted = !on;
    return on;
  }

  function setLocalStream(stream) {
    myStream = stream;
    if (stream) {
      var audioTrack = stream.getAudioTracks()[0];
      if (audioTrack && !userMicMuted) {
        try { audioTrack.enabled = true; } catch (e) {}
      }
      logLocalTrackState(stream, 'setLocalStream');
      if (global.McVideoCallCore && typeof global.McVideoCallCore.hintLiveTracks === 'function') {
        global.McVideoCallCore.hintLiveTracks(stream);
      }
      addLocalVideo(stream);
    }
  }

  function getLocalStream() {
    return myStream;
  }

  function wireDataConnection(conn) {
    if (!conn) return;
    if (dataConn && dataConn.open && dataConn !== conn) {
      try { conn.close(); } catch (e) {}
      return;
    }
    dataConn = conn;
    conn.on('open', function () {
      emit('data-open', { conn: conn, peer: conn.peer });
    });
    conn.on('data', function (data) {
      emit('data', { data: data, conn: conn });
    });
    conn.on('close', function () {
      if (dataConn === conn) dataConn = null;
      emit('data-close', { conn: conn });
    });
    conn.on('error', function (err) {
      emit('data-error', { error: err, conn: conn });
    });
  }

  function openDataChannel(targetId) {
    if (!peerReady || !peer || !targetId) return;
    if (dataConn && dataConn.open) return;
    try {
      var conn = peer.connect(targetId, { reliable: true });
      wireDataConnection(conn);
    } catch (e) {
      console.warn('Could not open data channel:', e);
    }
  }

  function sendData(payload) {
    if (!dataConn || !dataConn.open) return false;
    try {
      dataConn.send(payload);
      return true;
    } catch (e) {
      console.warn('Data channel send failed:', e);
      return false;
    }
  }

  function getPeerConnection(call) {
    var target = call || currentCall;
    if (!target) return null;
    try {
      return target.peerConnection || null;
    } catch (e) {
      return null;
    }
  }

  function sdpTransform(sdp) {
    if (global.McVideoCallCore && typeof global.McVideoCallCore.constrainCallSdp === 'function') {
      return global.McVideoCallCore.constrainCallSdp(sdp);
    }
    return sdp;
  }

  /**
   * PeerJS does not send a locally created ICE-restart offer to the remote peer.
   * setLocalDescription here desyncs SDP and causes freeze/reconnect loops.
   * Brief ICE disconnects should self-heal; only redial on sustained failure.
   */
  function attemptIceRestart(call) {
    return Promise.resolve(false);
  }

  function stopQualityMonitor() {
    if (qualityTimer) {
      clearInterval(qualityTimer);
      qualityTimer = null;
    }
    poorQualityStreak = 0;
    goodQualityStreak = 0;
    currentScale = 1;
  }

  function applyLiveRtcTuning(pc) {
    if (!pc || pc.signalingState === 'closed') return;
    if (global.McVideoCallCore && typeof global.McVideoCallCore.applyRtcPerformance === 'function') {
      global.McVideoCallCore.applyRtcPerformance(pc, {
        videoMaxBitrate: currentScale > 1 ? 350000 : 700000,
        videoMaxFramerate: currentScale > 1 ? 15 : 20,
        scaleResolutionDownBy: currentScale,
      });
    }
  }

  function startQualityMonitor(call) {
    stopQualityMonitor();
    if (global.McVideoCallCore && typeof global.McVideoCallCore.createStatsCollector === 'function') {
      collectRtcStats = global.McVideoCallCore.createStatsCollector();
    }
    qualityTimer = setInterval(function () {
      var pc = getPeerConnection(call);
      if (!pc || pc.signalingState === 'closed') {
        stopQualityMonitor();
        return;
      }
      if (!collectRtcStats) return;
      collectRtcStats(pc).then(function (snapshot) {
        if (!snapshot) return;
        global.__mcWebrtcStats = snapshot;
        var ice = pc.iceConnectionState || '';
        var conn = pc.connectionState || '';
        var quality = global.McVideoCallCore && typeof global.McVideoCallCore.qualityFromStats === 'function'
          ? global.McVideoCallCore.qualityFromStats(snapshot, ice, conn)
          : { level: 'good' };
        if (quality.level === 'poor') {
          poorQualityStreak += 1;
          goodQualityStreak = 0;
          if (poorQualityStreak >= 2 && currentScale < 2) {
            currentScale = 2;
            applyLiveRtcTuning(pc);
          }
        } else if (quality.level === 'good') {
          goodQualityStreak += 1;
          poorQualityStreak = 0;
          if (goodQualityStreak >= 3 && currentScale > 1) {
            currentScale = 1;
            applyLiveRtcTuning(pc);
          }
        } else {
          poorQualityStreak = 0;
        }
      });
    }, 5000);
  }

  function scheduleIceRecovery(reason) {
    if (intentionalLeave) return;
    if (iceRecoveryTimer) return;
    emit('recovering', { reason: reason || 'ice-disconnected' });
    iceRecoveryTimer = setTimeout(function () {
      iceRecoveryTimer = null;
      var pc = getPeerConnection();
      if (!pc) {
        scheduleReconnect(reason || 'no-pc');
        return;
      }
      var state = pc.iceConnectionState || '';
      if (state === 'connected' || state === 'completed') {
        emit('recovered', { reason: 'ice-self-healed' });
        reconnectAttempts = 0;
        return;
      }
      attemptIceRestart(currentCall).then(function (ok) {
        if (ok) return;
        scheduleReconnect(reason || 'ice-restart-failed');
      });
    }, ICE_DISCONNECT_GRACE_MS);
  }

  function scheduleReconnect(reason) {
    if (intentionalLeave || global.__mcCallEnded) return;
    if (reconnectInProgress) return;
    if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
      emit('connection-failed', { reason: reason, attempts: reconnectAttempts });
      return;
    }
    if (reconnectTimer) return;

    reconnectAttempts += 1;
    reconnectInProgress = true;
    emit('recovering', { reason: reason, attempt: reconnectAttempts });

    reconnectTimer = setTimeout(function () {
      reconnectTimer = null;
      reconnectInProgress = false;

      var hadRemote = callHasRemoteStream;
      closeCurrentCall({ preserveRemoteFlag: hadRemote, silent: true });

      if (typeof config.onNeedsRedial === 'function') {
        config.onNeedsRedial({ reason: reason, attempt: reconnectAttempts });
      } else {
        emit('needs-redial', { reason: reason, attempt: reconnectAttempts });
      }
    }, 900 + Math.min(reconnectAttempts * 400, 2000));
  }

  function clearRecoveryTimers() {
    iceRecoveryTimer = clearTimer(iceRecoveryTimer);
    reconnectTimer = clearTimer(reconnectTimer);
    reconnectInProgress = false;
  }

  function handleIceStateChange(call) {
    var pc = getPeerConnection(call);
    if (!pc) return;
    var iceState = pc.iceConnectionState || '';
    var connState = pc.connectionState || '';

    emit('ice-state', {
      call: call,
      iceConnectionState: iceState,
      connectionState: connState,
    });

    if (iceState === 'connected' || iceState === 'completed') {
      clearRecoveryTimers();
      reconnectAttempts = 0;
      applyLiveRtcTuning(pc);
      startQualityMonitor(call);
      emit('recovered', { iceConnectionState: iceState, connectionState: connState });
      return;
    }

    if (connState === 'connected' && iceState !== 'failed' && iceState !== 'closed' && iceState !== 'disconnected') {
      applyLiveRtcTuning(pc);
      return;
    }

    if (iceState === 'disconnected' || connState === 'disconnected') {
      scheduleIceRecovery('disconnected');
      return;
    }

    if (iceState === 'failed' || connState === 'failed') {
      iceRecoveryTimer = clearTimer(iceRecoveryTimer);
      attemptIceRestart(call).then(function (ok) {
        if (!ok) scheduleReconnect('failed');
      });
    }
  }

  function wirePeerConnection(call, attempt) {
    if (!call || isCallWired(call)) return;
    var pc = getPeerConnection(call);
    if (!pc) {
      var n = attempt || 0;
      if (n < 20) {
        setTimeout(function () { wirePeerConnection(call, n + 1); }, 50);
      }
      return;
    }
    markCallWired(call);
    applyLiveRtcTuning(pc);
    setTimeout(function () { applyLiveRtcTuning(pc); }, 800);
    startQualityMonitor(call);

    var onIceChange = function () { handleIceStateChange(call); };
    pc.addEventListener('iceconnectionstatechange', onIceChange);
    pc.addEventListener('connectionstatechange', onIceChange);

    pc.addEventListener('track', function (ev) {
      var stream = null;
      if (ev.track) {
        stream = mergeRemoteTrack(call, ev.track);
      } else if (ev.streams && ev.streams[0]) {
        stream = ev.streams[0];
      }
      if (!stream) return;
      callHasRemoteStream = true;
      var emitKey = remoteAttachKey(stream);
      addRemoteVideo(stream);
      if (global.McVideoCallCore && typeof global.McVideoCallCore.applyReceiverLowLatency === 'function') {
        global.McVideoCallCore.applyReceiverLowLatency(pc);
      }
      if (emitKey && emitKey === lastRemoteEmitKey) return;
      lastRemoteEmitKey = emitKey;
      emit('remote-stream', { stream: stream, call: call, peer: call.peer, track: ev.track });
    });
  }

  function handleRemoteStream(call, remoteStream) {
    if (!remoteStream) return;
    if (peerList.indexOf(call.peer) === -1) peerList.push(call.peer);
    callHasRemoteStream = true;
    clearRecoveryTimers();
    reconnectAttempts = 0;
    remoteStream.getTracks().forEach(function (track) {
      mergeRemoteTrack(call, track);
    });
    var merged = getOrCreateRemoteStream(call);
    var stream = merged || remoteStream;
    addRemoteVideo(stream);
    var emitKey = remoteAttachKey(stream);
    if (emitKey && emitKey === lastRemoteEmitKey) return;
    lastRemoteEmitKey = emitKey;
    emit('remote-stream', { stream: stream, call: call, peer: call.peer });
  }

  function handleCall(call) {
    if (!call) return;

    // Glare: both phones dialing at once must not close the live connection.
    if (currentCall && currentCall !== call) {
      var samePeer = currentCall.peer === call.peer;
      var existingLive = callHasRemoteStream || (currentCall.open && isCallNegotiating());
      if (existingLive && samePeer) {
        try { call.close(); } catch (e) {}
        return;
      }
      if (config.originator && outboundCallInFlight && samePeer) {
        try { call.close(); } catch (e) {}
        return;
      }
      try { currentCall.close(); } catch (e) {}
      currentCall = null;
      outboundCallInFlight = false;
      if (!callHasRemoteStream) callHasRemoteStream = false;
    }

    if (currentCall === call) {
      wirePeerConnection(call);
      return;
    }

    currentCall = call;
    global.__mcCurrentCall = call;
    outboundCallInFlight = false;
    callStartedAt = Date.now();
    if (!callHasRemoteStream) callHasRemoteStream = false;
    emit('call-started', { call: call, peer: call.peer });

    wirePeerConnection(call);

    call.on('stream', function (remoteStream) {
      handleRemoteStream(call, remoteStream);
    });

    call.on('close', function () {
      if (currentCall === call) {
        currentCall = null;
        global.__mcCurrentCall = null;
      }
      outboundCallInFlight = false;
      var hadRemote = callHasRemoteStream;
      callHasRemoteStream = false;
      emit('call-close', { call: call, peer: call.peer, hadRemote: hadRemote, intentionalLeave: intentionalLeave });
    });

    call.on('error', function (err) {
      outboundCallInFlight = false;
      emit('call-error', { error: err, call: call });
      if (intentionalLeave) return;
      if (currentCall === call && !callHasRemoteStream) {
        scheduleReconnect('call-error');
      }
    });
  }

  function answerCall(call) {
    if (!call || !myStream) return;
    if (currentCall && currentCall !== call && callHasRemoteStream && currentCall.open) {
      try { call.close(); } catch (e) {}
      return;
    }
    if (currentCall && currentCall !== call) {
      try { currentCall.close(); } catch (e) {}
      currentCall = null;
      outboundCallInFlight = false;
      callHasRemoteStream = false;
    }
    call.answer(myStream, { sdpTransform: sdpTransform });
    handleCall(call);
  }

  /** ZIP: listenToCall */
  function listenToCall() {
    if (!peer || peer._mcListening) return;
    peer._mcListening = true;
    peer.on('call', function (call) {
      emit('incoming-call', { call: call, peer: call.peer });
      if (!myStream) {
        pendingIncomingCall = call;
        emit('incoming-call-waiting-media', { call: call });
        return;
      }
      answerCall(call);
    });
  }

  /** ZIP: makeCall */
  function makeCall(receiverId) {
    if (!peerReady || !myStream || !peer || !receiverId) return null;
    if (currentCall && callHasRemoteStream && isCallConnected()) return currentCall;
    if (currentCall && (currentCall.open || outboundCallInFlight || isCallNegotiating())) {
      return currentCall;
    }
    if (pendingIncomingCall) {
      flushPendingCall();
      return currentCall;
    }

    outboundCallInFlight = true;
    logLocalTrackState(myStream, 'makeCall');
    var call = null;
    try {
      call = peer.call(receiverId, myStream, { sdpTransform: sdpTransform });
    } catch (e) {
      console.warn('peer.call failed:', e);
      outboundCallInFlight = false;
      emit('call-error', { error: e });
      return null;
    }

    if (call) {
      handleCall(call);
    } else {
      outboundCallInFlight = false;
    }
    return call;
  }

  function flushPendingCall() {
    if (!pendingIncomingCall || !myStream) return;
    var call = pendingIncomingCall;
    pendingIncomingCall = null;
    try {
      if (call.open === false && typeof call.close === 'function') {
        var pc = call.peerConnection;
        if (pc && (pc.connectionState === 'closed' || pc.connectionState === 'failed')) {
          return;
        }
      }
    } catch (e) {}
    answerCall(call);
  }

  function destroyPeer() {
    clearRecoveryTimers();
    stopQualityMonitor();
    lastRemoteAttachKey = '';
    lastRemoteEmitKey = '';
    peerReady = false;
    myPeerJsId = null;
    outboundCallInFlight = false;
    callHasRemoteStream = false;
    pendingIncomingCall = null;
    lastRemoteStream = null;
    reconnectAttempts = 0;
    if (myStream) {
      myStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      myStream = null;
    }
    if (peerRetryTimer) {
      clearTimeout(peerRetryTimer);
      peerRetryTimer = null;
    }
    if (dataConn) {
      try { dataConn.close(); } catch (e) {}
      dataConn = null;
    }
    if (currentCall) {
      try { currentCall.close(); } catch (e) {}
      currentCall = null;
      global.__mcCurrentCall = null;
    }
    try {
      if (peer && !peer.destroyed) peer.destroy();
    } catch (e) {}
    peer = null;
    peerList = [];
  }

  function recreatePeer(reason) {
    if (intentionalLeave || global.__mcCallEnded) return;
    console.warn('Recreating PeerJS connection:', reason || 'retry');
    destroyPeer();
    peerRetryTimer = setTimeout(function () {
      if (typeof config.onRecreate === 'function') {
        config.onRecreate(reason);
      } else {
        emit('recreate', { reason: reason });
      }
    }, 1200);
  }

  /** ZIP: init */
  function init(userId, options) {
    options = options || {};
    if (options.peerOptions) config.peerOptions = options.peerOptions;
    config.useAutoPeerId = !!options.useAutoPeerId;
    config.originator = !!options.originator;
    if (typeof options.onRecreate === 'function') config.onRecreate = options.onRecreate;
    if (typeof options.onNeedsRedial === 'function') config.onNeedsRedial = options.onNeedsRedial;

    if (!config.useAutoPeerId && peer && peerReady && !peer.destroyed && myPeerJsId === userId) {
      return peer;
    }

    destroyPeer();

    peer = config.useAutoPeerId
      ? new global.Peer(config.peerOptions)
      : new global.Peer(userId, config.peerOptions);

    peer.on('open', function (id) {
      myPeerJsId = id;
      peerReady = true;
      emit('open', { id: id });
    });

    peer.on('connection', function (conn) {
      wireDataConnection(conn);
    });

    listenToCall();

    peer.on('disconnected', function () {
      emit('disconnected', {});
      if (intentionalLeave) return;
      try {
        if (peer && !peer.destroyed) peer.reconnect();
      } catch (e) {
        recreatePeer('disconnected');
      }
    });

    peer.on('error', function (err) {
      emit('error', { error: err });
    });

    return peer;
  }

  function isCallConnected() {
    var pc = getPeerConnection();
    if (pc) {
      var ice = pc.iceConnectionState || '';
      var conn = pc.connectionState || '';
      if (ice === 'connected' || ice === 'completed' || conn === 'connected') return true;
    }
    // Require remote media — PeerJS "open" alone is not a usable consult link.
    return !!(currentCall && callHasRemoteStream);
  }

  function getCallAgeMs() {
    if (!currentCall || !callStartedAt) return 0;
    return Math.max(0, Date.now() - callStartedAt);
  }

  /** True while a MediaConnection is alive and still waiting for remote A/V. */
  function isCallNegotiating() {
    if (!currentCall || callHasRemoteStream || intentionalLeave) return false;
    if (outboundCallInFlight) return true;
    var pc = getPeerConnection();
    if (pc) {
      var ice = String(pc.iceConnectionState || '');
      var conn = String(pc.connectionState || '');
      if (ice === 'failed' || ice === 'closed' || conn === 'failed' || conn === 'closed') {
        return false;
      }
      if (
        ice === 'new' || ice === 'checking' || ice === 'connected' || ice === 'completed' ||
        conn === 'new' || conn === 'connecting' || conn === 'connected'
      ) {
        return getCallAgeMs() < NEGOTIATION_GRACE_MS;
      }
    }
    return getCallAgeMs() < NEGOTIATION_GRACE_MS;
  }

  /** True when the current attempt is dead or stuck past the negotiation grace. */
  function shouldAbandonCurrentCall() {
    if (!currentCall && !outboundCallInFlight) return false;
    if (callHasRemoteStream && isCallConnected()) return false;
    if (isCallNegotiating()) return false;
    var pc = getPeerConnection();
    if (pc) {
      var ice = String(pc.iceConnectionState || '');
      var conn = String(pc.connectionState || '');
      if (ice === 'failed' || ice === 'closed' || conn === 'failed' || conn === 'closed') {
        return true;
      }
    }
    return getCallAgeMs() >= NEGOTIATION_GRACE_MS;
  }

  function closeCurrentCall(options) {
    options = options || {};
    clearRecoveryTimers();
    stopQualityMonitor();
    callStartedAt = 0;
    if (currentCall) {
      var pcClose = getPeerConnection(currentCall);
      if (pcClose && pcClose.signalingState !== 'closed') {
        try { pcClose.close(); } catch (e) {}
      }
      if (currentCall._mcRemoteStream) {
        try {
          currentCall._mcRemoteStream.getTracks().forEach(function (t) {
            try { currentCall._mcRemoteStream.removeTrack(t); } catch (e) {}
          });
        } catch (e) {}
        currentCall._mcRemoteStream = null;
      }
      try { currentCall.close(); } catch (e) {}
      currentCall = null;
      global.__mcCurrentCall = null;
    }
    outboundCallInFlight = false;
    if (!options.preserveRemoteFlag) {
      callHasRemoteStream = false;
      lastRemoteStream = null;
    }
    if (!options.silent) {
      reconnectAttempts = 0;
    }
  }

  function resetCallState() {
    pendingIncomingCall = null;
    outboundCallInFlight = false;
    callHasRemoteStream = false;
    lastRemoteStream = null;
    lastRemoteAttachKey = '';
    lastRemoteEmitKey = '';
    clearRecoveryTimers();
    reconnectAttempts = 0;
    peerList = [];
  }

  function requestReconnect(reason) {
    reconnectAttempts = 0;
    scheduleReconnect(reason || 'manual');
  }

  function replaceLocalVideoTrack(newTrack) {
    if (!newTrack) return Promise.resolve(false);
    if (myStream) {
      myStream.getVideoTracks().forEach(function (old) {
        try { myStream.removeTrack(old); } catch (e) {}
        try { old.stop(); } catch (e) {}
      });
      try { myStream.addTrack(newTrack); } catch (e) {}
      addLocalVideo(myStream);
    }

    var pc = getPeerConnection();
    if (!pc || typeof pc.getSenders !== 'function') return Promise.resolve(true);

    var senders = pc.getSenders();
    var videoSender = null;
    for (var i = 0; i < senders.length; i++) {
      if (senders[i].track && senders[i].track.kind === 'video') {
        videoSender = senders[i];
        break;
      }
    }
    if (!videoSender) {
      for (var j = 0; j < senders.length; j++) {
        if (!senders[j].track) {
          videoSender = senders[j];
          break;
        }
      }
    }
    if (videoSender && typeof videoSender.replaceTrack === 'function') {
      return videoSender.replaceTrack(newTrack).then(function () {
        applyLiveRtcTuning(pc);
        return true;
      }).catch(function (err) {
        console.warn('[McWebrtcPeerCall] replaceTrack failed:', err);
        return false;
      });
    }
    return Promise.resolve(true);
  }

  global.McWebrtcPeerCall = {
    init: init,
    listenToCall: listenToCall,
    makeCall: makeCall,
    addLocalVideo: addLocalVideo,
    addRemoteVideo: addRemoteVideo,
    refreshRemoteMedia: refreshRemoteMedia,
    toggleVideo: toggleVideo,
    toggleAudio: toggleAudio,
    replaceLocalVideoTrack: replaceLocalVideoTrack,
    setLocalStream: setLocalStream,
    getLocalStream: getLocalStream,
    getPeer: function () { return peer; },
    getCurrentCall: function () { return currentCall; },
    getPeerConnection: function () { return getPeerConnection(); },
    isReady: function () { return peerReady; },
    getMyPeerId: function () { return myPeerJsId; },
    hasRemoteStream: function () { return callHasRemoteStream; },
    isOutboundInFlight: function () { return outboundCallInFlight; },
    isCallConnected: isCallConnected,
    isCallNegotiating: isCallNegotiating,
    shouldAbandonCurrentCall: shouldAbandonCurrentCall,
    flushPendingCall: flushPendingCall,
    answerCall: answerCall,
    openDataChannel: openDataChannel,
    sendData: sendData,
    wireDataConnection: wireDataConnection,
    destroy: destroyPeer,
    recreatePeer: recreatePeer,
    closeCurrentCall: closeCurrentCall,
    resetCallState: resetCallState,
    requestReconnect: requestReconnect,
    setIntentionalLeave: setIntentionalLeave,
    isIntentionalLeave: isIntentionalLeave,
    on: on,
  };
})(window);
