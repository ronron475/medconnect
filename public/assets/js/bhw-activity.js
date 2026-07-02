(function () {
  var page = 1;
  var perPage = 20;
  var base = document.body.dataset.assetBase || '';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function filters() {
    return {
      page: page,
      per_page: perPage,
      q: document.getElementById('act_search')?.value.trim() || '',
      module: document.getElementById('act_module')?.value || '',
      period: document.getElementById('act_period')?.value || '',
      date_from: document.getElementById('act_date_from')?.value || '',
      date_to: document.getElementById('act_date_to')?.value || '',
    };
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function statusClass(st) {
    return (st || 'success') === 'failed' ? 'is-failed' : 'is-success';
  }

  function renderRows(rows) {
    var body = document.getElementById('actBody');
    var cards = document.getElementById('actCards');
    if (!body) return;

    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No activity recorded yet.</td></tr>';
      if (cards) cards.innerHTML = '<p class="text-muted text-center py-3">No activity recorded yet.</p>';
      return;
    }

    body.innerHTML = rows.map(function (r) {
      return '<tr data-id="' + r.id + '">' +
        '<td>' + esc(r.date) + '</td>' +
        '<td>' + esc(r.time) + '</td>' +
        '<td>' + esc(r.action) + '</td>' +
        '<td>' + esc(r.patient_name) + '</td>' +
        '<td>' + esc(r.module) + '</td>' +
        '<td>' + esc(r.ip_address) + '</td>' +
        '<td>' + esc(r.device) + '</td>' +
        '<td><span class="bhw-activity-status ' + statusClass(r.status) + '">' + esc(r.status) + '</span></td>' +
        '</tr>';
    }).join('');

    if (cards) {
      cards.innerHTML = rows.map(function (r) {
        return '<div class="bhw-activity-card" data-id="' + r.id + '">' +
          '<strong>' + esc(r.action) + '</strong>' +
          '<span>' + esc(r.date) + ' · ' + esc(r.time) + '</span>' +
          '<span>' + esc(r.module) + (r.patient_name !== '—' ? ' · ' + esc(r.patient_name) : '') + '</span>' +
          '<span class="bhw-activity-status ' + statusClass(r.status) + '">' + esc(r.status) + '</span>' +
          '</div>';
      }).join('');
    }

    document.querySelectorAll('#actBody tr[data-id], .bhw-activity-card').forEach(function (el) {
      el.addEventListener('click', function () {
        openDetail(parseInt(el.dataset.id, 10));
      });
    });
  }

  function renderPagination(total, currentPage) {
    var nav = document.getElementById('actPagination');
    if (!nav) return;
    var pages = Math.max(1, Math.ceil(total / perPage));
    if (pages <= 1) {
      nav.innerHTML = '';
      return;
    }
    var html = '';
    html += '<button type="button" data-page="' + (currentPage - 1) + '" ' + (currentPage <= 1 ? 'disabled' : '') + '>Prev</button>';
    for (var i = 1; i <= pages && i <= 7; i++) {
      html += '<button type="button" data-page="' + i + '" class="' + (i === currentPage ? 'is-active' : '') + '">' + i + '</button>';
    }
    html += '<button type="button" data-page="' + (currentPage + 1) + '" ' + (currentPage >= pages ? 'disabled' : '') + '>Next</button>';
    nav.innerHTML = html;
    nav.querySelectorAll('button[data-page]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var p = parseInt(btn.dataset.page, 10);
        if (p >= 1 && p <= pages) {
          page = p;
          load();
        }
      });
    });
  }

  function openDetail(id) {
    BhwPortal.get('activity.php', { action: 'get', id: id }).then(function (r) {
      if (!r.success || !r.activity) return;
      var a = r.activity;
      var modal = document.getElementById('actModal');
      var body = document.getElementById('actModalBody');
      if (!modal || !body) return;
      var pairs = [
        ['Action', a.action],
        ['Description', a.description],
        ['Module', a.module],
        ['Patient', a.patient_name || '—'],
        ['Timestamp', a.timestamp],
        ['Browser', a.browser],
        ['Operating System', a.os],
        ['Device', a.device],
        ['IP Address', a.ip_address],
        ['Result', a.status],
      ];
      body.innerHTML = pairs.map(function (p) {
        return '<div><dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd></div>';
      }).join('');
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
    });
  }

  function closeModal() {
    var modal = document.getElementById('actModal');
    if (modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }
  }

  function load() {
    var params = Object.assign({ action: 'list' }, filters());
    return BhwPortal.get('activity.php', params).then(function (r) {
      if (!r.success) return;
      renderRows(r.rows || []);
      renderPagination(r.total || 0, r.page || 1);
    });
  }

  function loadModules() {
    BhwPortal.get('activity.php', { action: 'modules' }).then(function (r) {
      if (!r.success) return;
      var sel = document.getElementById('act_module');
      if (!sel) return;
      (r.modules || []).forEach(function (m) {
        var o = document.createElement('option');
        o.value = m;
        o.textContent = m;
        sel.appendChild(o);
      });
    });
  }

  function exportUrl(fmt) {
    var q = new URLSearchParams(Object.assign({ format: fmt === 'excel' ? 'excel' : 'csv' }, filters()));
    q.delete('page');
    q.delete('per_page');
    return base + '/app/api/bhw/export_activity.php?' + q.toString();
  }

  function triggerDownload(url) {
    var link = document.createElement('a');
    link.href = url;
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  ready(function () {
    if (!document.getElementById('bhwActivityRoot')) return;
    loadModules();
    load();

    document.getElementById('act_apply')?.addEventListener('click', function () {
      page = 1;
      load();
    });

    document.getElementById('act_reset')?.addEventListener('click', function () {
      ['act_search', 'act_period', 'act_module', 'act_date_from', 'act_date_to'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
      });
      page = 1;
      load();
    });

    document.getElementById('act_search')?.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        page = 1;
        load();
      }
    });

    document.querySelectorAll('[data-close-modal]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeModal();
    });

    document.querySelectorAll('[data-export]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var fmt = btn.dataset.export;
        if (fmt === 'print') {
          window.print();
          return;
        }
        triggerDownload(exportUrl(fmt));
      });
    });
  });
})();
