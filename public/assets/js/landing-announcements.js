(function () {
  'use strict';

  var apiBase = (window.ASSET_BASE || window.APP_BASE || '') + '/app/api/public/announcements.php';
  var modal = null;
  var bellSvg = null;
  var closeTimer = null;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function fmtDate(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d.getTime()) ? s : d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
  }

  function ringBell() {
    if (!bellSvg) return;
    bellSvg.classList.remove('ann-bell-ring');
    void bellSvg.offsetWidth;
    bellSvg.classList.add('ann-bell-ring');
    bellSvg.addEventListener('animationend', function () {
      bellSvg.classList.remove('ann-bell-ring');
    }, { once: true });
  }

  function openModal() {
    if (!modal) return;
    clearTimeout(closeTimer);
    modal.classList.remove('ann-closing');
    modal.style.display = 'flex';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        modal.classList.add('ann-open');
        setTimeout(ringBell, 180);
      });
    });
    document.body.style.overflow = 'hidden';

    var navMenu = document.getElementById('nav-menu');
    var navToggle = document.getElementById('nav-toggle');
    if (navMenu) navMenu.classList.remove('open');
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('ann-open');
    modal.classList.add('ann-closing');
    document.body.style.overflow = '';
    closeTimer = setTimeout(function () {
      modal.classList.remove('ann-closing');
      modal.style.display = 'none';
    }, 280);
  }

  function renderDetail(data) {
    var icon = document.getElementById('ann-modal-icon');
    var content = document.getElementById('ann-modal-content');
    var titleEl = document.querySelector('#announcement-modal .ann-title');
    var body = document.querySelector('#announcement-modal .ann-body');
    var box = document.querySelector('#announcement-modal .ann-box');
    if (!content) return;

    if (body) body.classList.add('ann-body--detail');
    if (box) box.classList.add('ann-box--detail');
    if (icon) icon.style.display = 'none';
    if (titleEl) titleEl.textContent = data.title || 'Announcement';

    var html = '';
    if (data.banner_url) {
      html += '<img class="ann-detail__banner" src="' + esc(data.banner_url) + '" alt="">';
    }
    html += '<span class="ann-detail__badge">' + esc(data.category_label || data.category) + '</span>';
    if (data.subtitle) html += '<p class="ann-detail__subtitle">' + esc(data.subtitle) + '</p>';
    html += '<time class="ann-detail__date">' + esc(fmtDate(data.publish_at || data.created_at)) + '</time>';
    if (data.short_description) html += '<p class="ann-detail__lead"><strong>' + esc(data.short_description) + '</strong></p>';
    html += '<div class="ann-detail__body">' + esc(data.content || '').replace(/\n/g, '<br>') + '</div>';
    if (data.attachment_url) {
      html += '<a class="ann-detail__attach" href="' + esc(data.attachment_url) + '" target="_blank" rel="noopener">Download attachment</a>';
    }
    content.innerHTML = html;
  }

  function showLoading() {
    var content = document.getElementById('ann-modal-content');
    var body = document.querySelector('#announcement-modal .ann-body');
    var box = document.querySelector('#announcement-modal .ann-box');
    if (body) body.classList.remove('ann-body--detail');
    if (box) box.classList.remove('ann-box--detail');
    if (content) content.innerHTML = '<p class="ann-body-msg">Loading announcement…</p>';
  }

  function openAnnouncement(dataOrId) {
    var data = null;
    var id = null;

    if (dataOrId && typeof dataOrId === 'object') {
      data = dataOrId;
    } else {
      id = parseInt(dataOrId, 10);
    }

    showLoading();
    openModal();

    if (data && data.title) {
      renderDetail(data);
      return;
    }

    if (!id) {
      var content = document.getElementById('ann-modal-content');
      if (content) content.innerHTML = '<p class="ann-body-msg">Announcement not found.</p>';
      return;
    }

    fetch(apiBase + '?action=get&id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.success && j.data) renderDetail(j.data);
        else {
          var content = document.getElementById('ann-modal-content');
          if (content) content.innerHTML = '<p class="ann-body-msg">Announcement not found.</p>';
        }
      })
      .catch(function () {
        var content = document.getElementById('ann-modal-content');
        if (content) content.innerHTML = '<p class="ann-body-msg">Unable to load announcement.</p>';
      });
  }

  function parseCardData(card) {
    var raw = card.getAttribute('data-ann-json');
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function bindCard(card) {
    function handleOpen(e) {
      if (e.target.closest('.ann-slide__read') || e.target.closest('.ann-card__read')) {
        e.preventDefault();
        e.stopPropagation();
      }
      var data = parseCardData(card);
      openAnnouncement(data || card.getAttribute('data-ann-id'));
    }

    card.addEventListener('click', function (e) {
      if (e.target.closest('.ann-carousel__control') || e.target.closest('.ann-carousel__dot')) return;
      if (e.target.closest('.ann-slide__read') || e.target.closest('.ann-feature-card__media-btn')) return;
      handleOpen(e);
    });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        handleOpen(e);
      }
    });
  }

  function initCarousel() {
    var carousel = document.getElementById('ann-carousel');
    if (!carousel || carousel.classList.contains('ann-carousel--empty')) return;

    var slides = Array.from(carousel.querySelectorAll('.ann-carousel__slide'));
    var dots = Array.from(carousel.querySelectorAll('.ann-carousel__dot'));
    var btnPrev = document.getElementById('ann-carousel-prev');
    var btnNext = document.getElementById('ann-carousel-next');

    if (slides.length <= 1) {
      if (btnPrev) btnPrev.style.display = 'none';
      if (btnNext) btnNext.style.display = 'none';
      if (slides[0]) slides[0].classList.add('is-active');
      return;
    }

    var index = 0;
    var intervalMs = parseInt(carousel.getAttribute('data-interval') || '5000', 10);
    var timer = null;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var touchStartX = 0;
    var touchDeltaX = 0;

    function goTo(nextIndex) {
      index = ((nextIndex % slides.length) + slides.length) % slides.length;

      slides.forEach(function (slide, i) {
        var active = i === index;
        slide.classList.toggle('is-active', active);
        slide.setAttribute('tabindex', active ? '0' : '-1');
        slide.setAttribute('aria-hidden', active ? 'false' : 'true');
      });

      dots.forEach(function (dot, i) {
        var active = i === index;
        dot.classList.toggle('is-active', active);
        dot.setAttribute('aria-selected', active ? 'true' : 'false');
      });
    }

    function next() { goTo(index + 1); }
    function prev() { goTo(index - 1); }

    function stopAutoplay() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    }

    function startAutoplay() {
      if (reducedMotion || slides.length <= 1) return;
      stopAutoplay();
      timer = setInterval(next, intervalMs);
    }

    if (btnPrev) btnPrev.addEventListener('click', function (e) { e.stopPropagation(); prev(); startAutoplay(); });
    if (btnNext) btnNext.addEventListener('click', function (e) { e.stopPropagation(); next(); startAutoplay(); });

    dots.forEach(function (dot) {
      dot.addEventListener('click', function (e) {
        e.stopPropagation();
        var target = parseInt(dot.getAttribute('data-slide'), 10);
        if (!isNaN(target)) goTo(target);
        startAutoplay();
      });
    });

    carousel.addEventListener('mouseenter', stopAutoplay);
    carousel.addEventListener('mouseleave', startAutoplay);
    carousel.addEventListener('focusin', stopAutoplay);
    carousel.addEventListener('focusout', startAutoplay);

    carousel.addEventListener('touchstart', function (e) {
      touchStartX = e.changedTouches[0].clientX;
      touchDeltaX = 0;
      stopAutoplay();
    }, { passive: true });

    carousel.addEventListener('touchmove', function (e) {
      touchDeltaX = e.changedTouches[0].clientX - touchStartX;
    }, { passive: true });

    carousel.addEventListener('touchend', function () {
      if (Math.abs(touchDeltaX) > 48) {
        if (touchDeltaX < 0) next();
        else prev();
      }
      startAutoplay();
    });

    goTo(0);
    startAutoplay();
  }

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

    document.querySelectorAll('.ann-feature-card__media-btn').forEach(function (btn) {
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

  function init() {
    modal = document.getElementById('announcement-modal');
    bellSvg = document.getElementById('ann-bell-svg');

    document.querySelectorAll('.ann-card[data-ann-id]').forEach(bindCard);

    initCarousel();
    initLightbox();

    document.querySelectorAll('[data-read-ann]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var card = btn.closest('.ann-card') || btn.closest('.ann-carousel__slide');
        var data = card ? parseCardData(card) : null;
        openAnnouncement(data || btn.getAttribute('data-read-ann'));
      });
    });

    var btnClose1 = document.getElementById('close-announcement-modal');
    var btnClose2 = document.getElementById('btn-close-announcement');
    if (btnClose1) btnClose1.addEventListener('click', closeModal);
    if (btnClose2) btnClose2.addEventListener('click', closeModal);

    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var lightboxEl = document.getElementById('ann-image-lightbox');
        if (lightboxEl && lightboxEl.classList.contains('is-open')) return;
        if (modal && modal.classList.contains('ann-open')) closeModal();
      }
    });

    var btnOpen = document.getElementById('open-book-modal');
    if (btnOpen) {
      btnOpen.addEventListener('click', function () {
        var section = document.getElementById('announcements-section');
        if (section) {
          section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    }

    window.MedConnectAnnouncements = {
      open: openModal,
      close: closeModal,
      openAnnouncement: openAnnouncement,
      api: apiBase
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
