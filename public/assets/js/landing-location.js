(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  var modal = document.getElementById('location-modal');
  var openBtn = document.getElementById('open-location-modal');
  var closeBtn = document.getElementById('close-location-modal');
  var mapFrame = document.getElementById('location-modal-map');
  var closeTimer = null;
  var mapSrc = mapFrame && mapFrame.getAttribute('data-src');

  function loadMap() {
    if (!mapFrame || !mapSrc || mapFrame.getAttribute('src')) return;
    mapFrame.setAttribute('src', mapSrc);
  }

  function openModal() {
    if (!modal) return;
    clearTimeout(closeTimer);
    modal.classList.remove('loc-closing');
    modal.removeAttribute('hidden');
    modal.style.display = 'flex';
    loadMap();

    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        modal.classList.add('loc-open');
      });
    });

    document.body.style.overflow = 'hidden';

    var navMenu = document.getElementById('nav-menu');
    var navToggle = document.getElementById('nav-toggle');
    if (navMenu) navMenu.classList.remove('open');
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');

    if (closeBtn) closeBtn.focus();
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('loc-open');
    modal.classList.add('loc-closing');
    document.body.style.overflow = '';

    closeTimer = setTimeout(function () {
      modal.classList.remove('loc-closing');
      modal.style.display = 'none';
      modal.setAttribute('hidden', '');
      if (openBtn) openBtn.focus();
    }, 300);
  }

  if (openBtn) {
    openBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openModal();
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', closeModal);
  }

  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal && modal.classList.contains('loc-open')) {
      closeModal();
    }
  });
})();
