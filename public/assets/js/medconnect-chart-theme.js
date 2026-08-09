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

  var COLORS_LIGHT = {
    blue: '#3b82f6',
    teal: '#14b8a6',
    amber: '#f59e0b',
    red: '#ef4444',
    purple: '#8b5cf6',
    cyan: '#06b6d4',
    grid: '#f1f5f9',
    text: '#64748b',
    title: '#0f172a',
    tooltipBg: '#ffffff',
    tooltipBody: '#475569',
    pointBorder: '#ffffff',
  };

  var COLORS_DARK = {
    blue: '#3b82f6',
    teal: '#06b6d4',
    amber: '#f59e0b',
    red: '#ef4444',
    purple: '#8b5cf6',
    cyan: '#22d3ee',
    grid: '#2a2a2a',
    text: '#a1a1aa',
    title: '#ffffff',
    tooltipBg: '#1c1c1c',
    tooltipBody: '#a1a1aa',
    pointBorder: '#1c1c1c',
  };

  var COLORS = COLORS_LIGHT;

  function isDarkMode() {
    if (typeof document === 'undefined') return false;
    return document.documentElement.getAttribute('data-theme-resolved') === 'dark';
  }

  function syncColors() {
    COLORS = isDarkMode() ? COLORS_DARK : COLORS_LIGHT;
  }

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
    syncColors();
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
        backgroundColor: COLORS.tooltipBg,
        titleColor: COLORS.title,
        bodyColor: COLORS.tooltipBody,
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

  function cartesianScales(suggestedMax) {
    var yScale = {
      beginAtZero: true,
      grid: { color: COLORS.grid, drawBorder: false },
      border: { display: false },
      ticks: {
        color: COLORS.text,
        font: { family: FONT_FAMILY, size: 10, weight: '500' },
        precision: 0,
        padding: 6,
      },
    };
    if (suggestedMax != null) {
      yScale.suggestedMax = suggestedMax;
    }
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
      y: yScale,
    };
  }

  function cartesianOptions(extra, suggestedMax) {
    var opts = {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      layout: { padding: { top: 8, right: 8, bottom: 4, left: 4 } },
      plugins: basePlugins(),
      scales: cartesianScales(suggestedMax),
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
    syncColors();
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

  function maxCountFromSeries(series) {
    return (series || []).reduce(function (max, p) {
      var n = p.count != null ? p.count : (p.value != null ? p.value : 0);
      return Math.max(max, Number(n) || 0);
    }, 0);
  }

  function suggestedMaxForSeries(series) {
    var max = maxCountFromSeries(series);
    if (max <= 0) return 5;
    return Math.max(max + 1, 5);
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
      pointBorderColor: COLORS.pointBorder,
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
    var today = todayColor || (isDarkMode() ? COLORS.purple : COLORS.teal);
    var muted = isDarkMode() ? 'rgba(59, 130, 246, 0.35)' : hexToRgba(def, 0.85);
    return {
      label: 'Count',
      data: (series || []).map(function (p) { return p.count != null ? p.count : p.value; }),
      backgroundColor: (series || []).map(function (p) {
        if (p.is_today) return today;
        return isDarkMode() ? def : muted;
      }),
      borderRadius: 6,
      maxBarThickness: 44,
      minBarLength: 4,
      borderSkipped: false,
    };
  }

  function labelsFromSeries(series) {
    return (series || []).map(function (p) { return p.label || ''; });
  }

  function fillRoundRect(ctx, x, y, w, h, r) {
    var radius = Math.min(r, h / 2, w / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.lineTo(x + w - radius, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
    ctx.lineTo(x + w, y + h - radius);
    ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
    ctx.lineTo(x + radius, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
    ctx.lineTo(x, y + radius);
    ctx.quadraticCurveTo(x, y, x + radius, y);
    ctx.closePath();
    ctx.fill();
  }

  function registerZeroWeekPlugin() {
    if (typeof Chart === 'undefined' || registerZeroWeekPlugin._done) return;
    registerZeroWeekPlugin._done = true;
    Chart.register({
      id: 'mcZeroWeekPlaceholder',
      afterDatasetsDraw: function (chart) {
        var series = chart.$mcWeekSeries;
        if (!series || !series.length) return;
        if (maxCountFromSeries(series) > 0) return;

        var meta = chart.getDatasetMeta(0);
        if (!meta || !meta.data || !meta.data.length) return;

        var ctx = chart.ctx;
        var dark = isDarkMode();
        var barHeight = 8;
        ctx.save();
        meta.data.forEach(function (bar, index) {
          if (!bar || typeof bar.getProps !== 'function') return;
          var props = bar.getProps(['x', 'width', 'base'], true);
          var isToday = !!(series[index] && series[index].is_today);
          ctx.fillStyle = isToday
            ? (dark ? 'rgba(139, 92, 246, 0.5)' : 'rgba(20, 184, 166, 0.4)')
            : (dark ? 'rgba(59, 130, 246, 0.28)' : 'rgba(59, 130, 246, 0.2)');
          fillRoundRect(
            ctx,
            props.x - props.width / 2,
            props.base - barHeight,
            props.width,
            barHeight,
            4
          );
        });
        ctx.restore();
      },
    });
  }

  function mountWeeklyBarChart(canvas, series) {
    if (!canvas || typeof Chart === 'undefined') return null;
    registerZeroWeekPlugin();
    syncColors();
    applyDefaults();
    var normalized = (series || []).map(function (p) {
      return {
        label: p.label,
        count: p.count != null ? p.count : 0,
        is_today: !!p.is_today,
      };
    });
    if (canvas._mcChart && typeof canvas._mcChart.destroy === 'function') {
      canvas._mcChart.destroy();
    }
    var yMax = suggestedMaxForSeries(normalized);
    var chart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: labelsFromSeries(normalized),
        datasets: [barDataset(normalized, COLORS.blue, isDarkMode() ? COLORS.purple : COLORS.teal)],
      },
      options: cartesianOptions(null, yMax),
    });
    canvas._mcChart = chart;
    chart.$mcWeekSeries = normalized;
    return chart;
  }

  function applyChartTheme(chart, yMax) {
    if (!chart || !chart.options) return;
    if (chart.options.scales && chart.options.scales.y) {
      chart.options.scales.y.suggestedMax = yMax;
      chart.options.scales.y.grid.color = COLORS.grid;
      chart.options.scales.y.ticks.color = COLORS.text;
    }
    if (chart.options.scales && chart.options.scales.x && chart.options.scales.x.ticks) {
      chart.options.scales.x.ticks.color = COLORS.text;
    }
    if (chart.options.plugins && chart.options.plugins.tooltip) {
      chart.options.plugins.tooltip.backgroundColor = COLORS.tooltipBg;
      chart.options.plugins.tooltip.titleColor = COLORS.title;
      chart.options.plugins.tooltip.bodyColor = COLORS.tooltipBody;
    }
  }

  function updateWeeklyBarChart(canvas, series) {
    if (!canvas || typeof Chart === 'undefined') return null;
    syncColors();
    var normalized = (series || []).map(function (p) {
      return {
        label: p.label,
        count: p.count != null ? p.count : 0,
        is_today: !!p.is_today,
      };
    });
    var chart = canvas._mcChart;
    if (!chart) {
      return mountWeeklyBarChart(canvas, normalized);
    }
    var yMax = suggestedMaxForSeries(normalized);
    chart.data.labels = labelsFromSeries(normalized);
    chart.data.datasets = [barDataset(normalized, COLORS.blue, isDarkMode() ? COLORS.purple : COLORS.teal)];
    chart.$mcWeekSeries = normalized;
    applyChartTheme(chart, yMax);
    chart.update('none');
    return chart;
  }

  function refreshAllCharts() {
    syncColors();
    applyDefaults();
    mountWeeklyBarChartsFromDom();
    themeRefreshCallbacks.forEach(function (fn) {
      try {
        fn();
      } catch (e) {
        /* ignore listener errors */
      }
    });
  }

  var themeRefreshCallbacks = [];

  function registerThemeRefresh(fn) {
    if (typeof fn !== 'function') return;
    if (themeRefreshCallbacks.indexOf(fn) === -1) {
      themeRefreshCallbacks.push(fn);
    }
  }

  function segmentBorderColor() {
    syncColors();
    return COLORS.pointBorder;
  }

  function bindThemeListener() {
    if (typeof document === 'undefined' || bindThemeListener._bound) return;
    bindThemeListener._bound = true;
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        if (m.attributeName === 'data-theme-resolved') {
          refreshAllCharts();
        }
      });
    });
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-theme-resolved'],
    });
    window.addEventListener('medconnect-theme-changed', refreshAllCharts);
  }

  function mountWeeklyBarChartsFromDom() {
    if (typeof document === 'undefined') return;
    bindThemeListener();
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

  function periodLabel(days) {
    var n = parseInt(days, 10);
    if (!n || n < 1) n = 7;
    return n === 1 ? 'Today' : ('Last ' + n + ' days');
  }

  function periodRangeLabel(days) {
    var n = parseInt(days, 10);
    if (!n || n < 1) n = 7;
    return n === 1 ? 'today' : ('last ' + n + ' days');
  }

  global.McChartTheme = {
    REFRESH_MS: REFRESH_MS,
    palette: PALETTE,
    colors: COLORS,
    isDarkMode: isDarkMode,
    syncColors: syncColors,
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
    suggestedMaxForSeries: suggestedMaxForSeries,
    segmentBorderColor: segmentBorderColor,
    mountWeeklyBarChart: mountWeeklyBarChart,
    updateWeeklyBarChart: updateWeeklyBarChart,
    mountWeeklyBarChartsFromDom: mountWeeklyBarChartsFromDom,
    refreshAllCharts: refreshAllCharts,
    registerThemeRefresh: registerThemeRefresh,
    periodLabel: periodLabel,
    periodRangeLabel: periodRangeLabel,
  };
})(typeof window !== 'undefined' ? window : this);
