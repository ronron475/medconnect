(function () {
  'use strict';

  var charts = [];
  var lastData = null;

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function payload() {
    var el = document.getElementById('diseaseStatsPayload');
    if (!el || !el.textContent) return null;
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  }

  function destroyAll() {
    charts.forEach(function (chart) {
      if (chart && typeof chart.destroy === 'function') {
        chart.destroy();
      }
    });
    charts = [];
  }

  function rowsToChart(rows) {
    return (rows || []).map(function (r) {
      return { label: r.label, value: r.cnt != null ? r.cnt : r.count };
    });
  }

  function hBar(canvasId, rows) {
    var T = window.McChartTheme;
    var el = document.getElementById(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;
    T.syncColors();
    T.applyDefaults();
    var data = rowsToChart(rows);
    charts.push(new Chart(el, {
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
    }));
  }

  function doughnut(canvasId, rows) {
    var T = window.McChartTheme;
    var el = document.getElementById(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;
    T.syncColors();
    T.applyDefaults();
    var data = rowsToChart(rows);
    charts.push(new Chart(el, {
      type: 'doughnut',
      data: {
        labels: data.map(function (r) { return r.label; }),
        datasets: [{
          data: data.map(function (r) { return r.value; }),
          backgroundColor: T.colorsForCount(data.length),
          borderColor: T.segmentBorderColor(),
          borderWidth: 2,
          hoverOffset: 6,
        }],
      },
      options: T.ringOptions(),
    }));
  }

  function render(data) {
    if (!data || !window.McChartTheme) return;
    lastData = data;
    destroyAll();
    McChartTheme.applyDefaults();
    if (data.triage && data.triage.length) {
      doughnut('chart_disease_triage', data.triage);
    }
    if (data.diagnosis && data.diagnosis.length) {
      hBar('chart_disease_dx', data.diagnosis.slice(0, 12));
    }
    if (data.complaints && data.complaints.length) {
      hBar('chart_disease_complaints', data.complaints.slice(0, 12));
    }
  }

  ready(function () {
    var root = document.getElementById('diseaseStatsCharts');
    if (!root) return;
    var data = payload();
    if (!data || !window.McChartTheme) return;
    if (typeof McChartTheme.registerThemeRefresh === 'function') {
      McChartTheme.registerThemeRefresh(function () {
        if (lastData) render(lastData);
      });
    }
    render(data);
  });
})();
