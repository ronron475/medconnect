/**
 * Attach CSRF to Admin/Superadmin same-origin mutating fetch() calls.
 * Server still validates via auth_csrf_require() (POST csrf_token or X-CSRF-TOKEN).
 */
(function () {
  var csrf = document.body && document.body.getAttribute('data-csrf');
  if (!csrf || typeof window.fetch !== 'function') {
    return;
  }

  var originalFetch = window.fetch.bind(window);

  window.fetch = function (input, init) {
    init = init ? Object.assign({}, init) : {};
    var method = String(init.method || 'GET').toUpperCase();
    if (method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE') {
      var headers = new Headers(init.headers || {});
      if (!headers.has('X-CSRF-TOKEN') && !headers.has('X-CSRF-Token')) {
        headers.set('X-CSRF-TOKEN', csrf);
      }
      init.headers = headers;

      if (typeof FormData !== 'undefined' && init.body instanceof FormData) {
        if (!init.body.has('csrf_token')) {
          init.body.append('csrf_token', csrf);
        }
      } else if (typeof URLSearchParams !== 'undefined' && init.body instanceof URLSearchParams) {
        if (!init.body.has('csrf_token')) {
          init.body.append('csrf_token', csrf);
        }
      } else if (typeof init.body === 'string') {
        var ct = (headers.get('Content-Type') || '').toLowerCase();
        if (ct.indexOf('application/json') !== -1) {
          try {
            var obj = JSON.parse(init.body);
            if (obj && typeof obj === 'object' && !Array.isArray(obj) && obj.csrf_token == null) {
              obj.csrf_token = csrf;
              init.body = JSON.stringify(obj);
            }
          } catch (e) {
            /* leave body unchanged */
          }
        }
      }
    }
    return originalFetch(input, init);
  };
})();
