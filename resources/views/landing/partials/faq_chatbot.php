<?php
/**
 * Landing page — premium medConnect AI FAQ chatbot (CHO Bago City).
 */
$fcbBase = htmlspecialchars($asset);
$faqChatbotCssVer = (int) @filemtime(ASSETS_PATH . '/css/faq-chatbot.css');
$faqChatbotThemeCssVer = (int) @filemtime(ASSETS_PATH . '/css/faq-chatbot-theme.css');
$faqChatbotThemeVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/theme.js');
$faqChatbotLanguageVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/language.js');
$faqChatbotI18nVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/i18n.js');
$faqChatbotEmotionDatasetVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/emotion_intent_dataset.js');
$faqChatbotEmotionsVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/emotions.js');
$faqChatbotConversationVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/conversation.js');
$faqChatbotIntentVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/intent.js');
$faqChatbotUnderstandingVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/understanding.js');
$faqChatbotModerationVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/moderation.js');
$faqChatbotEngineVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/engine.js');
$faqChatbotUiVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/ui.js');
$faqChatbotVoiceVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/voice.js');
$faqChatbotEmotionApiVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/emotion-api.js');
$faqChatbotChatApiVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/chat-api.js');
$faqChatbotHilBridgeVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/hil-bridge.js');
$faqChatbotAppVer = (int) @filemtime(ASSETS_PATH . '/js/faq-chatbot/app.js');
?>
<link rel="stylesheet" href="<?= $fcbBase ?>/assets/css/faq-chatbot.css?v=<?= $faqChatbotCssVer ?>" />
<link rel="stylesheet" href="<?= $fcbBase ?>/assets/css/faq-chatbot-theme.css?v=<?= $faqChatbotThemeCssVer ?>" />

<div
  class="fcb"
  id="faq-chatbot"
  data-open="false"
  data-theme="light"
  data-asset="<?= $fcbBase ?>"
  data-php-chat="assist"
  aria-live="polite"
