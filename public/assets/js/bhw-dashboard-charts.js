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

  function canvasEl(canvasId) {
    return document.getElementById(canvasId);
  }

  function weekBar(canvasId, series) {
    var T = theme();
    var el = canvasEl(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;

    // Prefer in-place updates so auto-refresh does not remount / resize the canvas.
    if (typeof T.updateWeeklyBarChart === 'function') {
      charts[canvasId] = T.updateWeeklyBarChart(el, series);
      return;
    }

    destroy(canvasId);
    charts[canvasId] = T.mountWeeklyBarChart(el, series);
  }

  function normalizeDoughnutRows(rows) {
    var data = (rows || []).filter(function (r) { return (r.value || 0) > 0; });
    if (!data.length) {
      data = [{ label: 'No data yet', value: 1 }];
    }
    return data;
  }

  function doughnut(canvasId, rows, forceRemount) {
    var T = theme();
    var el = canvasEl(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;

    T.syncColors();
    T.applyDefaults();
    var data = normalizeDoughnutRows(rows);
    var labels = data.map(function (r) { return r.label; });
    var values = data.map(function (r) { return r.value; });
    var colors = [T.colors.red, T.colors.amber, T.colors.cyan, T.colors.purple];

    var existing = charts[canvasId];
    if (!forceRemount && existing && existing.config && existing.config.type === 'doughnut') {
      existing.data.labels = labels;
      existing.data.datasets[0].data = values;
      existing.data.datasets[0].backgroundColor = colors;
      existing.data.datasets[0].borderColor = T.segmentBorderColor();
      if (existing.options && existing.options.plugins && existing.options.plugins.tooltip) {
        existing.options.plugins.tooltip.backgroundColor = T.colors.tooltipBg;
        existing.options.plugins.tooltip.titleColor = T.colors.title;
        existing.options.plugins.tooltip.bodyColor = T.colors.tooltipBody;
      }
      existing.update('none');
      return;
    }

    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors,
          borderColor: T.segmentBorderColor(),
          borderWidth: 2,
          hoverOffset: 6,
        }],
      },
      options: T.ringOptions(),
    });
  }

  function normalizePipelineRows(rows) {
    var data = rows || [];
    if (!data.length) {
      data = [{ label: 'No patients in pipeline', value: 0 }];
    }
    return data;
  }

  function hBar(canvasId, rows, forceRemount) {
    var T = theme();
    var el = canvasEl(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;

    T.syncColors();
    T.applyDefaults();
    var data = normalizePipelineRows(rows);
    var labels = data.map(function (r) { return r.label; });
    var values = data.map(function (r) { return r.value; });
    var colors = T.colorsForCount(data.length);

    var existing = charts[canvasId];
    if (!forceRemount && existing && existing.config && existing.config.type === 'bar') {
      existing.data.labels = labels;
      existing.data.datasets[0].data = values;
      existing.data.datasets[0].backgroundColor = colors;
      if (existing.options && existing.options.scales) {
        if (existing.options.scales.x) {
          existing.options.scales.x.grid = existing.options.scales.x.grid || {};
          existing.options.scales.x.grid.color = T.colors.grid;
          if (existing.options.scales.x.ticks) existing.options.scales.x.ticks.color = T.colors.text;
        }
        if (existing.options.scales.y && existing.options.scales.y.ticks) {
          existing.options.scales.y.ticks.color = T.colors.text;
        }
      }
      existing.update('none');
      return;
    }

    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors,
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
  var lastFingerprint = '';

  function fingerprint(payload) {
    try {
      return JSON.stringify(payload || {});
    } catch (e) {
      return String(Date.now());
    }
  }

  function render(payload, force) {
    if (!payload || !theme()) return;
    var fp = fingerprint(payload);
    if (!force && fp === lastFingerprint && charts.bhw_dash_consult_week) {
      return;
    }
    lastFingerprint = fp;
    lastPayload = payload;
    weekBar('bhw_dash_consult_week', payload.consultations_week);
    weekBar('bhw_dash_reg_week', payload.registrations_week);
    doughnut('bhw_dash_triage_mix', payload.triage_mix, !!force);
    hBar('bhw_dash_workflow', payload.workflow_pipeline, !!force);
  }

  function init() {
    if (typeof Chart === 'undefined' || !theme()) return;
    theme().applyDefaults();
    if (typeof theme().registerThemeRefresh === 'function') {
      theme().registerThemeRefresh(function () {
        if (lastPayload) render(lastPayload, true);
      });
    }
    var dataEl = document.getElementById('bhwDashChartsData');
    if (!dataEl || !dataEl.textContent) return;
    try {
      render(JSON.parse(dataEl.textContent), true);
    } catch (e) {
      /* ignore */
    }
  }

  window.BhwDashboardCharts = {
    init: init,
    update: function (payload) {
      render(payload, false);
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
