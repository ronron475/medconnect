/**
 * Backward-compatible shim — delegates to global-loader.js
 */
(function (global) {
  'use strict';
  if (global.MedConnectGlobalLoader) {
    global.MedConnectLoader = global.MedConnectGlobalLoader;
    global.MedConnectLoginLoading = {
      show: global.MedConnectGlobalLoader.showTransition.bind(global.MedConnectGlobalLoader),
      performLogout: global.MedConnectGlobalLoader.performLogout.bind(global.MedConnectGlobalLoader),
    };
  }
})(window);