>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/theme.js?v=<?= $faqChatbotThemeVer ?>"></script>
<script>try{if(window.McFaqTheme&&window.McFaqTheme.boot)window.McFaqTheme.boot();}catch(e){}</script>
  <!-- Floating launcher -->
  <div class="fcb-fab-wrap">
    <div class="fcb-fab-bubbles" aria-hidden="true">
      <span class="fcb-bubble fcb-bubble--1"></span>
      <span class="fcb-bubble fcb-bubble--2"></span>
      <span class="fcb-bubble fcb-bubble--3"></span>
      <span class="fcb-bubble fcb-bubble--4"></span>
      <span class="fcb-bubble fcb-bubble--5"></span>
      <span class="fcb-bubble fcb-bubble--6"></span>
      <span class="fcb-bubble fcb-bubble--7"></span>
      <span class="fcb-bubble fcb-bubble--8"></span>
    </div>
    <button
      type="button"
      class="fcb-fab"
      id="fcb-fab"
      aria-expanded="false"
      aria-controls="fcb-panel"
      aria-label="Open medConnect assistant"
      title="Chat with medConnect Assistant"
    >
      <span class="fcb-fab__pulse" aria-hidden="true"></span>
      <span class="fcb-fab__glow" aria-hidden="true"></span>
      <svg class="fcb-fab__icon" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <span class="fcb-fab__badge" id="fcb-fab-badge" hidden aria-hidden="true" aria-label="New message"></span>
    </button>
  </div>

  <!-- Mobile backdrop (tap outside to close) -->
  <div class="fcb-backdrop" id="fcb-backdrop" hidden aria-hidden="true"></div>

  <!-- Chat panel -->
  <section
    class="fcb-panel"
    id="fcb-panel"
    role="dialog"
    aria-modal="false"
    aria-labelledby="fcb-header-title"
    aria-describedby="fcb-header-sub"
    aria-hidden="true"
    hidden
  >
    <!-- Header -->
    <header class="fcb-header" id="fcb-header">
      <div class="fcb-header__row fcb-header__row--main">
        <div class="fcb-header__brand">
          <div class="fcb-header__logo-wrap">
            <img
              src="<?= $fcbBase ?>/assets/img/medcon_logo.png"
              alt=""
              class="fcb-header__logo"
              width="40"
              height="40"
              loading="lazy"
              decoding="async"
            />
            <span class="fcb-header__online" title="Online" aria-label="Assistant is online"></span>
          </div>
          <div class="fcb-header__meta">
            <h2 class="fcb-header__title" id="fcb-header-title"><span class="fcb-header__brand-med">med</span><span class="fcb-header__brand-connect">Connect</span><span class="fcb-header__title-suffix"> Assistant</span></h2>
            <p class="fcb-header__sub" id="fcb-header-sub">
              <span class="fcb-header__sub-label">Official AI Assistant</span>
              <span class="fcb-header__sub-dot" aria-hidden="true">•</span>
              <span class="fcb-header__sub-status">City Health Office · Online</span>
              <span class="fcb-header__sub-dot" aria-hidden="true">•</span>
              <span class="fcb-emotion-aware-chip" id="fcb-emotion-aware-chip" hidden title="Emotion-aware support">Support</span>
            </p>
          </div>
        </div>
        <div class="fcb-header__actions fcb-header__actions--tools" role="toolbar" aria-label="Chat controls">
          <button
            type="button"
            class="fcb-theme-toggle"
            id="fcb-theme-toggle"
            data-theme="light"
            aria-pressed="false"
            aria-label="Switch to dark mode"
            title="Dark mode"
          >
            <span class="fcb-theme-toggle__track" aria-hidden="true">
              <span class="fcb-theme-toggle__icon fcb-theme-toggle__icon--sun">☀</span>
              <span class="fcb-theme-toggle__icon fcb-theme-toggle__icon--moon">🌙</span>
            </span>
            <span class="fcb-theme-toggle__knob" aria-hidden="true"></span>
          </button>
          <button type="button" class="fcb-icon-btn fcb-tts-btn" id="fcb-voice-tts" aria-label="Toggle read replies aloud" aria-pressed="true" title="Read replies aloud">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
          </button>
          <button type="button" class="fcb-icon-btn" id="fcb-new-chat" aria-label="Start new chat" title="New chat">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
          </button>
          <button type="button" class="fcb-icon-btn" id="fcb-close" aria-label="Close chat" title="Close">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
        </div>
      </div>
    </header>

    <!-- Message area -->
    <div class="fcb-body">
      <div class="fcb-bg" aria-hidden="true">
        <div class="fcb-bg__mesh"></div>
        <div class="fcb-bg__grid"></div>
        <div class="fcb-bg__robots">
          <svg class="fcb-bg-robot fcb-bg-robot--1" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="14" y="22" width="36" height="30" rx="8" stroke="currentColor" stroke-width="2.5"/>
            <path d="M22 22V14a10 10 0 0 1 20 0v8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="24" cy="34" r="3" fill="currentColor"/><circle cx="40" cy="34" r="3" fill="currentColor"/>
            <path d="M24 44h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="32" cy="9" r="3" fill="currentColor"/>
            <path d="M32 12v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <svg class="fcb-bg-robot fcb-bg-robot--2" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="14" y="22" width="36" height="30" rx="8" stroke="currentColor" stroke-width="2.5"/>
            <path d="M22 22V14a10 10 0 0 1 20 0v8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="24" cy="34" r="3" fill="currentColor"/><circle cx="40" cy="34" r="3" fill="currentColor"/>
            <path d="M24 44h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="32" cy="9" r="3" fill="currentColor"/>
            <path d="M32 12v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <svg class="fcb-bg-robot fcb-bg-robot--3" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="14" y="22" width="36" height="30" rx="8" stroke="currentColor" stroke-width="2.5"/>
            <path d="M22 22V14a10 10 0 0 1 20 0v8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="24" cy="34" r="3" fill="currentColor"/><circle cx="40" cy="34" r="3" fill="currentColor"/>
            <path d="M24 44h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="32" cy="9" r="3" fill="currentColor"/>
            <path d="M32 12v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <svg class="fcb-bg-robot fcb-bg-robot--4" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="14" y="22" width="36" height="30" rx="8" stroke="currentColor" stroke-width="2.5"/>
            <path d="M22 22V14a10 10 0 0 1 20 0v8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="24" cy="34" r="3" fill="currentColor"/><circle cx="40" cy="34" r="3" fill="currentColor"/>
            <path d="M24 44h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="32" cy="9" r="3" fill="currentColor"/>
            <path d="M32 12v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <svg class="fcb-bg-robot fcb-bg-robot--5" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="14" y="22" width="36" height="30" rx="8" stroke="currentColor" stroke-width="2.5"/>
            <path d="M22 22V14a10 10 0 0 1 20 0v8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="24" cy="34" r="3" fill="currentColor"/><circle cx="40" cy="34" r="3" fill="currentColor"/>
            <path d="M24 44h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="32" cy="9" r="3" fill="currentColor"/>
            <path d="M32 12v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="fcb-bg__bubbles" aria-hidden="true">
          <span class="fcb-bg-bubble fcb-bg-bubble--1"></span>
          <span class="fcb-bg-bubble fcb-bg-bubble--2"></span>
          <span class="fcb-bg-bubble fcb-bg-bubble--3"></span>
          <span class="fcb-bg-bubble fcb-bg-bubble--4"></span>
          <span class="fcb-bg-bubble fcb-bg-bubble--5"></span>
          <span class="fcb-bg-bubble fcb-bg-bubble--6"></span>
          <span class="fcb-bg-bubble fcb-bg-bubble--7"></span>
          <span class="fcb-bg-bubble fcb-bg-bubble--8"></span>
        </div>
        <div class="fcb-bg__scanline"></div>
      </div>
      <div
        class="fcb-messages"
        id="fcb-messages"
        role="log"
        aria-relevant="additions"
        aria-label="Conversation with medConnect Assistant"
        tabindex="0"
      ></div>
    </div>

    <!-- Input -->
    <footer class="fcb-footer">
      <div class="fcb-restricted" id="fcb-restricted" role="alert" aria-live="assertive" hidden>
        <span class="fcb-restricted__icon" aria-hidden="true">🔒</span>
        <span class="fcb-restricted__text">
          Chat temporarily limited —
          <strong data-fcb-restricted-timer>30</strong> remaining
        </span>
      </div>
      <div class="fcb-input-wrap">
        <button type="button" class="fcb-voice-btn" id="fcb-voice-mic" aria-label="Speak your question" aria-pressed="false" title="Speak your question">
          <svg class="fcb-voice-btn__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/>
            <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
            <line x1="12" x2="12" y1="19" y2="22"/>
          </svg>
          <span class="fcb-voice-btn__pulse" aria-hidden="true"></span>
        </button>
        <label class="visually-hidden" for="fcb-input">Ask medConnect Assistant</label>
        <textarea
          class="fcb-input"
          id="fcb-input"
          rows="1"
          placeholder="Ask me anything about medConnect..."
          maxlength="500"
          autocomplete="off"
          aria-describedby="fcb-char-count"
        ></textarea>
        <button type="button" class="fcb-send" id="fcb-send" aria-label="Send message" disabled>
          <svg class="fcb-send__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>
          </svg>
        </button>
      </div>
      <p class="fcb-voice-status" id="fcb-voice-status" hidden aria-live="polite"></p>
      <div class="fcb-footer__meta">
        <span class="fcb-char-count" id="fcb-char-count" aria-live="polite">0 / 500</span>
      </div>
      <p class="fcb-disclaimer">
        For non-emergency use only. I cannot diagnose or prescribe medication.
      </p>
    </footer>
  </section>
