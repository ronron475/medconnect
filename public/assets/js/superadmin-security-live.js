(function () {
  'use strict';

  var REFRESH_MS = 30000;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function pollSecurityTable(cfg) {
    var tbody = document.querySelector(cfg.tbodySelector);
    if (!tbody || !cfg.api) return;

    function render(rows) {
      if (!rows || !rows.length) {
        tbody.innerHTML = '<tr><td colspan="' + cfg.colspan + '"><div class="mc-table-empty"><p>No records found.</p></div></td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(function (row) {
        return '<tr>' + cfg.columns.map(function (col) {
          var val = typeof col.key === 'function' ? col.key(row) : (row[col.key] ?? '—');
          return '<td class="text-sm">' + esc(val) + '</td>';
        }).join('') + (cfg.actionCell ? cfg.actionCell(row) : '') + '</tr>';
      }).join('');
    }

    function load() {
      fetch(cfg.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.success && j.rows) render(j.rows);
          var stamp = document.getElementById(cfg.updatedId);
          if (stamp) stamp.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        })
        .catch(function () {});
    }

    load();
    setInterval(load, REFRESH_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
  }

  window.saPollSecurityTable = pollSecurityTable;
})();
