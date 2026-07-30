/**
 * medConnect — unified Chart.js theme (APM-style dashboards).
 * Load after Chart.js; call McChartTheme.applyDefaults() once per page.
 */
(function (global) {
  'use strict';

  var FONT_FAMILY = "'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif";
  var REFRESH_MS = 15000;

  var PALETTE = [
    '#3b82f6', // blue
    '#14b8a6', // teal
    '#f59e0b', // amber
    '#ef4444', // red
    '#8b5cf6', // purple
    '#06b6d4', // cyan
    '#ec4899', // pink
    '#64748b', // slate
  ];

  var COLORS = {
    blue: '#3b82f6',
    teal: '#14b8a6',
    amber: '#f59e0b',
    red: '#ef4444',
    purple: '#8b5cf6',
    cyan: '#06b6d4',
    grid: '#f1f5f9',
    text: '#64748b',
    title: '#0f172a',
  };

  function hexToRgba(hex, alpha) {
    var h = String(hex || '#3b82f6').replace('#', '');
    if (h.length === 3) {
      h = h.split('').map(function (c) { return c + c; }).join('');
    }
    var n = parseInt(h, 16);
    if (Number.isNaN(n)) return 'rgba(59, 130, 246, ' + alpha + ')';
    return 'rgba(' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].join(',') + ',' + alpha + ')';
  }

  function tooltipBorderColor(tooltipItems) {
    if (!tooltipItems || !tooltipItems.length) return COLORS.blue;
    var ctx = tooltipItems[0];
    var ds = ctx.chart.data.datasets[ctx.datasetIndex];
    if (ds.borderColor) {
      return Array.isArray(ds.borderColor) ? ds.borderColor[ctx.dataIndex] : ds.borderColor;
    }
    if (ds.backgroundColor) {
      return Array.isArray(ds.backgroundColor) ? ds.backgroundColor[ctx.dataIndex] : ds.backgroundColor;
    }
    return COLORS.blue;
  }

  function basePlugins() {
    return {
      legend: {
        display: false,
        labels: {
          boxWidth: 10,
          boxHeight: 10,
          padding: 14,
          color: COLORS.text,
          font: { family: FONT_FAMILY, size: 11, weight: '500' },
          usePointStyle: true,
          pointStyle: 'rectRounded',
        },
      },
      tooltip: {
        enabled: true,
        backgroundColor: '#ffffff',
        titleColor: COLORS.title,
        bodyColor: '#475569',
        borderColor: tooltipBorderColor,
        borderWidth: 2,
        padding: 12,
        cornerRadius: 4,
        titleFont: { family: FONT_FAMILY, size: 12, weight: '700' },
        bodyFont: { family: FONT_FAMILY, size: 11, weight: '500' },
        displayColors: true,
        boxPadding: 6,
        caretSize: 6,
        caretPadding: 8,
      },
    };
  }

  function cartesianScales() {
    return {
      x: {
        grid: { display: false, drawBorder: false },
        border: { display: false },
        ticks: {
          color: COLORS.text,
          font: { family: FONT_FAMILY, size: 10, weight: '500' },
          maxRotation: 0,
          autoSkipPadding: 12,
        },
      },
      y: {
        beginAtZero: true,
        grid: { color: COLORS.grid, drawBorder: false },
        border: { display: false },
        ticks: {
          color: COLORS.text,
          font: { family: FONT_FAMILY, size: 10, weight: '500' },
          precision: 0,
          padding: 6,
        },
      },
    };
  }

  function cartesianOptions(extra) {
    var opts = {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      layout: { padding: { top: 8, right: 8, bottom: 4, left: 4 } },
      plugins: basePlugins(),
      scales: cartesianScales(),
    };
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        opts[k] = extra[k];
      });
    }
    return opts;
  }

  function ringOptions(extra) {
    var opts = {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '62%',
      layout: { padding: 8 },
      plugins: Object.assign({}, basePlugins(), {
        legend: Object.assign({}, basePlugins().legend, { display: true, position: 'bottom' }),
      }),
    };
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        opts[k] = extra[k];
      });
    }
    return opts;
  }

  function applyDefaults() {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.font.family = FONT_FAMILY;
    Chart.defaults.font.size = 11;
    Chart.defaults.color = COLORS.text;
    Chart.defaults.borderColor = COLORS.grid;
    Chart.defaults.plugins.tooltip = Object.assign(
      {},
      Chart.defaults.plugins.tooltip,
      basePlugins().tooltip
    );
  }

  function colorsForCount(n, baseColor) {
    if (baseColor) {
      return Array.from({ length: n }, function () { return baseColor; });
    }
    return Array.from({ length: n }, function (_, i) {
      return PALETTE[i % PALETTE.length];
    });
  }

  function lineDataset(series, color) {
    var c = color || COLORS.blue;
    return {
      label: 'Count',
      data: (series || []).map(function (p) { return p.count != null ? p.count : p.value; }),
      borderColor: c,
      backgroundColor: hexToRgba(c, 0.18),
      pointBackgroundColor: c,
      pointBorderColor: '#fff',
      pointBorderWidth: 2,
      pointRadius: 3,
      pointHoverRadius: 5,
      fill: true,
      tension: 0.35,
      borderWidth: 2,
    };
  }

  function barDataset(series, color, todayColor) {
    var def = color || COLORS.blue;
    var today = todayColor || COLORS.teal;
    return {
      label: 'Count',
      data: (series || []).map(function (p) { return p.count != null ? p.count : p.value; }),
      backgroundColor: (series || []).map(function (p) {
        return p.is_today ? today : def;
      }),
      borderRadius: 4,
      maxBarThickness: 40,
      borderSkipped: false,
    };
  }

  function labelsFromSeries(series) {
    return (series || []).map(function (p) { return p.label || ''; });
  }

  function mountWeeklyBarChart(canvas, series) {
    if (!canvas || typeof Chart === 'undefined') return null;
    applyDefaults();
    var normalized = (series || []).map(function (p) {
      return {
        label: p.label,
        count: p.count != null ? p.count : 0,
        is_today: !!p.is_today,
      };
    });
    return new Chart(canvas, {
      type: 'bar',
      data: {
        labels: labelsFromSeries(normalized),
        datasets: [barDataset(normalized, COLORS.blue, COLORS.teal)],
      },
      options: cartesianOptions(),
    });
  }

  function mountWeeklyBarChartsFromDom() {
    if (typeof document === 'undefined') return;
    document.querySelectorAll('canvas[data-mc-weekly-bar]').forEach(function (canvas) {
      var dataId = canvas.getAttribute('data-mc-weekly-bar');
      if (!dataId) return;
      var dataEl = document.getElementById(dataId);
      if (!dataEl || !dataEl.textContent) return;
      try {
        mountWeeklyBarChart(canvas, JSON.parse(dataEl.textContent));
      } catch (e) {
        /* ignore bad JSON */
      }
    });
  }

  global.McChartTheme = {
    REFRESH_MS: REFRESH_MS,
    palette: PALETTE,
    colors: COLORS,
    fontFamily: FONT_FAMILY,
    applyDefaults: applyDefaults,
    hexToRgba: hexToRgba,
    basePlugins: basePlugins,
    cartesianOptions: cartesianOptions,
    ringOptions: ringOptions,
    colorsForCount: colorsForCount,
    lineDataset: lineDataset,
    barDataset: barDataset,
    labelsFromSeries: labelsFromSeries,
    mountWeeklyBarChart: mountWeeklyBarChart,
    mountWeeklyBarChartsFromDom: mountWeeklyBarChartsFromDom,
  };
})(typeof window !== 'undefined' ? window : this);
