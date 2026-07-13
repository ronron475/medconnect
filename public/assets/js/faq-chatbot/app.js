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
  const TYPING_MS = 780;
  const MODERATION_TYPING_MS = 420;
  const STORAGE_KEY = 'mc_fcb_opened';
  const PULSE_INTERVAL = 8000;

  const assetBase = root.dataset.asset || window.ASSET_BASE || '';
  const registerUrl = assetBase + '/app/controllers/auth/register.controller.php';

  let isOpen = false;
  let typingTimer = null;
  let pulseTimer = null;
  let inputDebounce = null;
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

  // ── Panel state ──
  function setOpen(open) {
    isOpen = open;
    root.dataset.open = open ? 'true' : 'false';
    fab.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.setAttribute('aria-modal', open ? 'true' : 'false');

    if (open) {
      panel.hidden = false;
      panel.setAttribute('aria-hidden', 'false');
      if (backdropEl) backdropEl.hidden = false;
      root.dataset.visited = 'true';
      hideBadge();
      setBodyScrollLock(true);
      try { sessionStorage.setItem(STORAGE_KEY, '1'); } catch (_) { /* ignore */ }

      if (!inConversation) startNewChat(false);
      if (!Moderation.isOnCooldown()) {
        window.setTimeout(() => inputEl?.focus(), 320);
      }
    } else {
      panel.setAttribute('aria-hidden', 'true');
      setBodyScrollLock(false);
      window.setTimeout(() => {
        if (!isOpen) {
          panel.hidden = true;
          if (backdropEl) backdropEl.hidden = true;
        }
      }, 320);
    }
  }

  function showBadge() {
    if (badgeEl && !isOpen) {
      badgeEl.hidden = false;
      root.classList.add('fcb--has-badge');
    }
  }

  function hideBadge() {
    if (badgeEl) {
      badgeEl.hidden = true;
      root.classList.remove('fcb--has-badge');
    }
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
    applyChromeStrings(Language.DEFAULT_LANG);
    messagesEl.appendChild(UI.renderWelcomeCard(handleFlowSelect, currentLang()));
    UI.scrollToBottom(messagesEl);
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
      html = UI.renderInfoCard(html, lang, variant);
    }

    const useActionCards = options.actionCards
      || INFO_CARD_FLOWS.includes(flowKey)
      || flowKey === 'crisis'
      || flowKey === 'emergency';
    const emergencyActions = flowKey === 'crisis' || flowKey === 'emergency';

    const noClosing = ['crisis', 'emergency', 'moderation', 'restricted', 'spam', 'partial_clarify', 'not_understood'].includes(flowKey);
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
    });
    messagesEl.appendChild(msg);
    UI.scrollToBottom(messagesEl);
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
      default:
        runFlow('unknown', false, { lang });
    }
  }

  /**
   * Pipeline:
   * Language → Moderation → Intent → Emotion → Understanding → Response
   */
  function processUserText(text) {
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
    applyChromeStrings(lang);

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

    const classification = Intent.classify(workingText);
    const INTENT = Intent.INTENT;
    const LEVEL = Understanding.LEVEL;

    if (classification.intent === INTENT.CRISIS) {
      appendUser(trimmed, 'hopeless');
      Understanding.incrementMessageCount();
      runFlow('crisis', false, { lang });
      return;
    }

    if (classification.intent === INTENT.MEDICAL_EMERGENCY) {
      appendUser(trimmed, 'emergency');
      Understanding.incrementMessageCount();
      runFlow('emergency', false, { lang });
      return;
    }

    const emotion = Emotions.analyze(workingText, { intent: classification.intent });
    const emoKey = Emotions.normalizeEmotionKey(emotion.primary);
    const displayEmo = Intent.getDisplayEmotion(emoKey, classification);

    const understanding = Understanding.analyze(workingText, {
      classification,
      emotion,
      fromClarification,
    });

    if (understanding.flowKey && !classification.flowKey) {
      classification.flowKey = understanding.flowKey;
    }

    const skipUnderstandingGate = [
      INTENT.REASSURANCE,
    ].includes(classification.intent)
      || emotion.standalone
      || (Conversation && Conversation.isPainOrSick(workingText))
      || Emotions.isSelfHarmCrisis(workingText)
      || Engine.isMedicalAdviceRequest(workingText);

    appendUser(trimmed, displayEmo);

    if (!skipUnderstandingGate) {
      const hasFlow = Boolean(classification.flowKey || understanding.flowKey);

      if (understanding.level === LEVEL.NONE && !hasFlow) {
        Understanding.setPending({
          originalText: workingText,
          keywords: understanding.keywords,
          flowKey: understanding.flowKey,
        });
        Understanding.incrementMessageCount();
        runFlow('not_understood', false, { lang, closingSeed: trimmed });
        return;
      }

      if (understanding.level === LEVEL.PARTIAL && !hasFlow) {
        Understanding.setPending({
          originalText: workingText,
          keywords: understanding.keywords,
          flowKey: null,
        });
        Understanding.incrementMessageCount();
        runFlow('partial_clarify', false, { lang, closingSeed: trimmed });
        return;
      }
    }

    const contextPrefix = (fromClarification && understanding.level === LEVEL.FULL)
      ? Understanding.getContextContinueHtml(lang)
      : '';

    if (classification.intent === INTENT.REASSURANCE) {
      Understanding.incrementMessageCount();
      runFlow('reassurance', false, { lang, emotion: displayEmo || 'curious' });
      return;
    }

    if (classification.intent === INTENT.GREETING || Conversation.isGreeting(workingText)) {
      Understanding.incrementMessageCount();
      if (Understanding.shouldAllowFullGreeting() || Understanding.isExplicitRestart(trimmed)) {
        runFlow('greeting', false, { lang });
      } else {
        runFlow('greeting_return', false, { lang });
      }
      return;
    }

    if (Conversation.isPainOrSick(workingText)) {
      const empathyHtml = I18n.getEmpathyPrefix(lang, emoKey || 'sick', 'pain_sick');
      Understanding.incrementMessageCount();
      runFlow('pain_sick', false, { lang, emotion: emoKey || 'sick', empathyHtml });
      return;
    }

    if (emotion.standalone === Emotions.EMOTION.THANKFUL || emotion.standalone === Emotions.EMOTION.GRATITUDE) {
      Understanding.incrementMessageCount();
      runFlow('gratitude', false, { lang });
      return;
    }

    if (emotion.standalone === Emotions.EMOTION.HAPPY) {
      Understanding.incrementMessageCount();
      runFlow('happy', false, { lang });
      return;
    }

    if (emotion.standalone === Emotions.EMOTION.RELIEVED) {
      Understanding.incrementMessageCount();
      runFlow('relieved', false, { lang });
      return;
    }

    if (emotion.standalone === Emotions.EMOTION.CONFUSED || emotion.standalone === Emotions.EMOTION.CONFUSION) {
      const welcome = Engine.getFlow('welcome', lang);
      Understanding.incrementMessageCount();
      runFlow('clarify', false, {
        lang,
        emotion: 'confused',
        empathy: true,
        html: contextPrefix + I18n.getEmpathyPrefix(lang, 'confusion', 'clarify') + `<p>${I18n.t(lang, 'confusionPrompt')}</p>`,
        followUp: I18n.t(lang, 'chooseTopic'),
        actions: welcome.actions,
      });
      return;
    }

    if (Engine.isMedicalAdviceRequest(workingText)) {
      const empathyHtml = (contextPrefix || '')
        + (emoKey ? I18n.getEmpathyPrefix(lang, emoKey, 'policy') : '');
      Understanding.incrementMessageCount();
      runFlow('policy', false, { lang, emotion: emoKey, empathyHtml });
      return;
    }

    const intent = classification.flowKey || Engine.matchIntent(understanding.effectiveText || workingText);
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
      runFlow(understanding.level === LEVEL.PARTIAL ? 'partial_clarify' : 'not_understood', false, { lang, closingSeed: trimmed });
      return;
    }

    if (flowKey === 'pain_sick') {
      const empathyHtml = I18n.getEmpathyPrefix(lang, emoKey || 'sick', 'pain_sick');
      Understanding.incrementMessageCount();
      runFlow('pain_sick', false, { lang, emotion: emoKey || 'sick', empathyHtml });
      return;
    }

    if (flowKey === 'happy' || flowKey === 'relieved' || flowKey === 'gratitude') {
      Understanding.incrementMessageCount();
      runFlow(flowKey === 'gratitude' ? 'gratitude' : flowKey, false, { lang });
      return;
    }

    if (flowKey === 'distress_support') {
      const empathyHtml = emoKey ? I18n.getEmpathyPrefix(lang, emoKey, '_default') : '';
      Understanding.incrementMessageCount();
      runFlow('distress_support', false, { lang, emotion: emoKey, empathyHtml });
      return;
    }

    let empathyHtml = contextPrefix;
    if (emoKey && flowKey !== 'crisis' && flowKey !== 'emergency') {
      empathyHtml += I18n.getEmpathyPrefix(lang, emoKey, flowKey);
    }

    Understanding.incrementMessageCount();
    runFlow(flowKey, false, { lang, emotion: emoKey, empathyHtml, closingSeed: trimmed });
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

  function handleSend() {
    if (!inputEl || Moderation.isOnCooldown()) return;
    const text = inputEl.value.trim();
    if (!text) return;
    inputEl.value = '';
    resizeInput();
    updateCharCount();
    processUserText(text);
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

  messagesEl.addEventListener('scroll', onMessagesScroll, { passive: true });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen) setOpen(false);
  });

  document.addEventListener('medconnect:signin', (e) => {
    if (e.detail?.open) setOpen(false);
  });

  try {
    if (!sessionStorage.getItem(STORAGE_KEY)) {
      window.setTimeout(() => {
        if (!isOpen) showBadge();
      }, 6000);
    }
  } catch (_) { /* ignore */ }

  startPulse();
  applyChromeStrings(Language.DEFAULT_LANG);
  updateCharCount();
  const onCooldown = Moderation.isOnCooldown();
  setRestrictedState(onCooldown, onCooldown ? Moderation.cooldownRemainingSec() : 0);

  if (window.McFaqTheme) window.McFaqTheme.init();
})();
