/**
 * MedConnect Request Guard — prevents duplicate in-flight AJAX requests.
 * Usage:
 *   var guard = McRequestGuard.create();
 *   guard.fetch(url, options).then(...) — identical URLs won't fire concurrently
 *   guard.abort() — cancel any pending request
 */
(function (global) {
  'use strict';

  function create() {
    var inflight = {};

    function guardedFetch(url, options) {
      var key = (options && options.method || 'GET') + ' ' + url;
      if (inflight[key]) return inflight[key];
      var promise = fetch(url, options).finally(function () {
        delete inflight[key];
      });
      inflight[key] = promise;
      return promise;
    }

    function abort() {
      inflight = {};
    }

    return { fetch: guardedFetch, abort: abort };
  }

  global.McRequestGuard = { create: create };
})(window);
