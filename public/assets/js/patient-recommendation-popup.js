/**
 * Patient Care tips chatbot (provider-approved self-care only).
 * Non-urgent: waiting until provider approves; then tips + optional read-aloud.
 */
(function () {
  'use strict';

  var STORAGE_TTS = 'mc_pt_remedy_tts';

  function csrf() {
    var root = document.getElementById('medconnectThemeRoot');
    return (document.body && document.body.dataset.csrf)
      || (root && root.dataset.csrf)
      || '';
  }

  function assetBase() {
    var root = document.getElementById('medconnectThemeRoot');
    if (typeof window.APP_BASE !== 'undefined' && window.APP_BASE) {
      return String(window.APP_BASE).replace(/\/$/, '');
    }
    return ((document.body && document.body.getAttribute('data-asset-base'))
      || (root && root.getAttribute('data-asset-base'))
      || '').replace(/\/$/, '');
  }

  function el(id) {
    return document.getElementById(id);
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  var base = assetBase();
  var currentId = 0;
  var typingTimer = null;
  var pollTimer = null;
  var mode = ''; // approved | waiting | ''
  var upcomingConsult = null;
  var ttsEnabled = true;
  var voiceBtn = null;
  var speechQueue = [];
  var speechBusy = false;
  var voicesReady = false;
  var ttsUserPrimed = false;
  var suppressAutoOpen = false;
  /** Panel visibility. Availability (FAB) is independent — never restore this as true on load. */
  var careTipsOpen = false;
  var loadGeneration = 0;
  var tipsReadyPromptShownFor = 0;
  var expiredNoticeShown = false;
  var historyUrl = base + '/views/patient/my_health.php?tab=care-tips';
  /** Cached active approved tip (within 24h) for floating Care tips button reopen. */
  var lastActiveItem = null;

  function tipsReadyPromptKey(tipId) {
    return 'mc_tips_ready_cancel_prompt_' + String(tipId || 0);
  }

  function wasTipsReadyPromptShown(tipId) {
    try {
      return sessionStorage.getItem(tipsReadyPromptKey(tipId)) === '1';
    } catch (e) {
      return tipsReadyPromptShownFor === Number(tipId || 0);
    }
  }

  function markTipsReadyPromptShown(tipId) {
    tipsReadyPromptShownFor = Number(tipId || 0);
    try {
      sessionStorage.setItem(tipsReadyPromptKey(tipId), '1');
    } catch (e) { /* ignore */ }
  }

  function closeCarePlanAcceptedModal() {
    var modal = el('mcCarePlanAcceptedModal');
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('mc-tips-ready-modal-open');
  }

  function openCarePlanAcceptedModal() {
    var modal = el('mcCarePlanAcceptedModal');
    if (!modal) return;
    var dashBtn = el('mcCarePlanDashboardBtn');
    var bookBtn = el('mcCarePlanBookBtn');
    if (dashBtn) {
      dashBtn.setAttribute('href', base + '/views/patient/dashboard.php');
    }
    if (bookBtn) {
      var href = base + '/views/patient/triage.php';
      if (currentId) {
        href += '?triage_id=' + encodeURIComponent(String(currentId));
      }
      bookBtn.setAttribute('href', href);
    }
    modal.hidden = false;
    document.body.classList.add('mc-tips-ready-modal-open');
  }

  function acceptCareTipsKeepCase() {
    if (currentId) {
      acknowledge(currentId);
      postCareEvent('dismissed', currentId);
    }
  }

  function closeTipsReadyCancelModal() {
    var modal = el('mcTipsReadyCancelModal');
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('mc-tips-ready-modal-open');
  }

  function openTipsReadyCancelModal(item) {
    var upcoming = item && item.upcoming_consultation ? item.upcoming_consultation : null;
    var tipId = Number((item && (item.tip_id || item.id)) || 0);
    if (!upcoming || !upcoming.id || !tipId) return;
    if (wasTipsReadyPromptShown(tipId)) return;

    var modal = el('mcTipsReadyCancelModal');
    if (!modal) return;

    markTipsReadyPromptShown(tipId);
    upcomingConsult = upcoming;
    currentId = tipId;

    var msg = el('mcTipsReadyCancelMessage');
    var visit = el('mcTipsReadyCancelVisit');
    if (msg) {
      msg.textContent =
        'Your doctor approved your care tips' +
        (item.chief_complaint ? (' for “' + item.chief_complaint + '”') : '') +
        '. You already have a video visit booked. If you only need the written tips, cancel the visit so the doctor’s slot opens for other patients.';
    }
    if (visit) {
      visit.hidden = false;
      visit.textContent =
        'Booked: ' +
        (upcoming.provider_name || 'Your doctor') +
        ' · ' +
        (upcoming.label || 'scheduled time');
    }

    modal.hidden = false;
    document.body.classList.add('mc-tips-ready-modal-open');
  }

  function shouldSuppressCareTipsAutoUi() {
    try {
      var path = String(window.location.pathname || '');
      if (path.indexOf('/consultation_detail.php') !== -1) return true;
      if (path.indexOf('/consultations.php') !== -1) {
        var q = String(window.location.search || '');
        if (q.indexOf('tab=past') !== -1) return true;
      }
      if (path.indexOf('/view_recording.php') !== -1) return true;
      // Returning from recording/history should not reopen care tips.
      var ref = String(document.referrer || '');
      if (ref.indexOf('view_recording.php') !== -1) return true;
      if (ref.indexOf('consultation_detail.php') !== -1) return true;
    } catch (e) { /* ignore */ }
    return !!suppressAutoOpen;
  }

  function maybeShowTipsCancelPrompt(prompt) {
    if (shouldSuppressCareTipsAutoUi()) return;
    if (!prompt || !prompt.upcoming_consultation || !prompt.upcoming_consultation.id) return;
    window.CAN_CANCEL_AFTER_TIPS_APPROVED = true;
    openTipsReadyCancelModal({
      tip_id: prompt.tip_id || prompt.id || 0,
      id: prompt.tip_id || prompt.id || 0,
      chief_complaint: prompt.chief_complaint || '',
      upcoming_consultation: prompt.upcoming_consultation,
    });
    if (typeof window.filterSessions === 'function' && Array.isArray(window.consultations)) {
      window.filterSessions('upcoming');
    }
  }

  function waitingDismissKey(id) {
    return 'ptRemedyWaitDismiss_' + String(id || 0);
  }

  function isWaitingDismissed(id) {
    try {
      if (sessionStorage.getItem('ptRemedyWaitDismiss_active') === '1') return true;
      return sessionStorage.getItem(waitingDismissKey(id)) === '1';
    } catch (e) {
      return false;
    }
  }

  function setWaitingDismissed(id) {
    try {
      sessionStorage.setItem('ptRemedyWaitDismiss_active', '1');
      if (id) sessionStorage.setItem(waitingDismissKey(id), '1');
    } catch (e) { /* ignore */ }
  }

  function clearWaitingDismissed() {
    suppressAutoOpen = false;
    try {
      sessionStorage.removeItem('ptRemedyWaitDismiss_active');
    } catch (e) { /* ignore */ }
  }

  function ttsSupported() {
    return typeof window.speechSynthesis !== 'undefined';
  }

  function loadTtsPref() {
    try {
      var saved = sessionStorage.getItem(STORAGE_TTS);
      ttsEnabled = saved !== '0';
    } catch (e) {
      ttsEnabled = true;
    }
  }

  function saveTtsPref() {
    try {
      sessionStorage.setItem(STORAGE_TTS, ttsEnabled ? '1' : '0');
    } catch (e) { /* ignore */ }
  }

  function primeVoices() {
    if (!ttsSupported()) return;
    var synth = window.speechSynthesis;
    var list = synth.getVoices();
    if (list && list.length) {
      voicesReady = true;
      return;
    }
    synth.addEventListener('voiceschanged', function onVoices() {
      voicesReady = synth.getVoices().length > 0;
      synth.removeEventListener('voiceschanged', onVoices);
    });
  }

  function pickVoice() {
    if (!ttsSupported()) return null;
    var voices = window.speechSynthesis.getVoices();
    if (!voices || !voices.length) return null;
    var prefer = ['en-PH', 'fil-PH', 'en-US', 'en-GB'];
    for (var p = 0; p < prefer.length; p++) {
      for (var i = 0; i < voices.length; i++) {
        if (voices[i].lang && voices[i].lang.indexOf(prefer[p].split('-')[0]) === 0) {
          return voices[i];
        }
      }
    }
    return voices[0];
  }

  function syncVoiceUi() {
    if (!voiceBtn) return;
    voiceBtn.hidden = !ttsSupported();
    voiceBtn.setAttribute('aria-pressed', ttsEnabled ? 'true' : 'false');
    voiceBtn.classList.toggle('is-on', ttsEnabled);
    voiceBtn.title = ttsEnabled
      ? 'Tap to read messages aloud'
      : 'Read aloud off — tap to hear messages';
    voiceBtn.setAttribute(
      'aria-label',
      'Read messages aloud'
    );
  }

  function markTtsPrimed() {
    ttsUserPrimed = true;
    primeSpeechFromUserGesture();
  }

  function resetSpeechQueue() {
    speechQueue = [];
    speechBusy = false;
  }

  function cancelSpeech() {
    resetSpeechQueue();
    onSpeechQueueIdle();
    if (ttsSupported()) {
      window.speechSynthesis.cancel();
    }
  }

  function processSpeechQueue() {
    if (!ttsEnabled || !ttsSupported() || speechBusy || !speechQueue.length) return;
    speechBusy = true;
    var text = speechQueue.shift();
    var u = new SpeechSynthesisUtterance(text);
    u.lang = 'en-PH';
    u.rate = 1;
    u.pitch = 1;
    var voice = pickVoice();
    if (voice) u.voice = voice;
    u.onend = function () {
      speechBusy = false;
      if (!speechQueue.length) onSpeechQueueIdle();
      window.setTimeout(processSpeechQueue, 80);
    };
    u.onerror = function () {
      speechBusy = false;
      if (!speechQueue.length) onSpeechQueueIdle();
      window.setTimeout(processSpeechQueue, 80);
    };
    try {
      window.speechSynthesis.speak(u);
      if (window.speechSynthesis.paused) {
        window.speechSynthesis.resume();
      }
    } catch (e) {
      speechBusy = false;
      onSpeechQueueIdle();
    }
  }

  function queueSpeech(text) {
    if (!ttsEnabled || !ttsSupported() || !text || !ttsUserPrimed) return;
    primeVoices();
    var t = String(text).replace(/\s+/g, ' ').trim();
    if (!t) return;
    speechQueue.push(t);
    if (!speechBusy) {
      processSpeechQueue();
    }
  }

  function speakText(text) {
    queueSpeech(text);
  }

  function speakAllBotBubblesInThread() {
    var thread = el('ptRemedyThread');
    if (!thread) return;
    cancelSpeech();
    var nodes = thread.querySelectorAll(
      '.pt-remedy__row--bot .pt-remedy__bubble:not(.pt-remedy__bubble--typing)'
    );
    for (var i = 0; i < nodes.length; i++) {
      var t = (nodes[i].textContent || '').trim();
      if (t) speechQueue.push(t);
    }
    if (speechQueue.length) {
      processSpeechQueue();
    }
  }

  function readAloudNow() {
    if (!ttsSupported()) return;
    markTtsPrimed();
    ttsEnabled = true;
    saveTtsPref();
    syncVoiceUi();
    if (voiceBtn) voiceBtn.classList.add('is-speaking');
    window.setTimeout(function () {
      speakAllBotBubblesInThread();
    }, 40);
  }

  function primeSpeechFromUserGesture() {
    if (!ttsSupported()) return;
    primeVoices();
    try {
      window.speechSynthesis.cancel();
    } catch (e) { /* ignore */ }
  }

  function onSpeechQueueIdle() {
    if (voiceBtn) voiceBtn.classList.remove('is-speaking');
  }

  function syncMessagesFabSuppressed(suppress) {
    var nodes = document.querySelectorAll('.mc-messages-fab, [data-messages-fab]');
    for (var i = 0; i < nodes.length; i++) {
      var fab = nodes[i];
      if (suppress) {
        fab.setAttribute('data-suppressed-for', 'pt-remedy');
        fab.style.setProperty('display', 'none', 'important');
      } else {
        fab.removeAttribute('data-suppressed-for');
        fab.style.removeProperty('display');
      }
    }
  }

  function setPanelOpenState(open) {
    careTipsOpen = !!open;
    document.body.classList.toggle('pt-remedy-panel-open', careTipsOpen);
    var root = el('ptRemedyChat');
    if (root) root.setAttribute('data-open', careTipsOpen ? 'true' : 'false');
    syncMessagesFabSuppressed(careTipsOpen);
  }

  function careTipsAvailable() {
    return mode === 'approved' || mode === 'waiting' || !!lastActiveItem;
  }

  /** Page load / back-forward: panel closed. FAB stays if tips are available. */
  function forcePanelClosedKeepFab() {
    closePanel(careTipsAvailable());
    if (careTipsAvailable()) {
      showFab(true);
    }
  }

  async function postCareEvent(action, id) {
    var tipId = Number(id || currentId || 0);
    if (!tipId || !action) return;
    try {
      await fetch(base + '/app/api/patient/care_assistant_event.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
        body: new URLSearchParams({
          id: String(tipId),
          action: String(action),
          csrf_token: csrf(),
        }),
        mcNoLoader: true,
      });
    } catch (e) { /* ignore */ }
  }

  function stopTypingAnimation() {
    if (typingTimer) {
      window.clearTimeout(typingTimer);
      typingTimer = null;
    }
    removeTyping();
  }

  function dismissWaitingPanel() {
    suppressAutoOpen = true;
    if (currentId) setWaitingDismissed(currentId);
    else setWaitingDismissed(0);
    stopTypingAnimation();
    // Persist close for approved Care Tips — FAB stays for the rest of the 24h window.
    if (mode === 'approved' && currentId) {
      postCareEvent('dismissed', currentId);
    }
    var keepFab = mode === 'approved' || mode === 'waiting' || !!lastActiveItem;
    closePanel(keepFab);
    if (keepFab) {
      showFab(true);
    }
  }

  function closeRemedyPanel(e) {
    if (e && e.preventDefault) e.preventDefault();
    if (e && e.stopPropagation) e.stopPropagation();
    dismissWaitingPanel();
    return false;
  }

  function isActiveItemFresh(item) {
    if (!item || Number(item.id || 0) <= 0) return false;
    if (item.expires_at) {
      var exp = Date.parse(String(item.expires_at));
      if (!isNaN(exp) && Date.now() >= exp) return false;
    }
    return true;
  }

  function openApprovedFromCacheOrLoad() {
    suppressAutoOpen = false;
    markTtsPrimed();
    if (isActiveItemFresh(lastActiveItem)) {
      currentId = Number(lastActiveItem.id || 0);
      mode = 'approved';
      showFab(true);
      stopTypingAnimation();
      clearThread();
      openPanel();
      playConversation(lastActiveItem);
      postCareEvent('viewed', currentId);
      return Promise.resolve();
    }
    lastActiveItem = null;
    return load({ silent: false, manualOpen: true });
  }

  function openCareAssistant() {
    suppressAutoOpen = false;
    try {
      sessionStorage.removeItem('ptRemedyWaitDismiss_active');
      if (currentId) {
        sessionStorage.removeItem(waitingDismissKey(currentId));
      }
    } catch (err) { /* ignore */ }
    showFab(true);
    return openApprovedFromCacheOrLoad();
  }

  window.MedConnectPtRemedy = {
    close: closeRemedyPanel,
    open: openCareAssistant,
    isOpen: function () { return !!careTipsOpen; },
  };

  function hideAllChoices() {
    var w = el('ptRemedyChoicesWaiting');
    var a = el('ptRemedyChoicesApproved');
    if (w) w.hidden = true;
    if (a) a.hidden = true;
  }

  function showFab(on) {
    var root = el('ptRemedyChat');
    var fab = el('ptRemedyFab');
    if (root) root.hidden = !on;
    if (fab) fab.hidden = !on;
  }

  function openPanel() {
    var root = el('ptRemedyChat');
    var panel = el('ptRemedyPanel');
    var fab = el('ptRemedyFab');
    if (!root || !panel) return;
    root.hidden = false;
    panel.hidden = false;
    panel.removeAttribute('hidden');
    setPanelOpenState(true);
    panel.setAttribute('aria-hidden', 'false');
    if (fab) {
      fab.setAttribute('aria-expanded', 'true');
    }
    syncVoiceUi();
  }

  function closePanel(keepFab) {
    var root = el('ptRemedyChat');
    var panel = el('ptRemedyPanel');
    var fab = el('ptRemedyFab');
    cancelSpeech();
    if (panel) {
      panel.hidden = true;
      panel.setAttribute('hidden', '');
      panel.setAttribute('aria-hidden', 'true');
    }
    setPanelOpenState(false);
    if (fab) {
      fab.setAttribute('aria-expanded', 'false');
      fab.hidden = !keepFab;
    }
    if (!keepFab && root) root.hidden = true;
  }

  function clearThread() {
    stopTypingAnimation();
    var thread = el('ptRemedyThread');
    if (thread) thread.innerHTML = '';
    hideAllChoices();
  }

  function appendBubble(text, kind) {
    var thread = el('ptRemedyThread');
    if (!thread) return;
    var row = document.createElement('div');
    row.className = 'pt-remedy__row pt-remedy__row--' + (kind || 'bot');
    var bubble = document.createElement('div');
    bubble.className = 'pt-remedy__bubble';
    bubble.innerHTML = escapeHtml(text);
    row.appendChild(bubble);
    thread.appendChild(row);
    thread.scrollTop = thread.scrollHeight;
    if (kind === 'bot' || !kind) {
      speakText(text);
    }
  }

  function appendTyping() {
    var thread = el('ptRemedyThread');
    if (!thread) return null;
    var row = document.createElement('div');
    row.className = 'pt-remedy__row pt-remedy__row--bot';
    row.id = 'ptRemedyTyping';
    row.innerHTML = '<div class="pt-remedy__bubble pt-remedy__bubble--typing"><span></span><span></span><span></span></div>';
    thread.appendChild(row);
    thread.scrollTop = thread.scrollHeight;
    return row;
  }

  function removeTyping() {
    var typing = el('ptRemedyTyping');
    if (typing && typing.parentNode) typing.parentNode.removeChild(typing);
  }

  function playConversation(item) {
    clearWaitingDismissed();
    mode = 'approved';
    hideAllChoices();
    upcomingConsult = item && item.upcoming_consultation ? item.upcoming_consultation : null;
    var tips = Array.isArray(item.recommendations) ? item.recommendations.slice() : [];
    var choices = el('ptRemedyChoicesApproved');
    var bookBtn = el('ptRemedyBook');
    var cancelBtn = el('ptRemedyCancelVisit');
    if (bookBtn) {
      var bookHref = item.book_url || (base + '/views/patient/triage.php');
      if (item.id && bookHref.indexOf('triage_id=') === -1 && bookHref.indexOf('/triage.php') !== -1) {
        bookHref += (bookHref.indexOf('?') === -1 ? '?' : '&') + 'triage_id=' + encodeURIComponent(String(item.id));
      }
      bookBtn.setAttribute('href', bookHref);
      bookBtn.textContent = item.book_cta_label || 'Book a consultation';
    }
    if (cancelBtn) {
      cancelBtn.hidden = !(upcomingConsult && upcomingConsult.id);
    }

    var messages = [];
    if (item.reviewed_by_label) {
      messages.push(item.reviewed_by_label + '.');
    }
    messages.push('Hi — your provider reviewed your concern' +
      (item.chief_complaint ? (' (“' + item.chief_complaint + '”)') : '') +
      ' and approved self-care guidance for a non-urgent case.');
    messages.push('Here are the tips you can try at home:');
    tips.forEach(function (tip) {
      messages.push(tip);
    });
    messages.push(item.book_message ||
      'You can follow these tips on your own. If you would like to consult a licensed doctor, you may book an appointment anytime.');

    var i = 0;
    function next() {
      if (i >= messages.length) {
        removeTyping();
        if (choices) choices.hidden = false;
        return;
      }
      appendTyping();
      typingTimer = window.setTimeout(function () {
        removeTyping();
        appendBubble(messages[i], 'bot');
        i += 1;
        next();
      }, i === 0 ? 450 : 700);
    }
    next();
  }

  async function cancelUpcomingVisit(reason) {
    if (!upcomingConsult || !upcomingConsult.id) {
      return { ok: false, message: 'No upcoming visit to cancel.' };
    }
    try {
      var res = await fetch(base + '/app/api/patient/cancel_consultation.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
        body: new URLSearchParams({
          consultation_id: String(upcomingConsult.id),
          reason: reason || 'Patient chose self-care tips instead of video visit',
          csrf_token: csrf(),
        }),
        mcNoLoader: true,
      });
      var data = await res.json().catch(function () { return null; });
      if (!data || !data.success) {
        return { ok: false, message: (data && data.message) || 'Could not cancel appointment.' };
      }
      var cancelledId = Number(upcomingConsult.id || 0);
      upcomingConsult = null;
      var cancelBtn = el('ptRemedyCancelVisit');
      if (cancelBtn) cancelBtn.hidden = true;
      return {
        ok: true,
        message: data.message || 'Appointment cancelled. Slot freed.',
        consultation_id: cancelledId,
        slots_freed: Number(data.slots_freed || (data.data && data.data.slots_freed) || 0),
      };
    } catch (e) {
      return { ok: false, message: 'Network error while cancelling.' };
    }
  }

  function playWaiting(info) {
    mode = 'waiting';
    clearThread();
    appendBubble(
      'Thanks for sharing your concern' +
      (info.chief_complaint ? (' (“' + info.chief_complaint + '”)') : '') +
      '.',
      'bot'
    );
    appendBubble(
      info.message ||
      'Your case is currently being reviewed by a healthcare provider. Please wait while your guidance is being prepared.',
      'bot'
    );
    appendBubble('You can close this chat and come back later — we will notify you here when tips are ready.', 'bot');

    var choices = el('ptRemedyChoicesWaiting');
    var bookBtn = el('ptRemedyBookWaiting');
    if (bookBtn) {
      var waitHref = base + '/views/patient/triage.php';
      var waitId = Number((info && info.id) || currentId || 0);
      if (waitId > 0) {
        waitHref += '?triage_id=' + encodeURIComponent(String(waitId));
      }
      bookBtn.setAttribute('href', waitHref);
    }
    if (choices) choices.hidden = false;
  }

  async function acknowledge(id) {
    if (!id) return;
    try {
      await fetch(base + '/app/api/patient/acknowledge_recommendation.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
        body: new URLSearchParams({
          id: String(id),
          csrf_token: csrf(),
        }),
        mcNoLoader: true,
      });
    } catch (e) { /* ignore */ }
  }

  function expiredNoticeStorageKey(expired) {
    var id = Number((expired && expired.id) || 0);
    var at = (expired && (expired.approved_at || expired.expired_at)) || '';
    return 'ptRemedyExpiredNotice_' + id + '_' + String(at);
  }

  function hasShownExpiredNotice(expired) {
    try {
      return localStorage.getItem(expiredNoticeStorageKey(expired)) === '1';
    } catch (e) {
      return expiredNoticeShown;
    }
  }

  function markExpiredNoticeShown(expired) {
    expiredNoticeShown = true;
    try {
      localStorage.setItem(expiredNoticeStorageKey(expired), '1');
    } catch (e) { /* ignore */ }
  }

  function showExpiredNotice(expired, opts) {
    if (!expired) return;
    var force = opts && opts.force;
    if (!force && (expiredNoticeShown || hasShownExpiredNotice(expired))) {
      showFab(false);
      closePanel(false);
      mode = '';
      return;
    }
    markExpiredNoticeShown(expired);
    var msg = (expired.message ||
      'Your Care Tips have expired. You can view your previous Care Tips anytime in My Health → Care Tips History.');
    var url = expired.history_url || historyUrl;
    currentId = Number(expired.id || 0) || currentId;
    mode = 'expired';
    showFab(true);
    clearThread();
    openPanel();
    appendBubble(msg, 'bot');
    hideAllChoices();

    var thread = el('ptRemedyThread');
    if (thread && url) {
      var row = document.createElement('div');
      row.className = 'pt-remedy__row pt-remedy__row--bot';
      var bubble = document.createElement('div');
      bubble.className = 'pt-remedy__bubble';
      var link = document.createElement('a');
      link.className = 'pt-remedy-link';
      link.href = url;
      link.textContent = 'Open Care Tips History';
      bubble.appendChild(link);
      row.appendChild(bubble);
      thread.appendChild(row);
      thread.scrollTop = thread.scrollHeight;
    }

    // Auto-close after a short read window; tips remain in history permanently.
    window.setTimeout(function () {
      closePanel(false);
      showFab(false);
      mode = '';
    }, 9000);
  }

  async function load(opts) {
    var silent = opts && opts.silent;
    var manualOpen = opts && opts.manualOpen;
    var gen = ++loadGeneration;
    try {
      var res = await fetch(base + '/app/api/patient/approved_recommendations.php', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-MC-No-Loader': '1' },
        mcNoLoader: true,
      });
      if (gen !== loadGeneration) return;
      var data = await res.json();
      if (!data || !data.success) return;
      if (gen !== loadGeneration) return;

      // Api::success merges payload at top level (item / awaiting_provider / expired).
      var item = data.item || (data.data && data.data.item) || null;
      var awaiting = data.awaiting_provider || (data.data && data.data.awaiting_provider) || null;
      var expired = data.expired || (data.data && data.data.expired) || null;
      var cancelPrompt = data.tips_cancel_prompt || (data.data && data.data.tips_cancel_prompt) || null;

      if (item) {
        if (item.history_url) historyUrl = item.history_url;
        var tipId = Number(item.id || 0);
        var fabVisible = item.fab_visible !== false;
        lastActiveItem = item;
        currentId = tipId;
        mode = 'approved';

        // Tips are available (FAB) but the panel stays closed unless the patient clicked.
        if (fabVisible) {
          showFab(true);
        }

        if (manualOpen) {
          clearThread();
          openPanel();
          playConversation(item);
          postCareEvent('viewed', tipId);
          maybeShowTipsCancelPrompt(cancelPrompt || {
            tip_id: item.id,
            chief_complaint: item.chief_complaint,
            upcoming_consultation: item.upcoming_consultation,
          });
          return;
        }

        // Page load, poll, back/forward, refresh: never open the panel.
        if (!careTipsOpen) {
          closePanel(true);
          showFab(true);
        }
        maybeShowTipsCancelPrompt(cancelPrompt || {
          tip_id: item.id,
          chief_complaint: item.chief_complaint,
          upcoming_consultation: item.upcoming_consultation,
        });
        return;
      }

      lastActiveItem = null;

      if (expired) {
        showFab(false);
        if (manualOpen) {
          showExpiredNotice(expired, { force: true });
        } else {
          closePanel(false);
          mode = '';
        }
        return;
      }

      if (awaiting) {
        var waitId = Number(awaiting.id || 0);
        currentId = waitId;
        mode = 'waiting';
        showFab(true);
        if (manualOpen) {
          playWaiting(awaiting);
          openPanel();
          if (ttsUserPrimed) {
            window.setTimeout(readAloudNow, 180);
          }
          return;
        }
        // Available via FAB only — do not auto-open waiting chat on page load.
        if (!careTipsOpen) {
          closePanel(true);
          showFab(true);
        }
        return;
      }

      maybeShowTipsCancelPrompt(cancelPrompt);

      if (!silent) {
        showFab(false);
        closePanel(false);
        mode = '';
      }
    } catch (e) { /* ignore */ }
  }

  function startPoll() {
    if (pollTimer) return;
    pollTimer = window.setInterval(function () {
      // Keep checking so cancel popup can appear when the doctor approves tips.
      load({ silent: true });
    }, 12000);
  }

  function bind() {
    var fab = el('ptRemedyFab');
    var closeBtn = el('ptRemedyClose');
    var selfCareBtn = el('ptRemedySelfCare');
    var bookBtn = el('ptRemedyBook');
    var bookWaiting = el('ptRemedyBookWaiting');
    var waitClose = el('ptRemedyWaitClose');
    voiceBtn = el('ptRemedyVoice');

    document.addEventListener('click', function (e) {
      var target = e.target;
      if (!target || !target.closest) return;
      if (target.closest('#ptRemedyClose') || target.closest('#ptRemedyWaitClose')) {
        closeRemedyPanel(e);
      }
    }, true);

    document.addEventListener('pointerup', function (e) {
      var target = e.target;
      if (!target || !target.closest) return;
      if (target.closest('#ptRemedyClose') || target.closest('#ptRemedyWaitClose')) {
        closeRemedyPanel(e);
      }
    }, true);

    loadTtsPref();
    syncVoiceUi();

    if (voiceBtn) {
      voiceBtn.addEventListener('click', function () {
        readAloudNow();
      });
    }

    if (fab) {
      fab.addEventListener('click', function () {
        var panel = el('ptRemedyPanel');
        if (panel && panel.hidden) {
          openApprovedFromCacheOrLoad().then(function () {
            if (ttsUserPrimed && (mode === 'approved' || mode === 'waiting')) {
              window.setTimeout(readAloudNow, 180);
            }
          });
        } else {
          closeRemedyPanel();
        }
      });
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', closeRemedyPanel);
    }
    if (waitClose) {
      waitClose.addEventListener('click', closeRemedyPanel);
    }
    document.addEventListener('keydown', function (e) {
      if (!e || e.key !== 'Escape') return;
      var panel = el('ptRemedyPanel');
      if (!panel || panel.hidden) return;
      closeRemedyPanel(e);
    });
    var cancelVisitBtn = el('ptRemedyCancelVisit');
    var tipsReadyCancelBtn = el('mcTipsReadyCancelBtn');
    var tipsReadyKeepBtn = el('mcTipsReadyKeepBtn');

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      if (t.closest('[data-mc-tips-ready-dismiss]')) {
        closeTipsReadyCancelModal();
      }
    });

    if (tipsReadyCancelBtn) {
      tipsReadyCancelBtn.addEventListener('click', async function () {
        if (!upcomingConsult || !upcomingConsult.id) {
          closeTipsReadyCancelModal();
          return;
        }
        tipsReadyCancelBtn.disabled = true;
        tipsReadyCancelBtn.textContent = 'Cancelling…';
        var result = await cancelUpcomingVisit('Cancelled after tips approved (popup)');
        tipsReadyCancelBtn.disabled = false;
        tipsReadyCancelBtn.textContent = 'Cancel video visit';
        if (!result.ok) {
          window.alert(result.message || 'Could not cancel. Try My Sessions.');
          return;
        }
        closeTipsReadyCancelModal();
        appendBubble('Cancel my video visit.', 'user');
        appendBubble(
          result.message ||
            'Visit cancelled. The doctor’s slot is free again. Your care tips stay available.',
          'bot'
        );
        if (currentId) acknowledge(currentId);
        var cancelBtn = el('ptRemedyCancelVisit');
        if (cancelBtn) cancelBtn.hidden = true;
        var bookBtnEl = el('ptRemedyBook');
        if (bookBtnEl) {
          bookBtnEl.textContent = 'Book again later';
          bookBtnEl.setAttribute('href', base + '/views/patient/triage.php');
        }
        if (Array.isArray(window.consultations)) {
          var cancelledId = Number(result.consultation_id || 0);
          window.consultations = window.consultations.map(function (c) {
            return Number(c.id) === cancelledId
              ? Object.assign({}, c, { status: 'cancelled' })
              : c;
          });
          if (typeof window.filterSessions === 'function') {
            window.filterSessions('upcoming');
          }
        }
        window.alert(result.message || 'Video visit cancelled. Slot is free again.');
      });
    }
    if (tipsReadyKeepBtn) {
      tipsReadyKeepBtn.addEventListener('click', function () {
        closeTipsReadyCancelModal();
      });
    }

    if (selfCareBtn) {
      selfCareBtn.addEventListener('click', function () {
        if (mode !== 'approved') return;
        appendBubble('I’ll follow the self-care tips.', 'user');
        acceptCareTipsKeepCase();
        openCarePlanAcceptedModal();
      });
    }
    var carePlanModal = el('mcCarePlanAcceptedModal');
    if (carePlanModal) {
      carePlanModal.addEventListener('click', function (e) {
        var target = e.target;
        if (target && target.getAttribute && target.getAttribute('data-mc-care-plan-dismiss') !== null) {
          closeCarePlanAcceptedModal();
        }
      });
    }
    var carePlanDashBtn = el('mcCarePlanDashboardBtn');
    if (carePlanDashBtn) {
      carePlanDashBtn.addEventListener('click', function () {
        closeCarePlanAcceptedModal();
        closePanel(true);
      });
    }
    var carePlanBookBtn = el('mcCarePlanBookBtn');
    if (carePlanBookBtn) {
      carePlanBookBtn.addEventListener('click', function () {
        closeCarePlanAcceptedModal();
        closePanel(true);
      });
    }
    if (cancelVisitBtn) {
      cancelVisitBtn.addEventListener('click', async function () {
        if (mode !== 'approved' || !upcomingConsult || !upcomingConsult.id) return;
        if (!window.confirm(
          'Cancel your video visit for ' + (upcomingConsult.label || 'the scheduled time') +
          '?\n\nThe doctor’s slot will become available immediately.'
        )) {
          return;
        }
        appendBubble('Cancel my video visit.', 'user');
        var result = await cancelUpcomingVisit('Cancelled from care tips chat');
        if (!result.ok) {
          appendBubble(result.message || 'Could not cancel. Try My Sessions.', 'bot');
          return;
        }
        appendBubble(result.message || 'Visit cancelled. Slot freed for other patients.', 'bot');
        acknowledge(currentId);
        var bookBtnEl = el('ptRemedyBook');
        if (bookBtnEl) {
          bookBtnEl.textContent = 'Book a consultation later';
          bookBtnEl.setAttribute('href', base + '/views/patient/triage.php');
        }
      });
    }
    if (bookBtn) {
      bookBtn.addEventListener('click', function () {
        if (mode === 'approved') acknowledge(currentId);
      });
    }
    if (bookWaiting) {
      bookWaiting.addEventListener('click', function () {
        if (currentId) setWaitingDismissed(currentId);
      });
    }
  }

  function startPatientCareTips() {
    primeVoices();
    bind();
    careTipsOpen = false;
    closePanel(false);
    load({ silent: true });
    startPoll();
    window.addEventListener('pageshow', function () {
      // Back/forward and bfcache restore must not leave the panel open.
      forcePanelClosedKeepFab();
    });
    window.addEventListener('popstate', function () {
      forcePanelClosedKeepFab();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPatientCareTips);
  } else {
    startPatientCareTips();
  }
})();
