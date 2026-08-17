/**
 * medConnect FAQ Chatbot — Application orchestrator (multilingual)
 */
(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  const Engine = window.McFaqEngine;
  const UI = window.McFaqUI;
  const Moderation = window.McFaqModeration;
  const Language = window.McFaqLanguage;
  const I18n = window.McFaqI18n;
  const Emotions = window.McFaqEmotions;
  const Intent = window.McFaqIntent;
  const Conversation = window.McFaqConversation;
  const Understanding = window.McFaqUnderstanding;
  if (!Engine || !UI || !Moderation || !Language || !I18n || !Emotions || !Intent || !Understanding) return;

  const root = document.getElementById('faq-chatbot');
  const fab = document.getElementById('fcb-fab');
  const panel = document.getElementById('fcb-panel');
  const messagesEl = document.getElementById('fcb-messages');
  const inputEl = document.getElementById('fcb-input');
  const sendBtn = document.getElementById('fcb-send');
  const charCountEl = document.getElementById('fcb-char-count');
  const badgeEl = document.getElementById('fcb-fab-badge');
  const restrictedBanner = document.getElementById('fcb-restricted');
  const backdropEl = document.getElementById('fcb-backdrop');
  const disclaimerEl = document.querySelector('.fcb-disclaimer');
  const newChatBtn = document.getElementById('fcb-new-chat');
  const minimizeBtn = document.getElementById('fcb-minimize');
  const closeBtn = document.getElementById('fcb-close');
  const inputWrap = document.querySelector('.fcb-input-wrap');

  if (!root || !fab || !panel || !messagesEl) return;

  const MAX_CHARS = 500;
  const TYPING_MS = 0;
  const MODERATION_TYPING_MS = 0;
  const STORAGE_KEY = 'mc_fcb_opened';
  const BADGE_DISMISSED_KEY = 'mc_fcb_badge_dismissed';
  const UNREAD_KEY = 'mc_fcb_unread';
  const HISTORY_KEY = 'mc_fcb_thread_html';
  const PULSE_INTERVAL = 8000;

  const assetBase = root.dataset.asset || window.ASSET_BASE || '';
  const registerUrl = assetBase + '/app/controllers/auth/register.controller.php';
  const phpChatEnabled = window.McFaqChatApi && window.McFaqChatApi.isEnabled && window.McFaqChatApi.isEnabled();
  let lastPhpAssist = null;

  let isOpen = false;
  let typingTimer = null;
  let pulseTimer = null;
  let inputDebounce = null;
  let isProcessing = false;
  let inConversation = false;

  const MOBILE_MQ = window.matchMedia('(max-width: 767px)');

  function isMobileViewport() {
    return MOBILE_MQ.matches;
  }

  function currentLang() {
    return Language.getSessionLang();
  }

  function applyChromeStrings(lang) {
    const L = I18n.normLang(lang);
    if (inputEl && !Moderation.isOnCooldown()) {
      inputEl.placeholder = I18n.t(L, 'inputPlaceholder');
    }
    if (disclaimerEl) {
      disclaimerEl.textContent = I18n.t(L, 'disclaimer');
    }
    const restrictedText = restrictedBanner?.querySelector('.fcb-restricted__text');
    if (restrictedText) {
      const timerEl = restrictedText.querySelector('[data-fcb-restricted-timer]');
      const sec = timerEl ? timerEl.textContent : '0';
      restrictedText.innerHTML = `${I18n.t(L, 'restrictedBanner')} <strong data-fcb-restricted-timer">${sec}</strong>${I18n.t(L, 'restrictedRemaining')}`;
    }
  }

  // ── Restriction / cooldown UI ──
  function setRestrictedState(restricted, seconds) {
    const active = restricted && seconds > 0;
    const displaySec = active ? seconds : 0;
    const L = currentLang();

    if (inputEl) {
      inputEl.disabled = active;
      inputEl.placeholder = active
        ? I18n.t(L, 'restrictedPlaceholder', { n: displaySec })
        : I18n.t(L, 'inputPlaceholder');
    }
    if (sendBtn) {
      sendBtn.disabled = active || !(inputEl && inputEl.value.trim().length > 0);
    }
    if (inputWrap) inputWrap.classList.toggle('fcb-input-wrap--restricted', active);
    if (restrictedBanner) {
      restrictedBanner.hidden = !active;
      const restrictedText = restrictedBanner.querySelector('.fcb-restricted__text');
      if (restrictedText) {
        restrictedText.innerHTML = `${I18n.t(L, 'restrictedBanner')} <strong data-fcb-restricted-timer">${displaySec}</strong>${I18n.t(L, 'restrictedRemaining')}`;
      }
    }
    root.classList.toggle('fcb--restricted', active);
  }

  Moderation.setCooldownListener((restricted, seconds) => {
    setRestrictedState(restricted, seconds);
    if (restricted && seconds > 0) {
      updateRestrictedMessageTimer(seconds);
    }
  });

  function setBodyScrollLock(locked) {
    document.body.classList.toggle('fcb-scroll-lock', locked && isMobileViewport());
  }

  function updateRestrictedMessageTimer(seconds) {
    messagesEl.querySelectorAll('[data-fcb-cooldown]').forEach((el) => {
      el.textContent = String(seconds);
    });
  }

  function hasActiveThread() {
    if (!messagesEl) return false;
    return Boolean(
      messagesEl.querySelector('.fcb-msg, .fcb-welcome, .fcb-actions')
    );
  }

  function persistThread() {
    if (!messagesEl) return;
    try {
      if (!hasActiveThread()) {
        sessionStorage.removeItem(HISTORY_KEY);
        return;
      }
      sessionStorage.setItem(HISTORY_KEY, messagesEl.innerHTML);
    } catch (_) { /* ignore */ }
  }

  function restoreThreadFromStorage() {
    if (!messagesEl) return false;
    try {
      const html = sessionStorage.getItem(HISTORY_KEY);
      if (!html || !html.trim()) return false;
      messagesEl.innerHTML = html;
      inConversation = true;
      UI.scrollToBottom(messagesEl);
      return true;
    } catch (_) {
      return false;
    }
  }

  function handleMessagesClick(e) {
    const card = e.target.closest('button.fcb-action-card');
    if (card) {
      if (card.dataset.fcbFlow) {
        UI.ripple(e, card);
        handleFlowSelect(card.dataset.fcbFlow, card.dataset.fcbLabel || '');
        return;
      }
      if (card.dataset.fcbAction) {
        UI.ripple(e, card);
        try { handleAction(JSON.parse(card.dataset.fcbAction)); } catch (_) { /* ignore */ }
        return;
      }
    }
    const follow = e.target.closest('button.fcb-followup');
    if (follow && follow.dataset.fcbAction) {
      UI.ripple(e, follow);
      try { handleAction(JSON.parse(follow.dataset.fcbAction)); } catch (_) { /* ignore */ }
      return;
    }
    const fbBtn = e.target.closest('button[data-fcb-feedback]');
    if (fbBtn && window.McFaqChatApi) {
      const wrap = fbBtn.closest('.fcb-feedback');
      const mid = wrap && wrap.dataset.messageId;
      const rating = fbBtn.getAttribute('data-fcb-feedback');
      if (mid && rating) {
        fbBtn.disabled = true;
        window.McFaqChatApi.sendFeedback(Number(mid), rating).then((ok) => {
          if (wrap) {
            wrap.classList.add(ok ? 'fcb-feedback--saved' : 'fcb-feedback--error');
          }
        });
      }
    }
  }

  // ── Panel state ──
  function setOpen(open) {
    isOpen = open;
    root.dataset.open = open ? 'true' : 'false';
    fab.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.setAttribute('aria-modal', open ? 'true' : 'false');
    document.body.classList.toggle('fcb-open', open);
    document.body.classList.toggle('landing-fab-open', open);

    if (open) {
      panel.hidden = false;
      panel.setAttribute('aria-hidden', 'false');
      if (backdropEl) backdropEl.hidden = false;
      root.dataset.visited = 'true';
      hideBadge();
      setBodyScrollLock(true);
      try { sessionStorage.setItem(STORAGE_KEY, '1'); } catch (_) { /* ignore */ }

      if (!hasActiveThread()) {
        startNewChat(false);
      } else {
        inConversation = true;
        applyChromeStrings(currentLang());
      }
      if (!Moderation.isOnCooldown()) {
        window.setTimeout(() => inputEl?.focus(), 320);
      }
    } else {
      panel.setAttribute('aria-hidden', 'true');
      setBodyScrollLock(false);
      if (window.McFaqVoice && window.McFaqVoice.onPanelClose) {
        window.McFaqVoice.onPanelClose();
      }
      window.setTimeout(() => {
        if (!isOpen) {
          panel.hidden = true;
          if (backdropEl) backdropEl.hidden = true;
        }
      }, 320);
      persistThread();
    }
  }

  function showBadge() {
    if (!badgeEl || isOpen) return;
    try {
      if (sessionStorage.getItem(BADGE_DISMISSED_KEY) === '1') return;
    } catch (_) { /* ignore */ }
    badgeEl.textContent = '1';
    badgeEl.hidden = false;
    badgeEl.setAttribute('aria-hidden', 'false');
    root.classList.add('fcb--has-badge');
  }

  function hideBadge() {
    if (badgeEl) {
      badgeEl.hidden = true;
      badgeEl.setAttribute('aria-hidden', 'true');
      root.classList.remove('fcb--has-badge');
    }
    try {
      sessionStorage.setItem(BADGE_DISMISSED_KEY, '1');
      sessionStorage.removeItem(UNREAD_KEY);
    } catch (_) { /* ignore */ }
  }

  /** New activity while chat is closed (e.g. bot reply finished after minimize). */
  function markChatUnread() {
    try {
      sessionStorage.removeItem(BADGE_DISMISSED_KEY);
      sessionStorage.setItem(UNREAD_KEY, '1');
    } catch (_) { /* ignore */ }
    showBadge();
  }

  function startPulse() {
    if (UI.prefersReduced()) return;
    pulseTimer = window.setInterval(() => {
      if (!isOpen) fab.classList.add('fcb-fab--pulse-tick');
      window.setTimeout(() => fab.classList.remove('fcb-fab--pulse-tick'), 900);
    }, PULSE_INTERVAL);
  }

  // ── Chat reset ──
  function startNewChat(clearInput) {
    inConversation = true;
    Moderation.resetViolations();
    Language.resetSessionLang();
    Understanding.resetSession();
    messagesEl.innerHTML = '';
    messagesEl.classList.remove('fcb-messages--scrolled');
    try { sessionStorage.removeItem(HISTORY_KEY); } catch (_) { /* ignore */ }
    applyChromeStrings(Language.DEFAULT_LANG);
    messagesEl.appendChild(UI.renderWelcomeCard(handleFlowSelect, currentLang()));
    UI.scrollToBottom(messagesEl);
    persistThread();
    if (clearInput && inputEl) {
      inputEl.value = '';
      resizeInput();
      updateCharCount();
    }
  }

  // ── Message pipeline ──
  function appendUser(text, emotionKey) {
    const lang = currentLang();
    messagesEl.appendChild(UI.renderUserMessage(text, { emotion: emotionKey || null, lang }));
    UI.scrollToBottom(messagesEl);
    persistThread();
    return emotionKey;
  }

  function showTyping() {
    const el = UI.renderTypingIndicator();
    messagesEl.appendChild(el);
    UI.scrollToBottom(messagesEl);
    return el;
  }

  function removeTyping() {
    messagesEl.querySelectorAll('[data-typing="true"]').forEach((n) => n.remove());
  }

  function deliverBot(flowKey, options = {}) {
    const lang = options.lang || currentLang();
    if (!options.suggestions && lastPhpAssist && lastPhpAssist.suggestions && lastPhpAssist.suggestions.length) {
      options.suggestions = lastPhpAssist.suggestions;
    }
    let html = options.html;
    let followUp = options.followUp;
    let actions = options.actions;

    if (html === undefined) {
      if (flowKey === 'moderation') {
        const mod = Moderation.getModerationFlow(lang);
        html = mod.html;
        actions = mod.actions;
      } else if (flowKey === 'restricted') {
        html = Moderation.getRestrictedFlow(lang, options.cooldownSec || Moderation.cooldownRemainingSec()).html;
      } else if (flowKey === 'spam') {
        const spam = Moderation.getSpamFlow(lang);
        html = spam.html;
        followUp = spam.followUp;
        actions = spam.actions;
      } else if (flowKey === 'partial_clarify') {
        const flow = Engine.getFlow(flowKey, lang);
        html = options.html || Understanding.getPartialHtml(lang, options.closingSeed || flowKey);
        followUp = flow.followUp;
        actions = flow.actions;
      } else if (flowKey === 'not_understood') {
        const flow = Engine.getFlow(flowKey, lang);
        html = options.html || Understanding.getNotUnderstoodHtml(lang, options.closingSeed || flowKey);
        followUp = flow.followUp;
        actions = flow.actions;
      } else if (flowKey === 'unknown' && Conversation) {
        const flow = Engine.getFlow(flowKey, lang);
        html = Conversation.getUnknownHtml(lang, options.closingSeed || flowKey);
        followUp = flow.followUp;
        actions = flow.actions;
      } else if (flowKey === 'clarify' && Conversation && !options.html) {
        const flow = Engine.getFlow(flowKey, lang);
        html = Conversation.getClarifyHtml(lang, options.closingSeed || flowKey);
        followUp = flow.followUp;
        actions = flow.actions;
      } else {
        const flow = Engine.getFlow(flowKey, lang);
        html = flow.html;
        followUp = flow.followUp;
        actions = flow.actions;
      }
    }

    if (followUp === undefined) {
      const flow = Engine.getFlow(flowKey, lang);
      followUp = flow.followUp;
    }
    if (actions === undefined) {
      const flow = Engine.getFlow(flowKey, lang);
      actions = flow.actions;
    }

    if (options.empathyHtml) {
      html = options.empathyHtml + html;
    }

    const INFO_CARD_FLOWS = ['partial_clarify', 'not_understood', 'unknown'];
    if (INFO_CARD_FLOWS.includes(flowKey)) {
      const variant = flowKey === 'partial_clarify' ? 'partial' : 'not_understood';
      html = UI.renderInfoCard(html, lang, variant, { suppressTitle: true });
    }

    const useActionCards = options.actionCards
      || INFO_CARD_FLOWS.includes(flowKey)
      || flowKey === 'crisis'
      || flowKey === 'emergency';
    const emergencyActions = flowKey === 'crisis' || flowKey === 'emergency';

    const noClosing = ['crisis', 'emergency', 'moderation', 'restricted', 'spam', 'partial_clarify', 'not_understood', 'domain_out_of_scope', 'domain_ambiguous'].includes(flowKey);
    if (!noClosing && !followUp && Conversation) {
      followUp = Conversation.getClosing(lang, options.closingSeed || flowKey);
    }

    const msg = UI.renderBotMessage(html, {
      followUp,
      actions,
      emergency: flowKey === 'emergency',
      crisis: flowKey === 'crisis',
      moderation: flowKey === 'moderation',
      restricted: flowKey === 'restricted',
      lang,
      emotion: options.emotion || null,
      empathy: Boolean(options.empathyHtml || options.empathy),
      actionCards: useActionCards,
      emergencyActions,
      onAction: handleAction,
      feedbackMessageId: options.feedbackMessageId || null,
      suggestions: options.suggestions || null,
    });
    messagesEl.appendChild(msg);
    UI.scrollToBottom(messagesEl);
    persistThread();
    if (!isOpen) markChatUnread();

    if (phpChatEnabled && window.McFaqChatApi && !options.feedbackMessageId) {
      const plain = msg.querySelector('.fcb-msg__bubble');
      const logHtml = plain ? plain.innerHTML : html;
      window.McFaqChatApi.logBot(logHtml, {
        flowKey,
        intent: lastPhpAssist && lastPhpAssist.intent,
        confidence: options.confidence,
      }).then((logged) => {
        const mid = logged && logged.bot_message_id;
        if (mid && !options.feedbackMessageId) {
          const fb = msg.querySelector('.fcb-feedback');
          if (!fb) {
            const fbRow = document.createElement('div');
            fbRow.className = 'fcb-feedback';
            fbRow.dataset.messageId = String(mid);
            fbRow.innerHTML = `
              <span class="fcb-feedback__label">Was this helpful?</span>
              <button type="button" class="fcb-feedback__btn" data-fcb-feedback="helpful" aria-label="Helpful">👍</button>
              <button type="button" class="fcb-feedback__btn" data-fcb-feedback="not_helpful" aria-label="Not helpful">👎</button>
            `;
            msg.appendChild(fbRow);
          } else {
            fb.dataset.messageId = String(mid);
          }
        }
      }).catch(() => {});
    }
    if (window.McFaqVoice && window.McFaqVoice.speakLastBot) {
      window.setTimeout(() => window.McFaqVoice.speakLastBot(messagesEl), 120);
    }
  }

  function deliverFromPhp(meta, lang) {
    const flow = meta.emergency_flow === 'crisis' ? 'crisis' : (meta.emergency ? 'emergency' : 'faq_php');
    deliverBot(flow, {
      html: meta.response_html,
      lang,
      typingMs: meta.typing_ms || TYPING_MS,
      feedbackMessageId: meta.bot_message_id,
      suggestions: meta.suggestions || [],
      empathy: true,
      emotion: meta.emotion_detail || meta.emotion,
      confidence: meta.confidence,
      instant: false,
    });
  }

  function runFlow(flowKey, userLabel, options = {}) {
    const lang = options.lang || currentLang();
    if (userLabel) appendUser(userLabel);

    const delay = options.instant ? 0 : (options.typingMs ?? TYPING_MS);
    if (delay === 0) {
      deliverBot(flowKey, { ...options, lang });
      return;
    }

    const typingEl = showTyping();
    window.clearTimeout(typingTimer);
    typingTimer = window.setTimeout(() => {
      removeTyping();
      if (typingEl.parentNode) typingEl.remove();
      deliverBot(flowKey, { ...options, lang });
    }, delay);
  }

  function handleFlowSelect(flowKey, label) {
    if (label) Language.resolve(label);
    const lang = currentLang();
    applyChromeStrings(lang);
    const userLabel = label || Engine.getFlowLabel(flowKey, lang);
    runFlow(flowKey, userLabel, { lang });
  }

  function handleAction(action) {
    if (Moderation.isOnCooldown()) return;
    const lang = currentLang();
    if (action.action === 'suggest') {
      processUserText(action.payload || action.label || '');
      return;
    }
    if (action.label) {
      Language.resolve(action.label);
      appendUser(action.label);
    }

    switch (action.action) {
      case 'flow':
        runFlow(action.target || 'unknown', false, { lang: currentLang() });
        break;
      case 'openSignIn':
        deliverBot('signin', {
          html: `<p>${I18n.t(lang, 'openingSignIn')}</p>`,
          followUp: null,
          actions: [],
          lang,
        });
        setOpen(false);
        window.setTimeout(openSignIn, 300);
        break;
      case 'openRegister':
        deliverBot('register', {
          html: `<p>${I18n.t(lang, 'openingRegister')}</p>`,
          followUp: null,
          actions: [],
          lang,
        });
        window.setTimeout(() => { window.location.href = registerUrl; }, 650);
        break;
      case 'openForgot':
        deliverBot('reset', {
          html: `<p>${I18n.t(lang, 'openingForgot')}</p>`,
          followUp: null,
          actions: [],
          lang,
        });
        setOpen(false);
        window.setTimeout(openForgotModal, 300);
        break;
      case 'openRequirements':
        deliverBot('register', {
          html: `<p>${I18n.t(lang, 'openingRequirements')}</p>`,
          followUp: null,
          actions: [],
          lang,
        });
        setOpen(false);
        window.setTimeout(() => document.getElementById('signin-req-fab')?.click(), 300);
        break;
      case 'scrollContact':
        deliverBot('contact', {
          html: `<p>${I18n.t(lang, 'scrollingContact')}</p>`,
          followUp: null,
          actions: [],
          lang,
        });
        window.setTimeout(scrollToContact, 450);
        break;
      case 'callEmergency':
        window.location.href = 'tel:911';
        break;
      case 'suggest':
        processUserText(action.payload || action.label || '');
        break;
      default:
        runFlow('unknown', false, { lang });
    }
  }

  function empathyHtmlFor(emoKey, flowKey, contextPrefix, phpOverride, bridge) {
    if (phpOverride) return (contextPrefix || '') + phpOverride;
    const L = currentLang();
    if (bridge && bridge.isHiligaynon && window.McFaqHilBridge) {
      const enLine = I18n.getEmpathyPrefix('en', emoKey, flowKey);
      return (contextPrefix || '') + window.McFaqHilBridge.bilingualEmpathyHtml(emoKey || 'worried', enLine);
    }
    if (!emoKey) return contextPrefix || '';
    return (contextPrefix || '') + I18n.getEmpathyPrefix(L, emoKey, flowKey);
  }

  const DISTRESS_EMOTIONS = new Set([
    'worried', 'anxious', 'stressed', 'overwhelmed', 'sad', 'lonely', 'afraid',
    'frustrated', 'angry', 'disappointed', 'nervous', 'crying', 'tired', 'hopeless', 'panic',
    'grief', 'embarrassed', 'ashamed', 'guilty', 'jealous', 'irritated', 'bored', 'uncertain', 'mixed',
  ]);

  function shouldTreatAsEmotionalSupport(emoKey, emotion, phpSuggestedFlow, text, classification, bridge) {
    if (phpSuggestedFlow === 'distress_support') return true;
    if (classification && classification.intent === Intent.INTENT.EMOTIONAL_SUPPORT) return true;
    if (bridge && bridge.isHiligaynon && /\b(worried|sad|scared|afraid|tired|angry|confused|stressed|pain|sick|help)\b/i.test(text)) {
      return true;
    }
    if (!emoKey || !DISTRESS_EMOTIONS.has(emoKey)) return false;
    const score = Number(emotion && emotion.score) || 0;
    const minScore = bridge && bridge.isHiligaynon ? 1.0 : 1.2;
    if (score < minScore) return false;
    const raw = String(text || '');
    const faqCue = /\b(how\s+do\s+i|how\s+to|paano\s+(mag|i)|register|sign\s*in|log\s*in|appointment|book|schedule)\b/i.test(raw);
    if (faqCue && !/\b(stress|worri|sad|anxious|feel|feeling|overwhelm|lonely|afraid)\b/i.test(raw)) {
      return false;
    }
    return true;
  }

  /**
   * Pipeline:
   * Language → Moderation → Intent → Emotion (PHP + client) → Understanding → Response
   */
  async function processUserText(text) {
    const trimmed = text.trim();
    if (!trimmed) return;

    let workingText = trimmed;
    let fromClarification = false;
    if (Understanding.hasPending()) {
      const merged = Understanding.mergeWithPending(trimmed);
      workingText = merged.text;
      fromClarification = merged.isContinuation;
      Understanding.clearPending();
    }

    const lang = Language.resolve(workingText);
    const bridge = window.McFaqHilBridge
      ? window.McFaqHilBridge.prepare(workingText, lang)
      : { replyLang: lang, nlpText: workingText, englishGloss: '', isHiligaynon: false };
    const replyLang = bridge.replyLang || lang;
    const nlpText = bridge.nlpText || workingText;
    if (bridge.isHiligaynon) {
      Language.setSessionLang('hil');
    }
    applyChromeStrings(replyLang);

    const validation = Moderation.validateMessage(trimmed);

    if (!validation.allow) {
      if (validation.reason === 'cooldown') {
        if (validation.cooldownSec > 0) {
          setRestrictedState(true, validation.cooldownSec);
        }
        return;
      }

      if (validation.showUser) {
        appendUser(trimmed);
      }

      if (validation.flow === 'moderation') {
        runFlow('moderation', false, { typingMs: MODERATION_TYPING_MS, lang });
        return;
      }

      if (validation.flow === 'spam') {
        runFlow('spam', false, { typingMs: MODERATION_TYPING_MS, lang });
        return;
      }

      if (validation.flow === 'restricted') {
        runFlow('restricted', false, {
          instant: true,
          cooldownSec: validation.cooldownSec,
          lang,
        });
        if (validation.cooldownSec > 0) {
          setRestrictedState(true, validation.cooldownSec);
        }
        return;
      }

      return;
    }

    if (Emotions.isSelfHarmCrisis(workingText) || Emotions.isSelfHarmCrisis(nlpText)) {
      appendUser(trimmed, 'hopeless');
      Understanding.incrementMessageCount();
      runFlow('crisis', false, { lang: replyLang });
      return;
    }

    lastPhpAssist = null;
    if (phpChatEnabled && window.McFaqChatApi) {
      const typingEl = showTyping();
      await window.McFaqChatApi.ensureSession();
      lastPhpAssist = await window.McFaqChatApi.assist(workingText, replyLang);
      removeTyping();
      if (typingEl && typingEl.parentNode) typingEl.remove();

      if (lastPhpAssist && lastPhpAssist._error) {
        if (lastPhpAssist.rateLimited) {
          Moderation.applyServerRestriction(lastPhpAssist.restriction_seconds || 30);
          appendUser(trimmed);
          runFlow('restricted', false, {
            instant: true,
            cooldownSec: lastPhpAssist.restriction_seconds || 30,
            lang: replyLang,
          });
          setRestrictedState(true, lastPhpAssist.restriction_seconds || 30);
        }
        return;
      }

      if (lastPhpAssist && lastPhpAssist.guard_action) {
        appendUser(trimmed);
        Understanding.incrementMessageCount();
        if (lastPhpAssist.restricted || lastPhpAssist.guard_action === 'restricted') {
          const sec = lastPhpAssist.restriction_seconds || 60;
          Moderation.applyServerRestriction(sec);
          setRestrictedState(true, sec);
        }
        deliverFromPhp(lastPhpAssist, replyLang);
        return;
      }

      if (lastPhpAssist && lastPhpAssist.emergency) {
        appendUser(trimmed);
        Understanding.incrementMessageCount();
        if (lastPhpAssist.use_server_response) {
          deliverFromPhp(lastPhpAssist, replyLang);
        } else if (lastPhpAssist.emergency_flow === 'crisis') {
          runFlow('crisis', false, { lang: replyLang });
        } else {
          runFlow('emergency', false, { lang: replyLang });
        }
        return;
      }
      if (lastPhpAssist && lastPhpAssist.use_server_response && lastPhpAssist.response_html) {
        appendUser(trimmed);
        Understanding.incrementMessageCount();
        deliverFromPhp(lastPhpAssist, replyLang);
        return;
      }
      if (lastPhpAssist && !lastPhpAssist.use_server_response
        && lastPhpAssist.intent
        && ['financial', 'appointment', 'login', 'registration', 'consultation', 'symptoms', 'emotional_support', 'bhw', 'technical', 'doctor', 'password_reset', 'capabilities'].includes(lastPhpAssist.intent)
        && (lastPhpAssist.confidence || 0) >= 0.60) {
        appendUser(trimmed);
        Understanding.incrementMessageCount();
        const flowFromIntent = {
          financial: 'financial',
          appointment: 'appointment',
          login: 'signin',
          registration: 'register',
          consultation: 'video',
          symptoms: 'pain_sick',
          emotional_support: 'distress_support',
          bhw: 'services',
          technical: 'services',
          doctor: 'appointment',
          password_reset: 'reset',
          capabilities: 'services',
        };
        runFlow(flowFromIntent[lastPhpAssist.intent] || 'distress_support', false, { lang: replyLang });
        return;
      }
    }

    const healthcareRelated = !Conversation
      || typeof Conversation.isHealthcareRelated !== 'function'
      || Conversation.isHealthcareRelated(workingText)
      || Conversation.isHealthcareRelated(nlpText);
    if (healthcareRelated && (Emotions.isMedicalEmergency(workingText) || Emotions.isMedicalEmergency(nlpText))) {
      appendUser(trimmed, 'emergency');
      Understanding.incrementMessageCount();
      runFlow('emergency', false, { lang: replyLang });
      return;
    }

    const classification = Intent.classify(nlpText);
    const INTENT = Intent.INTENT;
    const LEVEL = Understanding.LEVEL;

    if (classification.intent === INTENT.CRISIS) {
      appendUser(trimmed, 'hopeless');
      Understanding.incrementMessageCount();
      runFlow('crisis', false, { lang: replyLang });
      return;
    }

    if (classification.intent === INTENT.MEDICAL_EMERGENCY) {
      appendUser(trimmed, 'emergency');
      Understanding.incrementMessageCount();
      runFlow('emergency', false, { lang: replyLang });
      return;
    }

    const clientEmotion = Emotions.analyze(nlpText, { intent: classification.intent });
    let emotion = clientEmotion;
    let phpEmpathyHtml = '';
    let phpSuggestedFlow = null;

    if (window.McFaqEmotionApi) {
      const php = await window.McFaqEmotionApi.analyze(workingText, replyLang, classification.intent);
      const merged = window.McFaqEmotionApi.merge(clientEmotion, php);
      emotion = merged.emotion;
      phpEmpathyHtml = emotion.phpEmpathyHtml || '';
      phpSuggestedFlow = php && php.suggested_flow;
      window.McFaqEmotionApi.setEmotionAwareUi(!!emotion.emotionalSupport);
    }

    const emoKey = Emotions.normalizeEmotionKey(emotion.primary);
    const displayEmo = Intent.getDisplayEmotion(emoKey, classification);

    if (phpSuggestedFlow === 'crisis'
      && classification.intent !== INTENT.CRISIS
      && classification.intent !== INTENT.MEDICAL_EMERGENCY) {
      appendUser(trimmed, displayEmo || 'hopeless');
      Understanding.incrementMessageCount();
      runFlow('crisis', false, {
        lang: replyLang,
        emotion: emoKey || 'hopeless',
        empathyHtml: empathyHtmlFor(emoKey, 'crisis', '', phpEmpathyHtml, bridge),
        empathy: true,
      });
      return;
    }

    const understanding = Understanding.analyze(nlpText, {
      classification,
      emotion,
      fromClarification,
      isHiligaynon: bridge.isHiligaynon,
      englishGloss: bridge.englishGloss,
    });

    if (understanding.flowKey && !classification.flowKey) {
      classification.flowKey = understanding.flowKey;
    }

    const skipUnderstandingGate = [
      INTENT.REASSURANCE,
      INTENT.EMOTIONAL_SUPPORT,
      INTENT.APPOINTMENT,
      INTENT.LOGIN,
      INTENT.REGISTRATION,
      INTENT.FINANCIAL,
      INTENT.FAQ,
      INTENT.TECHNICAL,
      INTENT.CONNECTIVITY,
      INTENT.OFF_TOPIC,
      INTENT.HELP_OPEN,
      INTENT.AMBIGUOUS,
      INTENT.MEDICAL_INFO,
    ].includes(classification.intent)
      || emotion.standalone
      || (Conversation && Conversation.isPainOrSick(nlpText))
      || (Conversation && typeof Conversation.isPossibleHealth === 'function' && Conversation.isPossibleHealth(nlpText))
      || Emotions.isSelfHarmCrisis(nlpText)
      || Engine.isMedicalAdviceRequest(nlpText)
      || bridge.isHiligaynon
      || /\b(doctor|doktor|checkup|sakit|ulo|book|login|bulig|appointment|scared|nahadlok|ginakulbaan|bhw|otp|camera|video|password|register|mabatian|makita|ginhawa|kulbaan)\b/i.test(nlpText)
      || shouldTreatAsEmotionalSupport(emoKey, emotion, phpSuggestedFlow, nlpText, classification, bridge);

    appendUser(trimmed, displayEmo);

    if (shouldTreatAsEmotionalSupport(emoKey, emotion, phpSuggestedFlow, nlpText, classification, bridge)) {
      Understanding.incrementMessageCount();
      runFlow('distress_support', false, {
        lang: replyLang,
        emotion: emoKey,
        empathyHtml: empathyHtmlFor(emoKey, '_default', '', phpEmpathyHtml, bridge),
        empathy: true,
      });
      return;
    }

    if (!skipUnderstandingGate) {
      const hasFlow = Boolean(classification.flowKey || understanding.flowKey);

      if (understanding.level === LEVEL.NONE && !hasFlow) {
        Understanding.setPending({
          originalText: workingText,
          keywords: understanding.keywords,
          flowKey: understanding.flowKey,
        });
        Understanding.incrementMessageCount();
        runFlow('not_understood', false, { lang: replyLang, closingSeed: trimmed });
        return;
      }

      if (understanding.level === LEVEL.PARTIAL && !hasFlow) {
        Understanding.setPending({
          originalText: workingText,
          keywords: understanding.keywords,
          flowKey: null,
        });
        Understanding.incrementMessageCount();
        runFlow('partial_clarify', false, { lang: replyLang, closingSeed: trimmed });
        return;
      }
    }

    const contextPrefix = (fromClarification && understanding.level === LEVEL.FULL)
      ? Understanding.getContextContinueHtml(replyLang)
      : '';

    if (classification.intent === INTENT.REASSURANCE) {
      Understanding.incrementMessageCount();
      runFlow('reassurance', false, { lang: replyLang, emotion: displayEmo || 'curious' });
      return;
    }

    if (classification.intent === INTENT.FINANCIAL) {
      Understanding.incrementMessageCount();
      runFlow('financial', false, {
        lang: replyLang,
        emotion: emoKey || 'worried',
        empathyHtml: empathyHtmlFor(emoKey || 'worried', 'financial', contextPrefix, phpEmpathyHtml, bridge),
        empathy: true,
      });
      return;
    }

    if ([INTENT.CONNECTIVITY, INTENT.TRANSPORT, INTENT.WEATHER, INTENT.PRIVACY].includes(classification.intent)) {
      const flowMap = {
        [INTENT.CONNECTIVITY]: 'video',
        [INTENT.PRIVACY]: 'policy',
        [INTENT.WEATHER]: 'distress_support',
        [INTENT.TRANSPORT]: 'distress_support',
      };
      Understanding.incrementMessageCount();
      runFlow(flowMap[classification.intent] || 'distress_support', false, { lang: replyLang, emotion: displayEmo });
      return;
    }

    if (classification.intent === INTENT.OFF_TOPIC) {
      Understanding.incrementMessageCount();
      runFlow('domain_out_of_scope', false, { lang: replyLang });
      return;
    }

    if (classification.intent === INTENT.HELP_OPEN || classification.intent === INTENT.AMBIGUOUS) {
      Understanding.incrementMessageCount();
      runFlow('domain_ambiguous', false, { lang: replyLang });
      return;
    }

    const medicalConcern = (Conversation && Conversation.isPainOrSick(nlpText))
      || classification.intent === INTENT.MEDICAL_INFO;

    if ((classification.intent === INTENT.GREETING || Conversation.isGreeting(nlpText)) && !medicalConcern) {
      Understanding.incrementMessageCount();
      if (Understanding.shouldAllowFullGreeting() || Understanding.isExplicitRestart(trimmed)) {
        runFlow('greeting', false, { lang: replyLang });
      } else {
        runFlow('greeting_return', false, { lang: replyLang });
      }
      return;
    }

    if (Conversation.isPainOrSick(nlpText)) {
      const empathyHtml = empathyHtmlFor(emoKey || 'sick', 'pain_sick', '', phpEmpathyHtml, bridge);
      Understanding.incrementMessageCount();
      runFlow('pain_sick', false, { lang: replyLang, emotion: emoKey || 'sick', empathyHtml });
      return;
    }

    if (Conversation && typeof Conversation.isPossibleHealth === 'function' && Conversation.isPossibleHealth(nlpText)) {
      Understanding.incrementMessageCount();
      runFlow('domain_ambiguous', false, { lang: replyLang });
      return;
    }

    if (emotion.standalone === Emotions.EMOTION.THANKFUL || emotion.standalone === Emotions.EMOTION.GRATITUDE) {
      Understanding.incrementMessageCount();
      runFlow('gratitude', false, { lang: replyLang });
      return;
    }

    if (emotion.standalone === Emotions.EMOTION.HAPPY) {
      Understanding.incrementMessageCount();
      runFlow('happy', false, { lang: replyLang });
      return;
    }

    if (emotion.standalone === Emotions.EMOTION.RELIEVED) {
      Understanding.incrementMessageCount();
      runFlow('relieved', false, { lang: replyLang });
      return;
    }

    if (emotion.standalone === Emotions.EMOTION.CONFUSED || emotion.standalone === Emotions.EMOTION.CONFUSION) {
      const welcome = Engine.getFlow('welcome', replyLang);
      Understanding.incrementMessageCount();
      runFlow('clarify', false, {
        lang: replyLang,
        emotion: 'confused',
        empathy: true,
        html: contextPrefix + I18n.getEmpathyPrefix(replyLang, 'confusion', 'clarify') + `<p>${I18n.t(replyLang, 'confusionPrompt')}</p>`,
        followUp: I18n.t(replyLang, 'chooseTopic'),
        actions: welcome.actions,
      });
      return;
    }

    if (Engine.isMedicalAdviceRequest(nlpText)) {
      const empathyHtml = empathyHtmlFor(emoKey, 'policy', contextPrefix, phpEmpathyHtml, bridge);
      Understanding.incrementMessageCount();
      runFlow('policy', false, { lang: replyLang, emotion: emoKey, empathyHtml });
      return;
    }

    const intent = classification.flowKey || Engine.matchIntent(understanding.effectiveText || nlpText);
    let flowKey = Emotions.resolveFlow(intent, emotion);

    if (flowKey === 'unknown' && understanding.level === LEVEL.PARTIAL && !displayEmo) {
      Understanding.setPending({ originalText: workingText, keywords: understanding.keywords });
      Understanding.incrementMessageCount();
      runFlow('partial_clarify', false, { lang, closingSeed: trimmed });
      return;
    }

    if (!flowKey || flowKey === 'unknown') {
      flowKey = intent || 'unknown';
    }

    if (flowKey === 'welcome' && !Understanding.shouldAllowFullGreeting()) {
      flowKey = 'greeting_return';
    }

    if (flowKey === 'unknown' && understanding.level !== LEVEL.FULL) {
      Understanding.setPending({ originalText: workingText, keywords: understanding.keywords });
      Understanding.incrementMessageCount();
      runFlow(understanding.level === LEVEL.PARTIAL ? 'partial_clarify' : 'not_understood', false, { lang: replyLang, closingSeed: trimmed });
      return;
    }

    if (flowKey === 'pain_sick') {
      Understanding.incrementMessageCount();
      runFlow('pain_sick', false, {
        lang: replyLang,
        emotion: emoKey || 'sick',
        empathyHtml: empathyHtmlFor(emoKey || 'sick', 'pain_sick', '', phpEmpathyHtml, bridge),
      });
      return;
    }

    if (flowKey === 'happy' || flowKey === 'relieved' || flowKey === 'gratitude') {
      Understanding.incrementMessageCount();
      runFlow(flowKey === 'gratitude' ? 'gratitude' : flowKey, false, { lang: replyLang });
      return;
    }

    if (flowKey === 'distress_support') {
      Understanding.incrementMessageCount();
      runFlow('distress_support', false, {
        lang: replyLang,
        emotion: emoKey,
        empathyHtml: empathyHtmlFor(emoKey, '_default', '', phpEmpathyHtml, bridge),
        empathy: true,
      });
      return;
    }

    let empathyHtml = empathyHtmlFor(emoKey, flowKey, contextPrefix, phpEmpathyHtml, bridge);
    if (!phpEmpathyHtml && emoKey && flowKey !== 'crisis' && flowKey !== 'emergency' && !empathyHtml) {
      empathyHtml = contextPrefix + I18n.getEmpathyPrefix(replyLang, emoKey, flowKey);
    }

    Understanding.incrementMessageCount();
    runFlow(flowKey, false, { lang: replyLang, emotion: emoKey, empathyHtml, closingSeed: trimmed });
  }

  // ── Integrations ──
  function openSignIn() {
    if (typeof window.openSignInModal === 'function') {
      window.openSignInModal();
      return;
    }
    document.getElementById('open-signin-modal')?.click();
  }

  function openForgotModal() {
    const modal = document.getElementById('forgot-modal');
    if (modal) {
      modal.style.display = 'flex';
      document.getElementById('fp-email')?.focus();
      return;
    }
    openSignIn();
    window.setTimeout(() => document.getElementById('forgot-link')?.click(), 400);
  }

  function scrollToContact() {
    const target = document.getElementById('contact-section');
    if (!target) return;
    const nav = document.getElementById('navbar');
    const banner = document.querySelector('.landing-maintenance-banner');
    let offset = nav ? nav.offsetHeight : 72;
    if (banner) offset += banner.offsetHeight;
    const top = Math.max(0, target.getBoundingClientRect().top + window.scrollY - offset - 8);
    window.scrollTo({ top, behavior: UI.prefersReduced() ? 'auto' : 'smooth' });
    setOpen(false);
  }

  // ── Input handling ──
  function resizeInput() {
    if (!inputEl) return;
    inputEl.style.height = 'auto';
    inputEl.style.height = `${Math.min(inputEl.scrollHeight, 120)}px`;
  }

  function updateCharCount() {
    if (!inputEl || !charCountEl) return;
    const len = inputEl.value.length;
    charCountEl.textContent = `${len} / ${MAX_CHARS}`;
    if (sendBtn) {
      sendBtn.disabled = Moderation.isOnCooldown() || len === 0;
    }
  }

  async function handleSend() {
    if (!inputEl || Moderation.isOnCooldown() || isProcessing) return;
    const text = inputEl.value.trim();
    if (!text) return;
    isProcessing = true;
    if (sendBtn) sendBtn.disabled = true;
    inputEl.value = '';
    resizeInput();
    updateCharCount();
    try {
      await processUserText(text);
    } finally {
      isProcessing = false;
      updateCharCount();
    }
  }

  function onInputChange() {
    window.clearTimeout(inputDebounce);
    inputDebounce = window.setTimeout(() => {
      resizeInput();
      updateCharCount();
    }, 80);
  }

  // ── Scrollbar auto-hide ──
  let scrollHideTimer = null;
  function onMessagesScroll() {
    messagesEl.classList.add('fcb-messages--scrolled');
    window.clearTimeout(scrollHideTimer);
    scrollHideTimer = window.setTimeout(() => {
      messagesEl.classList.remove('fcb-messages--scrolled');
    }, 1200);
  }

  // ── Events ──
  fab.addEventListener('click', () => setOpen(!isOpen));
  backdropEl?.addEventListener('click', () => setOpen(false));
  minimizeBtn?.addEventListener('click', (e) => { UI.ripple(e, minimizeBtn); setOpen(false); });
  closeBtn?.addEventListener('click', (e) => { UI.ripple(e, closeBtn); setOpen(false); });
  newChatBtn?.addEventListener('click', (e) => {
    UI.ripple(e, newChatBtn);
    Moderation.clearCooldown();
    setRestrictedState(false, 0);
    startNewChat(true);
  });
  [newChatBtn, minimizeBtn, closeBtn].forEach(UI.bindRipple);

  sendBtn?.addEventListener('click', (e) => { UI.ripple(e, sendBtn); handleSend(); });
  inputEl?.addEventListener('input', onInputChange);
  inputEl?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  });

  messagesEl.addEventListener('click', handleMessagesClick);
  messagesEl.addEventListener('scroll', onMessagesScroll, { passive: true });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen) setOpen(false);
  });

  document.addEventListener('medconnect:signin', (e) => {
    if (e.detail?.open) setOpen(false);
  });

  try {
    restoreThreadFromStorage();
    // Badge is only for real unread bot replies — never a fake "1" teaser on load/login.
    if (sessionStorage.getItem(UNREAD_KEY) === '1' && sessionStorage.getItem(BADGE_DISMISSED_KEY) !== '1') {
      showBadge();
    } else if (badgeEl) {
      badgeEl.hidden = true;
      badgeEl.setAttribute('aria-hidden', 'true');
      root.classList.remove('fcb--has-badge');
    }
  } catch (_) { /* ignore */ }

  startPulse();
  applyChromeStrings(Language.DEFAULT_LANG);
  updateCharCount();
  const onCooldown = Moderation.isOnCooldown();
  setRestrictedState(onCooldown, onCooldown ? Moderation.cooldownRemainingSec() : 0);

  if (phpChatEnabled && window.McFaqChatApi) {
    window.McFaqChatApi.ensureSession().catch(() => {});
  }

  if (window.McFaqTheme) window.McFaqTheme.init();

  if (window.McFaqVoice) {
    window.McFaqVoice.init({
      root,
      inputEl,
      getLang: currentLang,
      isRestricted: () => Moderation.isOnCooldown(),
      ripple: UI.ripple,
      onInputChange: () => {
        resizeInput();
        updateCharCount();
      },
      onFinalTranscript: async (text) => {
        await processUserText(text);
      },
    });
  }
})();
