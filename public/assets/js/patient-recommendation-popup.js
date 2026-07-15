/**
 * Patient Care tips chatbot (provider-approved self-care only).
 * Shows a waiting state while tips are pending approval.
 */
(function () {
  'use strict';

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
    root.setAttribute('data-open', 'true');
    panel.hidden = false;
    panel.setAttribute('aria-hidden', 'false');
    if (fab) {
      fab.hidden = false;
      fab.setAttribute('aria-expanded', 'true');
    }
  }

  function closePanel(keepFab) {
    var root = el('ptRemedyChat');
    var panel = el('ptRemedyPanel');
    var fab = el('ptRemedyFab');
    if (panel) {
      panel.hidden = true;
      panel.setAttribute('aria-hidden', 'true');
    }
    if (root) root.setAttribute('data-open', 'false');
    if (fab) {
      fab.setAttribute('aria-expanded', 'false');
      fab.hidden = !keepFab;
    }
  }

  function clearThread() {
    var thread = el('ptRemedyThread');
    if (thread) thread.innerHTML = '';
    var choices = el('ptRemedyChoices');
    if (choices) choices.hidden = true;
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
    mode = 'approved';
    var tips = Array.isArray(item.recommendations) ? item.recommendations.slice() : [];
    var choices = el('ptRemedyChoices');
    var bookBtn = el('ptRemedyBook');
    if (choices) choices.hidden = true;
    if (bookBtn && item.book_url) bookBtn.setAttribute('href', item.book_url);

    var messages = [];
    messages.push('Hi — your provider reviewed your concern' +
      (item.chief_complaint ? (' (“' + item.chief_complaint + '”)') : '') +
      ' and marked it as non-urgent.');
    messages.push('Here are basic self-care tips you can try at home:');
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
      'Your self-care tips are ready for provider review. They will appear here after your healthcare provider approves them.',
      'bot'
    );
    appendBubble('You can keep this chat closed and come back anytime — or book a consultation if you prefer to talk with a doctor now.', 'bot');
    var choices = el('ptRemedyChoices');
    var bookBtn = el('ptRemedyBook');
    if (bookBtn) {
      bookBtn.setAttribute('href', base + '/views/patient/triage.php');
    }
    if (choices) {
      var selfCare = el('ptRemedySelfCare');
      if (selfCare) selfCare.hidden = true;
      choices.hidden = false;
    }
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

  async function load(opts) {
    var silent = opts && opts.silent;
    try {
      var res = await fetch(base + '/app/api/patient/approved_recommendations.php', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-MC-No-Loader': '1' },
        mcNoLoader: true,
      });
      var data = await res.json();
      if (!data || !data.success) return;

      if (data.item) {
        if (mode === 'approved' && currentId === Number(data.item.id || 0) && silent) {
          return;
        }
        var wasWaiting = mode === 'waiting';
        currentId = Number(data.item.id || 0);
        showFab(true);
        clearThread();
        var selfCare = el('ptRemedySelfCare');
        if (selfCare) selfCare.hidden = false;
        if (!silent || wasWaiting) openPanel();
        playConversation(data.item);
        return;
      }

      if (data.awaiting_provider) {
        currentId = Number(data.awaiting_provider.id || 0);
        showFab(true);
        if (mode !== 'waiting' || !silent) {
          if (!silent) openPanel();
          playWaiting(data.awaiting_provider);
        }
        return;
      }

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
      if (mode === 'waiting') load({ silent: true });
    }, 20000);
  }

  function bind() {
    var fab = el('ptRemedyFab');
    var closeBtn = el('ptRemedyClose');
    var selfCareBtn = el('ptRemedySelfCare');
    var bookBtn = el('ptRemedyBook');

    if (fab) {
      fab.addEventListener('click', function () {
        var panel = el('ptRemedyPanel');
        if (panel && panel.hidden) {
          openPanel();
          if (mode === '') load();
        } else {
          closePanel(true);
        }
      });
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        closePanel(true);
      });
    }
    if (selfCareBtn) {
      selfCareBtn.addEventListener('click', function () {
        if (mode !== 'approved') return;
        appendBubble('I’ll follow the self-care tips.', 'user');
        window.setTimeout(function () {
          appendBubble('Sounds good. Take care — you can book a consultation later if symptoms change or you want to talk with a licensed doctor.', 'bot');
          acknowledge(currentId);
          window.setTimeout(function () {
            closePanel(false);
            showFab(false);
            mode = '';
          }, 1800);
        }, 350);
      });
    }
    if (bookBtn) {
      bookBtn.addEventListener('click', function () {
        if (mode === 'approved') acknowledge(currentId);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      bind();
      load();
      startPoll();
    });
  } else {
    bind();
    load();
    startPoll();
  }
})();
