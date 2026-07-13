/**
 * MedConnect landing — reusable scroll animation utility
 * Intersection Observer, hero sequence, counters, parallax.
 */
(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const STAGGER_MS = 100;
  const HERO_STAGGER_MS = 110;

  const revealObserverDefaults = {
    threshold: 0.14,
    rootMargin: '0px 0px -6% 0px',
  };

  /** @type {IntersectionObserver | null} */
  let revealObserver = null;

  function markReady() {
    document.documentElement.classList.add('lsa-ready');
    if (!document.body.classList.contains('landing-page--no-hero-anim')) {
      document.body.classList.add('hero-anim-active');
    }
  }

  function reveal(el) {
    if (!el) return;
    el.classList.add('lsa-visible');
    if (el.classList.contains('mc-reveal')) {
      el.classList.add('mc-visible');
    }
    if (el.classList.contains('lsa-card-reveal')) {
      el.classList.add('lsa-card-visible');
    }
  }

  function setStaggerDelay(el, index, baseMs) {
    el.style.setProperty('--lsa-delay', `${index * (baseMs || STAGGER_MS)}ms`);
  }

  function tag(el, variant, extra) {
    if (!el) return;
    el.classList.add('lsa', `lsa--${variant}`);
    if (extra) extra.split(' ').forEach(c => el.classList.add(c));
  }

  function observeReveal(el) {
    if (!el || el.classList.contains('lsa-visible')) return;

    if (REDUCED || !revealObserver) {
      reveal(el);
      return;
    }

    revealObserver.observe(el);
  }

  function createRevealObserver() {
    if (!('IntersectionObserver' in window)) return null;

    return new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        reveal(entry.target);
        revealObserver.unobserve(entry.target);
      });
    }, revealObserverDefaults);
  }

  /* ── Hero on-load sequence ── */
  function initHeroSequence() {
    if (document.body.classList.contains('landing-page--no-hero-anim')) return;

    const heroLeft = document.querySelector('.hero--cinematic .hero-left');
    if (!heroLeft) return;

    const titleLines = heroLeft.querySelectorAll('.hero-title__line');
    const chips = heroLeft.querySelectorAll('.hero-trust-chip');
    const ctas = heroLeft.querySelectorAll('.hero-ctas .hero-cta');
    const visual = document.querySelector('.hero-illustration-wrap, .hrc-wrap');

    /** @type {Array<{ el: Element | null | undefined, cls?: string }>} */
    const sequence = [
      { el: heroLeft.querySelector('.hero-badge'), cls: 'lsa-hero-item--badge' },
      ...Array.from(titleLines).map((el) => ({ el, cls: 'lsa-hero-item--title-line' })),
      { el: heroLeft.querySelector('.hero-desc'), cls: 'lsa-hero-item--desc' },
      ...Array.from(chips).map((el) => ({ el, cls: 'lsa-hero-item--chip' })),
      ...Array.from(ctas).map((el) => ({ el, cls: 'lsa-hero-item--cta' })),
    ];

    sequence.forEach(({ el, cls }, i) => {
      if (!el) return;
      el.classList.add('lsa-hero-item');
      if (cls) el.classList.add(cls);
      if (REDUCED) {
        el.classList.add('lsa-hero-visible');
        return;
      }
      setTimeout(() => el.classList.add('lsa-hero-visible'), i * HERO_STAGGER_MS);
    });

    if (visual) {
      visual.classList.add('lsa-hero-item', 'lsa-hero-item--visual');
      if (REDUCED) {
        visual.classList.add('lsa-hero-visible');
      } else {
        setTimeout(
          () => visual.classList.add('lsa-hero-visible'),
          sequence.length * HERO_STAGGER_MS + 40
        );
      }
    }
  }

  /* ── Announcements section ── */
  function initAnnouncements() {
    const section = document.getElementById('announcements-section');
    if (!section) return;

    const kicker = section.querySelector('.ann-slideshow__kicker');
    const title = section.querySelector('.ann-slideshow__title');
    const panel = section.querySelector('.ann-slideshow__panel');
    const footer = section.querySelector('.ann-slideshow__footer');

    [kicker, title].forEach((el, i) => {
      if (!el) return;
      tag(el, 'fade-up', 'lsa--fast');
      setStaggerDelay(el, i, 80);
      observeReveal(el);
    });

    if (panel) {
      tag(panel, 'fade-scale');
      observeReveal(panel);
    }

    if (footer) {
      const btn = footer.querySelector('.ann-view-all-btn') || footer;
      tag(btn, 'fade-up-sm', 'lsa--fast');
      setStaggerDelay(btn, 2, 80);
      observeReveal(btn);
    }

    section.querySelectorAll('.ann-feature-card__media').forEach((wrap) => {
      if (wrap.classList.contains('lsa-img-wrap')) return;
      wrap.classList.add('lsa-img-wrap');
      wrap.setAttribute('data-lsa-parallax', '8');
      const img = wrap.querySelector('.ann-feature-card__img');
      if (img) img.classList.add('lsa-img-inner');
      tag(wrap, 'img');
      observeReveal(wrap);
    });
  }

  /* ── About Us / milestone ── */
  function initAboutSection() {
    const section = document.getElementById('about-section');
    if (!section) return;

    const kicker = section.querySelector('.about-team-section__kicker');
    const story = section.querySelector('.about-team-section__story');

    [kicker].forEach((el, i) => {
      if (!el) return;
      tag(el, 'fade-up', 'lsa--fast');
      setStaggerDelay(el, i, 80);
      observeReveal(el);
    });

    if (story) {
      tag(story, 'fade-scale');
      setStaggerDelay(story, 1, 120);
      observeReveal(story);
    }
  }

  /* ── Contact / footer ── */
  function initContactFooter() {
    document.querySelectorAll('.contact-section .contact-col').forEach((el, i) => {
      const variant = i % 2 === 0 ? 'slide-left' : 'slide-right';
      tag(el, variant);
      setStaggerDelay(el, i, STAGGER_MS);
      observeReveal(el);
    });

    document.querySelectorAll('.contact-section .contact-bottom').forEach((el) => {
      tag(el, 'fade-up-sm');
      observeReveal(el);
    });

    const logo = document.querySelector('.contact-section .contact-logo');
    if (logo) {
      tag(logo, 'img', 'lsa--fast');
      observeReveal(logo);
    }
  }

  /* ── Generic selectors (FAQ, stats, testimonials — future-ready) ── */
  function initGenericPatterns() {
    document.querySelectorAll('[data-lsa]').forEach((el) => {
      const variant = el.getAttribute('data-lsa') || 'fade-up';
      tag(el, variant);
      const delay = parseInt(el.getAttribute('data-lsa-delay') || '', 10);
      if (!Number.isNaN(delay)) {
        el.style.setProperty('--lsa-delay', `${delay}ms`);
      }
      observeReveal(el);
    });

    document.querySelectorAll('[data-lsa-stagger]').forEach((group) => {
      const variant = group.getAttribute('data-lsa-stagger') || 'fade-up';
      const children = group.querySelectorAll(':scope > [data-lsa-child], :scope > .lsa-stagger-child');
      children.forEach((child, i) => {
        tag(child, variant);
        setStaggerDelay(child, i, STAGGER_MS);
        observeReveal(child);
      });
    });

    document.querySelectorAll('.lsa-faq-item').forEach((el, i) => {
      tag(el, 'fade-up-sm', 'lsa--fast');
      setStaggerDelay(el, i, 90);
      observeReveal(el);
    });

    document.querySelectorAll('.lsa-stat').forEach((el, i) => {
      tag(el, 'fade-up');
      setStaggerDelay(el, i, STAGGER_MS);
      observeReveal(el);
    });

    document.querySelectorAll('.lsa-testimonial').forEach((el, i) => {
      tag(el, 'testimonial');
      setStaggerDelay(el, i, STAGGER_MS);
      observeReveal(el);
    });
  }

  /* ── Number counters ── */
  function animateCounter(el) {
    const target = parseFloat(el.getAttribute('data-lsa-count') || '0');
    const suffix = el.getAttribute('data-lsa-suffix') || '';
    const prefix = el.getAttribute('data-lsa-prefix') || '';
    const decimals = parseInt(el.getAttribute('data-lsa-decimals') || '0', 10);
    const duration = parseInt(el.getAttribute('data-lsa-duration') || '1400', 10);

    if (Number.isNaN(target)) return;

    if (REDUCED) {
      el.textContent = prefix + target.toFixed(decimals) + suffix;
      return;
    }

    const start = performance.now();
    const from = 0;

    function tick(now) {
      const t = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - t, 3);
      const value = from + (target - from) * eased;
      el.textContent = prefix + value.toFixed(decimals) + suffix;
      if (t < 1) requestAnimationFrame(tick);
    }

    requestAnimationFrame(tick);
  }

  function initCounters() {
    const counters = document.querySelectorAll('[data-lsa-count]');
    if (!counters.length) return;

    if (REDUCED || !('IntersectionObserver' in window)) {
      counters.forEach(animateCounter);
      return;
    }

    const counterObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        animateCounter(entry.target);
        counterObserver.unobserve(entry.target);
      });
    }, { threshold: 0.4 });

    counters.forEach((el) => counterObserver.observe(el));
  }

  /* ── Carousel card reveal — cards stay visible; track handles motion ── */
  function initCarouselCardReveal(carousel) {
    if (!carousel || carousel.dataset.lsaCardsDone === '1') return;
    carousel.dataset.lsaCardsDone = '1';
  }

  function watchCarousels() {
    const carousels = document.querySelectorAll('[data-landing-carousel]');
    if (!carousels.length) return;

    if (REDUCED || !('IntersectionObserver' in window)) {
      carousels.forEach(initCarouselCardReveal);
      return;
    }

    const carouselObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        initCarouselCardReveal(entry.target);
        carouselObserver.unobserve(entry.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

    carousels.forEach((c) => carouselObserver.observe(c));
  }

  /* ── Services / How It Works grid — reveal as a group with upward stagger ── */
  function initServicesGridReveal(sectionId) {
    const section = document.getElementById(sectionId);
    if (!section || section.dataset.lsaGridDone === '1') return;

    const grid = section.querySelector('.services-grid');
    if (!grid) return;

    const cards = grid.querySelectorAll('.service-card');
    if (!cards.length) return;

    cards.forEach((card, i) => {
      if (!card.classList.contains('lsa-card-reveal')) {
        card.classList.add('lsa-card-reveal');
      }
      card.style.setProperty('--lsa-delay', `${i * 110}ms`);
    });

    function playGridReveal() {
      if (section.dataset.lsaGridDone === '1') return;
      section.dataset.lsaGridDone = '1';

      cards.forEach((card, i) => {
        if (REDUCED) {
          card.classList.add('lsa-card-visible');
          return;
        }
        setTimeout(() => card.classList.add('lsa-card-visible'), 60 + i * 110);
      });
    }

    if (REDUCED || !('IntersectionObserver' in window)) {
      playGridReveal();
      return;
    }

    const gridObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        playGridReveal();
        gridObserver.disconnect();
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });

    gridObserver.observe(grid);
  }

  /* ── Static grid fallback (when carousel is disabled / reduced motion) ── */
  function initTimelineSteps(sectionId) {
    const section = document.getElementById(sectionId);
    if (!section || section.querySelector('[data-landing-carousel]')) return;

    initServicesGridReveal(sectionId);
  }

  /* ── Subtle image parallax ── */
  function initParallax() {
    if (REDUCED) return;

    const targets = document.querySelectorAll('[data-lsa-parallax]');
    if (!targets.length) return;

    targets.forEach((el) => el.classList.add('lsa-parallax-target'));

    let ticking = false;

    function update() {
      const vh = window.innerHeight;
      targets.forEach((el) => {
        const rect = el.getBoundingClientRect();
        if (rect.bottom < 0 || rect.top > vh) return;
        const center = rect.top + rect.height / 2;
        const offset = (center - vh / 2) / vh;
        const strength = parseFloat(el.getAttribute('data-lsa-parallax') || '12');
        el.style.transform = `translate3d(0, ${offset * strength}px, 0)`;
      });
      ticking = false;
    }

    window.addEventListener('scroll', () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(update);
    }, { passive: true });

    update();
  }

  /* ── Bridge legacy mc-reveal elements ── */
  function bridgeLegacyReveals() {
    document.querySelectorAll('.landing-page .mc-reveal:not(.lsa)').forEach((el) => {
      el.classList.add('lsa', 'lsa--fade-up');
      observeReveal(el);
    });
  }

  function init() {
    markReady();
    revealObserver = createRevealObserver();

    initHeroSequence();
    initAnnouncements();
    initAboutSection();
    initContactFooter();
    initGenericPatterns();
    initCounters();
    initParallax();
    bridgeLegacyReveals();

  }

  /** Call after landing-interactions builds carousels */
  function refreshDynamic() {
    initTimelineSteps('how-it-works');
    initTimelineSteps('services-section');
    watchCarousels();
  }

  window.MedConnectLandingAnim = {
    init,
    reveal,
    refreshDynamic,
    initCarouselCardReveal,
    watchCarousels,
    prefersReduced: REDUCED,
    STAGGER_MS,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
