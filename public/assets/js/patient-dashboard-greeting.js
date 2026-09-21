/**
 * Patient dashboard greeting — live local-time period label.
 * 12:00 AM–11:59 AM → Good morning
 * 12:00 PM–5:59 PM  → Good afternoon
 * 6:00 PM–11:59 PM  → Good evening
 */
(function () {
  'use strict';

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
    if (periodEl) periodEl.textContent = label;
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
