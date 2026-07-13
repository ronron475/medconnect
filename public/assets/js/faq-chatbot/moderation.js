/**
 * medConnect FAQ Chatbot — Content moderation layer
 * City Health Office · Bago City
 */
(function (global) {
  'use strict';

  const VIOLATION_LIMIT = 3;
  const COOLDOWN_MS = 30000;

  /** @type {Array<{ re: RegExp, reason: string }>} */
  const PATTERN_RULES = [
    // Profanity — English
    { re: /\bf+u+c+k+/i, reason: 'profanity' },
    { re: /\bf+u+c+\s*y+o+u+/i, reason: 'profanity' },
    { re: /\bf+u+c+\s*m+e+/i, reason: 'profanity' },
    { re: /\bmotherf+u+c+k+/i, reason: 'profanity' },
    { re: /\bbitch/i, reason: 'profanity' },
    { re: /\basshole/i, reason: 'profanity' },
    { re: /\bshit/i, reason: 'profanity' },
    { re: /\bdamn\s+you/i, reason: 'profanity' },
    { re: /\bcunt/i, reason: 'profanity' },
    { re: /\bwhore/i, reason: 'profanity' },
    { re: /\bslut/i, reason: 'profanity' },
    { re: /\bbastard/i, reason: 'profanity' },
    { re: /\bdickhead/i, reason: 'profanity' },
    { re: /\bwtf\b/i, reason: 'profanity' },
    { re: /\bstfu\b/i, reason: 'profanity' },
    // Profanity — Filipino / Hiligaynon
    { re: /\bputang\s*ina/i, reason: 'profanity' },
    { re: /\bputa\b/i, reason: 'profanity' },
    { re: /\btangina/i, reason: 'profanity' },
    { re: /\bgago\b/i, reason: 'profanity' },
    { re: /\bulol\b/i, reason: 'profanity' },
    { re: /\btarantado/i, reason: 'profanity' },
    { re: /\bpuke/i, reason: 'profanity' },
    { re: /\blintik/i, reason: 'profanity' },
    { re: /\bhayop\s*ka/i, reason: 'harassment' },
    { re: /\bbobo\b/i, reason: 'harassment' },
    { re: /\btanga\b/i, reason: 'harassment' },
    // Sexual / explicit
    { re: /\bsexy\b/i, reason: 'sexual' },
    { re: /\bsex\b/i, reason: 'sexual' },
    { re: /\bs3x\b/i, reason: 'sexual' },
    { re: /\bhorny\b/i, reason: 'sexual' },
    { re: /\bnude/i, reason: 'sexual' },
    { re: /\bsend\s+nudes/i, reason: 'sexual' },
    { re: /\bshow\s+me\s+your\s+body/i, reason: 'sexual' },
    { re: /\bshow\s+your\s+body/i, reason: 'sexual' },
    { re: /\bdick\b/i, reason: 'sexual' },
    { re: /\bpussy\b/i, reason: 'sexual' },
    { re: /\bpenis\b/i, reason: 'sexual' },
    { re: /\bvagina\b/i, reason: 'sexual' },
    { re: /\bmake\s+love/i, reason: 'sexual' },
    { re: /\bhave\s+sex/i, reason: 'sexual' },
    { re: /\badult\s+content/i, reason: 'sexual' },
    { re: /\bporn/i, reason: 'sexual' },
    { re: /\bxxx\b/i, reason: 'sexual' },
    // Romantic / flirting (redirect, not emergency)
    { re: /\bkiss\s+me/i, reason: 'romantic' },
    { re: /\bmarry\s+me/i, reason: 'romantic' },
    { re: /\bi\s+love\s+you/i, reason: 'romantic' },
    { re: /\bwill\s+you\s+marry/i, reason: 'romantic' },
    { re: /\bdate\s+me/i, reason: 'romantic' },
    { re: /\bbe\s+my\s+(boyfriend|girlfriend|partner)/i, reason: 'romantic' },
    { re: /\bflirt/i, reason: 'romantic' },
    { re: /\bbeautiful\s+(girl|woman|lady)/i, reason: 'romantic' },
    { re: /\bhot\s+(girl|woman|body)/i, reason: 'romantic' },
    // Harassment / threats
    { re: /\bkill\s+you/i, reason: 'threat' },
    { re: /\bi\s+will\s+kill/i, reason: 'threat' },
    { re: /\bgo\s+die/i, reason: 'threat' },
    { re: /\bhate\s+you/i, reason: 'harassment' },
    { re: /\bstupid\s+(idiot|bot|assistant)/i, reason: 'harassment' },
    { re: /\byou\s+suck/i, reason: 'harassment' },
    { re: /\bshut\s+up/i, reason: 'harassment' },
    // Hate speech (basic)
    { re: /\bhate\s+(all\s+)?(muslims|christians|filipinos|gays|lgbt)/i, reason: 'hate' },
    // Off-topic debates & arguments
    { re: /\b(debate|argue|arguing)\b.*\b(politics|political|election|president|religion|religious)\b/i, reason: 'offtopic' },
    { re: /\b(politics|political|religion|religious)\b.*\b(debate|argue|fight|discuss)\b/i, reason: 'offtopic' },
    { re: /\blet'?s\s+debate\b/i, reason: 'offtopic' },
  ];

  /** Compact tokens checked against collapsed normalized text */
  const BLOCKED_TOKENS = [
    'fuck', 'fuk', 'fvck', 'fck', 'fcuk', 'fucker', 'motherfucker', 'mfucker',
    'bitch', 'btch', 'asshole', 'ashole', 'shit', 'cunt', 'whore', 'slut',
    'puta', 'putangina', 'tangina', 'gago', 'gag0', 'ulol', 'tarantado',
    'bobo', 'tanga', 'sex', 'sexy', 's3x', 'horny', 'nude', 'nudes', 'porn',
    'dick', 'pussy', 'penis', 'vagina', 'xxx', 'bastard', 'dickhead',
  ];

  /** Phrase fragments on collapsed text (no spaces) */
  const BLOCKED_PHRASES = [
    'fuckyou', 'fuckme', 'fucku', 'kissme', 'marryme', 'iloveyou',
    'sendnudes', 'shownude', 'showmebody', 'showyourbody', 'putangina',
    'tanginamo', 'stupidbot', 'shutup', 'killyou',
  ];

  const MODERATION_ACTIONS = [
    { label: '🔐 Sign In', action: 'flow', target: 'signin' },
    { label: '📝 Create Account', action: 'flow', target: 'register' },
    { label: '📅 Book Appointment', action: 'flow', target: 'appointment' },
    { label: '🔑 Reset Password', action: 'flow', target: 'reset' },
    { label: '💬 Leave a Message', action: 'flow', target: 'contact', primary: true },
  ];

  let consecutiveViolations = 0;
  let cooldownUntil = 0;
  let cooldownTimer = null;
  let onCooldownChange = null;

  /**
   * Normalize text for moderation matching.
   * @param {string} text
   * @returns {{ raw: string, lower: string, collapsed: string, tokens: string[] }}
   */
  function normalize(text) {
    const raw = String(text || '');
    let lower = raw.toLowerCase();

    // Leetspeak / symbol substitutions
    const subMap = {
      '@': 'a', '4': 'a', '3': 'e', '1': 'i', '!': 'i', '|': 'i',
      '0': 'o', '$': 's', '5': 's', '7': 't', '+': 't', '8': 'b',
      '9': 'g', '6': 'g', '(': 'c', '{': 'c', ')': 'o',
    };
    lower = lower.replace(/[@4310$57+86(){}|]/g, (ch) => subMap[ch] || ch);

    // Remove punctuation, keep letters/numbers/spaces
    lower = lower.replace(/[^a-z0-9\s]/g, ' ');

    // Collapse whitespace
    lower = lower.replace(/\s+/g, ' ').trim();

    // Tokenize
    const tokens = lower.split(/\s+/).filter(Boolean);

    // Collapsed (no spaces) for bypass detection: f u c k, f*ck variants
    let collapsed = lower.replace(/\s+/g, '');

    // Reduce repeated letters (fuuuuck -> fuck)
    collapsed = collapsed.replace(/(.)\1{2,}/g, '$1$1');

  // Also produce spaced-normalized for regex
    const spaced = tokens.join(' ');

    return { raw, lower: spaced, collapsed, tokens };
  }

  /**
   * @param {string} text
   * @returns {{ blocked: boolean, reason: string, category: string }}
   */
  function analyzeContent(text) {
    const n = normalize(text);
    if (!n.lower && !n.collapsed) {
      return { blocked: false, reason: '', category: '' };
    }

    // Regex rules on original-ish text (case insensitive)
    for (const rule of PATTERN_RULES) {
      if (rule.re.test(text) || rule.re.test(n.lower)) {
        return { blocked: true, reason: rule.reason, category: 'inappropriate' };
      }
    }

    // Token exact match
    for (const token of n.tokens) {
      const t = token.replace(/(.)\1{2,}/g, '$1$1');
      if (BLOCKED_TOKENS.includes(t)) {
        return { blocked: true, reason: 'profanity', category: 'inappropriate' };
      }
    }

    // Collapsed phrase match
    for (const phrase of BLOCKED_PHRASES) {
      if (n.collapsed.includes(phrase)) {
        return { blocked: true, reason: 'profanity', category: 'inappropriate' };
      }
    }

    // Partial token in collapsed string
    for (const token of BLOCKED_TOKENS) {
      if (token.length >= 4 && n.collapsed.includes(token)) {
        return { blocked: true, reason: 'profanity', category: 'inappropriate' };
      }
    }

    return { blocked: false, reason: '', category: '' };
  }

  /**
   * @param {string} text
   * @returns {boolean}
   */
  function isSpam(text) {
    const t = String(text || '').trim();
    if (t.length < 3) return false;

    // Keyboard smashing: long single-char repeat or alternating nonsense
    if (/^(.)\1{7,}$/.test(t.replace(/\s/g, ''))) return true;
    if (/^(asdf|qwer|zxcv|hjkl|1234|aaaa|bbbb|cccc|testtest)/i.test(t) && t.length < 30) return true;

    // Same character dominates (>70%)
    const letters = t.replace(/\s/g, '');
    if (letters.length >= 8) {
      const counts = {};
      for (const c of letters.toLowerCase()) counts[c] = (counts[c] || 0) + 1;
      const max = Math.max(...Object.values(counts));
      if (max / letters.length > 0.7) return true;
    }

    // URL spam
    if (/(https?:\/\/|www\.)\S+/i.test(t) && !/medconnect|bagocity|bago/i.test(t)) {
      return true;
    }

    return false;
  }

  function isOnCooldown() {
    return Date.now() < cooldownUntil;
  }

  function cooldownRemainingSec() {
    return Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
  }

  function resetViolations() {
    consecutiveViolations = 0;
  }

  function clearCooldown() {
    cooldownUntil = 0;
    if (cooldownTimer) {
      global.clearInterval(cooldownTimer);
      cooldownTimer = null;
    }
    if (onCooldownChange) onCooldownChange(false, 0);
  }

  function startCooldown() {
    cooldownUntil = Date.now() + COOLDOWN_MS;
    consecutiveViolations = 0;

    if (cooldownTimer) global.clearInterval(cooldownTimer);

    const tick = () => {
      if (!isOnCooldown()) {
        clearCooldown();
        return;
      }
      const sec = cooldownRemainingSec();
      if (sec <= 0) {
        clearCooldown();
        return;
      }
      if (onCooldownChange) onCooldownChange(true, sec);
    };

    tick();
    cooldownTimer = global.setInterval(tick, 1000);
  }

  /**
   * Full validation pipeline for an incoming message.
   * @param {string} text
   * @returns {{
   *   allow: boolean,
   *   showUser: boolean,
   *   flow: string|null,
   *   restricted: boolean,
   *   cooldownSec: number,
   *   reason: string
   * }}
   */
  function validateMessage(text) {
    const trimmed = String(text || '').trim();
    if (!trimmed) {
      return { allow: false, showUser: false, flow: null, restricted: false, cooldownSec: 0, reason: 'empty' };
    }

    if (isOnCooldown()) {
      return {
        allow: false,
        showUser: false,
        flow: 'restricted',
        restricted: true,
        cooldownSec: cooldownRemainingSec(),
        reason: 'cooldown',
      };
    }

    if (isSpam(trimmed)) {
      return {
        allow: false,
        showUser: true,
        flow: 'spam',
        restricted: false,
        cooldownSec: 0,
        reason: 'spam',
      };
    }

    const content = analyzeContent(trimmed);
    if (content.blocked) {
      consecutiveViolations += 1;

      if (consecutiveViolations >= VIOLATION_LIMIT) {
        startCooldown();
        return {
          allow: false,
          showUser: false,
          flow: 'restricted',
          restricted: true,
          cooldownSec: cooldownRemainingSec(),
          reason: 'violation_limit',
        };
      }

      return {
        allow: false,
        showUser: true,
        flow: 'moderation',
        restricted: false,
        cooldownSec: 0,
        reason: content.reason,
      };
    }

    // Valid message — reset violation streak
    consecutiveViolations = 0;
    return {
      allow: true,
      showUser: true,
      flow: null,
      restricted: false,
      cooldownSec: 0,
      reason: '',
    };
  }

  function setCooldownListener(fn) {
    onCooldownChange = typeof fn === 'function' ? fn : null;
  }

  function getModerationFlow(lang) {
    const I18n = global.McFaqI18n;
    if (I18n) return I18n.getModerationContent(lang);
    return {
      html: getModerationFlowHtml(),
      actions: MODERATION_ACTIONS,
    };
  }

  function getRestrictedFlow(lang, seconds) {
    const I18n = global.McFaqI18n;
    if (I18n) return I18n.getRestrictedContent(lang, seconds);
    return { html: getRestrictedFlowHtml(seconds) };
  }

  function getSpamFlow(lang) {
    const I18n = global.McFaqI18n;
    if (I18n) return I18n.getSpamContent(lang);
    return {
      html: getSpamFlowHtml(),
      followUp: 'How can I help you with medConnect?',
      actions: MODERATION_ACTIONS,
    };
  }

  function getModerationFlowHtml() {
    return [
      '<div class="fcb-mod-badge" role="alert"><span aria-hidden="true">⚠</span> Respectful Communication Required</div>',
      '<p>Please use respectful language.</p>',
      '<p>I\'m the official <strong>medConnect Assistant</strong> and can only assist with City Health Office and medConnect services such as:</p>',
      '<ul>',
      '<li>Account Registration</li>',
      '<li>Login Assistance</li>',
      '<li>Password Reset</li>',
      '<li>Appointment Booking</li>',
      '<li>Video Consultation</li>',
      '<li>Medical Records</li>',
      '<li>Office Information</li>',
      '</ul>',
      '<p>Please ask a healthcare service-related question.</p>',
    ].join('');
  }

  function getRestrictedFlowHtml(seconds) {
    return [
      '<div class="fcb-mod-badge fcb-mod-badge--restricted" role="alert"><span aria-hidden="true">⚠</span> Chat Temporarily Restricted</div>',
      '<p>Your recent messages violate our community guidelines.</p>',
      '<p>Please wait <strong data-fcb-cooldown>' + String(seconds) + '</strong> seconds before sending another message.</p>',
    ].join('');
  }

  function getSpamFlowHtml() {
    return [
      '<p>I couldn\'t understand that message. It may be spam or random text.</p>',
      '<p>Please type a clear question about medConnect or City Health Office services.</p>',
    ].join('');
  }

  global.McFaqModeration = {
    VIOLATION_LIMIT,
    COOLDOWN_MS,
    MODERATION_ACTIONS,
    normalize,
    analyzeContent,
    isSpam,
    validateMessage,
    isOnCooldown,
    cooldownRemainingSec,
    resetViolations,
    clearCooldown,
    setCooldownListener,
    getModerationFlowHtml,
    getRestrictedFlowHtml,
    getSpamFlowHtml,
    getModerationFlow,
    getRestrictedFlow,
    getSpamFlow,
  };
})(window);
