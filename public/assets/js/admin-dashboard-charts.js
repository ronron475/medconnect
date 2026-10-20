(function () {
  'use strict';

  var REFRESH_MS = (window.McChartTheme && McChartTheme.REFRESH_MS) ? McChartTheme.REFRESH_MS : 15000;
  var charts = {};
  var pollTimer = null;
  var lastPayload = null;
  var inFlight = false;
  var hasLoadedOnce = false;
  var T = function () { return window.McChartTheme; };

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function root() {
    return document.getElementById('admChartsRoot');
  }

  function daysSelect() {
    return document.getElementById('admChartsDays');
  }

  function customRangeEl() {
    return document.getElementById('admChartsCustomRange');
  }

  function fromInput() {
    return document.getElementById('admChartsFrom');
  }

  function toInput() {
    return document.getElementById('admChartsTo');
  }

  function ymdLocal(d) {
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function isCustomMode() {
    var el = root();
    if (el && el.getAttribute('data-mode') === 'custom') return true;
    var sel = daysSelect();
    return !!(sel && sel.value === 'custom');
  }

  function syncCustomVisibility() {
    var box = customRangeEl();
    if (!box) return;
    var show = isCustomMode();
    box.hidden = !show;
    box.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function readCustomBounds() {
    var el = root();
    var from = fromInput() ? String(fromInput().value || '').trim() : '';
    var to = toInput() ? String(toInput().value || '').trim() : '';
    if ((!from || !to) && el) {
      from = from || String(el.getAttribute('data-from') || '').trim();
      to = to || String(el.getAttribute('data-to') || '').trim();
    }
    if (from && to && from > to) {
      var tmp = from;
      from = to;
      to = tmp;
    }
    return { from: from, to: to };
  }

  function apiUrl() {
    var el = root();
    if (!el) return '';
    var base = document.body.dataset.assetBase || '';
    var url = base + '/app/api/admin/dashboard_charts.php';
    var params = [];

    if (isCustomMode()) {
      var bounds = readCustomBounds();
      if (bounds.from && bounds.to) {
        params.push('from=' + encodeURIComponent(bounds.from));
        params.push('to=' + encodeURIComponent(bounds.to));
      } else {
        // Incomplete custom range — fall back to last selected preset days.
        var days = el.getAttribute('data-days') || '30';
        params.push('days=' + encodeURIComponent(days));
      }
    } else {
      var preset = el.getAttribute('data-days') || '30';
      params.push('days=' + encodeURIComponent(preset));
    }

    return url + '?' + params.join('&');
  }

  function destroy(id) {
    if (charts[id]) {
      charts[id].destroy();
      delete charts[id];
    }
  }

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function setUpdated(iso) {
    var el = document.getElementById('admChartsUpdated');
    if (!el) return;
    if (!iso) {
      el.textContent = 'Live data';
      return;
    }
    try {
      var d = new Date(iso);
      el.textContent = 'Updated ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    } catch (e) {
      el.textContent = 'Live data';
    }
  }

  function setBusy(busy) {
    var tiles = document.getElementById('admOverviewTiles');
    if (tiles) tiles.setAttribute('aria-busy', busy ? 'true' : 'false');
    var card = document.getElementById('admUserTrendsCard');
    if (card) card.classList.toggle('is-loading', !!busy);
  }

  function setChartState(stateId, wrapId, mode, message) {
    var state = document.getElementById(stateId);
    var wrap = document.getElementById(wrapId);
    if (!state || !wrap) return;

    if (!mode) {
      state.hidden = true;
      state.textContent = '';
      state.className = 'adm-chart-state';
      wrap.classList.remove('is-empty', 'is-error');
      return;
    }

    state.hidden = false;
    state.textContent = message || '';
    state.className = 'adm-chart-state adm-chart-state--' + mode;
    wrap.classList.toggle('is-empty', mode === 'empty');
    wrap.classList.toggle('is-error', mode === 'error');
  }

  function normalizeRoleRows(rows) {
    return (rows || []).map(function (r) {
      return {
        role: r.role || '',
        label: r.label || '',
        count: r.count != null ? Number(r.count) || 0 : 0,
        color: r.color || '',
      };
    });
  }

  function roleBarColors(rows, selectedIndex) {
    return rows.map(function (r, i) {
      var base = r.color || T().palette[i % T().palette.length];
      if (selectedIndex == null || selectedIndex < 0 || selectedIndex === i) {
        return base;
      }
      return T().hexToRgba(base, 0.28);
    });
  }

  function suggestedMaxForRoleSeries(series, roles) {
    var max = 0;
    (series || []).forEach(function (point) {
      var counts = point.counts || {};
      (roles || []).forEach(function (role) {
        var key = role.role || role;
        max = Math.max(max, Number(counts[key] || 0));
      });
      max = Math.max(max, Number(point.total || 0));
    });
    if (max <= 0) return 5;
    return Math.max(max + 1, 5);
  }

  function makeRoleLineChart(canvasId, byRole) {
    if (typeof Chart === 'undefined' || !T()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    T().syncColors();
    T().applyDefaults();

    var series = (byRole && byRole.series) || [];
    var roles = (byRole && byRole.roles) || [];
    var labels = series.map(function (p) { return p.label || ''; });
    var datasets = roles.map(function (role) {
      var color = role.color || T().colors.teal;
      return {
        label: role.label || role.role,
        data: series.map(function (p) {
          var counts = p.counts || {};
          return Number(counts[role.role] || 0);
        }),
        borderColor: color,
        backgroundColor: T().hexToRgba(color, 0.08),
        pointBackgroundColor: color,
        pointBorderColor: T().colors.pointBorder || '#fff',
        pointBorderWidth: 2,
        pointRadius: series.length > 60 ? 0 : 3,
        pointHoverRadius: 5,
        fill: false,
        tension: 0.35,
        borderWidth: 2.5,
      };
    });

    charts[canvasId] = new Chart(el, {
      type: 'line',
      data: { labels: labels, datasets: datasets },
      options: T().cartesianOptions({
        interaction: { mode: 'index', intersect: false },
        plugins: Object.assign({}, T().basePlugins(), {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              boxWidth: 10,
              boxHeight: 10,
              usePointStyle: true,
              pointStyle: 'circle',
              font: { size: 11, weight: '600' },
              color: T().colors.text,
              padding: 12,
            },
          },
          tooltip: Object.assign({}, T().basePlugins().tooltip, {
            callbacks: {
              label: function (ctx) {
                var n = ctx.parsed && ctx.parsed.y != null ? ctx.parsed.y : ctx.raw;
                var count = Number(n || 0);
                return ' ' + (ctx.dataset.label || 'Users') + ': ' + count.toLocaleString();
              },
            },
          }),
        }),
      }, suggestedMaxForRoleSeries(series, roles)),
    });
  }

  function makeHBarChart(canvasId, rows) {
    if (typeof Chart === 'undefined' || !T()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    T().syncColors();
    T().applyDefaults();

    var normalized = normalizeRoleRows(rows);
    var selectedIndex = -1;
    var labels = normalized.map(function (r) { return String(r.label || ''); });
    var data = normalized.map(function (r) { return r.count; });
    var colors = roleBarColors(normalized, selectedIndex);

    charts[canvasId] = new Chart(el, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          backgroundColor: colors,
          borderRadius: 6,
          maxBarThickness: 28,
          minBarLength: 4,
        }],
      },
      options: T().cartesianOptions({
        indexAxis: 'y',
        interaction: { mode: 'index', axis: 'y', intersect: false },
        onClick: function (evt, elements) {
          var chart = charts[canvasId];
          if (!chart) return;
          var rowsLocal = chart.$mcRoleRows || [];
          if (elements && elements.length) {
            var idx = elements[0].index;
            chart.$mcSelectedIndex = (chart.$mcSelectedIndex === idx) ? -1 : idx;
          } else {
            chart.$mcSelectedIndex = -1;
          }
          chart.data.datasets[0].backgroundColor = roleBarColors(rowsLocal, chart.$mcSelectedIndex);
          chart.update('none');
        },
        plugins: Object.assign({}, T().basePlugins(), {
          legend: { display: false },
          tooltip: Object.assign({}, T().basePlugins().tooltip, {
            callbacks: {
              title: function (items) {
                if (!items || !items.length) return '';
                var chart = items[0].chart;
                var row = (chart.$mcRoleRows || [])[items[0].dataIndex];
                return (row && row.label) || items[0].label || '';
              },
              label: function (ctx) {
                var chart = ctx.chart;
                var row = (chart.$mcRoleRows || [])[ctx.dataIndex];
                var n = row ? row.count : (ctx.parsed && ctx.parsed.x != null ? ctx.parsed.x : ctx.raw);
                return ' ' + Number(n || 0).toLocaleString() + ' users';
              },
            },
          }),
        }),
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: T().colors.grid, drawBorder: false },
            border: { display: false },
            ticks: { precision: 0, font: { size: 10 }, color: T().colors.text },
          },
          y: {
            grid: { display: false },
            border: { display: false },
            ticks: {
              autoSkip: false,
              font: { size: 12, weight: '600' },
              color: T().colors.text,
            },
          },
        },
      }),
    });
    charts[canvasId].$mcRoleRows = normalized;
    charts[canvasId].$mcSelectedIndex = selectedIndex;
  }

  function makeSysStatusChart(canvasId, buckets, operationalPct) {
    if (typeof Chart === 'undefined' || !T()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    T().syncColors();
    T().applyDefaults();

    var rows = buckets || [];
    var values = rows.map(function (r) { return Math.max(0, Number(r.count) || 0); });
    var sum = values.reduce(function (s, n) { return s + n; }, 0);
    if (sum <= 0) {
      values = [1, 0, 0];
      rows = [
        { label: 'Online', color: '#16a34a', count: 1, pct: 100 },
        { label: 'Maintenance', color: '#94a3b8', count: 0, pct: 0 },
        { label: 'Issues', color: '#ef4444', count: 0, pct: 0 },
      ];
    }

    charts[canvasId] = new Chart(el, {
      type: 'doughnut',
      data: {
        labels: rows.map(function (r) { return r.label; }),
        datasets: [{
          data: values,
          backgroundColor: rows.map(function (r) { return r.color || '#94a3b8'; }),
          borderColor: T().segmentBorderColor(),
          borderWidth: 2,
          hoverOffset: 4,
        }],
      },
      options: T().ringOptions({
        cutout: '72%',
        plugins: Object.assign({}, T().basePlugins(), {
          legend: { display: false },
          tooltip: Object.assign({}, T().basePlugins().tooltip, {
            callbacks: {
              label: function (ctx) {
                var row = rows[ctx.dataIndex] || {};
                return ' ' + (row.label || '') + ': ' + Number(row.count || 0).toLocaleString();
              },
            },
          }),
        }),
      }),
    });

    setText('admSysOpPct', String(operationalPct != null ? operationalPct : 0) + '%');
  }

  function renderOverview(overview) {
    var o = overview || {};
    setText('admOverviewTotal', Number(o.total_users || 0).toLocaleString());
    setText('admOverviewPatients', Number(o.patients || 0).toLocaleString());
    setText('admOverviewDoctors', Number(o.doctors || 0).toLocaleString());
    setText('admOverviewBhw', Number(o.bhw || 0).toLocaleString());
    setText('admOverviewAdmins', Number(o.administrators || 0).toLocaleString());
  }

  function renderPeriodTotals(byRole, periodLabel) {
    var host = document.getElementById('admPeriodTotals');
    if (!host) return;
    var roles = (byRole && byRole.roles) || [];
    var total = Number((byRole && byRole.total) || 0);
    if (!roles.length) {
      host.innerHTML = '';
      return;
    }
    var chips = roles.map(function (r) {
      return (
        '<span class="adm-period-chip" style="--chip-color:' + (r.color || '#64748b') + '">' +
          '<span class="adm-period-chip__label">' + String(r.label || '') + '</span>' +
          '<strong class="adm-period-chip__value">' + Number(r.total || 0).toLocaleString() + '</strong>' +
        '</span>'
      );
    }).join('');
    host.innerHTML =
      '<span class="adm-period-chip adm-period-chip--total">' +
        '<span class="adm-period-chip__label">New in ' + String(periodLabel || 'period') + '</span>' +
        '<strong class="adm-period-chip__value">' + total.toLocaleString() + '</strong>' +
      '</span>' + chips;
  }

  function renderSysStatusList(buckets) {
    var list = document.getElementById('admSysStatusList');
    if (!list) return;
    var rows = buckets || [];
    if (!rows.length) {
      list.innerHTML = '';
      return;
    }
    list.innerHTML = rows.map(function (r) {
      var color = r.color || '#94a3b8';
      return (
        '<li class="adm-sys-status__row">' +
          '<span class="adm-sys-status__dot" style="background:' + color + '"></span>' +
          '<span class="adm-sys-status__name">' + (r.label || '') + '</span>' +
          '<span class="adm-sys-status__pct">' + Number(r.pct || 0) + '%</span>' +
          '<span class="adm-sys-status__count">' + Number(r.count || 0).toLocaleString() + '</span>' +
        '</li>'
      );
    }).join('');
  }

  function render(data) {
    if (!data || !T()) return;
    lastPayload = data;
    hasLoadedOnce = true;

    var byRole = data.registrations_by_role || {};
    var overview = data.overview || {};
    var distribution = data.distribution || data.roles || [];
    var sys = data.system_status || {};
    var periodLabel = data.period_label || '1 Month';

    var regSub = document.getElementById('admChartRegSub');
    if (regSub) {
      regSub.textContent = 'New accounts by role — ' + periodLabel +
        (data.mode === 'custom' && data.from && data.to
          ? ' (' + data.from + ' to ' + data.to + ')'
          : '');
    }

    renderOverview(overview);
    renderPeriodTotals(byRole, periodLabel);
    makeRoleLineChart('admChartReg', byRole);
    makeHBarChart('admChartRoles', distribution);
    makeSysStatusChart('admChartSysStatus', sys.buckets || [], sys.operational_pct);
    renderSysStatusList(sys.buckets || []);
    setUpdated(data.generated_at);

    var regTotal = Number(byRole.total || 0);
    if (!((byRole.series || []).length)) {
      setChartState('admChartRegState', 'admChartRegWrap', 'empty', 'No date range available for this filter.');
    } else if (regTotal <= 0) {
      setChartState('admChartRegState', 'admChartRegWrap', 'empty', 'No new registrations in this period.');
    } else {
      setChartState('admChartRegState', 'admChartRegWrap', null);
    }

    var distTotal = normalizeRoleRows(distribution).reduce(function (s, r) { return s + r.count; }, 0);
    if (distTotal <= 0) {
      setChartState('admChartRolesState', 'admChartRolesWrap', 'empty', 'No user accounts to display.');
    } else {
      setChartState('admChartRolesState', 'admChartRolesWrap', null);
    }

    var el = root();
    if (el) {
      if (data.mode === 'custom') {
        el.setAttribute('data-mode', 'custom');
        if (data.from) el.setAttribute('data-from', data.from);
        if (data.to) el.setAttribute('data-to', data.to);
      } else {
        el.setAttribute('data-mode', 'preset');
        el.removeAttribute('data-from');
        el.removeAttribute('data-to');
        if (data.days != null) el.setAttribute('data-days', String(data.days));
      }
    }

    var daysSel = daysSelect();
    if (data.mode === 'custom' && daysSel) {
      daysSel.value = 'custom';
      if (fromInput() && data.from) fromInput().value = data.from;
      if (toInput() && data.to) toInput().value = data.to;
    } else if (daysSel && data.days != null) {
      var opt = daysSel.querySelector('option[value="' + data.days + '"]');
      if (opt) daysSel.value = String(data.days);
    }
    syncCustomVisibility();

    setBusy(false);
  }

  function showError(message) {
    setBusy(false);
    var el = document.getElementById('admChartsUpdated');
    if (el) el.textContent = message || 'Refresh failed — retrying…';
    if (!hasLoadedOnce) {
      setChartState('admChartRegState', 'admChartRegWrap', 'error', 'Could not load live graph data. Retrying…');
      setChartState('admChartRolesState', 'admChartRolesWrap', 'error', 'Could not load distribution data. Retrying…');
    }
  }

  function fetchAndRender() {
    var url = apiUrl();
    if (!url) return Promise.resolve();
    if (inFlight) return Promise.resolve();
    inFlight = true;

    if (!hasLoadedOnce) setBusy(true);

    return fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (json) {
        if (json && json.success && json.data) {
          render(json.data);
        } else {
          showError('Refresh failed — retrying…');
        }
      })
      .catch(function () {
        showError('Refresh failed — retrying…');
      })
      .then(function () {
        inFlight = false;
      });
  }

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function () {
      if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
      fetchAndRender();
    }, REFRESH_MS);
  }

  function applyPresetDays(days) {
    var el = root();
    var sel = daysSelect();
    if (sel && String(sel.value) !== String(days)) {
      sel.value = String(days);
    }
    if (el) {
      el.setAttribute('data-days', String(days));
      el.setAttribute('data-mode', 'preset');
      el.removeAttribute('data-from');
      el.removeAttribute('data-to');
    }
    syncCustomVisibility();
    fetchAndRender();
  }

  function ensureCustomDefaults() {
    if (fromInput() && !fromInput().value) {
      var start = new Date();
      start.setDate(start.getDate() - 29);
      fromInput().value = ymdLocal(start);
    }
    if (toInput() && !toInput().value) {
      toInput().value = ymdLocal(new Date());
    }
  }

  function applyCustomRange() {
    var sel = daysSelect();
    if (sel) sel.value = 'custom';
    ensureCustomDefaults();

    var bounds = readCustomBounds();
    var from = bounds.from;
    var to = bounds.to;
    var updated = document.getElementById('admChartsUpdated');
    if (!from || !to) {
      if (updated) updated.textContent = 'Select both From and To dates.';
      setChartState('admChartRegState', 'admChartRegWrap', 'error', 'Select both From and To dates.');
      syncCustomVisibility();
      return;
    }
    if (fromInput()) fromInput().value = from;
    if (toInput()) toInput().value = to;

    var el = root();
    if (el) {
      el.setAttribute('data-mode', 'custom');
      el.setAttribute('data-from', from);
      el.setAttribute('data-to', to);
    }
    syncCustomVisibility();
    fetchAndRender();
  }

  function boot() {
    if (window.McChartTheme) {
      McChartTheme.applyDefaults();
      if (typeof McChartTheme.registerThemeRefresh === 'function') {
        McChartTheme.registerThemeRefresh(function () {
          if (lastPayload) render(lastPayload);
        });
      }
    }
    syncCustomVisibility();
    return fetchAndRender().then(startPolling);
  }

  ready(function () {
    if (!root()) return;

    var daysSel = daysSelect();
    if (daysSel) {
      daysSel.addEventListener('change', function () {
        if (daysSel.value === 'custom') {
          var el = root();
          if (el) el.setAttribute('data-mode', 'custom');
          ensureCustomDefaults();
          syncCustomVisibility();
          // Selecting Custom immediately loads the From–To range into the graph.
          applyCustomRange();
          return;
        }
        applyPresetDays(daysSel.value);
      });
    }

    var applyBtn = document.getElementById('admChartsApplyCustom');
    if (applyBtn) {
      applyBtn.addEventListener('click', applyCustomRange);
    }
    [fromInput(), toInput()].forEach(function (input) {
      if (!input) return;
      input.addEventListener('change', function () {
        // Changing either date while custom is active refreshes the graph.
        if (isCustomMode()) applyCustomRange();
      });
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          applyCustomRange();
        }
      });
    });

    document.addEventListener('medconnect:live-sync', function (ev) {
      var changed = (ev.detail && ev.detail.changed) || [];
      if (
        changed.indexOf('dashboard') !== -1
        || changed.indexOf('staff_applications') !== -1
      ) {
        fetchAndRender();
      }
    });

    if (typeof Chart !== 'undefined') {
      boot();
      return;
    }
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
    s.onload = boot;
    document.head.appendChild(s);
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && root()) fetchAndRender();
  });

  window.MedConnectAdminDashboardCharts = {
    refresh: fetchAndRender,
  };
})();
