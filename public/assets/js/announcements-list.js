(function () {
  'use strict';

  function initLightbox() {
    var lightbox = document.getElementById('ann-image-lightbox');
    if (!lightbox) return;

    var img = lightbox.querySelector('.ann-lightbox__img');
    var stage = lightbox.querySelector('.ann-lightbox__stage');
    var zoomLevel = 1;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function applyZoom() {
      if (!img) return;
      img.style.transform = 'scale(' + zoomLevel + ')';
      var resetBtn = lightbox.querySelector('[data-ann-lightbox-zoom="reset"]');
      if (resetBtn) resetBtn.textContent = Math.round(zoomLevel * 100) + '%';
    }

    function openLightbox(url, alt) {
      if (!img || !url) return;
      zoomLevel = 1;
      img.src = url;
      img.alt = alt || 'Announcement image';
      applyZoom();
      lightbox.hidden = false;
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(function () {
        lightbox.classList.add('is-open');
      });
      var closeBtn = lightbox.querySelector('.ann-lightbox__close');
      if (closeBtn) closeBtn.focus();
    }

    function closeLightbox() {
      lightbox.classList.remove('is-open');
      document.body.style.overflow = '';
      setTimeout(function () {
        if (!lightbox.classList.contains('is-open')) {
          lightbox.hidden = true;
          if (img) {
            img.removeAttribute('src');
            img.alt = '';
          }
        }
      }, reducedMotion ? 0 : 320);
    }

    document.querySelectorAll('.ann-list-card__media-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var url = btn.getAttribute('data-image-url');
        var altImg = btn.querySelector('img');
        openLightbox(url, altImg ? altImg.alt : '');
      });
    });

    lightbox.querySelectorAll('[data-ann-lightbox-close]').forEach(function (el) {
      el.addEventListener('click', closeLightbox);
    });

    lightbox.querySelectorAll('[data-ann-lightbox-zoom]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.getAttribute('data-ann-lightbox-zoom');
        if (action === 'in') zoomLevel = Math.min(3, zoomLevel + 0.25);
        else if (action === 'out') zoomLevel = Math.max(1, zoomLevel - 0.25);
        else zoomLevel = 1;
        applyZoom();
      });
    });

    if (stage) {
      stage.addEventListener('wheel', function (e) {
        if (!lightbox.classList.contains('is-open')) return;
        e.preventDefault();
        zoomLevel = e.deltaY < 0
          ? Math.min(3, zoomLevel + 0.1)
          : Math.max(1, zoomLevel - 0.1);
        applyZoom();
      }, { passive: false });
    }

    document.addEventListener('keydown', function (e) {
      if (!lightbox.classList.contains('is-open')) return;
      if (e.key === 'Escape') closeLightbox();
      if (e.key === '+' || e.key === '=') { zoomLevel = Math.min(3, zoomLevel + 0.25); applyZoom(); }
      if (e.key === '-') { zoomLevel = Math.max(1, zoomLevel - 0.25); applyZoom(); }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLightbox);
  } else {
    initLightbox();
  }
})();
