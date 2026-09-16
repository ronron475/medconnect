/**
 * Patient dashboard greeting — live local-time period + icon.
 * 12:00 AM–11:59 AM → Good morning
 * 12:00 PM–5:59 PM  → Good afternoon
 * 6:00 PM–11:59 PM  → Good evening
 */
(function () {
  'use strict';

  var ICONS = {
    morning:
      '<svg class="pdash-hero__icon-svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="4"/>' +
      '<path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>' +
      '</svg>',
    afternoon:
      '<svg class="pdash-hero__icon-svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="4"/>' +
      '<path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>' +
      '</svg>',
    evening:
      '<svg class="pdash-hero__icon-svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>' +
      '</svg>',
  };

  function periodFromLocalDate(d) {
    var h = d.getHours();
    if (h < 12) return 'morning';
    if (h < 18) return 'afternoon';
    return 'evening';
  }

  function labelForPeriod(period) {
    if (period === 'afternoon') return 'Good afternoon';
    if (period === 'evening') return 'Good evening';
    return 'Good morning';
  }

  function msUntilNextPeriodChange(now) {
    var next = new Date(now.getTime());
    var h = now.getHours();
    if (h < 12) {
      next.setHours(12, 0, 0, 0);
    } else if (h < 18) {
      next.setHours(18, 0, 0, 0);
    } else {
      next.setDate(next.getDate() + 1);
      next.setHours(0, 0, 0, 0);
    }
    return Math.max(1000, next.getTime() - now.getTime() + 50);
  }

  function applyGreeting(root) {
    if (!root) return;
    var now = new Date();
    var period = periodFromLocalDate(now);
    var label = labelForPeriod(period);
    var periodEl = root.querySelector('[data-pdash-greeting-period]');
    var iconEl = root.querySelector('[data-pdash-greeting-icon]');
    if (periodEl) periodEl.textContent = label;
    if (iconEl) {
      iconEl.setAttribute('data-period', period);
      iconEl.innerHTML = ICONS[period] || ICONS.morning;
    }
    root.setAttribute('data-greeting-period', period);
  }

  function schedule(root) {
    applyGreeting(root);
    var delay = msUntilNextPeriodChange(new Date());
    window.setTimeout(function () {
      schedule(root);
    }, delay);
  }

  function init() {
    var roots = document.querySelectorAll('[data-pdash-greeting]');
    if (!roots.length) return;
    roots.forEach(function (root) {
      schedule(root);
    });
    // Safety poll in case the tab was suspended across a boundary.
    window.setInterval(function () {
      roots.forEach(applyGreeting);
    }, 60 * 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
