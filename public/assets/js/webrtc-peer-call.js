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
  var config = {
    peerOptions: {},
    useAutoPeerId: false,
    onRecreate: null,
  };

  function on(event, fn) {
    if (!listeners[event]) listeners[event] = [];
    listeners[event].push(fn);
  }

  function emit(event, data) {
    (listeners[event] || []).forEach(function (fn) {
      try { fn(data || {}); } catch (e) { console.warn('[McWebrtcPeerCall]', e); }
    });
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

  /** ZIP: addRemoteVideo — attach remote stream via McVideoCallCore or #remoteVideo */
  function addRemoteVideo(stream) {
    if (!stream) return;
    if (global.McVideoCallCore && typeof global.McVideoCallCore.attachRemoteMedia === 'function') {
      global.McVideoCallCore.attachRemoteMedia(stream).then(function (ok) {
        emit('remote-video-attached', { stream: stream, audioUnlocked: ok });
      });
      return;
    }
    var video = document.getElementById('remoteVideo');
    if (!video) return;
    video.srcObject = stream;
    video.playsInline = true;
    var playPromise = video.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(function () {});
    }
    emit('remote-video-attached', { stream: stream, audioUnlocked: true });
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
    return on;
  }

  function setLocalStream(stream) {
    myStream = stream;
    if (stream) addLocalVideo(stream);
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

  function handleCall(call) {
    if (!call) return;
    if (currentCall === call && callHasRemoteStream) return;
    if (currentCall && currentCall.open && callHasRemoteStream && currentCall.peer === call.peer) return;
    if (currentCall && currentCall !== call) {
      try { currentCall.close(); } catch (e) {}
    }

    currentCall = call;
    global.__mcCurrentCall = call;
    outboundCallInFlight = false;
    callHasRemoteStream = false;
    emit('call-started', { call: call, peer: call.peer });

    call.on('stream', function (remoteStream) {
      if (peerList.indexOf(call.peer) === -1) peerList.push(call.peer);
      callHasRemoteStream = true;
      addRemoteVideo(remoteStream);
      emit('remote-stream', { stream: remoteStream, call: call, peer: call.peer });
    });

    call.on('close', function () {
      if (currentCall === call) {
        currentCall = null;
        global.__mcCurrentCall = null;
      }
      outboundCallInFlight = false;
      callHasRemoteStream = false;
      emit('call-close', { call: call, peer: call.peer });
    });

    call.on('error', function (err) {
      outboundCallInFlight = false;
      if (currentCall === call && !callHasRemoteStream) currentCall = null;
      emit('call-error', { error: err, call: call });
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
    call.answer(myStream);
    handleCall(call);
  }

  /** ZIP: listenToCall */
  function listenToCall() {
    if (!peer) return;
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
    if (currentCall && currentCall.open && callHasRemoteStream) return currentCall;
    if (outboundCallInFlight) return currentCall;
    if (pendingIncomingCall) {
      flushPendingCall();
      return currentCall;
    }

    outboundCallInFlight = true;
    var call = null;
    try {
      call = peer.call(receiverId, myStream);
    } catch (e) {
      console.warn('peer.call failed:', e);
      outboundCallInFlight = false;
      emit('call-error', { error: e });
      return null;
    }

    if (call) {
      handleCall(call);
      setTimeout(function () {
        if (currentCall === call && !callHasRemoteStream) {
          outboundCallInFlight = false;
          try { call.close(); } catch (err) {}
          if (currentCall === call) currentCall = null;
        }
      }, 8000);
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
    peerReady = false;
    myPeerJsId = null;
    outboundCallInFlight = false;
    callHasRemoteStream = false;
    pendingIncomingCall = null;
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
    if (typeof options.onRecreate === 'function') config.onRecreate = options.onRecreate;

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
    return !!(currentCall && currentCall.open);
  }

  function closeCurrentCall() {
    if (currentCall) {
      try { currentCall.close(); } catch (e) {}
      currentCall = null;
      global.__mcCurrentCall = null;
    }
    outboundCallInFlight = false;
    callHasRemoteStream = false;
  }

  function resetCallState() {
    pendingIncomingCall = null;
    outboundCallInFlight = false;
    callHasRemoteStream = false;
    peerList = [];
  }

  global.McWebrtcPeerCall = {
    init: init,
    listenToCall: listenToCall,
    makeCall: makeCall,
    addLocalVideo: addLocalVideo,
    addRemoteVideo: addRemoteVideo,
    toggleVideo: toggleVideo,
    toggleAudio: toggleAudio,
    setLocalStream: setLocalStream,
    getLocalStream: getLocalStream,
    getPeer: function () { return peer; },
    getCurrentCall: function () { return currentCall; },
    isReady: function () { return peerReady; },
    getMyPeerId: function () { return myPeerJsId; },
    hasRemoteStream: function () { return callHasRemoteStream; },
    isOutboundInFlight: function () { return outboundCallInFlight; },
    isCallConnected: isCallConnected,
    flushPendingCall: flushPendingCall,
    answerCall: answerCall,
    openDataChannel: openDataChannel,
    sendData: sendData,
    wireDataConnection: wireDataConnection,
    destroy: destroyPeer,
    recreatePeer: recreatePeer,
    closeCurrentCall: closeCurrentCall,
    resetCallState: resetCallState,
    on: on,
  };
})(window);
