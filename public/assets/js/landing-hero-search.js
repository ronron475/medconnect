/**
 * medConnect landing — hero smart live search with suggestions
 */
(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  const DEBOUNCE_MS = 250;
  const MAX_RESULTS = 8;

  const ICONS = {
    home: '🏠',
    announcement: '📢',
    service: '🩺',
    triage: '🩺',
    video: '📹',
    records: '📋',
    calendar: '📅',
    health: '💚',
    prescription: '💊',
    monitor: '📈',
    contact: '📞',
    chat: '💬',
    location: '🏥',
    topic: '🌡️',
    team: '👥',
    guide: '📖',
  };

  const form = document.getElementById('hero-search-form');
  const input = document.getElementById('hero-search-input');
  const dropdown = document.getElementById('hero-search-results');
  const indexEl = document.getElementById('hero-search-index');
  const searchWrap = document.getElementById('nav-search-wrap');
  const searchToggle = document.getElementById('nav-search-toggle');
  const searchPanel = document.getElementById('nav-search-panel');

  if (!form || !input || !dropdown || !indexEl) return;

  /** @type {Array<Record<string, unknown>>} */
  let index = [];
  try {
    index = JSON.parse(indexEl.textContent || '[]');
  } catch (e) {
    index = [];
  }

  let debounceTimer = null;
  let activeIndex = -1;
  let currentResults = [];

  function esc(str) {
    const el = document.createElement('span');
    el.textContent = str == null ? '' : String(str);
    return el.innerHTML;
  }

  function getNavOffset() {
    const nav = document.getElementById('navbar');
    const banner = document.querySelector('.landing-maintenance-banner');
    let offset = nav ? nav.offsetHeight : 72;
    if (banner) offset += banner.offsetHeight;
    return offset + 8;
  }

  function scrollToSection(id) {
    const target = document.getElementById(id);
    if (!target) return;

    const top = Math.max(0, target.getBoundingClientRect().top + window.scrollY - getNavOffset());
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top, behavior: reduced ? 'auto' : 'smooth' });
  }

  function highlightText(text, query) {
    const raw = String(text || '');
    if (!query) return esc(raw);

    const lower = raw.toLowerCase();
    const q = query.toLowerCase();
    const idx = lower.indexOf(q);
    if (idx === -1) return esc(raw);

    return (
      esc(raw.slice(0, idx))
      + '<mark class="hero-search__mark">'
      + esc(raw.slice(idx, idx + q.length))
      + '</mark>'
      + esc(raw.slice(idx + q.length))
    );
  }

  function matchesQuery(item, query) {
    const q = query.toLowerCase();
    const title = String(item.title || '').toLowerCase();
    const keywords = (Array.isArray(item.keywords) ? item.keywords : []).map((kw) => String(kw).toLowerCase());
    const description = String(item.description || '').toLowerCase();
    const category = String(item.category || '').toLowerCase();

    const titleWords = title.split(/\s+/).filter(Boolean);
    const startsWith =
      title.startsWith(q)
      || titleWords.some((word) => word.startsWith(q))
      || keywords.some((kw) => kw.startsWith(q) || kw === q);

    if (q.length <= 2) return startsWith;

    return (
      startsWith
      || title.includes(q)
      || keywords.some((kw) => kw.includes(q))
      || description.includes(q)
      || category.includes(q)
    );
  }

  function scoreItem(item, query) {
    const q = query.toLowerCase();
    if (!matchesQuery(item, query)) return -1;

    const title = String(item.title || '').toLowerCase();
    const keywords = (Array.isArray(item.keywords) ? item.keywords : []).map((kw) => String(kw).toLowerCase());

    let score = 0;
    if (title.startsWith(q)) score += 100;
    else if (title.split(/\s+/).some((word) => word.startsWith(q))) score += 85;
    else if (title.includes(q)) score += 70;

    if (keywords.some((kw) => kw.startsWith(q))) score += 60;
    else if (keywords.some((kw) => kw.includes(q))) score += 35;

    if (String(item.description || '').toLowerCase().includes(q)) score += 10;
    if (String(item.category || '').toLowerCase().includes(q)) score += 8;

    return score;
  }

  function searchIndex(query) {
    const q = query.trim();
    if (!q) return [];

    return index
      .map((item) => ({ item, score: scoreItem(item, q) }))
      .filter((row) => row.score >= 0)
      .sort((a, b) => b.score - a.score)
      .slice(0, MAX_RESULTS)
      .map((row) => row.item);
  }

  function categoryClass(category) {
    return 'hero-search__badge--' + String(category || 'page').toLowerCase().replace(/\s+/g, '-');
  }

  function renderResults(results, query) {
    dropdown.innerHTML = '';

    if (!query.trim()) {
      closeDropdown();
      return;
    }

    if (!results.length) {
      dropdown.innerHTML = '<p class="hero-search__empty">No matching results found. Try another keyword.</p>';
      openDropdown();
      activeIndex = -1;
      return;
    }

    const list = document.createElement('ul');
    list.className = 'hero-search__list';
    list.setAttribute('role', 'presentation');

    results.forEach((item, i) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'presentation');

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'hero-search__option';
      btn.setAttribute('role', 'option');
      btn.id = `hero-search-option-${i}`;
      btn.dataset.index = String(i);
      if (i === activeIndex) {
        btn.classList.add('is-active');
        btn.setAttribute('aria-selected', 'true');
      } else {
        btn.setAttribute('aria-selected', 'false');
      }

      const icon = ICONS[item.icon] || '🔎';
      btn.innerHTML = `
        <span class="hero-search__option-icon" aria-hidden="true">${icon}</span>
        <span class="hero-search__option-body">
          <span class="hero-search__option-title">${highlightText(item.title, query)}</span>
          <span class="hero-search__option-meta">
            <span class="hero-search__badge ${categoryClass(item.category)}">${esc(item.category)}</span>
            ${item.description ? `<span class="hero-search__option-desc">${highlightText(item.description, query)}</span>` : ''}
          </span>
        </span>
      `;

      btn.addEventListener('mousedown', (e) => e.preventDefault());
      btn.addEventListener('click', () => selectItem(item));

      li.appendChild(btn);
      list.appendChild(li);
    });

    dropdown.appendChild(list);
    openDropdown();
  }

  function openDropdown() {
    dropdown.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function closeDropdown() {
    dropdown.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    activeIndex = -1;
    currentResults = [];
  }

  function isSearchExpanded() {
    return searchWrap?.classList.contains('is-expanded') ?? false;
  }

  function expandSearch() {
    if (!searchWrap || !searchPanel || !searchToggle) return;
    searchWrap.classList.add('is-expanded');
    searchPanel.hidden = false;
    searchToggle.setAttribute('aria-expanded', 'true');
    searchToggle.setAttribute('aria-label', 'Close search');
    requestAnimationFrame(() => input.focus());
  }

  function collapseSearch(force) {
    if (!searchWrap || !searchPanel || !searchToggle) return;
    if (!force && input.value.trim()) return;

    searchWrap.classList.remove('is-expanded');
    searchPanel.hidden = true;
    searchToggle.setAttribute('aria-expanded', 'false');
    searchToggle.setAttribute('aria-label', 'Open search');
    closeDropdown();
    input.value = '';
  }

  if (searchToggle && searchPanel) {
    searchToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      if (isSearchExpanded()) {
        collapseSearch(true);
      } else {
        expandSearch();
      }
    });
  }

  function executeAction(action) {
    if (!action || typeof action !== 'object') return;

    switch (action.type) {
      case 'scroll':
        if (action.id) scrollToSection(action.id);
        break;
      case 'book':
        document.getElementById('open-book-cta')?.click();
        break;
      case 'modal':
        document.getElementById(action.id)?.click();
        break;
      case 'announcement':
        if (window.MedConnectAnnouncements?.openAnnouncement) {
          window.MedConnectAnnouncements.openAnnouncement(action.id);
        } else {
          scrollToSection('announcements-section');
        }
        break;
      case 'url':
        if (action.href) window.location.href = action.href;
        break;
      default:
        break;
    }
  }

  function selectItem(item) {
    if (!item) return;
    executeAction(item.action);
    closeDropdown();
    input.value = '';
    collapseSearch(true);
    input.blur();
  }

  function runSearch() {
    const query = input.value;
    currentResults = searchIndex(query);
    if (activeIndex >= currentResults.length) activeIndex = currentResults.length - 1;
    renderResults(currentResults, query);
  }

  function scheduleSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runSearch, DEBOUNCE_MS);
  }

  function setActiveIndex(next) {
    if (!currentResults.length) return;

    activeIndex = Math.max(0, Math.min(next, currentResults.length - 1));
    dropdown.querySelectorAll('.hero-search__option').forEach((el, i) => {
      const active = i === activeIndex;
      el.classList.toggle('is-active', active);
      el.setAttribute('aria-selected', active ? 'true' : 'false');
      if (active) el.scrollIntoView({ block: 'nearest' });
    });
  }

  input.addEventListener('input', () => {
    activeIndex = -1;
    scheduleSearch();
  });

  input.addEventListener('focus', () => {
    if (input.value.trim()) scheduleSearch();
  });

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (isSearchExpanded()) {
        collapseSearch(true);
        searchToggle?.focus();
      } else {
        closeDropdown();
      }
      return;
    }

    if (!currentResults.length && e.key !== 'Enter') return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (dropdown.hidden) runSearch();
      setActiveIndex(activeIndex < 0 ? 0 : activeIndex + 1);
      return;
    }

    if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveIndex(activeIndex <= 0 ? 0 : activeIndex - 1);
      return;
    }

    if (e.key === 'Enter') {
      e.preventDefault();
      if (activeIndex >= 0 && currentResults[activeIndex]) {
        selectItem(currentResults[activeIndex]);
      } else if (currentResults[0]) {
        selectItem(currentResults[0]);
      } else {
        runSearch();
      }
    }
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (activeIndex >= 0 && currentResults[activeIndex]) {
      selectItem(currentResults[activeIndex]);
    } else if (currentResults[0]) {
      selectItem(currentResults[0]);
    } else {
      runSearch();
    }
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.nav-search-wrap')) {
      closeDropdown();
      collapseSearch(false);
    }
  });
})();
