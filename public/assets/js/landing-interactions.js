/**
 * MedConnect landing — smooth scroll, nav indicator, carousels, section reveals
 */
(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const navbar = document.getElementById('navbar');
  const navMenu = document.getElementById('nav-menu');
  const navToggle = document.getElementById('nav-toggle');
  const navBackdrop = document.getElementById('landing-nav-backdrop');
  const homeLink = document.getElementById('nav-home');

  const MOBILE_NAV_BREAK = 992;
  const USE_INFINITE_CAROUSEL = !prefersReduced;

  document.documentElement.classList.add('landing-scroll');

  let scrollAnimId = null;

  const CAROUSEL_SECTION_IDS = ['services-section', 'how-it-works'];
  const HEADER_REVEAL_SECTION_IDS = ['services-section', 'how-it-works', 'about-section'];
  const revealedSectionHeaders = new Set();

  /* ── Nav helpers ── */
  const navLinks = Array.from(document.querySelectorAll('.nav-links a'));

  function isLandingHomePath() {
    const path = window.location.pathname.replace(/\\/g, '/');
    return path === '/' || /(?:^|\/)index\.php$/i.test(path);
  }

  function resolveNavTarget(link) {
    if (!link) return null;
    const href = link.getAttribute('href') || '';
    if (href.startsWith('#')) {
      const id = href.slice(1);
      return id ? document.getElementById(id) : null;
    }
    const dataNav = link.getAttribute('data-nav');
    if (dataNav) return document.getElementById(dataNav);
    return null;
  }

  function findNavLinkForSection(sectionId) {
    return document.querySelector(`.nav-links a[href="#${sectionId}"]`)
      || document.querySelector(`.nav-links a[data-nav="${sectionId}"]`);
  }

  const sectionNavMap = [
    { id: 'hero-section', link: homeLink },
    { id: 'announcements-section', link: findNavLinkForSection('announcements-section') },
    { id: 'services-section', link: findNavLinkForSection('services-section') },
    { id: 'how-it-works', link: findNavLinkForSection('how-it-works') },
    { id: 'about-section', link: findNavLinkForSection('about-section') },
    { id: 'contact-section', link: findNavLinkForSection('contact-section') },
  ].filter(item => item.link);

  function setActiveNav(link) {
    if (!link) return;
    const href = link.getAttribute('href') || '';
    const dataNav = link.getAttribute('data-nav') || '';
    navLinks.forEach((a) => {
      const match = a === link
        || (href && a.getAttribute('href') === href)
        || (dataNav && a.getAttribute('data-nav') === dataNav);
      a.classList.toggle('is-active', match);
    });
  }

  function resetNavToggleIcon() {
    if (!navToggle) return;
    navToggle.querySelectorAll('span').forEach(span => {
      span.style.transform = '';
      span.style.opacity = '';
    });
  }

  function setMobileNavOpen(open) {
    if (!navMenu || !navToggle) return;

    navMenu.classList.toggle('open', open);
    navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('landing-nav-open', open);

    if (navBackdrop) {
      navBackdrop.hidden = !open;
      navBackdrop.classList.toggle('is-visible', open);
      navBackdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    }

    const spans = navToggle.querySelectorAll('span');
    if (open) {
      spans[0].style.transform = 'translateY(7px) rotate(45deg)';
      spans[1].style.opacity = '0';
      spans[2].style.transform = 'translateY(-7px) rotate(-45deg)';
    } else {
      resetNavToggleIcon();
    }
  }

  function closeMobileNav() {
    setMobileNavOpen(false);
  }

  function toggleMobileNav() {
    if (!navMenu || !navToggle) return;
    setMobileNavOpen(!navMenu.classList.contains('open'));
  }

  if (navToggle) {
    navToggle.addEventListener('click', toggleMobileNav);
  }

  if (navBackdrop) {
    navBackdrop.addEventListener('click', closeMobileNav);
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && navMenu?.classList.contains('open')) {
      closeMobileNav();
      navToggle?.focus();
    }
  });

  ['open-signin-modal', 'open-signin-modal-drawer'].forEach(id => {
    const btn = document.getElementById(id);
    if (btn) btn.addEventListener('click', closeMobileNav);
  });

  function getNavOffset() {
    return (navbar?.offsetHeight || 80) + 16;
  }

  function updateNavbarTheme() {
    if (!navbar) return;

    const scrollY = window.scrollY;
    const hero = document.getElementById('hero-section');
    const heroBottom = hero
      ? hero.getBoundingClientRect().top + window.scrollY + hero.offsetHeight
      : 0;
    const overHero = heroBottom > 0 && scrollY < heroBottom - (navbar.offsetHeight || 76);

    navbar.classList.toggle('nav-blended', overHero && scrollY < 120);
    navbar.classList.toggle('nav-scrolled', scrollY > 24);
    navbar.classList.toggle('scrolled', scrollY > 24);

    const lightSections = ['services-section', 'how-it-works', 'about-section', 'contact-section'];
    const probeY = getNavOffset();
    let onLight = false;

    for (const id of lightSections) {
      const section = document.getElementById(id);
      if (!section) continue;
      const rect = section.getBoundingClientRect();
      if (rect.top <= probeY && rect.bottom > probeY) {
        onLight = true;
        break;
      }
    }

    navbar.classList.toggle('nav-on-light', onLight && !overHero);
  }

  function isHeaderRevealSection(section) {
    return section && HEADER_REVEAL_SECTION_IDS.includes(section.id);
  }

  function isCarouselSection(section) {
    return section && CAROUSEL_SECTION_IDS.includes(section.id);
  }

  /* ── Eased smooth scroll ── */
  function easeInOutCubic(t) {
    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
  }

  function scrollToTarget(target) {
    if (!target) return;

    const top = Math.max(0, target.getBoundingClientRect().top + window.scrollY - getNavOffset());

    if (prefersReduced) {
      window.scrollTo(0, top);
      return;
    }

    if (scrollAnimId) cancelAnimationFrame(scrollAnimId);

    const startY = window.scrollY;
    const distance = top - startY;
    const duration = Math.min(950, Math.max(480, Math.abs(distance) * 0.55));
    const startTime = performance.now();

    function step(now) {
      const elapsed = now - startTime;
      const progress = Math.min(elapsed / duration, 1);

      window.scrollTo(0, startY + distance * easeInOutCubic(progress));

      if (progress < 1) {
        scrollAnimId = requestAnimationFrame(step);
      } else {
        scrollAnimId = null;
      }
    }

    scrollAnimId = requestAnimationFrame(step);
  }

  /* ── Infinite card carousels (Services + How It Works) ── */
  function buildCarouselFromGrid(grid) {
    const cards = Array.from(grid.querySelectorAll('.service-card'));
    if (!cards.length) return null;

    const carousel = document.createElement('div');
    carousel.className = 'landing-carousel';
    carousel.setAttribute('data-landing-carousel', '');

    const viewport = document.createElement('div');
    viewport.className = 'landing-carousel__viewport';

    const track = document.createElement('div');
    track.className = 'landing-carousel__track';

    const groupA = document.createElement('div');
    groupA.className = 'landing-carousel__group';

    const groupB = document.createElement('div');
    groupB.className = 'landing-carousel__group';
    groupB.setAttribute('aria-hidden', 'true');

    cards.forEach(card => groupA.appendChild(card));

    cards.forEach(card => {
      const clone = card.cloneNode(true);
      clone.setAttribute('aria-hidden', 'true');
      clone.setAttribute('tabindex', '-1');
      groupB.appendChild(clone);
    });

    track.appendChild(groupA);
    track.appendChild(groupB);
    viewport.appendChild(track);
    carousel.appendChild(viewport);

    grid.replaceWith(carousel);

    const section = carousel.closest('section');
    const sectionTitle = section?.querySelector('.services-title')?.textContent?.trim();
    carousel.setAttribute('role', 'region');
    carousel.setAttribute('aria-label', sectionTitle ? `${sectionTitle} carousel` : 'Service cards carousel');

    return carousel;
  }

  function bindCarouselInteractions(carousel) {
    let touchTimer = null;
    let activeCard = null;

    function pauseCarousel() {
      carousel.classList.add('is-paused');
    }

    function resumeCarousel() {
      carousel.classList.remove('is-paused');
    }

    function setTouchCard(card) {
      if (activeCard) activeCard.classList.remove('is-touch-active');
      activeCard = card;
      if (card) card.classList.add('is-touch-active');
    }

    carousel.addEventListener('mouseenter', pauseCarousel);
    carousel.addEventListener('mouseleave', resumeCarousel);

    carousel.addEventListener('touchstart', (e) => {
      pauseCarousel();
      const card = e.target.closest('.service-card');
      setTouchCard(card);
    }, { passive: true });

    carousel.addEventListener('touchmove', pauseCarousel, { passive: true });

    carousel.addEventListener('touchend', () => {
      clearTimeout(touchTimer);
      touchTimer = setTimeout(() => {
        resumeCarousel();
        setTouchCard(null);
      }, 700);
    }, { passive: true });

    carousel.addEventListener('touchcancel', () => {
      clearTimeout(touchTimer);
      resumeCarousel();
      setTouchCard(null);
    }, { passive: true });

    carousel.querySelectorAll('.landing-carousel__group:first-child .service-card').forEach(card => {
      card.addEventListener('focusin', pauseCarousel);
      card.addEventListener('focusout', () => {
        if (!carousel.contains(document.activeElement)) resumeCarousel();
      });
    });
  }

  function initCarousels() {
    if (!USE_INFINITE_CAROUSEL) return [];

    const carousels = [];

    document.querySelectorAll('#services-section .services-grid, #how-it-works .services-grid').forEach(grid => {
      const carousel = buildCarouselFromGrid(grid);
      if (carousel) {
        bindCarouselInteractions(carousel);
        carousels.push(carousel);
      }
    });

    if (!carousels.length || !('IntersectionObserver' in window)) {
      carousels.forEach(c => c.classList.add('is-in-view'));
      return carousels;
    }

    const carouselObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        entry.target.classList.toggle('is-in-view', entry.isIntersecting);
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -5% 0px' });

    carousels.forEach(carousel => carouselObserver.observe(carousel));
    return carousels;
  }

  /* ── Section header reveal (title + description) ── */
  function initCarouselSectionHeaders() {
    HEADER_REVEAL_SECTION_IDS.forEach(id => {
      const section = document.getElementById(id);
      if (!section) return;

      section.querySelectorAll('.services-title').forEach(el => {
        el.classList.add('landing-reveal-title');
      });

      section.querySelectorAll('.services-desc').forEach(el => {
        el.classList.add('landing-reveal-desc');
      });
    });
  }

  function playSectionHeaderReveal(section) {
    if (!section || revealedSectionHeaders.has(section.id)) return;
    revealedSectionHeaders.add(section.id);

    const title = section.querySelector('.services-title');
    const desc = section.querySelector('.services-desc');

    if (prefersReduced) {
      title?.classList.add('is-visible');
      desc?.classList.add('is-visible');
      if (section.id === 'about-section') revealGenericSection(section);
      return;
    }

    title?.classList.add('is-visible');
    setTimeout(() => desc?.classList.add('is-visible'), 140);
    if (section.id === 'about-section') {
      setTimeout(() => revealGenericSection(section), 180);
    }
  }

  navLinks.forEach(link => {
    link.addEventListener('click', e => {
      const href = link.getAttribute('href') || '';

      if (link.id === 'nav-home' || href === '#hero-section') {
        if (isLandingHomePath()) {
          e.preventDefault();
          scrollToTarget(document.getElementById('hero-section') || document.body);
          setActiveNav(homeLink);
          closeMobileNav();
        }
        return;
      }

      const target = resolveNavTarget(link);
      if (!target) return;

      e.preventDefault();
      scrollToTarget(target);
      setActiveNav(link);
      closeMobileNav();

      if (isHeaderRevealSection(target)) {
        setTimeout(() => playSectionHeaderReveal(target), prefersReduced ? 0 : 380);
      } else {
        revealGenericSection(target);
      }
    });
  });

  document.querySelectorAll('a[href^="#"]').forEach(link => {
    if (link.closest('.nav-links')) return;
    link.addEventListener('click', e => {
      const id = link.getAttribute('href').slice(1);
      if (!id) return;
      const target = document.getElementById(id);
      if (!target) return;
      e.preventDefault();
      scrollToTarget(target);
      const navMatch = sectionNavMap.find(s => s.id === id);
      if (navMatch?.link) setActiveNav(navMatch.link);

      if (isHeaderRevealSection(target)) {
        setTimeout(() => playSectionHeaderReveal(target), prefersReduced ? 0 : 380);
      } else {
        revealGenericSection(target);
      }
    });
  });

  /* ── Scroll spy — viewport-based (offsetTop breaks on nested layouts) ── */
  function getSectionProbeY() {
    return getNavOffset() + 56;
  }

  function updateActiveOnScroll() {
    const probeY = getSectionProbeY();
    const scrollBottom = window.scrollY + window.innerHeight;
    const docHeight = document.documentElement.scrollHeight;
    let active = homeLink;

    /* Near page bottom — snap to last section (Contact) */
    if (docHeight - scrollBottom < 100) {
      const lastItem = sectionNavMap[sectionNavMap.length - 1];
      if (lastItem?.link && document.getElementById(lastItem.id)) {
        setActiveNav(lastItem.link);
        return;
      }
    }

    for (let i = sectionNavMap.length - 1; i >= 0; i--) {
      const section = document.getElementById(sectionNavMap[i].id);
      if (!section) continue;

      const rect = section.getBoundingClientRect();
      if (rect.top <= probeY) {
        active = sectionNavMap[i].link;
        break;
      }
    }

    setActiveNav(active);
  }

  let scrollTicking = false;
  window.addEventListener('scroll', () => {
    if (scrollTicking) return;
    scrollTicking = true;
    requestAnimationFrame(() => {
      updateActiveOnScroll();
      updateNavbarTheme();
      scrollTicking = false;
    });
  }, { passive: true });

  window.addEventListener('resize', () => {
    updateNavbarTheme();
    if (window.innerWidth > MOBILE_NAV_BREAK && navMenu?.classList.contains('open')) {
      closeMobileNav();
    }
  }, { passive: true });

  function revealGenericSection(section) {
    if (!section || !window.MedConnectLandingAnim) return;
    section.querySelectorAll('.lsa:not(.lsa-visible), .mc-reveal:not(.mc-visible)').forEach((el, i) => {
      setTimeout(() => {
        window.MedConnectLandingAnim.reveal(el);
      }, prefersReduced ? 0 : i * 70);
    });
  }

  initCarousels();
  initCarouselSectionHeaders();

  if (window.MedConnectLandingAnim) {
    window.MedConnectLandingAnim.refreshDynamic();
  }

  if ('IntersectionObserver' in window) {
    const headerObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        playSectionHeaderReveal(entry.target);
        headerObserver.unobserve(entry.target);
      });
    }, { threshold: 0.16, rootMargin: '0px 0px -10% 0px' });

    CAROUSEL_SECTION_IDS.forEach(id => {
      const section = document.getElementById(id);
      if (section) headerObserver.observe(section);
    });

    const aboutSection = document.getElementById('about-section');
    if (aboutSection) headerObserver.observe(aboutSection);

  } else {
    HEADER_REVEAL_SECTION_IDS.forEach(id => {
      const section = document.getElementById(id);
      if (section) playSectionHeaderReveal(section);
    });
    if (window.MedConnectLandingAnim) {
      document.querySelectorAll('.lsa, .mc-reveal').forEach(el => window.MedConnectLandingAnim.reveal(el));
    }
  }

  requestAnimationFrame(() => {
    const initial = navLinks.find(a => a.classList.contains('is-active')) || homeLink;
    setActiveNav(initial);
    updateActiveOnScroll();
    updateNavbarTheme();
  });
})();
