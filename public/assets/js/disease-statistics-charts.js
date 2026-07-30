(function () {
  'use strict';

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

  function rowsToChart(rows) {
    return (rows || []).map(function (r) {
      return { label: r.label, value: r.cnt != null ? r.cnt : r.count };
    });
  }

  function hBar(canvasId, rows) {
    var T = window.McChartTheme;
    var el = document.getElementById(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;
    var data = rowsToChart(rows);
    return new Chart(el, {
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
            ticks: { precision: 0 },
          },
          y: {
            grid: { display: false },
            border: { display: false },
          },
        },
      }),
    });
  }

  function doughnut(canvasId, rows) {
    var T = window.McChartTheme;
    var el = document.getElementById(canvasId);
    if (!el || !T || typeof Chart === 'undefined') return;
    var data = rowsToChart(rows);
    return new Chart(el, {
      type: 'doughnut',
      data: {
        labels: data.map(function (r) { return r.label; }),
        datasets: [{
          data: data.map(function (r) { return r.value; }),
          backgroundColor: T.colorsForCount(data.length),
          borderColor: '#fff',
          borderWidth: 2,
          hoverOffset: 6,
        }],
      },
      options: T.ringOptions(),
    });
  }

  ready(function () {
    var root = document.getElementById('diseaseStatsCharts');
    if (!root) return;
    var data = payload();
    if (!data || !window.McChartTheme) return;
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
  });
})();
