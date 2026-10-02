/**
 * BITS Ollama demo page — POST prompt to campus generate/chat API.
 */
(function () {
  'use strict';

  var body = document.body;
  var promptEl = document.getElementById('bod-prompt');
  var sendBtn = document.getElementById('bod-send');
  var clearBtn = document.getElementById('bod-clear');
  var statusEl = document.getElementById('bod-status');
  var convEl = document.getElementById('bod-conversation');
  var inFlight = false;
  var base = typeof window.APP_BASE === 'string' ? window.APP_BASE : '';
  var apiUrl = base + '/app/api/ai/bits_ollama_demo.php';

  function setStatus(text, kind) {
    if (!statusEl) return;
    statusEl.textContent = 'Status: ' + text;
    statusEl.dataset.status = kind || 'idle';
  }

  function addBubble(who, text) {
    if (!convEl) return;
    var div = document.createElement('div');
    div.className = 'gci-bubble ' + (who === 'you' ? 'gci-bubble--patient' : 'gci-bubble--gemini');
    var label = document.createElement('strong');
    label.textContent = who === 'you' ? 'You' : 'BITS (phi3:mini)';
    div.appendChild(label);
    div.appendChild(document.createTextNode(text));
    convEl.appendChild(div);
    convEl.scrollTop = convEl.scrollHeight;
  }

  function csrf() {
    return (body && body.dataset.csrf) || '';
  }

  function demoToken() {
    return (body && body.dataset.demoToken) || '';
  }

  async function send() {
    if (inFlight || !promptEl || !sendBtn) return;
    var prompt = String(promptEl.value || '').trim();
    if (!prompt) {
      setStatus('Type a prompt first.', 'error');
      return;
    }

    inFlight = true;
    sendBtn.disabled = true;
    setStatus('Calling campus BITS… this can take up to a minute.', 'busy');
    addBubble('you', prompt);

    var controller = new AbortController();
    var timer = window.setTimeout(function () { controller.abort(); }, 90000);
    try {
      var fd = new FormData();
      fd.set('csrf_token', csrf());
      fd.set('demo_token', demoToken());
      fd.set('prompt', prompt);
      var res = await fetch(apiUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal,
      });
      var json = await res.json().catch(function () { return null; });
      if (!json || json.success === false) {
        var msg = (json && json.message) ? json.message : 'BITS did not reply.';
        setStatus(msg, 'error');
        addBubble('bits', msg);
        return;
      }
      var data = json.data || json;
      var reply = String(data.response || '').trim() || '(empty reply)';
      addBubble('bits', reply);
      var ms = data.elapsed_ms != null ? ' (' + data.elapsed_ms + ' ms)' : '';
      setStatus('Reply received' + ms + '. This is not a triage result.', 'ok');
      promptEl.value = '';
    } catch (err) {
      var timedOut = err && err.name === 'AbortError';
      setStatus(timedOut ? 'Timed out waiting for BITS. Try again.' : 'Network error. Try again.', 'error');
    } finally {
      window.clearTimeout(timer);
      inFlight = false;
      sendBtn.disabled = false;
    }
  }

  if (sendBtn) sendBtn.addEventListener('click', send);
  if (promptEl) {
    promptEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        send();
      }
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (promptEl) promptEl.value = '';
      if (convEl) convEl.innerHTML = '';
      setStatus('Ready', 'idle');
    });
  }
  document.querySelectorAll('.gci-chip[data-text]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      if (promptEl) promptEl.value = chip.getAttribute('data-text') || '';
      if (promptEl) promptEl.focus();
    });
  });
})();
