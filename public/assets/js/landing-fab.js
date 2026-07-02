/**
 * MedConnect landing — Speed Dial FAB + animated popup modals (landing page only).
 */
(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  const fab = document.getElementById('landing-fab');
  const toggle = document.getElementById('landing-fab-toggle');
  const stack = document.getElementById('landing-fab-stack');
  const menu = document.getElementById('landing-fab-menu');
  const backdrop = document.getElementById('landing-fab-backdrop');
  const modalRoot = document.getElementById('fab-modal-root');
  const actionBtns = menu ? Array.from(menu.querySelectorAll('[data-fab-modal]')) : [];
  const modals = modalRoot ? Array.from(modalRoot.querySelectorAll('.fab-modal')) : [];

  if (!fab || !toggle || !stack) return;

  const CLOSE_MS = 320;
  let isOpen = false;
  let activeModal = null;
  let closeTimer = null;

  function setFabOpen(open) {
    isOpen = open;
    fab.dataset.open = open ? 'true' : 'false';
    stack.classList.toggle('landing-fab__stack--hidden', !open);
    toggle.classList.toggle('is-active', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close quick menu' : 'Open quick menu');
    stack.setAttribute('aria-hidden', open ? 'false' : 'true');

    if (backdrop) {
      backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    }
  }

  function closeFab(focusToggle) {
    setFabOpen(false);
    if (focusToggle !== false) {
      toggle.focus({ preventScroll: true });
    }
  }

  function openFab() {
    setFabOpen(true);
  }

  function toggleFab() {
    if (isOpen) closeFab();
    else openFab();
  }

  function getModal(id) {
    return document.getElementById(`fab-modal-${id}`);
  }

  function closeModal(modal, focusEl) {
    if (!modal || !modal.classList.contains('is-open')) return Promise.resolve();

    modal.classList.add('is-closing');
    modal.classList.remove('is-open');

    return new Promise((resolve) => {
      window.clearTimeout(closeTimer);
      closeTimer = window.setTimeout(() => {
        modal.classList.remove('is-closing');
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        if (activeModal === modal) activeModal = null;

        const anyOpen = modals.some((m) => m.classList.contains('is-open'));
        if (!anyOpen) {
          document.body.classList.remove('fab-modal-open');
          if (modalRoot) modalRoot.setAttribute('aria-hidden', 'true');
        }

        if (focusEl) focusEl.focus({ preventScroll: true });
        resolve();
      }, CLOSE_MS);
    });
  }

  function openModal(id, triggerEl) {
    const modal = getModal(id);
    if (!modal) return;

    closeFab(false);

    if (activeModal && activeModal !== modal) {
      closeModal(activeModal);
    }

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    if (modalRoot) modalRoot.setAttribute('aria-hidden', 'false');
    document.body.classList.add('fab-modal-open');

    requestAnimationFrame(() => {
      modal.classList.add('is-open');
      activeModal = modal;
      const closeBtn = modal.querySelector('.fab-modal__close');
      if (closeBtn) closeBtn.focus({ preventScroll: true });
      else if (triggerEl) triggerEl.focus({ preventScroll: true });
    });
  }

  function closeSignInIfOpen() {
    const signin = document.getElementById('signin-modal');
    if (!signin || signin.hasAttribute('hidden')) return Promise.resolve();

    if (typeof window.closeSignInModal === 'function') {
      window.closeSignInModal();
    } else {
      const closeBtn = document.getElementById('close-signin-modal');
      if (closeBtn) closeBtn.click();
    }

    return new Promise((resolve) => {
      window.setTimeout(resolve, 320);
    });
  }

  function scrollToSection(sectionId) {
    const target = document.getElementById(sectionId);
    if (!target) return;

    const navLink = document.querySelector(`a[href="#${sectionId}"]`);
    if (navLink) {
      navLink.click();
      return;
    }

    const navbarHeight = document.getElementById('navbar')?.offsetHeight || 70;
    const top = target.getBoundingClientRect().top + window.scrollY - navbarHeight - 16;
    window.scrollTo({ top, behavior: 'smooth' });
  }

  function onSignInStateChange() {
    closeFab(false);
    if (activeModal) closeModal(activeModal);
  }

  toggle.addEventListener('click', toggleFab);

  if (backdrop) {
    backdrop.addEventListener('click', () => closeFab());
  }

  actionBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      const modalId = btn.getAttribute('data-fab-modal');
      if (modalId) openModal(modalId, btn);
    });
  });

  modals.forEach((modal) => {
    modal.querySelectorAll('[data-fab-modal-close]').forEach((el) => {
      el.addEventListener('click', () => closeModal(modal, toggle));
    });

    modal.querySelectorAll('[data-fab-scroll]').forEach((link) => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const sectionId = link.getAttribute('data-fab-scroll');
        closeModal(modal).then(() => closeSignInIfOpen()).then(() => {
          if (sectionId) scrollToSection(sectionId);
        });
      });
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;

    if (activeModal) {
      e.preventDefault();
      closeModal(activeModal, toggle);
      return;
    }

    if (isOpen) {
      e.preventDefault();
      closeFab();
    }
  });

  document.addEventListener('click', (e) => {
    if (isOpen && !fab.contains(e.target) && !activeModal) {
      closeFab();
    }
  });

  document.addEventListener('medconnect:signin', onSignInStateChange);
})();
