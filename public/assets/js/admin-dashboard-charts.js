(function () {
  'use strict';

  var REFRESH_MS = 45000;
  var charts = {};
  var pollTimer = null;

  var TEAL = '#0d9488';
  var BLUE = '#2563eb';
  var PURPLE = '#7c3aed';
  var RED = '#dc2626';

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
    var days = el.getAttribute('data-days') || '30';
    return base + '/app/api/admin/dashboard_charts.php?days=' + encodeURIComponent(days);
  }

  function destroy(id) {
    if (charts[id]) {
      charts[id].destroy();
      delete charts[id];
    }
  }

  function setKpi(id, value) {
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

  function seriesLabels(series) {
    return (series || []).map(function (p) { return p.label || ''; });
  }

  function seriesValues(series) {
    return (series || []).map(function (p) { return p.count || 0; });
  }

  function barColors(series, defaultColor, todayColor) {
    return (series || []).map(function (p) {
      return p.is_today ? (todayColor || TEAL) : (defaultColor || BLUE);
    });
  }

  function makeBarChart(canvasId, series, color, todayColor) {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'bar',
      data: {
        labels: seriesLabels(series),
        datasets: [{
          label: 'Count',
          data: seriesValues(series),
          backgroundColor: barColors(series, color, todayColor),
          borderRadius: 6,
          maxBarThickness: 36,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 0 } },
          y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } }, grid: { color: '#f1f5f9' } },
        },
      },
    });
  }

  function makeLineChart(canvasId, series, color) {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    var fill = color.charAt(0) === '#' ? hexToRgba(color, 0.12) : color;
    charts[canvasId] = new Chart(el, {
      type: 'line',
      data: {
        labels: seriesLabels(series),
        datasets: [{
          label: 'Count',
          data: seriesValues(series),
          borderColor: color,
          backgroundColor: fill,
          pointBackgroundColor: color,
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          pointRadius: 4,
          fill: true,
          tension: 0.35,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 10 } } },
          y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } }, grid: { color: '#f1f5f9' } },
        },
      },
    });
  }

  function hexToRgba(hex, alpha) {
    var h = hex.replace('#', '');
    if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
    var n = parseInt(h, 16);
    return 'rgba(' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].join(',') + ',' + alpha + ')';
  }

  function makeHBarChart(canvasId, rows) {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'bar',
      data: {
        labels: (rows || []).map(function (r) { return r.label; }),
        datasets: [{
          data: (rows || []).map(function (r) { return r.count; }),
          backgroundColor: (rows || []).map(function (r) { return r.color || BLUE; }),
          borderRadius: 6,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } }, grid: { color: '#f1f5f9' } },
          y: { grid: { display: false }, ticks: { font: { size: 11 } } },
        },
      },
    });
  }

  function makeDoughnutChart(canvasId, rows) {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'doughnut',
      data: {
        labels: (rows || []).map(function (r) { return r.label; }),
        datasets: [{
          data: (rows || []).map(function (r) { return r.count; }),
          backgroundColor: (rows || []).map(function (r) { return r.color || BLUE; }),
          borderWidth: 0,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        },
      },
    });
  }

  function render(data) {
    if (!data) return;

    var consult = data.consultations || {};
    var reg = data.registrations || {};
    var triage = data.triage || {};
    var roles = data.roles || [];
    var status = data.status || [];

    setKpi('admKpiConsultTotal', Number(consult.total || 0).toLocaleString());
    setKpi('admKpiRegTotal', Number(reg.total || 0).toLocaleString());
    setKpi('admKpiUsersTotal', roles.reduce(function (s, r) { return s + (r.count || 0); }, 0).toLocaleString());

    var statusTotal = status.reduce(function (s, r) { return s + (r.count || 0); }, 0);
    var fourthTitle = document.getElementById('admChartFourthTitle');
    var fourthSub = document.getElementById('admChartFourthSub');
    var fourthKpi = document.getElementById('admKpiFourthTotal');
    var fourthKpiLabel = document.getElementById('admKpiFourthLabel');

    var statusCanvas = document.getElementById('admChartStatus');
    var triageCanvas = document.getElementById('admChartTriage');

    if (status.length > 0) {
      if (fourthTitle) fourthTitle.textContent = 'Consultation Status';
      if (fourthSub) fourthSub.textContent = 'Live breakdown by workflow state';
      if (fourthKpi) fourthKpi.textContent = Number(statusTotal).toLocaleString();
      if (fourthKpiLabel) fourthKpiLabel.textContent = 'All consultations';
      if (statusCanvas) statusCanvas.style.display = 'block';
      if (triageCanvas) triageCanvas.style.display = 'none';
      makeDoughnutChart('admChartStatus', status);
      destroy('admChartTriage');
    } else {
      if (fourthTitle) fourthTitle.textContent = 'AI Triage Volume';
      if (fourthSub) fourthSub.textContent = 'Daily assessments — last ' + (data.days || 30) + ' days';
      if (fourthKpi) fourthKpi.textContent = Number(triage.total || 0).toLocaleString();
      if (fourthKpiLabel) fourthKpiLabel.textContent = 'Period total';
      if (statusCanvas) statusCanvas.style.display = 'none';
      if (triageCanvas) triageCanvas.style.display = 'block';
      makeLineChart('admChartTriage', triage.series || [], RED);
      destroy('admChartStatus');
    }

    makeBarChart('admChartConsult', consult.series || [], BLUE, TEAL);
    makeLineChart('admChartReg', reg.series || [], PURPLE);
    makeHBarChart('admChartRoles', roles);

    setUpdated(data.generated_at);
  }

  function fetchAndRender() {
    var url = apiUrl();
    if (!url) return Promise.resolve();

    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
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
    pollTimer = setInterval(fetchAndRender, REFRESH_MS);
  }

  ready(function () {
    if (!root()) return;

    fetchAndRender().then(function () {
      if (typeof Chart !== 'undefined') startPolling();
      else {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
        s.onload = function () {
          fetchAndRender().then(startPolling);
        };
        document.head.appendChild(s);
      }
    });

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) fetchAndRender();
    });
  });
})();
