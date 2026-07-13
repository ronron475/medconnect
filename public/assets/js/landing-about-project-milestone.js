/**
 * About the Project — continuous upward text scroll (BSIS milestone style)
 * Only the text inside the box moves; header stays fixed.
 */
(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  const box = document.getElementById('about-project-milestone');
  const track = document.getElementById('about-project-milestone-track');
  if (!box || !track) return;

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const PX_PER_SEC = 28;

  function setScrollDuration() {
    const group = track.querySelector('.about-project-milestone__scroll-group');
    if (!group) return;
    const height = group.offsetHeight;
    if (height <= 0) return;
    const seconds = Math.max(24, Math.round(height / PX_PER_SEC));
    track.style.setProperty('--scroll-duration', seconds + 's');
  }

  function startScroll() {
    if (reducedMotion) {
      box.classList.add('is-static');
      return;
    }
    setScrollDuration();
    box.classList.add('is-scrolling');
  }

  function stopScroll() {
    box.classList.remove('is-scrolling');
  }

  box.addEventListener('mouseenter', () => {
    if (!reducedMotion) track.style.animationPlayState = 'paused';
  });

  box.addEventListener('mouseleave', () => {
    if (!reducedMotion && box.classList.contains('is-scrolling')) {
      track.style.animationPlayState = 'running';
    }
  });

  if ('IntersectionObserver' in window) {
    const section = document.getElementById('about-section');
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) startScroll();
        else stopScroll();
      });
    }, { threshold: 0.15 });

    if (section) observer.observe(section);
    else startScroll();
  } else {
    startScroll();
  }

  window.addEventListener('load', setScrollDuration);
  window.addEventListener('resize', setScrollDuration, { passive: true });
  setScrollDuration();
})();
