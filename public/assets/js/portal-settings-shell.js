/**
 * Shared settings menu: grouped tabs and live search.
 * Root: [data-mc-settings]
 * Tabs: [data-mc-tab]  Panels: [data-mc-panel]
 */
(function () {
  'use strict';

  function activate(root, id) {
    root.querySelectorAll('[data-mc-tab]').forEach(function (tab) {
      var on = tab.getAttribute('data-mc-tab') === id;
      tab.classList.toggle('is-active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    root.querySelectorAll('[data-mc-panel]').forEach(function (panel) {
      var on = panel.getAttribute('data-mc-panel') === id;
      panel.classList.toggle('is-active', on);
    });
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', id);
      window.history.replaceState({}, '', url.toString());
    } catch (_) { /* ignore */ }
  }

  function filter(root, raw) {
    var query = String(raw || '').trim().toLowerCase();
    var first = null;
    var currentVisible = false;
    root.querySelectorAll('.mcs-group').forEach(function (group) {
      var visible = 0;
      group.querySelectorAll('[data-mc-tab]').forEach(function (tab) {
        var label = (tab.textContent || '').toLowerCase();
        var extra = (tab.getAttribute('data-search') || '').toLowerCase();
        var show = !query || label.indexOf(query) !== -1 || extra.indexOf(query) !== -1;
        tab.hidden = !show;
        if (!show) return;
        visible += 1;
        if (!first) first = tab;
        if (tab.classList.contains('is-active')) currentVisible = true;
      });
      group.hidden = visible === 0;
    });
    var empty = root.querySelector('.mcs-empty');
    if (empty) empty.hidden = !query || !!first;
    if (query && first && !currentVisible) activate(root, first.getAttribute('data-mc-tab'));
  }

  document.querySelectorAll('[data-mc-settings]').forEach(function (root) {
    if (root.getAttribute('data-mc-settings-bound') === '1') return;
    root.setAttribute('data-mc-settings-bound', '1');

    root.querySelectorAll('[data-mc-tab]').forEach(function (tab) {
      tab.addEventListener('click', function () {
        activate(root, tab.getAttribute('data-mc-tab'));
      });
    });

    var search = root.querySelector('.mcs-search input');
    if (search) {
      search.addEventListener('input', function () { filter(root, search.value); });
      search.addEventListener('search', function () { filter(root, search.value); });
    }

    var fromUrl = '';
    try { fromUrl = new URL(window.location.href).searchParams.get('tab') || ''; } catch (_) { fromUrl = ''; }
    var initial = fromUrl || root.getAttribute('data-initial-tab') || '';
    if (initial && root.querySelector('[data-mc-panel="' + initial + '"]')) {
      activate(root, initial);
    }
  });
})();
