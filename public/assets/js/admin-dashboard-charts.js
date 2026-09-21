(function () {
  'use strict';

  var REFRESH_MS = (window.McChartTheme && McChartTheme.REFRESH_MS) ? McChartTheme.REFRESH_MS : 15000;
  var charts = {};
  var pollTimer = null;
  var lastPayload = null;
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

  function apiUrl() {
    var el = root();
    if (!el) return '';
    var base = document.body.dataset.assetBase || '';
    var days = el.getAttribute('data-days') || '180';
    return base + '/app/api/admin/dashboard_charts.php?days=' + encodeURIComponent(days);
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
    if (!el || !iso) return;
    try {
      var d = new Date(iso);
      el.textContent = 'Updated ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    } catch (e) {
      el.textContent = 'Live data';
    }
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

  function makeLineChart(canvasId, series, color) {
    if (typeof Chart === 'undefined' || !T()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    T().syncColors();
    T().applyDefaults();
    var ds = T().lineDataset(series, color || T().colors.teal);
    ds.pointRadius = 4;
    ds.pointHoverRadius = 6;
    ds.borderWidth = 2.5;
    ds.backgroundColor = T().hexToRgba(color || T().colors.teal, 0.16);
    charts[canvasId] = new Chart(el, {
      type: 'line',
      data: {
        labels: T().labelsFromSeries(series),
        datasets: [ds],
      },
      options: T().cartesianOptions({
        plugins: Object.assign({}, T().basePlugins(), {
          legend: { display: false },
        }),
      }, T().suggestedMaxForSeries(series)),
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
    setText('admOverviewDoctors', Number(o.doctors || 0).toLocaleString());
    setText('admOverviewBhw', Number(o.bhw || 0).toLocaleString());
    setText('admOverviewAdmins', Number(o.administrators || 0).toLocaleString());
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

    var reg = data.registrations || {};
    var overview = data.overview || {};
    var distribution = data.distribution || data.roles || [];
    var sys = data.system_status || {};

    var periodLabel = data.period_label || 'Last 6 Months';
    var regSub = document.getElementById('admChartRegSub');
    if (regSub) regSub.textContent = 'New user sign-ups per month';

    renderOverview(overview);
    makeLineChart('admChartReg', reg.series || [], T().colors.teal);
    makeHBarChart('admChartRoles', distribution);
    makeSysStatusChart('admChartSysStatus', sys.buckets || [], sys.operational_pct);
    renderSysStatusList(sys.buckets || []);
    setUpdated(data.generated_at);

    // Keep period select label in sync if server remapped days
    var daysSel = document.getElementById('admChartsDays');
    if (daysSel && data.days != null && String(daysSel.value) !== String(data.days)) {
      var opt = daysSel.querySelector('option[value="' + data.days + '"]');
      if (opt) daysSel.value = String(data.days);
    }
    if (regSub && periodLabel && data.days && Number(data.days) < 180) {
      regSub.textContent = 'New user sign-ups — ' + periodLabel;
    }
  }

  function fetchAndRender() {
    var url = apiUrl();
    if (!url) return Promise.resolve();

    return fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.success && json.data) {
          render(json.data);
        }
      })
      .catch(function () {
        var el = document.getElementById('admChartsUpdated');
        if (el) el.textContent = 'Refresh failed — retrying…';
      });
  }

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function () {
      if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
      fetchAndRender();
    }, REFRESH_MS);
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
    return fetchAndRender().then(startPolling);
  }

  ready(function () {
    if (!root()) return;

    var daysSel = document.getElementById('admChartsDays');
    if (daysSel) {
      daysSel.addEventListener('change', function () {
        var el = root();
        if (el) el.setAttribute('data-days', daysSel.value);
        fetchAndRender();
      });
    }

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
