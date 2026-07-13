/**
 * Backward-compatible shim — implementation in global-loader.js
 */
(function (global) {
  'use strict';
  if (global.MedConnectGlobalLoader && !global.MedConnectLoginLoading) {
    global.MedConnectLoginLoading = {
      show: global.MedConnectGlobalLoader.showTransition.bind(global.MedConnectGlobalLoader),
      performLogout: global.MedConnectGlobalLoader.performLogout.bind(global.MedConnectGlobalLoader),
    };
  }
})(window);
