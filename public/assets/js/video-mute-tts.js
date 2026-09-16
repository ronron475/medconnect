/**
 * Mute Text-to-Speech + chat transcript for video consultations.
 * Activates when either participant mutes their microphone.
 */
(function (global) {
  'use strict';

  const MAX_CHARS = 500;
  const spokenIds = new Set();
  const recentSpokenTexts = new Map();
  const SPEECH_PREF_KEY = 'mc_tts_read_aloud';

  /**
   * Read-aloud is an accessibility aid, so it stays on by default, but it must be
   * something the user can switch off. Only incoming typed-voice messages are ever
   * spoken — never UI labels, button names or connection status.
   */
  function speechEnabled() {
    try {
      return global.localStorage.getItem(SPEECH_PREF_KEY) !== '0';
    } catch (e) {
      return true;
    }
  }

  function setSpeechEnabled(on) {
    try {
      global.localStorage.setItem(SPEECH_PREF_KEY, on ? '1' : '0');
    } catch (e) { /* preference is best-effort */ }
    if (!on && 'speechSynthesis' in global) {
      try { global.speechSynthesis.cancel(); } catch (e) {}
    }
  }

  function markSpoken(text, id) {
    if (id) spokenIds.add(String(id));
    const key = String(text || '').trim().toLowerCase();
    if (key) recentSpokenTexts.set(key, Date.now());
  }

  function wasRecentlySpoken(text, id) {
    if (id && spokenIds.has(String(id))) return true;
    const key = String(text || '').trim().toLowerCase();
    const at = recentSpokenTexts.get(key);
    if (!at) return false;
    return (Date.now() - at) < 15000;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function speakText(text, options = {}) {
    if (!('speechSynthesis' in global) || !text) {
      return Promise.resolve(false);
    }
    return new Promise((resolve) => {
      try {
        global.speechSynthesis.cancel();
        const utter = new SpeechSynthesisUtterance(String(text));
        utter.lang = options.lang || 'en-PH';
        utter.rate = options.rate || 1;
        if (typeof options.onStart === 'function') {
          utter.onstart = () => options.onStart(utter);
        }
        if (typeof options.onBoundary === 'function') {
          utter.onboundary = (event) => options.onBoundary(event, utter);
        }
        utter.onend = () => {
          if (typeof options.onEnd === 'function') options.onEnd();
          resolve(true);
        };
        utter.onerror = () => {
          if (typeof options.onEnd === 'function') options.onEnd();
          resolve(false);
        };
        if (typeof options.onCreate === 'function') {
          options.onCreate(utter);
        }
        global.speechSynthesis.speak(utter);
      } catch (e) {
        if (typeof options.onEnd === 'function') options.onEnd();
        resolve(false);
      }
    });
  }

  function estimateSpeechSeconds(text, rate) {
    const words = String(text || '').trim().split(/\s+/).filter(Boolean).length;
    const wpm = 155 * (Number(rate) || 1);
    return Math.max(1.2, (words / Math.max(wpm, 60)) * 60);
  }

  function formatPlayerTime(seconds) {
    const total = Math.max(0, Math.round(Number(seconds) || 0));
    const m = Math.floor(total / 60);
    const s = total % 60;
    return m + ':' + String(s).padStart(2, '0');
  }

  function playMuteTtsMessage(message, options = {}) {
    if (!message || message.message_kind !== 'mute_tts') return;
    if (message.is_deleted_for_everyone) return;
    const id = String(message.id || message.client_id || '');
    if (wasRecentlySpoken(message.message, id)) return;
    markSpoken(message.message, id);

    // A manual replay always speaks; automatic playback honours the toggle.
    if (!options.force && !speechEnabled()) return;

    const onReplay = options.onReplay;
    speakText(message.message, options).then(() => {
      if (typeof onReplay === 'function') onReplay(message);
    });
  }

  function createController(config) {
    const {
      userRole,
      consultationId,
      apiBase,
      csrfToken,
      sendData,
      notifyParent,
    } = config;

    const panel = document.getElementById('muteTtsPanel');
    const banner = document.getElementById('muteTtsBanner');
    const input = document.getElementById('muteTtsInput');
    const speakBtn = document.getElementById('muteTtsSpeakBtn');
    const clearBtn = document.getElementById('muteTtsClearBtn');
    const charCount = document.getElementById('muteTtsCharCount');
    const statusEl = document.getElementById('muteTtsStatus');
    const toastEl = document.getElementById('muteTtsToast');
    const logEl = document.getElementById('muteTtsLog');
    const receiveLogEl = document.getElementById('muteTtsReceiveLog');
    const receivePanel = document.getElementById('muteTtsReceivePanel');
    const restoreToast = document.getElementById('muteTtsRestoreToast');
    const typingBadge = document.getElementById('ttsTypingBadge');
    const remoteMuteBanner = document.getElementById('remoteMuteBanner');
    const closeBtn = document.getElementById('muteTtsCloseBtn');
    const receiveCloseBtn = document.getElementById('muteTtsReceiveCloseBtn');
    const speechToggle = document.getElementById('muteTtsSpeechToggle');
    const ttsOpenBtn = document.getElementById('mcVcTtsBtn');

    let micMuted = false;
    let remoteMuted = false;
    let speaking = false;
    let composerDismissed = false;
    let lastSpokenServerId = 0;
    let activePlayer = null;
    const roleLabel = userRole === 'provider' ? 'Provider' : 'Patient';
    const otherLabel = userRole === 'provider' ? 'Patient' : 'Provider';

    function stopActivePlayer(resetUi) {
      if (activePlayer && activePlayer.timer) {
        window.clearInterval(activePlayer.timer);
        activePlayer.timer = null;
      }
      if (resetUi && activePlayer && activePlayer.setIdle) {
        activePlayer.setIdle();
      }
      activePlayer = null;
      try {
        if ('speechSynthesis' in global) global.speechSynthesis.cancel();
      } catch (e) { /* ignore */ }
    }

    function buildReplayControl(text) {
      const wrap = document.createElement('div');
      wrap.className = 'mute-tts-player';
      wrap.innerHTML =
        '<button type="button" class="mute-tts-player__btn" aria-label="Play message audio">' +
        '<span class="mute-tts-player__icon mute-tts-player__icon--play" aria-hidden="true">' +
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>' +
        '</span>' +
        '<span class="mute-tts-player__icon mute-tts-player__icon--pause" aria-hidden="true" hidden>' +
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>' +
        '</span>' +
        '</button>' +
        '<div class="mute-tts-player__track" role="group" aria-label="Playback progress">' +
        '<div class="mute-tts-player__bar" aria-hidden="true"><span class="mute-tts-player__fill"></span></div>' +
        '<div class="mute-tts-player__times">' +
        '<span class="mute-tts-player__elapsed">0:00</span>' +
        '<span class="mute-tts-player__duration">' + escapeHtml(formatPlayerTime(estimateSpeechSeconds(text, 1))) + '</span>' +
        '</div></div>';

      const btn = wrap.querySelector('.mute-tts-player__btn');
      const fill = wrap.querySelector('.mute-tts-player__fill');
      const elapsedEl = wrap.querySelector('.mute-tts-player__elapsed');
      const durationEl = wrap.querySelector('.mute-tts-player__duration');
      const playIcon = wrap.querySelector('.mute-tts-player__icon--play');
      const pauseIcon = wrap.querySelector('.mute-tts-player__icon--pause');
      const duration = estimateSpeechSeconds(text, 1);
      let startedAt = 0;
      let pausedAt = 0;
      let elapsedBeforePause = 0;
      let playing = false;
      let paused = false;

      function setProgress(elapsedSec) {
        const ratio = Math.min(1, Math.max(0, elapsedSec / duration));
        if (fill) fill.style.width = (ratio * 100).toFixed(1) + '%';
        if (elapsedEl) elapsedEl.textContent = formatPlayerTime(elapsedSec);
        if (durationEl) durationEl.textContent = formatPlayerTime(duration);
        wrap.setAttribute('aria-valuenow', String(Math.round(ratio * 100)));
      }

      function setPlayingUi(isPlaying) {
        playing = isPlaying;
        wrap.classList.toggle('is-playing', isPlaying);
        wrap.classList.toggle('is-paused', !isPlaying && paused);
        if (playIcon) playIcon.hidden = isPlaying;
        if (pauseIcon) pauseIcon.hidden = !isPlaying;
        if (btn) {
          btn.setAttribute('aria-label', isPlaying ? 'Pause message audio' : 'Play message audio');
          btn.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
        }
      }

      function setIdle() {
        paused = false;
        elapsedBeforePause = 0;
        pausedAt = 0;
        if (api && api.timer) {
          window.clearInterval(api.timer);
          api.timer = null;
        }
        setPlayingUi(false);
        setProgress(0);
        wrap.classList.remove('is-paused');
      }

      function tick() {
        if (!playing || paused) return;
        const elapsed = elapsedBeforePause + ((Date.now() - startedAt) / 1000);
        setProgress(Math.min(elapsed, duration));
      }

      function startProgress() {
        startedAt = Date.now();
        if (activePlayer && activePlayer.timer) window.clearInterval(activePlayer.timer);
        const timer = window.setInterval(tick, 100);
        if (activePlayer) activePlayer.timer = timer;
      }

      function playFromStart() {
        if (activePlayer && activePlayer !== api) {
          stopActivePlayer(true);
        }
        activePlayer = api;
        paused = false;
        elapsedBeforePause = 0;
        setProgress(0);
        setPlayingUi(true);
        speakText(text, {
          force: true,
          onStart: () => {
            startProgress();
          },
          onBoundary: (event) => {
            if (!event || typeof event.charIndex !== 'number') return;
            const ratio = event.charIndex / Math.max(1, String(text).length);
            setProgress(ratio * duration);
            elapsedBeforePause = ratio * duration;
            startedAt = Date.now();
          },
          onEnd: () => {
            if (activePlayer === api) {
              if (api.timer) window.clearInterval(api.timer);
              api.timer = null;
              activePlayer = null;
            }
            setIdle();
          },
        });
      }

      function toggle() {
        if (!('speechSynthesis' in global)) {
          showToast('Speech playback is not supported in this browser.', 'warn');
          return;
        }

        if (playing && !paused) {
          try {
            global.speechSynthesis.pause();
            if (global.speechSynthesis.paused) {
              elapsedBeforePause += (Date.now() - startedAt) / 1000;
              paused = true;
              setPlayingUi(false);
              wrap.classList.add('is-paused');
              return;
            }
          } catch (e) { /* fall through to restart model */ }
          stopActivePlayer(true);
          return;
        }

        if (paused && activePlayer === api) {
          try {
            global.speechSynthesis.resume();
            paused = false;
            startedAt = Date.now();
            setPlayingUi(true);
            startProgress();
            return;
          } catch (e) {
            playFromStart();
            return;
          }
        }

        playFromStart();
      }

      const api = {
        wrap,
        setIdle,
        timer: null,
        toggle,
      };

      btn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        toggle();
      });

      wrap.setAttribute('role', 'group');
      wrap.setAttribute('aria-label', 'Message audio player');
      setProgress(0);
      return api;
    }

    function setStatus(html, tone) {
      if (!statusEl) return;
      statusEl.hidden = !html;
      statusEl.className = 'mute-tts-status' + (tone ? ' mute-tts-status--' + tone : '');
      statusEl.innerHTML = html || '';
    }

    function showToast(text, tone) {
      if (!toastEl) return;
      toastEl.textContent = text;
      toastEl.className = 'mute-tts-toast show' + (tone ? ' mute-tts-toast--' + tone : '');
      window.clearTimeout(showToast._timer);
      showToast._timer = window.setTimeout(() => {
        toastEl.classList.remove('show');
      }, 3200);
    }

    function updateCharCount() {
      if (!input || !charCount) return;
      const len = input.value.length;
      charCount.textContent = len + ' / ' + MAX_CHARS;
      charCount.classList.toggle('is-near', len >= MAX_CHARS - 40);
    }

    function setReceivePanelWatching(watching) {
      if (!receivePanel) return;
      receivePanel.classList.toggle('is-watching', !!watching);
    }

    function setRemoteMuteVisible(visible) {
      remoteMuted = Boolean(visible);
      if (remoteMuteBanner) {
        remoteMuteBanner.classList.toggle('is-open', remoteMuted);
        remoteMuteBanner.setAttribute('aria-hidden', remoteMuted ? 'false' : 'true');
      }
    }

    function appendToLog(target, entry) {
      if (!target) return;
      const item = document.createElement('article');
      item.className = 'mute-tts-log-item';
      const text = String(entry.text || '');
      item.innerHTML =
        '<div class="mute-tts-log-meta">' +
        '<strong class="mute-tts-log-name">' + escapeHtml(entry.label || roleLabel) + '</strong>' +
        '<time class="mute-tts-log-time">' + escapeHtml(entry.time || '') + '</time></div>' +
        '<p class="mute-tts-log-text">' + escapeHtml(text) + '</p>' +
        (entry.status
          ? '<div class="mute-tts-log-flags">' + escapeHtml(entry.status) + '</div>'
          : '');
      if (entry.playable) {
        const player = buildReplayControl(text);
        item.appendChild(player.wrap);
        item.classList.add('mute-tts-log-item--playable');
      }
      target.prepend(item);
      while (target.children.length > 12) {
        const last = target.lastChild;
        if (last && activePlayer && last.contains(activePlayer.wrap)) {
          stopActivePlayer(true);
        }
        target.removeChild(last);
      }
      if (target === receiveLogEl) {
        target.classList.add('has-items');
        if (receivePanel) receivePanel.classList.add('has-items');
      }
    }

    function appendLog(entry) {
      appendToLog(logEl, entry);
    }

    function appendReceiveLog(entry) {
      appendToLog(receiveLogEl || logEl, entry);
      if (receivePanel) receivePanel.classList.add('has-items');
    }

    function syncMuteChrome() {
      const panelOpen = !!(panel && panel.classList.contains('is-open'));
      if (banner) {
        const show = micMuted && !panelOpen;
        banner.classList.toggle('is-open', show);
        banner.setAttribute('aria-hidden', show ? 'false' : 'true');
      }
      if (ttsOpenBtn) {
        ttsOpenBtn.classList.toggle('is-active', micMuted && !panelOpen);
        ttsOpenBtn.setAttribute('aria-pressed', micMuted && !panelOpen ? 'true' : 'false');
      }
    }

    function setPanelVisible(visible) {
      if (panel) {
        panel.classList.toggle('is-open', visible);
        panel.setAttribute('aria-hidden', visible ? 'false' : 'true');
      }
      if (typingBadge) {
        typingBadge.hidden = !visible;
      }
      syncMuteChrome();
      if (visible && input) {
        window.setTimeout(() => input.focus(), 220);
      }
    }

    function closeComposer() {
      if (!panel || !panel.classList.contains('is-open')) return;
      composerDismissed = true;
      setPanelVisible(false);
    }

    function openComposer(options) {
      const opts = options || {};
      if (!micMuted) {
        if (!opts.silent) {
          showToast('Mute your microphone to send a typed voice message.', 'warn');
        }
        return false;
      }
      composerDismissed = false;
      setPanelVisible(true);
      return true;
    }

    function hideReceivePanel() {
      if (!receivePanel) return;
      receivePanel.classList.remove('has-items', 'is-watching');
    }

    function onMuteChanged(muted) {
      micMuted = Boolean(muted);

      if (micMuted) {
        composerDismissed = false;
        setPanelVisible(false);
        showToast('Microphone muted', 'warn');
        if (typeof sendData === 'function') {
          sendData({ type: 'mute_state', muted: true, role: userRole });
        }
      } else {
        composerDismissed = false;
        setPanelVisible(false);
        setStatus('');
        if (restoreToast) {
          restoreToast.classList.add('show');
          window.clearTimeout(onMuteChanged._restoreTimer);
          onMuteChanged._restoreTimer = window.setTimeout(() => {
            restoreToast.classList.remove('show');
          }, 2200);
        }
        showToast('Voice restored', 'ok');
        if (typeof sendData === 'function') {
          sendData({ type: 'mute_state', muted: false, role: userRole });
        }
      }

      if (typeof notifyParent === 'function') {
        notifyParent({ type: 'medconnect:mute-state', muted: micMuted, role: userRole });
      }

      if (global.McVideoCallCore && localStreamRef()) {
        global.McVideoCallCore.updateMediaStatusUI(localStreamRef());
      }
    }

    function localStreamRef() {
      return config.getLocalStream ? config.getLocalStream() : null;
    }

    async function sendTypedMessage() {
      if (!micMuted || speaking) return;
      const text = (input?.value || '').trim();
      if (!text) {
        setStatus('Type a message before pressing Send.', 'error');
        return;
      }
      if (text.length > MAX_CHARS) {
        setStatus('Message is too long (max ' + MAX_CHARS + ' characters).', 'error');
        return;
      }

      speaking = true;
      if (speakBtn) {
        speakBtn.disabled = true;
        speakBtn.classList.add('is-loading');
        speakBtn.textContent = 'Speaking…';
      }
      setStatus('Converting to speech and delivering…', 'busy');

      const clientId = 'local-' + Date.now();
      const payload = {
        type: 'mute_tts',
        text,
        client_id: clientId,
        role: userRole,
        created_at: new Date().toISOString(),
      };

      if (typeof sendData === 'function') {
        sendData(payload);
      }

      let saved = null;
      try {
        const res = await fetch(apiBase + '/app/api/messages/send.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-MC-No-Loader': '1',
          },
          body: new URLSearchParams({
            consultation_id: String(consultationId),
            message: text,
            message_kind: 'mute_tts',
            csrf_token: csrfToken || '',
          }),
          mcNoLoader: true,
        });
        saved = await res.json();
      } catch (e) {
        saved = { success: false, message: 'Network error while saving transcript.' };
      }

      if (saved && saved.success) {
        const row = saved.data || {};
        appendLog({
          label: roleLabel,
          text,
          time: row.time || new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
          status: 'Spoken and delivered',
        });
        if (input) input.value = '';
        updateCharCount();
        setStatus('Message spoken and delivered', 'ok');
        if (typeof notifyParent === 'function') {
          notifyParent({ type: 'medconnect:mute-tts', message: row });
        }
        showToast('Message spoken and delivered.', 'ok');
      } else {
        // Data channel may still have delivered live; mark partial success.
        appendLog({
          label: roleLabel,
          text,
          time: new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
          status: 'Delivered live · transcript saving…',
        });
        setStatus(escapeHtml((saved && saved.message) || 'Delivered to the other participant.'), 'busy');
        if (input) input.value = '';
        updateCharCount();
      }

      speaking = false;
      if (speakBtn) {
        speakBtn.disabled = false;
        speakBtn.classList.remove('is-loading');
        speakBtn.textContent = 'Send';
      }
    }

    function clearInput() {
      if (!input) return;
      input.value = '';
      updateCharCount();
      setStatus('');
      input.focus();
    }

    function handleIncomingData(data) {
      if (!data || typeof data !== 'object') return;

      if (data.type === 'mute_state' && data.role && data.role !== userRole) {
        const who = data.role === 'provider' ? 'Provider' : 'Patient';
        setRemoteMuteVisible(!!data.muted);
        showToast(
          data.muted
            ? (who + ' muted their microphone. Typed voice will appear in chat.')
            : (who + ' unmuted — voice communication restored.'),
          data.muted ? 'warn' : 'ok'
        );
        return;
      }

      if (data.type === 'mute_tts') {
        // Don't speak our own echo if somehow looped.
        if (data.role && data.role === userRole) return;
        const from = data.role === 'provider' ? 'Provider' : 'Patient';
        // Open chat panel so provider/patient always sees the typed message.
        if (receivePanel) receivePanel.classList.add('has-items', 'is-watching');
        playMuteTtsMessage({
          id: data.client_id || data.id,
          message: data.text || data.message,
          message_kind: 'mute_tts',
        });
        appendReceiveLog({
          label: from,
          text: data.text || data.message,
          time: new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
          status: 'Playing as speech',
          playable: true,
        });
        showToast(from + ' sent a typed message.', 'ok');
        if (typeof notifyParent === 'function') {
          notifyParent({
            type: 'medconnect:mute-tts',
            message: {
              message: data.text || data.message,
              message_kind: 'mute_tts',
              time: new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
            },
          });
        }
      }
    }

    async function pollMuteMessages() {
      if (!consultationId) return;
      try {
        const res = await fetch(
          apiBase + '/app/api/messages/list.php?consultation_id=' + encodeURIComponent(consultationId) + '&_=' + Date.now(),
          { cache: 'no-store', credentials: 'same-origin' }
        );
        const data = await res.json();
        if (!data.success) return;
        (data.messages || []).forEach((msg) => {
          if (msg.message_kind !== 'mute_tts') return;
          const id = Number(msg.id || 0);
          if (id <= lastSpokenServerId) return;
          lastSpokenServerId = Math.max(lastSpokenServerId, id);
          if (String(msg.sender_role || '') === userRole) return;
          playMuteTtsMessage(msg);
          appendReceiveLog({
            label: msg.sender_role === 'provider' ? 'Provider' : 'Patient',
            text: msg.message,
            time: msg.time || '',
            status: 'Available for playback',
            playable: true,
          });
        });
      } catch (e) { /* ignore */ }
    }

    if (input) {
      input.addEventListener('input', updateCharCount);
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendTypedMessage();
        }
      });
    }
    speakBtn?.addEventListener('click', sendTypedMessage);
    clearBtn?.addEventListener('click', clearInput);
    closeBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      closeComposer();
    });
    receiveCloseBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      hideReceivePanel();
    });

    function renderSpeechToggle() {
      if (!speechToggle) return;
      const on = speechEnabled();
      const label = speechToggle.querySelector('.mute-tts-speech-toggle__label');
      const icon = speechToggle.querySelector('.mute-tts-speech-toggle__icon');
      if (label) {
        label.textContent = on ? 'Read aloud' : 'Read aloud off';
      } else {
        speechToggle.textContent = on ? '🔊 Read aloud' : '🔇 Read aloud off';
      }
      if (icon) {
        icon.textContent = on ? '🔊' : '🔇';
      }
      speechToggle.classList.toggle('is-off', !on);
      speechToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
      speechToggle.setAttribute('aria-label', on ? 'Turn read-aloud off' : 'Turn read-aloud on');
    }
    if (speechToggle) {
      renderSpeechToggle();
      speechToggle.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const next = !speechEnabled();
        setSpeechEnabled(next);
        if (!next) stopActivePlayer(true);
        renderSpeechToggle();
      });
    }
    const bannerOpen = document.getElementById('muteTtsBannerOpen');
    bannerOpen?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (micMuted) openComposer({ silent: true });
    });
    banner?.addEventListener('click', () => {
      if (micMuted) openComposer({ silent: true });
    });
    updateCharCount();

    window.setInterval(pollMuteMessages, 2500);

    function syncMuteStateToPeer() {
      if (typeof sendData !== 'function') return;
      sendData({ type: 'mute_state', muted: micMuted, role: userRole });
    }

    return {
      onMuteChanged,
      handleIncomingData,
      playMuteTtsMessage,
      syncMuteStateToPeer,
      closeComposer,
      openComposer,
      isMicMuted: () => micMuted,
    };
  }

  global.McMuteTts = {
    MAX_CHARS,
    speakText,
    playMuteTtsMessage,
    createController,
    speechEnabled,
    setSpeechEnabled,
  };
})(window);
