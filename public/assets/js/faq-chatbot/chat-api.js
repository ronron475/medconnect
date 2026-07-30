/**
 * FAQ chatbot — PHP + MySQL API (emotion, FAQ, logging, feedback). No external AI.
 */
(function (global) {
  'use strict';

  const SESSION_KEY = 'mc_fcb_php_session';

  function baseUrl() {
    const root = document.getElementById('faq-chatbot');
    const b = (root && root.dataset.asset) || global.APP_BASE || global.ASSET_BASE || '';
    return String(b).replace(/\/$/, '');
  }

  function isEnabled() {
    const root = document.getElementById('faq-chatbot');
    if (!root) return false;
    const mode = (root.dataset.phpChat || 'assist').toLowerCase();
    return mode !== '0' && mode !== 'off' && mode !== 'false';
  }

  async function ensureSession() {
    let sid = '';
    try {
      sid = sessionStorage.getItem(SESSION_KEY) || '';
    } catch (_) { /* ignore */ }
    if (sid) return sid;

    try {
      const res = await fetch(baseUrl() + '/app/api/faq_chatbot_session.php', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const json = await res.json().catch(() => ({}));
      sid = json.data?.session_id || json.session_id || '';
      if (sid) {
        try {
          sessionStorage.setItem(SESSION_KEY, sid);
        } catch (_) { /* ignore */ }
      }
    } catch (_) { /* ignore */ }
    return sid;
  }

  async function postChat(body) {
    const sessionId = await ensureSession();
    const res = await fetch(baseUrl() + '/app/api/faq_chatbot_chat.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...body, session_id: sessionId }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.success) {
      return null;
    }
    return json.data || json;
  }

  /**
   * @returns {Promise<object|null>}
   */
  async function assist(text, lang) {
    if (!isEnabled()) return null;
    return postChat({ text, lang: lang || 'en', mode: 'assist' });
  }

  async function logBot(clientHtml, meta = {}) {
    if (!isEnabled()) return null;
    return postChat({
      text: '',
      mode: 'log_bot',
      client_html: clientHtml,
      flow_key: meta.flowKey || 'client',
      intent: meta.intent || '',
      confidence: meta.confidence,
    });
  }

  async function sendFeedback(messageId, rating) {
    if (!messageId) return false;
    try {
      const res = await fetch(baseUrl() + '/app/api/faq_chatbot_feedback.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ message_id: messageId, rating }),
      });
      const json = await res.json().catch(() => ({}));
      return !!(res.ok && json.success);
    } catch (_) {
      return false;
    }
  }

  async function translate(text, lang) {
    if (!isEnabled()) return null;
    try {
      const res = await fetch(baseUrl() + '/app/api/faq_chatbot_translate.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ text, lang: lang || 'en' }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.success) return null;
      return json.data || null;
    } catch (_) {
      return null;
    }
  }

  global.McFaqChatApi = {
    isEnabled,
    ensureSession,
    assist,
    logBot,
    sendFeedback,
    translate,
  };
})(window);
