/**
 * medConnect FAQ Chatbot — voice input (STT) and read-aloud replies (TTS).
 * Browser Web Speech API first; PHP + Whisper fallback via faq_chatbot_voice.php.
 */
(function (global) {
  'use strict';

  const STORAGE_TTS = 'mc_fcb_voice_tts';

  let options = {};
  let config = null;
  let recognition = null;
  let listening = false;
  let mediaRecorder = null;
  let mediaChunks = [];
  let recordTimer = null;
  let statusEl = null;
  let micBtn = null;
  let ttsBtn = null;
  let ttsEnabled = true;

  function baseUrl() {
    const fromRoot = options.root && options.root.dataset.asset;
    const b = fromRoot || global.APP_BASE || global.ASSET_BASE || '';
    return String(b).replace(/\/$/, '');
  }

  function mapSttLang(lang) {
    const L = String(lang || 'en').toLowerCase();
    if (L === 'fil') return 'fil-PH';
    if (L === 'hil') return 'en-PH';
    return config?.default_stt_lang || 'en-PH';
  }

  function t(key, fallback) {
    const I18n = global.McFaqI18n;
    const lang = options.getLang ? options.getLang() : 'en';
    if (I18n && I18n.t) {
      const v = I18n.t(I18n.normLang ? I18n.normLang(lang) : lang, key);
      if (v && v !== key) return v;
    }
    return fallback;
  }

  function setStatus(msg, type) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.hidden = !msg;
    statusEl.classList.toggle('fcb-voice-status--error', type === 'error');
    statusEl.classList.toggle('fcb-voice-status--active', type === 'active');
  }

  function hasBrowserStt() {
    return Boolean(global.SpeechRecognition || global.webkitSpeechRecognition);
  }

  function hasBrowserTts() {
    return Boolean(global.speechSynthesis);
  }

  async function loadConfig() {
    try {
      const res = await fetch(baseUrl() + '/app/api/faq_chatbot_voice.php', {
        credentials: 'same-origin',
      });
      const json = await res.json();
      if (json && json.success) {
        config = json;
        return json;
      }
    } catch (_) { /* ignore */ }
    config = {
      browser_stt: true,
      browser_tts: true,
      fallback_transcribe: true,
      max_recording_sec: 25,
    };
    return config;
  }

  function stopRecognition() {
    listening = false;
    if (micBtn) {
      micBtn.classList.remove('fcb-voice-btn--active');
      micBtn.setAttribute('aria-pressed', 'false');
    }
    if (recognition) {
      try { recognition.stop(); } catch (_) { /* ignore */ }
    }
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      try { mediaRecorder.stop(); } catch (_) { /* ignore */ }
    }
    window.clearTimeout(recordTimer);
    setStatus('');
  }

  function applyTranscript(text) {
    const trimmed = String(text || '').trim();
    if (!trimmed) {
      setStatus(t('voiceNoSpeech', 'No speech detected. Try again or type your message.'), 'error');
      return;
    }
    if (options.onFinalTranscript) {
      options.onFinalTranscript(trimmed);
    }
    setStatus('');
  }

  async function transcribeBlob(blob) {
    const fd = new FormData();
    fd.append('audio', blob, 'faq_voice.webm');
    const res = await fetch(baseUrl() + '/app/api/faq_chatbot_voice.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.success) {
      throw new Error(json.message || 'Transcription failed');
    }
    return json.text;
  }

  function startMediaFallback() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus(t('voiceUnsupported', 'Voice is not supported here. Please type your question.'), 'error');
      return;
    }
    navigator.mediaDevices.getUserMedia({ audio: true })
      .then((stream) => {
        mediaChunks = [];
        const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
          ? 'audio/webm;codecs=opus'
          : 'audio/webm';
        mediaRecorder = new MediaRecorder(stream, { mimeType: mime });
        mediaRecorder.ondataavailable = (e) => {
          if (e.data && e.data.size) mediaChunks.push(e.data);
        };
        mediaRecorder.onstop = async () => {
          stream.getTracks().forEach((tr) => tr.stop());
          listening = false;
          if (micBtn) {
            micBtn.classList.remove('fcb-voice-btn--active');
            micBtn.setAttribute('aria-pressed', 'false');
          }
          const blob = new Blob(mediaChunks, { type: mime });
          if (!blob.size) {
            setStatus(t('voiceNoSpeech', 'No speech detected. Try again or type your message.'), 'error');
            return;
          }
          setStatus(t('voiceProcessing', 'Processing your voice…'), 'active');
          try {
            const text = await transcribeBlob(blob);
            applyTranscript(text);
          } catch (err) {
            setStatus(err.message || t('voiceError', 'Could not hear you. Please try again.'), 'error');
          }
        };
        mediaRecorder.start();
        listening = true;
        if (micBtn) {
          micBtn.classList.add('fcb-voice-btn--active');
          micBtn.setAttribute('aria-pressed', 'true');
        }
        setStatus(t('voiceListening', 'Listening… tap mic to stop'), 'active');
        const maxSec = (config && config.max_recording_sec) || 25;
        recordTimer = window.setTimeout(() => {
          if (mediaRecorder && mediaRecorder.state === 'recording') {
            try { mediaRecorder.stop(); } catch (_) { /* ignore */ }
          }
        }, maxSec * 1000);
      })
      .catch(() => {
        setStatus(t('voiceMicDenied', 'Microphone access was denied.'), 'error');
      });
  }

  function startBrowserStt() {
    const SpeechRecognition = global.SpeechRecognition || global.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      startMediaFallback();
      return;
    }
    recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = true;
    recognition.lang = mapSttLang(options.getLang && options.getLang());
    recognition.maxAlternatives = 1;

    let finalText = '';

    recognition.onstart = () => {
      listening = true;
      if (micBtn) {
        micBtn.classList.add('fcb-voice-btn--active');
        micBtn.setAttribute('aria-pressed', 'true');
      }
      setStatus(t('voiceListening', 'Listening…'), 'active');
    };

    recognition.onresult = (event) => {
      let interim = '';
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const part = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          finalText += part;
        } else {
          interim += part;
        }
      }
      if (options.inputEl) {
        options.inputEl.value = (finalText || interim).trim();
        if (options.onInputChange) options.onInputChange();
      }
    };

    recognition.onerror = (event) => {
      const code = event.error || '';
      if (code === 'not-allowed') {
        setStatus(t('voiceMicDenied', 'Microphone access was denied.'), 'error');
      } else if (code !== 'aborted') {
        setStatus(t('voiceError', 'Could not hear you. Please try again.'), 'error');
      }
      stopRecognition();
    };

    recognition.onend = () => {
      const text = (finalText || (options.inputEl && options.inputEl.value) || '').trim();
      listening = false;
      if (micBtn) {
        micBtn.classList.remove('fcb-voice-btn--active');
        micBtn.setAttribute('aria-pressed', 'false');
      }
      if (text) {
        applyTranscript(text);
        if (options.inputEl) options.inputEl.value = '';
        if (options.onInputChange) options.onInputChange();
      } else {
        setStatus('');
      }
    };

    try {
      recognition.start();
    } catch (_) {
      startMediaFallback();
    }
  }

  function toggleListening() {
    if (listening) {
      stopRecognition();
      return;
    }
    if (options.isRestricted && options.isRestricted()) {
      setStatus(t('voiceRestricted', 'Chat is temporarily restricted.'), 'error');
      return;
    }
    global.speechSynthesis?.cancel();
    if (hasBrowserStt()) {
      startBrowserStt();
    } else if (config && config.fallback_transcribe) {
      startMediaFallback();
    } else {
      setStatus(t('voiceUnsupported', 'Voice is not supported here. Please type your question.'), 'error');
    }
  }

  function stripForSpeech(el) {
    if (!el) return '';
    const clone = el.cloneNode(true);
    clone.querySelectorAll('script, style, button, .fcb-followups, .fcb-actions').forEach((n) => n.remove());
    return (clone.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 800);
  }

  function speakBotFromElement(msgEl) {
    if (!ttsEnabled || !hasBrowserTts()) return;
    const bubble = msgEl && msgEl.querySelector('.fcb-msg__bubble');
    const text = stripForSpeech(bubble || msgEl);
    if (!text) return;
    global.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang = mapSttLang(options.getLang && options.getLang());
    u.rate = 1;
    u.pitch = 1;
    global.speechSynthesis.speak(u);
  }

  function speakLastBot(container) {
    if (!container) return;
    const bots = container.querySelectorAll('.fcb-msg--bot');
    if (!bots.length) return;
    speakBotFromElement(bots[bots.length - 1]);
  }

  function updateTtsButton() {
    if (!ttsBtn) return;
    ttsBtn.classList.toggle('fcb-tts-btn--off', !ttsEnabled);
    ttsBtn.setAttribute('aria-pressed', ttsEnabled ? 'true' : 'false');
    ttsBtn.title = ttsEnabled
      ? t('voiceSpeakOn', 'Read replies aloud (on)')
      : t('voiceSpeakOff', 'Read replies aloud (off)');
  }

  function init(opts) {
    options = opts || {};
    micBtn = document.getElementById('fcb-voice-mic');
    ttsBtn = document.getElementById('fcb-voice-tts');
    statusEl = document.getElementById('fcb-voice-status');

    try {
      const saved = localStorage.getItem(STORAGE_TTS);
      if (saved === '0') ttsEnabled = false;
    } catch (_) { /* ignore */ }

    if (micBtn) {
      micBtn.addEventListener('click', (e) => {
        if (options.ripple && micBtn) options.ripple(e, micBtn);
        toggleListening();
      });
    }

    if (ttsBtn) {
      ttsBtn.addEventListener('click', (e) => {
        if (options.ripple && ttsBtn) options.ripple(e, ttsBtn);
        ttsEnabled = !ttsEnabled;
        try { localStorage.setItem(STORAGE_TTS, ttsEnabled ? '1' : '0'); } catch (_) { /* ignore */ }
        if (!ttsEnabled) global.speechSynthesis?.cancel();
        updateTtsButton();
      });
      updateTtsButton();
    }

    if (!hasBrowserStt() && !config) {
      loadConfig().then(() => {
        if (micBtn && !hasBrowserStt() && !(config && config.fallback_transcribe)) {
          micBtn.disabled = true;
          micBtn.title = t('voiceUnsupported', 'Voice is not supported here.');
        }
      });
    } else {
      loadConfig();
    }
  }

  function onPanelClose() {
    stopRecognition();
    global.speechSynthesis?.cancel();
  }

  global.McFaqVoice = {
    init,
    speakLastBot,
    onPanelClose,
    stopListening: stopRecognition,
    isListening: () => listening,
  };
})(window);
