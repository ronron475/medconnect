(function () {
  'use strict';

  var REFRESH_MS = (window.McChartTheme && McChartTheme.REFRESH_MS) ? McChartTheme.REFRESH_MS : 15000;
  var charts = {};
  var pollTimer = null;
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

  function makeBarChart(canvasId, series, color, todayColor) {
    if (typeof Chart === 'undefined' || !T()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'bar',
      data: {
        labels: T().labelsFromSeries(series),
        datasets: [T().barDataset(series, color, todayColor)],
      },
      options: T().cartesianOptions(),
    });
  }

  function makeLineChart(canvasId, series, color) {
    if (typeof Chart === 'undefined' || !T()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'line',
      data: {
        labels: T().labelsFromSeries(series),
        datasets: [T().lineDataset(series, color)],
      },
      options: T().cartesianOptions(),
    });
  }

  function makeHBarChart(canvasId, rows) {
    if (typeof Chart === 'undefined' || !T()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'bar',
      data: {
        labels: (rows || []).map(function (r) { return r.label; }),
        datasets: [{
          data: (rows || []).map(function (r) { return r.count; }),
          backgroundColor: (rows || []).map(function (r, i) {
            return r.color || T().palette[i % T().palette.length];
          }),
          borderRadius: 4,
          maxBarThickness: 28,
        }],
      },
      options: T().cartesianOptions({
        indexAxis: 'y',
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: T().colors.grid, drawBorder: false },
            border: { display: false },
            ticks: { precision: 0, font: { size: 10 } },
          },
          y: {
            grid: { display: false },
            border: { display: false },
            ticks: { font: { size: 11, weight: '500' } },
          },
        },
      }),
    });
  }

  function makeDoughnutChart(canvasId, rows) {
    if (typeof Chart === 'undefined' || !T()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroy(canvasId);
    charts[canvasId] = new Chart(el, {
      type: 'doughnut',
      data: {
        labels: (rows || []).map(function (r) { return r.label; }),
        datasets: [{
          data: (rows || []).map(function (r) { return r.count; }),
          backgroundColor: (rows || []).map(function (r, i) {
            return r.color || T().palette[i % T().palette.length];
          }),
          borderColor: '#fff',
          borderWidth: 2,
          hoverOffset: 6,
        }],
      },
      options: T().ringOptions(),
    });
  }

  function render(data) {
    if (!data || !T()) return;

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
      makeLineChart('admChartTriage', triage.series || [], T().colors.red);
      destroy('admChartStatus');
    }

    makeBarChart('admChartConsult', consult.series || [], T().colors.blue, T().colors.teal);
    makeLineChart('admChartReg', reg.series || [], T().colors.purple);
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

  function boot() {
    if (window.McChartTheme) {
      McChartTheme.applyDefaults();
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
})();
