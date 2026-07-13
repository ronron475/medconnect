/**
 * medConnect FAQ Chatbot — UI components (multilingual)
 */
(function (global) {
  'use strict';

  const Engine = global.McFaqEngine;
  const I18n = global.McFaqI18n;
  const Emotions = global.McFaqEmotions;
  if (!Engine) return;

  const prefersReduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function esc(str) {
    const el = document.createElement('span');
    el.textContent = str == null ? '' : String(str);
    return el.innerHTML;
  }

  function formatTime(date) {
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function botName(lang) {
    return I18n ? I18n.t(lang, 'botName') : Engine.BOT_NAME;
  }

  function emergencyLabel(lang) {
    return I18n ? I18n.t(lang, 'emergencyBadge') : 'Medical Emergency';
  }

  function ripple(event, el) {
    if (prefersReduced()) return;
    const rect = el.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const x = (event.clientX || rect.left + rect.width / 2) - rect.left - size / 2;
    const y = (event.clientY || rect.top + rect.height / 2) - rect.top - size / 2;
    const dot = document.createElement('span');
    dot.className = 'fcb-ripple';
    dot.style.width = dot.style.height = `${size}px`;
    dot.style.left = `${x}px`;
    dot.style.top = `${y}px`;
    el.appendChild(dot);
    dot.addEventListener('animationend', () => dot.remove(), { once: true });
  }

  function bindRipple(el) {
    if (!el) return;
    el.addEventListener('click', (e) => ripple(e, el));
  }

  function robotSvg() {
    return `<svg class="fcb-robot__svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line class="fcb-robot__antenna" x1="12" y1="4.5" x2="12" y2="7.25"/>
      <circle class="fcb-robot__antenna-dot" cx="12" cy="3.25" r="1.35" fill="currentColor" stroke="none"/>
      <rect class="fcb-robot__head" x="5" y="7.25" width="14" height="11.75" rx="3.5"/>
      <circle class="fcb-robot__eye fcb-robot__eye--l" cx="9.25" cy="12.25" r="1.2" fill="currentColor" stroke="none"/>
      <circle class="fcb-robot__eye fcb-robot__eye--r" cx="14.75" cy="12.25" r="1.2" fill="currentColor" stroke="none"/>
      <path class="fcb-robot__mouth" d="M9.75 15.75c.85.85 1.65 1.15 2.25 1.15s1.4-.3 2.25-1.15"/>
      <path class="fcb-robot__cheek fcb-robot__cheek--l" d="M7.5 13.5h1.25" opacity="0.55"/>
      <path class="fcb-robot__cheek fcb-robot__cheek--r" d="M15.25 13.5h1.25" opacity="0.55"/>
    </svg>`;
  }

  function renderBotAvatar(options = {}) {
    const { typing = false, tone = '' } = options;
    const toneCls = tone ? ` fcb-robot--${tone}` : '';
    const typingCls = typing ? ' fcb-robot--typing' : '';
    return `
      <div class="fcb-msg__avatar fcb-robot${typingCls}${toneCls}" aria-hidden="true">
        <span class="fcb-robot__ring" aria-hidden="true"></span>
        <span class="fcb-robot__glow" aria-hidden="true"></span>
        <span class="fcb-robot__scan" aria-hidden="true"></span>
        ${robotSvg()}
      </div>
    `;
  }

  /** @deprecated use renderBotAvatar */
  function avatarSvg() {
    return robotSvg();
  }

  /** Welcome card + quick action cards */
  function renderWelcomeCard(onAction, lang) {
    const L = I18n ? I18n.normLang(lang) : 'en';
    const w = I18n ? I18n.getWelcomeStrings(L) : {
      title: 'Welcome to medConnect!',
      lead: 'I\'m your virtual assistant for the City Health Office of Bago City.',
      topicsLabel: 'I can help you with:',
      topicList: ['Account Registration', 'Login Assistance', 'Appointment Booking', 'Video Consultation', 'Password Recovery', 'General Questions'],
      cta: 'How may I assist you today?',
    };

    const frag = document.createDocumentFragment();
    const card = document.createElement('article');
    card.className = 'fcb-welcome fcb-animate-in';
    card.setAttribute('role', 'region');
    card.setAttribute('aria-label', 'Welcome');
    card.innerHTML = `
      <div class="fcb-welcome__hero">
        <span class="fcb-welcome__emoji" aria-hidden="true">👋</span>
        <h3 class="fcb-welcome__title">${esc(w.title)}</h3>
        <p class="fcb-welcome__lead">${w.lead}</p>
      </div>
      <div class="fcb-welcome__topics">
        <p class="fcb-welcome__topics-label">${esc(w.topicsLabel)}</p>
        <ul class="fcb-welcome__list">
          ${w.topicList.map((item) => `<li>${esc(item)}</li>`).join('')}
        </ul>
      </div>
      <p class="fcb-welcome__cta">${esc(w.cta)}</p>
    `;
    frag.appendChild(card);
    frag.appendChild(renderQuickActions(onAction, L));
    return frag;
  }

  function renderQuickActions(onAction, lang) {
    const items = Engine.getQuickActions(lang);
    const grid = document.createElement('div');
    grid.className = 'fcb-actions';
    grid.setAttribute('role', 'list');
    items.forEach((item, i) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fcb-action-card';
      btn.setAttribute('role', 'listitem');
      btn.style.animationDelay = `${i * 50}ms`;
      btn.innerHTML = `
        <span class="fcb-action-card__icon" aria-hidden="true">${esc(item.icon)}</span>
        <span class="fcb-action-card__body">
          <span class="fcb-action-card__title">${esc(item.title)}</span>
          <span class="fcb-action-card__desc">${esc(item.desc)}</span>
        </span>
        <span class="fcb-action-card__chev" aria-hidden="true">›</span>
      `;
      btn.addEventListener('click', (e) => {
        ripple(e, btn);
        onAction(item.flow, item.title);
      });
      bindRipple(btn);
      grid.appendChild(btn);
    });
    return grid;
  }

  function renderFollowUpActions(actions, onAction) {
    if (!actions || !actions.length) return null;
    const wrap = document.createElement('div');
    wrap.className = 'fcb-followups fcb-animate-in';
    actions.forEach((act, i) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fcb-followup' + (act.primary ? ' fcb-followup--primary' : '');
      btn.textContent = act.label;
      btn.style.animationDelay = `${i * 40}ms`;
      btn.addEventListener('click', (e) => {
        ripple(e, btn);
        onAction(act);
      });
      bindRipple(btn);
      wrap.appendChild(btn);
    });
    return wrap;
  }

  function renderFollowUpActionCards(actions, onAction, lang) {
    if (!actions || !actions.length) return null;
    const wrap = document.createElement('div');
    wrap.className = 'fcb-actions fcb-actions--followup fcb-animate-in';
    wrap.setAttribute('role', 'list');
    actions.forEach((act, i) => {
      const meta = I18n && I18n.getFollowActionMeta
        ? I18n.getFollowActionMeta(lang || 'en', act)
        : { icon: '›', desc: '' };
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fcb-action-card'
        + (act.primary ? ' fcb-action-card--primary' : '')
        + (meta.danger ? ' fcb-action-card--danger' : '');
      btn.setAttribute('role', 'listitem');
      btn.style.animationDelay = `${i * 50}ms`;
      btn.innerHTML = `
        <span class="fcb-action-card__icon" aria-hidden="true">${esc(meta.icon)}</span>
        <span class="fcb-action-card__body">
          <span class="fcb-action-card__title">${esc(act.label)}</span>
          ${meta.desc ? `<span class="fcb-action-card__desc">${esc(meta.desc)}</span>` : ''}
        </span>
        <span class="fcb-action-card__chev" aria-hidden="true">›</span>
      `;
      btn.addEventListener('click', (e) => {
        ripple(e, btn);
        onAction(act);
      });
      bindRipple(btn);
      wrap.appendChild(btn);
    });
    return wrap;
  }

  function renderInfoCard(innerHtml, lang, variant) {
    const copy = I18n && I18n.getInfoCardCopy
      ? I18n.getInfoCardCopy(lang, variant)
      : { icon: '🤔', title: "I couldn't fully understand your message.", topicsLabel: 'Please try asking about:' };
    const topics = I18n && I18n.getInfoCardTopics
      ? I18n.getInfoCardTopics(lang)
      : ['Appointments', 'Registration', 'Login', 'Medical Records', 'Video Consultation'];
    return `
      <div class="fcb-info-card" role="status">
        <div class="fcb-info-card__icon" aria-hidden="true">${copy.icon}</div>
        <h4 class="fcb-info-card__title">${esc(copy.title)}</h4>
        ${innerHtml ? `<div class="fcb-info-card__body">${innerHtml}</div>` : ''}
        <p class="fcb-info-card__topics-label">${esc(copy.topicsLabel)}</p>
        <ul class="fcb-info-card__topics">
          ${topics.map((topic) => `<li>${esc(topic)}</li>`).join('')}
        </ul>
      </div>
    `;
  }

  function emotionTone(emotion) {
    if (Emotions && Emotions.getEmotionTone) return Emotions.getEmotionTone(emotion);
    return 'neutral';
  }

  function emotionIcon(emotion) {
    if (Emotions && Emotions.getEmotionIcon) return Emotions.getEmotionIcon(emotion);
    return '';
  }

  function renderUserMessage(text, options = {}) {
    const { emotion, lang } = options;
    const row = document.createElement('div');
    const tone = emotion ? emotionTone(emotion) : '';
    row.className = 'fcb-msg fcb-msg--user fcb-animate-in'
      + (tone ? ` fcb-msg--tone-${tone}` : '');

    let chipHtml = '';
    if (emotion && I18n) {
      const label = I18n.getEmotionLabel(lang || 'en', emotion);
      if (label) {
        const tone = emotionTone(emotion);
        const icon = emotionIcon(emotion);
        const iconHtml = icon ? `<span class="fcb-emotion-chip__icon" aria-hidden="true">${icon}</span>` : '';
        chipHtml = `<span class="fcb-emotion-chip fcb-emotion-chip--${tone}" title="${esc(label)}">${iconHtml}<span class="fcb-emotion-chip__label">${esc(label)}</span></span>`;
      }
    }

    row.innerHTML = `
      <div class="fcb-msg__meta">
        ${chipHtml}
        <time datetime="${new Date().toISOString()}">${esc(formatTime(new Date()))}</time>
      </div>
      <div class="fcb-msg__bubble">${esc(text)}</div>
    `;
    return row;
  }

  function crisisLabel(lang) {
    return I18n ? I18n.t(lang, 'crisisBadge') : 'Safety Alert — Please Read';
  }

  function renderBotMessage(html, options = {}) {
    const {
      followUp, actions, onAction, emergency, crisis, moderation, restricted, lang,
      emotion, empathy, actionCards, emergencyActions,
    } = options;
    const row = document.createElement('div');
    row.className = 'fcb-msg fcb-msg--bot fcb-animate-in'
      + (emergency ? ' fcb-msg--emergency' : '')
      + (crisis ? ' fcb-msg--crisis' : '')
      + (moderation ? ' fcb-msg--moderation' : '')
      + (restricted ? ' fcb-msg--restricted' : '')
      + (empathy ? ` fcb-msg--empathy fcb-msg--empathy-${emotionTone(emotion)}` : '');

    let empathyMeta = '';
    if (empathy && emotion && I18n) {
      const label = I18n.getEmotionLabel(lang || 'en', emotion);
      const icon = emotionIcon(emotion);
      const tone = emotionTone(emotion);
      const iconHtml = icon ? `<span class="fcb-emotion-chip__icon" aria-hidden="true">${icon}</span>` : '';
      empathyMeta = `<span class="fcb-emotion-chip fcb-emotion-chip--bot fcb-emotion-chip--${tone}">${iconHtml}<span class="fcb-emotion-chip__label">${esc(label)}</span></span>`;
    }

    const body = document.createElement('div');
    body.className = 'fcb-msg__row';
    let robotTone = '';
    if (crisis || emergency) robotTone = 'crisis';
    else if (empathy && emotion) robotTone = emotionTone(emotion);
    body.innerHTML = `
      ${renderBotAvatar({ tone: robotTone })}
      <div class="fcb-msg__content">
        <div class="fcb-msg__meta">
          <span class="fcb-msg__name">${esc(botName(lang))}</span>
          ${empathyMeta}
          <time datetime="${new Date().toISOString()}">${esc(formatTime(new Date()))}</time>
        </div>
        ${emergency ? `<div class="fcb-emergency-badge" role="alert"><span aria-hidden="true">⚠</span> ${esc(emergencyLabel(lang))}</div>` : ''}
        ${crisis ? `<div class="fcb-crisis-badge" role="alert"><span aria-hidden="true">🆘</span> ${esc(crisisLabel(lang))}</div>` : ''}
        <div class="fcb-msg__bubble">${html}</div>
      </div>
    `;
    row.appendChild(body);

    if (followUp) {
      const fu = document.createElement('p');
      fu.className = 'fcb-msg__followup';
      fu.textContent = followUp;
      body.querySelector('.fcb-msg__content').appendChild(fu);
    }

    if (actions && onAction) {
      const useCards = actionCards || emergencyActions;
      const actEl = useCards
        ? renderFollowUpActionCards(actions, onAction, lang)
        : renderFollowUpActions(actions, onAction);
      if (actEl) {
        if (emergencyActions) actEl.classList.add('fcb-actions--emergency');
        row.appendChild(actEl);
      }
    }

    return row;
  }

  function renderTypingIndicator() {
    const row = document.createElement('div');
    row.className = 'fcb-msg fcb-msg--bot fcb-msg--typing';
    row.dataset.typing = 'true';
    row.setAttribute('aria-label', 'Assistant is typing');
    row.innerHTML = `
      <div class="fcb-msg__row">
        ${renderBotAvatar({ typing: true })}
        <div class="fcb-typing" aria-hidden="true"><span></span><span></span><span></span></div>
      </div>
    `;
    return row;
  }

  function scrollToBottom(el) {
    if (!el) return;
    requestAnimationFrame(() => {
      el.scrollTop = el.scrollHeight;
    });
  }

  global.McFaqUI = {
    esc,
    formatTime,
    ripple,
    bindRipple,
    renderWelcomeCard,
    renderQuickActions,
    renderFollowUpActions,
    renderFollowUpActionCards,
    renderInfoCard,
    renderUserMessage,
    renderBotMessage,
    renderTypingIndicator,
    scrollToBottom,
    prefersReduced,
    botName,
    emergencyLabel,
    crisisLabel,
  };
})(window);
