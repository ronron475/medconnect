/**
 * medConnect FAQ Chatbot — Message Understanding & Clarification Engine
 * Normalization · keywords · confidence · context preservation
 */
(function (global) {
  'use strict';

  const LEVEL = Object.freeze({
    FULL: 'full',
    PARTIAL: 'partial',
    NONE: 'none',
  });

  const THRESHOLD_FULL = 90;
  const THRESHOLD_PARTIAL = 60;

  /** Common FAQ tokens + typo variants */
  const TYPO_MAP = {
    apointment: 'appointment',
    appoitment: 'appointment',
    apppointment: 'appointment',
    pasword: 'password',
    passwrd: 'password',
    passord: 'password',
    logn: 'login',
    loggin: 'login',
    logiin: 'login',
    registr: 'register',
    regster: 'register',
    rehistro: 'register',
    konsultaion: 'consultation',
    consultaion: 'consultation',
    konsult: 'consultation',
    consulta: 'consultation',
    schedul: 'schedule',
    fotgot: 'forgot',
    frgot: 'forgot',
    medconect: 'medconnect',
    medconnct: 'medconnect',
    apointment: 'appointment',
    passsword: 'password',
    signin: 'sign in',
    signup: 'sign up',
  };

  const COMMON_WORDS = new Set([
    'i', 'me', 'my', 'you', 'the', 'a', 'an', 'is', 'are', 'can', 'will', 'do', 'does',
    'how', 'what', 'when', 'where', 'why', 'please', 'help', 'need', 'want', 'get', 'use',
    'ko', 'mo', 'ako', 'ang', 'ng', 'sa', 'ba', 'po', 'na', 'pa', 'lang', 'gid', 'sang', 'kag',
    'paano', 'ano', 'saan', 'hindi', 'wala', 'pwede', 'puwede', 'kailangan', 'gusto',
    'diin', 'indi', 'wala', 'subong', 'guid', 'bala', 'kon', 'nga', 'ini', 'sini',
    'sure', 'this', 'that', 'system', 'with', 'for', 'about', 'have', 'has', 'not',
  ]);

  const FAQ_KEYWORDS = [
    'sign in', 'signin', 'login', 'log in', 'register', 'account', 'password', 'reset', 'forgot',
    'appointment', 'book', 'schedule', 'consult', 'consultation', 'video', 'record', 'medical', 'prescription',
    'notification', 'contact', 'hours', 'service', 'medconnect', 'triage', 'patient',
    'mag-login', 'mag-login', 'rehistro', 'nakalimtan', 'nakalimutan', 'konsultasyon', 'konsulta',
    'paano', 'ano', 'diin', 'tawag', 'buligi', 'oras', 'bukas',
  ];

  const RESTART_PATTERNS = [
    /\b(new\s+chat|start\s+over|restart|reset\s+chat|ulitin|magsimula\s+ulit)\b/i,
    /\bbalik\s+sa\s+simula\b/i,
  ];

  /** @type {{ originalText: string, keywords: string[], flowKey: string|null, at: number }|null} */
  let pendingClarification = null;
  let userMessageCount = 0;

  const PARTIAL_POOL = {
    en: [
      '<p>I\'m sorry, but I couldn\'t fully understand part of your message. Could you please clarify or rephrase it? I\'ll do my best to help.</p>',
      '<p>I understand part of your question, but I\'m not completely sure what you mean. Could you explain it a little differently?</p>',
      '<p>I want to make sure I understand correctly. Could you provide a little more detail?</p>',
      '<p>I may have misunderstood your message. Would you mind explaining it again?</p>',
      '<p>Could you please explain that a little differently? I\'m here to help with medConnect and City Health Office services.</p>',
    ],
    fil: [
      '<p>Paumanhin, ngunit hindi ko lubos na naintindihan ang bahagi ng iyong mensahe. Maaari mo bang linawin o i-rephrase ito? Gagawin ko ang makakaya para tumulong.</p>',
      '<p>Naiintindihan ko ang bahagi ng iyong tanong, ngunit hindi ako lubos na sigurado sa ibig mong sabihin. Maaari mo bang ipaliwanag ito nang kaunti?</p>',
      '<p>Gusto kong matiyak na naiintindihan kita nang tama. Maaari mo bang magbigay ng kaunting detalye?</p>',
      '<p>Maaaring hindi ko lubos na naintindihan ang iyong mensahe. Maaari mo bang ipaliwanag muli?</p>',
      '<p>Maaari mo bang ipaliwanag iyon nang kaunti? Nandito ako para tumulong sa medConnect at City Health Office services.</p>',
    ],
    hil: [
      '<p>Pasensya, indi ko gid maintindihan ang parte sang imo mensahe. Pwede mo bala ini linawon ukon i-rephrase? Himuon ko ang akon makaya para makabulig.</p>',
      '<p>Naintiendihan ko ang parte sang imo pamangkot, pero indi ako sigurado sang imo gusto silingon. Pwede mo bala ipaliwanag ini gamay?</p>',
      '<p>Gusto ko siguraduhon nga husto ang akon pag-intindi. Pwede mo bala maghatag sang gamay nga detalye?</p>',
      '<p>Posible nga indi ko gid maintindihan ang imo mensahe. Pwede mo bala ipaliwanag liwat?</p>',
      '<p>Pwede mo bala ipaliwanag sina gamay? Diri ako para makabulig sa medConnect kag City Health Office services.</p>',
    ],
  };

  const NONE_POOL = {
    en: [
      '<p>I\'m sorry, but I couldn\'t understand your message. Could you please rephrase your question?</p><p>I\'m here to help with <strong>appointments</strong>, <strong>consultations</strong>, <strong>account assistance</strong>, <strong>medical records</strong>, and other City Health Office services.</p>',
      '<p>I didn\'t quite understand your question. Could you rephrase it?</p><p>I can assist with medConnect registration, login, appointments, video consultations, and general healthcare service information.</p>',
    ],
    fil: [
      '<p>Paumanhin, ngunit hindi ko naintindihan ang iyong mensahe. Maaari mo bang i-rephrase ang iyong tanong?</p><p>Nandito ako para tumulong sa <strong>appointments</strong>, <strong>konsultasyon</strong>, <strong>account assistance</strong>, <strong>medical records</strong>, at iba pang serbisyo ng City Health Office.</p>',
      '<p>Hindi ko lubos na naintindihan ang iyong tanong. Maaari mo bang i-rephrase ito?</p><p>Makakatulong ako sa medConnect registration, login, appointments, video consultations, at impormasyon ng healthcare services.</p>',
    ],
    hil: [
      '<p>Pasensya, indi ko maintindihan ang imo mensahe. Pwede mo bala i-rephrase ang imo pamangkot?</p><p>Diri ako para makabulig sa <strong>appointments</strong>, <strong>konsultasyon</strong>, <strong>account assistance</strong>, <strong>medical records</strong>, kag iban pa nga serbisyo sang City Health Office.</p>',
      '<p>Indi ko gid maintindihan ang imo pamangkot. Pwede mo bala i-rephrase ini?</p><p>Makabulig ako sa medConnect registration, login, appointments, video consultations, kag impormasyon sang healthcare services.</p>',
    ],
  };

  const CONTEXT_CONTINUE = {
    en: '<p>Thank you for the additional detail. Let me help you with that.</p>',
    fil: '<p>Salamat sa karagdagang detalye. Tutulungan kita diyan.</p>',
    hil: '<p>Salamat sa dugang nga detalye. Tatabangan ko ikaw sina.</p>',
  };

  let pickCounter = 0;

  function hashPick(seed) {
    pickCounter += 1;
    const s = String(seed || pickCounter);
    let h = pickCounter;
    for (let i = 0; i < s.length; i++) {
      h = ((h << 5) - h) + s.charCodeAt(i);
      h |= 0;
    }
    return Math.abs(h);
  }

  function pickPool(pool, lang, seed) {
    const L = pool[lang] ? lang : 'en';
    const items = pool[L] || pool.en;
    return items[hashPick(seed) % items.length];
  }

  function collapseRepeats(str) {
    return String(str || '').replace(/(.)\1{2,}/gi, '$1$1');
  }

  function normalizeText(text) {
    let s = String(text || '').toLowerCase();
    s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    s = s.replace(/[@4]/g, 'a').replace(/3/g, 'e').replace(/0/g, 'o')
      .replace(/1/g, 'i').replace(/\$/g, 's').replace(/7/g, 't');
    s = collapseRepeats(s);
    s = s.replace(/[^a-z0-9\s?'-]/g, ' ');
    s = s.replace(/\s+/g, ' ').trim();
    return s;
  }

  function applyTypoCorrections(normalized) {
    let s = ` ${normalized} `;
    Object.keys(TYPO_MAP).forEach((typo) => {
      const fix = TYPO_MAP[typo];
      s = s.replace(new RegExp(` ${typo} `, 'g'), ` ${fix} `);
    });
    return s.trim();
  }

  function levenshtein(a, b) {
    if (a === b) return 0;
    if (!a.length) return b.length;
    if (!b.length) return a.length;
    const row = [];
    for (let i = 0; i <= b.length; i++) row[i] = i;
    for (let i = 1; i <= a.length; i++) {
      let prev = i - 1;
      row[0] = i;
      for (let j = 1; j <= b.length; j++) {
        const val = a[i - 1] === b[j - 1] ? prev : Math.min(prev, row[j], row[j - 1]) + 1;
        prev = row[j];
        row[j] = val;
      }
    }
    return row[b.length];
  }

  function isKnownToken(word) {
    if (!word || word.length < 2) return false;
    if (COMMON_WORDS.has(word)) return true;
    if (TYPO_MAP[word]) return true;
    for (const kw of FAQ_KEYWORDS) {
      if (kw.includes(word) || word.includes(kw)) return true;
      if (word.length >= 4 && kw.length >= 4 && levenshtein(word, kw) <= 2) return true;
    }
    return false;
  }

  function isGibberish(raw, normalized) {
    const text = String(raw || '').trim();
    if (text.length < 2) return true;

    const alpha = (text.match(/[a-zA-Z\u00C0-\u024F]/g) || []).length;
    if (text.length > 3 && alpha / text.length < 0.4) return true;

    const compact = normalized.replace(/\s/g, '');
    if (/^(asd|qwe|zxc|jkl|hjkl|asdf|qwerty|xxx|aaa|bbb|kkk|lol)+$/i.test(compact)) return true;
    if (/(.)\1{4,}/i.test(text)) return true;

    const words = normalized.split(/\s+/).filter((w) => w.length > 1);
    if (!words.length) return true;

    const known = words.filter((w) => isKnownToken(w)).length;
    return words.length >= 2 && known / words.length < 0.12;
  }

  function extractKeywords(normalized) {
    const found = [];
    const padded = ` ${normalized} `;
    FAQ_KEYWORDS.forEach((kw) => {
      if (padded.includes(` ${kw} `) || normalized.includes(kw)) found.push(kw);
    });
    return [...new Set(found)];
  }

  function computeConfidence(ctx) {
    const {
      classification, keywords, normalized, emotion, flowKey, gibberish, fromClarification,
    } = ctx;

    if (classification?.urgency === 'critical') return 98;
    if (classification?.intent === 'crisis' || classification?.intent === 'medical_emergency') return 98;

    let score = 0;

    if (classification?.intent === 'reassurance') score += 92;
    else if (classification?.intent === 'greeting' && userMessageCount === 0) score += 94;
    else if (emotion?.standalone) score += 85;
    else {
      if (flowKey && flowKey !== 'unknown') score += 48;
      score += Math.min(keywords.length * 14, 42);
      if (/\?/.test(normalized) || /^(how|what|when|where|why|paano|ano|diin|can|will|are|is)\b/.test(normalized)) {
        score += 10;
      }
      const wordCount = normalized.split(/\s+/).filter(Boolean).length;
      if (wordCount >= 3) score += 6;
      if (wordCount >= 6 && keywords.length >= 1) score += 8;
      if (emotion?.primary && (emotion.score || 0) >= 2) score += 8;
      if (fromClarification) score += 18;
      if (!flowKey || flowKey === 'unknown') {
        if (keywords.length === 0) score -= 25;
        else score += 12;
      }
    }

    if (gibberish) score = Math.min(score, 38);

    if (flowKey && flowKey !== 'unknown') {
      score = Math.max(score, THRESHOLD_FULL);
    }

    return Math.max(0, Math.min(100, Math.round(score)));
  }

  function classifyLevel(confidence, gibberish, keywords) {
    if (confidence >= THRESHOLD_FULL) return LEVEL.FULL;
    if (confidence >= THRESHOLD_PARTIAL) return LEVEL.PARTIAL;
    if (keywords.length > 0 && confidence >= 45 && !gibberish) return LEVEL.PARTIAL;
    return LEVEL.NONE;
  }

  /**
   * Full message analysis.
   * @param {string} text
   * @param {object} ctx
   * @returns {object}
   */
  function analyze(text, ctx = {}) {
    const raw = String(text || '').trim();
    const normalized = normalizeText(raw);
    const corrected = applyTypoCorrections(normalized);
    const keywords = extractKeywords(corrected);
    const gibberish = isGibberish(raw, corrected);

    const Engine = global.McFaqEngine;
    const flowKey = ctx.classification?.flowKey
      || (Engine ? Engine.matchIntent(corrected) : null)
      || (Engine && corrected !== normalized ? Engine.matchIntent(normalized) : null);

    const confidence = computeConfidence({
      classification: ctx.classification,
      keywords,
      normalized: corrected,
      emotion: ctx.emotion,
      flowKey,
      gibberish,
      fromClarification: ctx.fromClarification,
    });

    const level = classifyLevel(confidence, gibberish, keywords);

    return {
      level,
      confidence,
      normalized: corrected,
      raw,
      keywords,
      isGibberish: gibberish,
      flowKey: flowKey === 'unknown' ? null : flowKey,
      effectiveText: corrected,
    };
  }

  function hasPending() {
    return pendingClarification !== null;
  }

  function getPending() {
    return pendingClarification;
  }

  function setPending(data) {
    pendingClarification = {
      originalText: data.originalText,
      keywords: data.keywords || [],
      flowKey: data.flowKey || null,
      at: Date.now(),
    };
  }

  function clearPending() {
    pendingClarification = null;
  }

  /**
   * Merge follow-up with pending clarification context.
   * @returns {{ text: string, isContinuation: boolean }}
   */
  function mergeWithPending(followUpText) {
    if (!pendingClarification) {
      return { text: String(followUpText || '').trim(), isContinuation: false };
    }
    const combined = `${pendingClarification.originalText} ${String(followUpText || '').trim()}`.trim();
    return { text: combined, isContinuation: true };
  }

  function getPartialHtml(lang, seed) {
    return pickPool(PARTIAL_POOL, lang, seed);
  }

  function getNotUnderstoodHtml(lang, seed) {
    return pickPool(NONE_POOL, lang, seed);
  }

  function getContextContinueHtml(lang) {
    const L = CONTEXT_CONTINUE[lang] ? lang : 'en';
    return CONTEXT_CONTINUE[L];
  }

  function incrementMessageCount() {
    userMessageCount += 1;
  }

  function getUserMessageCount() {
    return userMessageCount;
  }

  function isExplicitRestart(text) {
    return RESTART_PATTERNS.some((re) => re.test(String(text || '')));
  }

  function shouldAllowFullGreeting() {
    return userMessageCount === 0;
  }

  function resetSession() {
    pendingClarification = null;
    userMessageCount = 0;
    pickCounter = 0;
  }

  global.McFaqUnderstanding = {
    LEVEL,
    THRESHOLD_FULL,
    THRESHOLD_PARTIAL,
    normalizeText,
    applyTypoCorrections,
    extractKeywords,
    analyze,
    hasPending,
    getPending,
    setPending,
    clearPending,
    mergeWithPending,
    getPartialHtml,
    getNotUnderstoodHtml,
    getContextContinueHtml,
    incrementMessageCount,
    getUserMessageCount,
    isExplicitRestart,
    shouldAllowFullGreeting,
    resetSession,
  };
})(window);
