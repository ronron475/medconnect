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
