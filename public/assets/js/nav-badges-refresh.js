/**
 * Trigger immediate sidebar badge refresh after read/resolve/submit actions.
 */
(function (global) {
  'use strict';

  function refresh() {
    global.dispatchEvent(new CustomEvent('medconnect:nav-badges-refresh'));
    if (global.MedConnectPortalNavBadges && typeof global.MedConnectPortalNavBadges.refresh === 'function') {
      global.MedConnectPortalNavBadges.refresh();
    }
    if (global.MedConnectProviderNavCounts && typeof global.MedConnectProviderNavCounts.refresh === 'function') {
      global.MedConnectProviderNavCounts.refresh();
    }
  }

  global.MedConnectNavBadgesRefresh = refresh;
})(window);
