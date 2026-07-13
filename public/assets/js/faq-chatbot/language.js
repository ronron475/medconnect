/**
 * medConnect FAQ Chatbot — Automatic language detection
 * English · Filipino (Tagalog) · Hiligaynon (Ilonggo)
 */
(function (global) {
  'use strict';

  const LANG = Object.freeze({
    EN: 'en',
    FIL: 'fil',
    HIL: 'hil',
  });

  const DEFAULT_LANG = LANG.EN;
  const SWITCH_MARGIN = 1.35;
  const LOW_CONFIDENCE = 1.15;

  /** @type {Array<{ re: RegExp, w: number }>} */
  const HIL_PATTERNS = [
    { re: /\b(sang|kag|gid|diin|indi|wala|halin|subong|amo|ini|sini|dira|didto|guid|bala|lang)\b/i, w: 2.4 },
    { re: /\b(nakalimtan|gintan-aw|gintanaw|makita|maka-|mag-|nag-|ginkuha)\b/i, w: 2.2 },
    { re: /\b(konsultasyon|doktor|oras|tawag|pasyente|reseta)\b/i, w: 1.6 },
    { re: /\b(paano|pano)\b.*\b(sang|ko|ka)\b/i, w: 2.0 },
    { re: /\b(ko|mo|ko|nako|imo|iya)\b/i, w: 0.55 },
    { re: /\bnakalimtan\s+ko\b/i, w: 2.4 },
    { re: /\b(pwede|puwede)\s+ko\s+mag-/i, w: 2.0 },
    { re: /\bneed\s+ko\b/i, w: 2.0 },
    { re: /\bforgot\s+ko\b/i, w: 2.0 },
    { re: /\bnakalimtan\s+ko\b/i, w: 2.5 },
    { re: /\bdiin\s+ko\b/i, w: 2.5 },
  ];

  /** @type {Array<{ re: RegExp, w: number }>} */
  const FIL_PATTERNS = [
    { re: /\b(ang|mga|ng|sa|kay|kung|po|opo|naman|lang|ba)\b/i, w: 1.1 },
    { re: /\b(paano|pano|ano|saan|kailan|bakit|sino)\b/i, w: 1.8 },
    { re: /\b(hindi|wala|pwede|puwede|maaari|gusto|kailangan)\b/i, w: 1.5 },
    { re: /\b(nakalimutan|nakalimot|mag-book|mag-login|mag-reset)\b/i, w: 2.0 },
    { re: /\bako\b.*\b(ng|sa|ang)\b/i, w: 1.4 },
    { re: /\bnakalimutan\s+ko\b/i, w: 2.4 },
    { re: /\bpaano\s+ako\b/i, w: 2.0 },
    { re: /\bpassword\s+ko\b/i, w: 1.2 },
    { re: /\bmag-book\s+ng\b/i, w: 2.2 },
  ];

  /** @type {Array<{ re: RegExp, w: number }>} */
  const EN_PATTERNS = [
    { re: /\b(the|how|what|where|when|why|please|could|would|should|my|your)\b/i, w: 1.2 },
    { re: /\b(sign\s*in|log\s*in|register|appointment|password|reset|forgot|consultation|schedule)\b/i, w: 1.8 },
    { re: /\b(book|booking|account|dashboard|notification|prescription|record)\b/i, w: 1.4 },
    { re: /\bhow\s+do\s+i\b/i, w: 2.2 },
    { re: /\bforgot\s+my\b/i, w: 2.0 },
    { re: /\boffice\s+hours\b/i, w: 2.0 },
  ];

  let sessionLang = null;

  /**
   * @param {string} text
   * @param {Array<{ re: RegExp, w: number }>} patterns
   * @returns {number}
   */
  function scorePatterns(text, patterns) {
    let score = 0;
    for (const p of patterns) {
      if (p.re.test(text)) score += p.w;
    }
    return score;
  }

  /**
   * @param {string} text
   * @returns {{ lang: string, scores: Record<string, number>, confidence: number, mixed: boolean }}
   */
  function detect(text) {
    const raw = String(text || '').trim();
    if (!raw) {
      return { lang: sessionLang || DEFAULT_LANG, scores: { en: 0, fil: 0, hil: 0 }, confidence: 0, mixed: false };
    }

    const scores = {
      [LANG.EN]: scorePatterns(raw, EN_PATTERNS),
      [LANG.FIL]: scorePatterns(raw, FIL_PATTERNS),
      [LANG.HIL]: scorePatterns(raw, HIL_PATTERNS),
    };

    const ranked = Object.entries(scores).sort((a, b) => b[1] - a[1]);
    const top = ranked[0];
    const second = ranked[1];
    const topScore = top[1];
    const secondScore = second ? second[1] : 0;

    let lang = topScore > 0 ? top[0] : DEFAULT_LANG;
    let confidence = topScore;
    const mixed = topScore > 0 && secondScore > 0 && secondScore >= topScore * 0.55;

    // Local CHO bias: when Filipino and Hiligaynon scores are close, prefer Hiligaynon
    if (scores[LANG.FIL] > 0 && scores[LANG.HIL] > 0
      && Math.abs(scores[LANG.FIL] - scores[LANG.HIL]) < 0.65
      && scores[LANG.EN] < Math.max(scores[LANG.FIL], scores[LANG.HIL])) {
      lang = scores[LANG.HIL] >= scores[LANG.FIL] ? LANG.HIL : LANG.FIL;
    }

    if (mixed && topScore - secondScore < 0.8) {
      lang = sessionLang || lang;
      confidence = topScore * 0.85;
    }

    return { lang, scores, confidence, mixed };
  }

  /**
   * Resolve language for this turn with session continuity.
   * @param {string} text
   * @returns {string}
   */
  function resolve(text) {
    const result = detect(text);
    const { lang, confidence, scores } = result;

    if (!sessionLang) {
      sessionLang = confidence > 0 ? lang : DEFAULT_LANG;
      return sessionLang;
    }

    const sessionScore = scores[sessionLang] || 0;
    const bestScore = scores[lang] || 0;

    if (bestScore >= LOW_CONFIDENCE && (bestScore >= sessionScore * SWITCH_MARGIN || lang !== sessionLang && bestScore - sessionScore >= 0.9)) {
      sessionLang = lang;
    }

    return sessionLang;
  }

  /**
   * @param {string} text
   * @returns {boolean}
   */
  function isLowConfidence(text) {
    const { confidence, scores } = detect(text);
    const max = Math.max(scores.en, scores.fil, scores.hil);
    const tokenCount = String(text || '').trim().split(/\s+/).filter(Boolean).length;
    return tokenCount >= 2 && max < LOW_CONFIDENCE && confidence < LOW_CONFIDENCE;
  }

  function getSessionLang() {
    return sessionLang || DEFAULT_LANG;
  }

  function setSessionLang(lang) {
    if (lang === LANG.EN || lang === LANG.FIL || lang === LANG.HIL) {
      sessionLang = lang;
    }
  }

  function resetSessionLang() {
    sessionLang = null;
  }

  global.McFaqLanguage = {
    LANG,
    DEFAULT_LANG,
    detect,
    resolve,
    isLowConfidence,
    getSessionLang,
    setSessionLang,
    resetSessionLang,
  };
})(window);
