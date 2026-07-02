(function () {
  'use strict';

  function closeBhwDrawer() {
    var sidebar = document.getElementById('bhw-sidebar');
    if (sidebar) sidebar.classList.remove('is-open');
    document.body.classList.remove('mc-nav-open');
    var backdrop = document.querySelector('.mc-nav-backdrop');
    if (backdrop) backdrop.classList.remove('is-visible');
    var toggle = document.getElementById('mcNavToggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
  }

  function isMobileNav() {
    return window.matchMedia('(max-width: 1024px)').matches;
  }

  function resetDrawerForViewport() {
    if (!isMobileNav()) closeBhwDrawer();
  }

  resetDrawerForViewport();
  window.addEventListener('resize', resetDrawerForViewport);

  var groups = document.querySelectorAll('.bhw-sb-group');

  if (groups.length) {
    groups.forEach(function (group) {
      var toggle = group.querySelector('.bhw-sb-group-btn');
      if (toggle && group.classList.contains('is-open')) {
        toggle.classList.add('is-expanded');
      }
    });

    groups.forEach(function (group) {
      var toggle = group.querySelector('.bhw-sb-group-btn');
      if (!toggle) return;

      toggle.addEventListener('click', function () {
        var willOpen = !group.classList.contains('is-open');

        groups.forEach(function (other) {
          if (other === group) return;
          other.classList.remove('is-open');
          var otherToggle = other.querySelector('.bhw-sb-group-btn');
          if (otherToggle) {
            otherToggle.setAttribute('aria-expanded', 'false');
            otherToggle.classList.remove('is-expanded');
          }
        });

        group.classList.toggle('is-open', willOpen);
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        toggle.classList.toggle('is-expanded', willOpen);
      });

      toggle.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggle.click();
        }
      });
    });
  }

  document.querySelectorAll('.bhw-sb-subitem:not(.bhw-sb-subitem--logout), .bhw-sb-item, .bhw-sb-profile').forEach(function (link) {
    if (link.dataset.bhwNavBound === '1') return;
    link.dataset.bhwNavBound = '1';

    link.addEventListener('click', function (e) {
      if (!isMobileNav()) return;
      var href = link.getAttribute('href');
      if (!href || href === '#') return;

      e.preventDefault();
      e.stopPropagation();
      closeBhwDrawer();
      window.location.assign(href);
    });
  });

  document.querySelectorAll('[data-logout-trigger]').forEach(function (btn) {
    if (btn.dataset.bhwLogoutBound) return;
    btn.dataset.bhwLogoutBound = '1';
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (typeof window.showLogoutModal === 'function') {
        window.showLogoutModal();
      }
    });
  });

  var pathMatch = window.location.pathname.match(/\/views\/bhw\/(.+?)(?:\?|#|$)/);
  if (pathMatch) {
    var currentRoute = decodeURIComponent(pathMatch[1]);
    var dashboardLink = document.querySelector('.bhw-sb-nav > a.bhw-sb-item');
    if (dashboardLink) {
      dashboardLink.classList.toggle('is-active', currentRoute === 'dashboard.php');
    }
    document.querySelectorAll('.bhw-sb-subitem[data-bhw-route]').forEach(function (link) {
      var route = link.getAttribute('data-bhw-route') || '';
      var isActive = route === currentRoute;
      link.classList.toggle('is-active', isActive);
      if (isActive) {
        var group = link.closest('.bhw-sb-group');
        if (group) {
          group.classList.add('is-open');
          var toggle = group.querySelector('.bhw-sb-group-btn');
          if (toggle) {
            toggle.classList.add('has-active-child', 'is-active', 'is-expanded');
            toggle.setAttribute('aria-expanded', 'true');
          }
        }
      }
    });
  }
})();
