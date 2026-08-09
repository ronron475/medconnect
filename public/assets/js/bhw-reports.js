(function () {
  var charts = {};
  var activeTab = 'patients';
  var lastReportData = {};
  var base = document.body.dataset.assetBase || '';

  function theme() {
    return window.McChartTheme;
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function whenChartReady() {
    return new Promise(function (resolve) {
      var tries = 0;
      (function wait() {
        if (typeof Chart !== 'undefined' && theme()) {
          theme().applyDefaults();
          resolve();
          return;
        }
        tries += 1;
        if (tries > 80) {
          resolve();
          return;
        }
        setTimeout(wait, 50);
      })();
    });
  }

  function rowValue(row) {
    if (!row) return 0;
    if (row.value != null) return Number(row.value) || 0;
    if (row.count != null) return Number(row.count) || 0;
    return 0;
  }

  function hasChartValues(rows) {
    return (rows || []).some(function (row) { return rowValue(row) > 0; });
  }

  function normalizeRows(rows, emptyLabel) {
    var data = Array.isArray(rows) ? rows.slice() : [];
    if (!data.length || !hasChartValues(data)) {
      return [{ label: emptyLabel || 'No data yet', value: 1, _placeholder: true }];
    }
    return data;
  }

  function normalizeSeries(rows) {
    var data = Array.isArray(rows) ? rows.slice() : [];
    if (data.length) return data;
    var out = [];
    var now = new Date();
    for (var i = 5; i >= 0; i--) {
      var d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      var label = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
      out.push({ label: label, value: 0, _placeholder: true });
    }
    return out;
  }

  var SUMMARY_GROUPS = [
    {
      title: 'Patients',
      metrics: [
        ['total_patients', 'Registered'],
        ['new_patients_month', 'New this month'],
        ['male_patients', 'Male'],
        ['female_patients', 'Female'],
      ],
    },
    {
      title: 'At-risk groups',
      metrics: [
        ['senior_citizens', 'Seniors'],
        ['children', 'Children'],
        ['high_risk_patients', 'High risk'],
        ['ai_emergency_cases', 'AI emergency'],
      ],
    },
    {
      title: 'Consultations',
      metrics: [
        ['pending_consultations', 'Pending'],
        ['completed_consultations', 'Completed'],
        ['cancelled_consultations', 'Cancelled'],
      ],
    },
    {
      title: 'Referrals & follow-up',
      metrics: [
        ['pending_referrals', 'Pending referrals'],
        ['completed_referrals', 'Completed referrals'],
        ['home_visits_completed', 'Home visits'],
        ['overdue_followups', 'Overdue follow-ups'],
      ],
    },
  ];

  function filters() {
    return {
      date_from: document.getElementById('rf_date_from')?.value || '',
      date_to: document.getElementById('rf_date_to')?.value || '',
      month: document.getElementById('rf_month')?.value || '',
      year: document.getElementById('rf_year')?.value || '',
      gender: document.getElementById('rf_gender')?.value || '',
      age_group: document.getElementById('rf_age')?.value || '',
    };
  }

  function destroyChart(id) {
    if (charts[id]) {
      charts[id].destroy();
      delete charts[id];
    }
  }

  function ringData(rows) {
    var T = theme();
    if (T) T.syncColors();
    var normalized = normalizeRows(rows);
    var isPlaceholder = normalized.length === 1 && normalized[0]._placeholder;
    var n = normalized.length;
    return {
      labels: normalized.map(function (r) { return r.label || '—'; }),
      datasets: [{
        data: normalized.map(function (r) { return rowValue(r) || 1; }),
        backgroundColor: isPlaceholder
          ? [T ? T.hexToRgba(T.colors.text, 0.18) : '#e2e8f0']
          : (T ? T.colorsForCount(n) : []),
        borderColor: T ? T.segmentBorderColor() : '#fff',
        borderWidth: 2,
        hoverOffset: isPlaceholder ? 0 : 6,
      }],
    };
  }

  function cartesianData(type, rows) {
    var T = theme();
    var normalized = type === 'line' ? normalizeSeries(rows) : normalizeRows(rows);
    var isPlaceholder = normalized.length === 1 && normalized[0]._placeholder;
    var labels = normalized.map(function (r) { return r.label; });
    if (type === 'line' && T) {
      var series = normalized.map(function (r) {
        return { label: r.label, count: rowValue(r), value: rowValue(r) };
      });
      var dataset = T.lineDataset(series, T.colors.blue);
      if (isPlaceholder || normalized.every(function (r) { return rowValue(r) === 0; })) {
        dataset.borderColor = T.hexToRgba(T.colors.blue, 0.35);
        dataset.backgroundColor = T.hexToRgba(T.colors.blue, 0.08);
        dataset.pointRadius = 2;
      }
      return {
        labels: labels,
        datasets: [dataset],
      };
    }
    var colors = T ? T.colorsForCount(normalized.length) : [];
    if (isPlaceholder) {
      colors = [T ? T.hexToRgba(T.colors.text, 0.2) : '#e2e8f0'];
    }
    return {
      labels: labels,
      datasets: [{
        label: 'Count',
        data: normalized.map(function (r) { return isPlaceholder ? 1 : rowValue(r); }),
        backgroundColor: colors,
        borderRadius: 4,
        maxBarThickness: 36,
        borderSkipped: false,
      }],
    };
  }

  function makeChart(canvasId, type, rows, options) {
    if (typeof Chart === 'undefined' || !theme()) return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroyChart(canvasId);
    var T = theme();
    T.syncColors();
    T.applyDefaults();
    var isRing = type === 'pie' || type === 'doughnut';
    var chartType = type === 'pie' ? 'doughnut' : type;
    var chartOptions;
    if (isRing) {
      chartOptions = T.ringOptions();
      if (type === 'pie') {
        chartOptions.cutout = '0%';
      }
    } else {
      chartOptions = T.cartesianOptions({
        interaction: { mode: 'index', intersect: false },
      });
      if (options && options.indexAxis === 'y') {
        chartOptions.indexAxis = 'y';
        chartOptions.scales = {
          x: {
            beginAtZero: true,
            grid: { color: T.colors.grid, drawBorder: false },
            border: { display: false },
            ticks: { precision: 0, font: { size: 10 }, color: T.colors.text },
          },
          y: {
            grid: { display: false },
            border: { display: false },
            ticks: { font: { size: 11, weight: '500' }, color: T.colors.text },
          },
        };
      }
    }
    if (options) {
      Object.keys(options).forEach(function (k) {
        if (k !== 'indexAxis' || !isRing) {
          chartOptions[k] = options[k];
        }
      });
    }
    charts[canvasId] = new Chart(el, {
      type: chartType,
      data: isRing ? ringData(rows) : cartesianData(type, rows),
      options: chartOptions,
    });
  }

  function resizeVisibleCharts() {
    requestAnimationFrame(function () {
      Object.keys(charts).forEach(function (id) {
        if (charts[id]) charts[id].resize();
      });
    });
  }

  function renderPatientsCharts(rep) {
    makeChart('chart_pat_monthly', 'line', rep.monthly);
    makeChart('chart_pat_gender', 'doughnut', rep.genderDist);
    makeChart('chart_pat_age', 'bar', rep.ageDist);
    makeChart('chart_pat_purok', 'bar', rep.purokDist, { indexAxis: 'y' });
    resizeVisibleCharts();
  }

  function renderConsultationsCharts(rep) {
    makeChart('chart_con_status', 'pie', rep.by_status);
    makeChart('chart_con_monthly', 'line', rep.monthly_trend);
    makeChart('chart_con_provider', 'bar', rep.provider_dist, { indexAxis: 'y' });
    resizeVisibleCharts();
  }

  function renderTriageCharts(rep) {
    makeChart('chart_tri_urgency', 'doughnut', [
      { label: 'Low Risk', value: rep.low_risk },
      { label: 'Moderate', value: rep.moderate_risk },
      { label: 'High Risk', value: rep.high_risk },
      { label: 'Emergency', value: rep.emergency },
    ]);
    makeChart('chart_tri_class', 'bar', rep.classifications);
    makeChart('chart_tri_symptoms', 'bar', rep.top_symptoms, { indexAxis: 'y' });
    resizeVisibleCharts();
  }

  function renderReferralsCharts(rep) {
    makeChart('chart_ref_type', 'pie', rep.by_type);
    makeChart('chart_ref_status', 'bar', rep.by_status);
    resizeVisibleCharts();
  }

  function renderFollowupsCharts(rep) {
    makeChart('chart_fol_overview', 'bar', [
      { label: 'Home Visits', value: rep.homeVisits },
      { label: 'Completed', value: rep.completed },
      { label: 'Pending', value: rep.pending },
      { label: 'Overdue', value: rep.overdue },
    ]);
    makeChart('chart_fol_visits', 'pie', rep.visitTypes);
    resizeVisibleCharts();
  }

  function renderDiseaseCharts(rep) {
    makeChart('chart_dis_conditions', 'bar', rep.top_diseases, { indexAxis: 'y' });
    makeChart('chart_dis_symptoms', 'bar', rep.top_symptoms, { indexAxis: 'y' });
    makeChart('chart_dis_age', 'pie', rep.age_groups);
    makeChart('chart_dis_monthly', 'line', rep.monthly_trends);
    resizeVisibleCharts();
  }

  function rerenderActiveTab() {
    var rep = lastReportData[activeTab];
    if (!rep) return;
    if (activeTab === 'patients') renderPatientsCharts(rep);
    else if (activeTab === 'consultations') renderConsultationsCharts(rep);
    else if (activeTab === 'triage') renderTriageCharts(rep);
    else if (activeTab === 'referrals') renderReferralsCharts(rep);
    else if (activeTab === 'followups') renderFollowupsCharts(rep);
    else if (activeTab === 'disease') renderDiseaseCharts(rep);
  }

  function renderSummary(summary) {
    var row = document.getElementById('bhwSummaryRow');
    var skel = document.getElementById('bhwSummarySkeleton');
    if (!row) return;
    row.innerHTML = SUMMARY_GROUPS.map(function (group) {
      var stats = group.metrics.map(function (pair) {
        var key = pair[0];
        var label = pair[1];
        var val = summary[key] != null ? summary[key] : 0;
        return '<li class="bhw-report-stat"><span class="bhw-report-stat-label">' + label + '</span>' +
          '<span class="bhw-report-stat-val">' + val + '</span></li>';
      }).join('');
      return '<article class="bhw-report-group">' +
        '<h3 class="bhw-report-group-title">' + group.title + '</h3>' +
        '<ul class="bhw-report-group-stats">' + stats + '</ul></article>';
    }).join('');
    if (skel) skel.style.display = 'none';
    row.hidden = false;
  }

  function loadSummary() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'summary' }, filters())).then(function (r) {
      if (!r.success) return;
      renderSummary(r.summary || {});
    });
  }

  function loadPatients() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'patients' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      lastReportData.patients = r.report;
      renderPatientsCharts(r.report);
    });
  }

  function loadConsultations() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'consultations' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      lastReportData.consultations = r.report;
      renderConsultationsCharts(r.report);
    });
  }

  function loadTriage() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'triage' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      lastReportData.triage = r.report;
      renderTriageCharts(r.report);
    });
  }

  function loadReferrals() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'referrals' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      lastReportData.referrals = r.report;
      renderReferralsCharts(r.report);
    });
  }

  function loadFollowups() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'followups' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      lastReportData.followups = r.report;
      var rep = r.report;
      renderFollowupsCharts(rep);
      var list = document.getElementById('fol_requiring_list');
      if (list) {
        var rows = rep.requiring || [];
        if (!rows.length) {
          list.innerHTML = '<p class="text-muted p-3">No patients currently requiring follow-up.</p>';
        } else {
          list.innerHTML = '<table class="table bhw-table"><thead><tr><th>Patient</th><th>Date</th><th>Status</th></tr></thead><tbody>' +
            rows.map(function (x) {
              return '<tr><td>' + (x.patient_name || '—') + '</td><td>' + (x.followup_date || '—') + '</td><td>' + (x.status || '—') + '</td></tr>';
            }).join('') + '</tbody></table>';
        }
      }
    });
  }

  function loadDisease() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'disease' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      lastReportData.disease = r.report;
      renderDiseaseCharts(r.report);
    });
  }

  var loaders = {
    patients: loadPatients,
    consultations: loadConsultations,
    triage: loadTriage,
    referrals: loadReferrals,
    followups: loadFollowups,
    disease: loadDisease,
  };

  var reportsPollTimer = null;
  var REFRESH_MS = (theme() && theme().REFRESH_MS) ? theme().REFRESH_MS : 15000;

  function touchReportsSync() {
    var el = document.getElementById('bhwReportsLastSync');
    if (!el) return;
    el.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function refreshReportsLive() {
    loadSummary().then(function () {
      return loadTab(activeTab);
    }).then(touchReportsSync);
  }

  function startReportsPolling() {
    if (reportsPollTimer) clearInterval(reportsPollTimer);
    reportsPollTimer = setInterval(refreshReportsLive, REFRESH_MS);
  }

  function loadTab(tab) {
    if (loaders[tab]) return loaders[tab]();
    return Promise.resolve();
  }

  function bootReports() {
    if (theme() && typeof theme().registerThemeRefresh === 'function') {
      theme().registerThemeRefresh(function () {
        rerenderActiveTab();
      });
    }

    loadSummary().then(function () {
      return loadTab('patients');
    }).then(touchReportsSync);
    startReportsPolling();
  }

  function exportUrl(fmt) {
    var q = new URLSearchParams(Object.assign({ type: activeTab, format: fmt === 'excel' ? 'excel' : 'csv' }, filters()));
    return base + '/app/api/bhw/export_report.php?' + q.toString();
  }

  ready(function () {
    var root = document.getElementById('bhwReportsRoot');
    if (!root) return;

    whenChartReady().then(function () {
      if (typeof window.BhwPortal === 'undefined') {
        setTimeout(bootReports, 100);
      } else {
        bootReports();
      }
    });

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refreshReportsLive();
    });

    document.querySelectorAll('.bhw-reports-tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        activeTab = btn.dataset.tab;
        document.querySelectorAll('.bhw-reports-tab').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
        document.querySelectorAll('.bhw-reports-panel').forEach(function (p) {
          p.classList.toggle('is-active', p.dataset.panel === activeTab);
        });
        loadTab(activeTab);
      });
    });

    document.getElementById('rf_apply')?.addEventListener('click', function () {
      loadSummary().then(function () { return loadTab(activeTab); }).then(touchReportsSync);
    });

    document.getElementById('rf_reset')?.addEventListener('click', function () {
      ['rf_date_from', 'rf_date_to', 'rf_month', 'rf_year', 'rf_gender', 'rf_age'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
      });
      loadSummary().then(function () { return loadTab(activeTab); }).then(touchReportsSync);
    });

    document.querySelectorAll('[data-export]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var fmt = btn.dataset.export;
        if (fmt === 'print') {
          window.print();
          return;
        }
        var link = document.createElement('a');
        link.href = exportUrl(fmt);
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();
      });
    });
  });
})();