</div>

<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/language.js?v=<?= $faqChatbotLanguageVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/i18n.js?v=<?= $faqChatbotI18nVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/emotions.js?v=<?= $faqChatbotEmotionsVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/conversation.js?v=<?= $faqChatbotConversationVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/intent.js?v=<?= $faqChatbotIntentVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/understanding.js?v=<?= $faqChatbotUnderstandingVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/moderation.js?v=<?= $faqChatbotModerationVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/engine.js?v=<?= $faqChatbotEngineVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/ui.js?v=<?= $faqChatbotUiVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/voice.js?v=<?= $faqChatbotVoiceVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/emotion-api.js?v=<?= $faqChatbotEmotionApiVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/chat-api.js?v=<?= $faqChatbotChatApiVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/hil-bridge.js?v=<?= $faqChatbotHilBridgeVer ?>" defer></script>
<script src="<?= $fcbBase ?>/assets/js/faq-chatbot/app.js?v=<?= $faqChatbotAppVer ?>" defer></script>
<script>
(function () {
  var datasetLoaded = false;
  var queuedOpen = false;
  var src = <?= json_encode($fcbBase) ?> + '/assets/js/faq-chatbot/emotion_intent_dataset.js?v=<?= $faqChatbotEmotionDatasetVer ?>';
  var fab = document.getElementById('fcb-fab');

  function loadDataset() {
    if (datasetLoaded) return;
    datasetLoaded = true;
    var s = document.createElement('script');
    s.src = src;
    s.async = true;
    document.body.appendChild(s);
  }

  function openChat() {
    if (window.McFaqApp && typeof window.McFaqApp.open === 'function') {
      window.McFaqApp.open();
      queuedOpen = false;
      if (fab) fab.removeAttribute('aria-busy');
      return true;
    }
    return false;
  }

  if (fab) {
    fab.addEventListener('click', function (e) {
      loadDataset();
      if (window.McFaqApp) return;
      queuedOpen = true;
      fab.setAttribute('aria-busy', 'true');
      e.preventDefault();
      e.stopImmediatePropagation();
    }, true);
  }

  var tries = 0;
  var readyTimer = window.setInterval(function () {
    if (!queuedOpen) {
      if (window.McFaqApp) window.clearInterval(readyTimer);
      return;
    }
    if (openChat() || ++tries > 80) window.clearInterval(readyTimer);
  }, 50);
})();
</script>
