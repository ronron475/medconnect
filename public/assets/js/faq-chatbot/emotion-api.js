/**
 * FAQ chatbot — PHP emotion API client (merges with McFaqEmotions).
 */
(function (global) {
  'use strict';

  const CACHE_TTL_MS = 45000;
  const cache = new Map();

  function baseUrl() {
    const root = document.getElementById('faq-chatbot');
    const b = (root && root.dataset.asset) || global.APP_BASE || global.ASSET_BASE || '';
    return String(b).replace(/\/$/, '');
  }

  function cacheKey(text, lang) {
    return String(lang || 'en') + '|' + String(text || '').trim().slice(0, 400);
  }

  /**
   * @returns {Promise<object|null>}
   */
  async function analyze(text, lang, intent) {
    const trimmed = String(text || '').trim();
    if (!trimmed) return null;

    const key = cacheKey(trimmed, lang);
    const hit = cache.get(key);
    if (hit && Date.now() - hit.at < CACHE_TTL_MS) {
      return hit.data;
    }

    try {
      const res = await fetch(baseUrl() + '/app/api/faq_chatbot_emotion.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          text: trimmed,
          lang: lang || 'en',
          intent: intent || '',
        }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.success) {
        return null;
      }
      const data = json.data || json;
      cache.set(key, { at: Date.now(), data });
      return data;
    } catch (_) {
      return null;
    }
  }

  /**
   * Merge PHP emotion result with client-side McFaqEmotions analyze output.
   * @param {object} clientEmo
   * @param {object|null} phpEmo
   */
  function merge(clientEmo, phpEmo) {
    if (!phpEmo || !phpEmo.emotion) {
      return { emotion: clientEmo, php: null };
    }
    const clientScore = Number(clientEmo && clientEmo.score) || 0;
    const phpScore = Number(phpEmo.score) || 0;
    const usePhp = phpScore >= clientScore || !clientEmo.primary;

    const primary = usePhp ? phpEmo.emotion : clientEmo.primary;
    const merged = {
      primary,
      score: Math.max(clientScore, phpScore),
      standalone: clientEmo.standalone || null,
      inferredFlow: phpEmo.suggested_flow || clientEmo.inferredFlow || null,
      scores: { ...(clientEmo.scores || {}), ...(phpEmo.scores || {}) },
      phpEmpathyHtml: phpEmo.empathy_html || '',
      emotionalSupport: !!phpEmo.emotional_support_active,
      tone: phpEmo.tone || null,
      label: phpEmo.label || '',
      icon: phpEmo.icon || '',
      engine: phpEmo.engine || 'php-faq-emotion',
    };

    if (phpEmo.suggested_flow && !clientEmo.inferredFlow) {
      merged.inferredFlow = phpEmo.suggested_flow;
    }

    return { emotion: merged, php: phpEmo };
  }

  function setEmotionAwareUi(active) {
    const root = document.getElementById('faq-chatbot');
    if (!root) return;
    root.classList.toggle('fcb--emotion-aware', !!active);
    const chip = document.getElementById('fcb-emotion-aware-chip');
    if (chip) chip.hidden = !active;
  }

  global.McFaqEmotionApi = {
    analyze,
    merge,
    setEmotionAwareUi,
  };
})(window);
