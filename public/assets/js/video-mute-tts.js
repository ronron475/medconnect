/**
 * Mute Text-to-Speech + chat transcript for video consultations.
 * Activates when either participant mutes their microphone.
 */
(function (global) {
  'use strict';

  const MAX_CHARS = 500;
  const spokenIds = new Set();
  const recentSpokenTexts = new Map();

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
        utter.onend = () => resolve(true);
        utter.onerror = () => resolve(false);
        global.speechSynthesis.speak(utter);
      } catch (e) {
        resolve(false);
      }
    });
  }

  function playMuteTtsMessage(message, options = {}) {
    if (!message || message.message_kind !== 'mute_tts') return;
    if (message.is_deleted_for_everyone) return;
    const id = String(message.id || message.client_id || '');
    if (wasRecentlySpoken(message.message, id)) return;
    markSpoken(message.message, id);

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

    let micMuted = false;
    let remoteMuted = false;
    let speaking = false;
    let lastSpokenServerId = 0;
    const roleLabel = userRole === 'provider' ? 'Provider' : 'Patient';
    const otherLabel = userRole === 'provider' ? 'Patient' : 'Provider';

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
      // Keep chat panel open on provider while patient is muted so messages are obvious.
      if (userRole === 'provider') {
        setReceivePanelWatching(remoteMuted);
      }
    }

    function appendToLog(target, entry) {
      if (!target) return;
      const item = document.createElement('div');
      item.className = 'mute-tts-log-item';
      const text = String(entry.text || '');
      item.innerHTML =
        '<div class="mute-tts-log-meta">' +
        '<strong>' + escapeHtml(entry.label || roleLabel) + '</strong>' +
        '<span>' + escapeHtml(entry.time || '') + '</span></div>' +
        '<div class="mute-tts-log-text">' + escapeHtml(text) + '</div>' +
        '<div class="mute-tts-log-flags">' + escapeHtml(entry.status || 'Spoken and delivered') + '</div>' +
        (entry.playable
          ? '<button type="button" class="mute-tts-replay" data-tts-text="' + escapeHtml(text).replace(/"/g, '&quot;') + '">Play audio</button>'
          : '');
      const replay = item.querySelector('.mute-tts-replay');
      if (replay) {
        replay.addEventListener('click', () => speakText(text));
      }
      target.prepend(item);
      while (target.children.length > 12) {
        target.removeChild(target.lastChild);
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

    function setPanelVisible(visible) {
      if (panel) {
        panel.classList.toggle('is-open', visible);
        panel.setAttribute('aria-hidden', visible ? 'false' : 'true');
      }
      if (banner) {
        banner.classList.toggle('is-open', visible);
        banner.setAttribute('aria-hidden', visible ? 'false' : 'true');
      }
      if (typingBadge) {
        typingBadge.hidden = !visible;
      }
      if (visible && input) {
        window.setTimeout(() => input.focus(), 220);
      }
    }

    function onMuteChanged(muted) {
      micMuted = Boolean(muted);

      if (micMuted) {
        setPanelVisible(true);
        showToast(
          userRole === 'patient'
            ? 'Microphone muted. Type your message — the provider will hear and see it.'
            : 'Microphone muted. Type your message — the patient will hear and see it.',
          'warn'
        );
        if (typeof sendData === 'function') {
          sendData({ type: 'mute_state', muted: true, role: userRole });
        }
      } else {
        setPanelVisible(false);
        setStatus('');
        if (restoreToast) {
          restoreToast.classList.add('show');
          window.clearTimeout(onMuteChanged._restoreTimer);
          onMuteChanged._restoreTimer = window.setTimeout(() => {
            restoreToast.classList.remove('show');
          }, 2800);
        }
        showToast('Voice communication restored.', 'ok');
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
      isMicMuted: () => micMuted,
    };
  }

  global.McMuteTts = {
    MAX_CHARS,
    speakText,
    playMuteTtsMessage,
    createController,
  };
})(window);
