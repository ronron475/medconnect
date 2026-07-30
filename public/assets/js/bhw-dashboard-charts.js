(function () {
  'use strict';

  var charts = {};

  function theme() {
    return window.McChartTheme;
  }

  function destroy(id) {
    if (charts[id]) {
      charts[id].destroy();
      delete charts[id];
    }
  }

  function weekBar(canvasId, series) {
    var T = theme();
    var el = document.getElementById(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;
    destroy(canvasId);
    charts[canvasId] = T.mountWeeklyBarChart(el, series);
  }

  function doughnut(canvasId, rows) {
    var T = theme();
    var el = document.getElementById(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;
    destroy(canvasId);
    T.syncColors();
    T.applyDefaults();
    var data = (rows || []).filter(function (r) { return (r.value || 0) > 0; });
    if (!data.length) {
      data = [{ label: 'No data yet', value: 1 }];
    }
    charts[canvasId] = new Chart(el, {
      type: 'doughnut',
      data: {
        labels: data.map(function (r) { return r.label; }),
        datasets: [{
          data: data.map(function (r) { return r.value; }),
          backgroundColor: [T.colors.red, T.colors.amber, T.colors.cyan, T.colors.purple],
          borderColor: T.segmentBorderColor(),
          borderWidth: 2,
          hoverOffset: 6,
        }],
      },
      options: T.ringOptions(),
    });
  }

  function hBar(canvasId, rows) {
    var T = theme();
    var el = document.getElementById(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;
    destroy(canvasId);
    T.syncColors();
    T.applyDefaults();
    var data = rows || [];
    if (!data.length) {
      data = [{ label: 'No patients in pipeline', value: 0 }];
    }
    charts[canvasId] = new Chart(el, {
      type: 'bar',
      data: {
        labels: data.map(function (r) { return r.label; }),
        datasets: [{
          data: data.map(function (r) { return r.value; }),
          backgroundColor: T.colorsForCount(data.length),
          borderRadius: 4,
          maxBarThickness: 28,
        }],
      },
      options: T.cartesianOptions({
        indexAxis: 'y',
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: T.colors.grid, drawBorder: false },
            border: { display: false },
            ticks: { precision: 0, color: T.colors.text },
          },
          y: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: T.colors.text },
          },
        },
      }),
    });
  }

  var lastPayload = null;

  function render(payload) {
    if (!payload || !theme()) return;
    lastPayload = payload;
    weekBar('bhw_dash_consult_week', payload.consultations_week);
    weekBar('bhw_dash_reg_week', payload.registrations_week);
    doughnut('bhw_dash_triage_mix', payload.triage_mix);
    hBar('bhw_dash_workflow', payload.workflow_pipeline);
  }

  function init() {
    if (typeof Chart === 'undefined' || !theme()) return;
    theme().applyDefaults();
    if (typeof theme().registerThemeRefresh === 'function') {
      theme().registerThemeRefresh(function () {
        if (lastPayload) render(lastPayload);
      });
    }
    var dataEl = document.getElementById('bhwDashChartsData');
    if (!dataEl || !dataEl.textContent) return;
    try {
      render(JSON.parse(dataEl.textContent));
    } catch (e) {
      /* ignore */
    }
  }

  window.BhwDashboardCharts = {
    init: init,
    update: render,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
