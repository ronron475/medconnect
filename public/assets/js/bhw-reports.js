(function () {
  var charts = {};
  var activeTab = 'patients';
  var base = document.body.dataset.assetBase || '';

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

  var CHART_COLORS = ['#1d4ed8', '#3b82f6', '#60a5fa', '#2563eb', '#1e40af', '#93c5fd', '#64748b', '#475569'];

  var CHART_FONT = {
    family: "'Inter', 'Segoe UI', system-ui, sans-serif",
    size: 11,
    weight: '500',
  };

  function baseChartOptions(type) {
    var isRing = type === 'pie' || type === 'doughnut';
    return {
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { top: 4, bottom: 4, left: 4, right: 4 } },
      plugins: {
        legend: {
          display: isRing,
          position: 'bottom',
          align: 'center',
          labels: {
            boxWidth: 10,
            boxHeight: 10,
            padding: 14,
            color: '#64748b',
            font: CHART_FONT,
            usePointStyle: true,
            pointStyle: 'rectRounded',
          },
        },
        tooltip: {
          backgroundColor: '#0f172a',
          titleFont: { family: CHART_FONT.family, size: 12, weight: '600' },
          bodyFont: { family: CHART_FONT.family, size: 11 },
          padding: 10,
          cornerRadius: 6,
          displayColors: true,
        },
      },
      scales: isRing ? {} : {
        x: {
          grid: { color: '#f1f5f9', drawBorder: false },
          ticks: { color: '#64748b', font: CHART_FONT, maxRotation: 0 },
        },
        y: {
          beginAtZero: true,
          grid: { color: '#f1f5f9', drawBorder: false },
          ticks: { color: '#64748b', font: CHART_FONT, precision: 0 },
        },
      },
    };
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function filters() {
    return {
      date_from: document.getElementById('rf_date_from')?.value || '',
      date_to: document.getElementById('rf_date_to')?.value || '',
      month: document.getElementById('rf_month')?.value || '',
      year: document.getElementById('rf_year')?.value || '',
      purok: document.getElementById('rf_purok')?.value || '',
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

  function chartData(rows) {
    return {
      labels: (rows || []).map(function (r) { return r.label || '—'; }),
      datasets: [{
        data: (rows || []).map(function (r) { return r.value; }),
        backgroundColor: CHART_COLORS,
        borderColor: '#fff',
        borderWidth: 2,
        hoverOffset: 4,
      }],
    };
  }

  function lineBarData(type, rows) {
    return {
      labels: (rows || []).map(function (r) { return r.label; }),
      datasets: [{
        label: 'Count',
        data: (rows || []).map(function (r) { return r.value; }),
        borderColor: '#1d4ed8',
        backgroundColor: type === 'line' ? 'rgba(29, 78, 216, 0.08)' : 'rgba(59, 130, 246, 0.75)',
        borderWidth: type === 'line' ? 2 : 0,
        fill: type === 'line',
        tension: 0.3,
        borderRadius: type === 'bar' ? 4 : 0,
        maxBarThickness: 36,
      }],
    };
  }

  function makeChart(canvasId, type, rows, options) {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroyChart(canvasId);
    var isRing = type === 'pie' || type === 'doughnut';
    var chartOptions = Object.assign({}, baseChartOptions(type), options || {});
    if (type === 'doughnut' && chartOptions.cutout == null) {
      chartOptions.cutout = '62%';
    }
    charts[canvasId] = new Chart(el, {
      type: type,
      data: isRing ? chartData(rows) : lineBarData(type, rows),
      options: chartOptions,
    });
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

  function fillPuroks(puroks) {
    var sel = document.getElementById('rf_purok');
    if (!sel) return;
    var cur = sel.value;
    sel.innerHTML = '<option value="">All puroks</option>';
    (puroks || []).forEach(function (p) {
      var v = p.purok || p;
      var o = document.createElement('option');
      o.value = v;
      o.textContent = v;
      sel.appendChild(o);
    });
    if (cur) sel.value = cur;
  }

  function loadSummary() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'summary' }, filters())).then(function (r) {
      if (!r.success) return;
      renderSummary(r.summary || {});
      fillPuroks(r.puroks || []);
    });
  }

  function loadPatients() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'patients' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      var rep = r.report;
      makeChart('chart_pat_monthly', 'line', rep.monthly);
      makeChart('chart_pat_gender', 'doughnut', rep.genderDist);
      makeChart('chart_pat_age', 'bar', rep.ageDist);
      makeChart('chart_pat_purok', 'bar', rep.purokDist, { indexAxis: 'y' });
    });
  }

  function loadConsultations() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'consultations' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      var rep = r.report;
      makeChart('chart_con_status', 'pie', rep.by_status);
      makeChart('chart_con_monthly', 'line', rep.monthly_trend);
      makeChart('chart_con_provider', 'bar', rep.provider_dist, { indexAxis: 'y' });
    });
  }

  function loadTriage() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'triage' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      var rep = r.report;
      makeChart('chart_tri_urgency', 'doughnut', [
        { label: 'Low Risk', value: rep.low_risk },
        { label: 'Moderate', value: rep.moderate_risk },
        { label: 'High Risk', value: rep.high_risk },
        { label: 'Emergency', value: rep.emergency },
      ]);
      makeChart('chart_tri_class', 'bar', rep.classifications);
      makeChart('chart_tri_symptoms', 'bar', rep.top_symptoms, { indexAxis: 'y' });
    });
  }

  function loadReferrals() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'referrals' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      var rep = r.report;
      makeChart('chart_ref_type', 'pie', rep.by_type);
      makeChart('chart_ref_status', 'bar', rep.by_status);
    });
  }

  function loadFollowups() {
    return BhwPortal.get('reports.php', Object.assign({ action: 'followups' }, filters())).then(function (r) {
      if (!r.success || !r.report) return;
      var rep = r.report;
      makeChart('chart_fol_overview', 'bar', [
        { label: 'Home Visits', value: rep.homeVisits },
        { label: 'Completed', value: rep.completed },
        { label: 'Pending', value: rep.pending },
        { label: 'Overdue', value: rep.overdue },
      ]);
      makeChart('chart_fol_visits', 'pie', rep.visitTypes);
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
      var rep = r.report;
      makeChart('chart_dis_conditions', 'bar', rep.top_diseases, { indexAxis: 'y' });
      makeChart('chart_dis_symptoms', 'bar', rep.top_symptoms, { indexAxis: 'y' });
      makeChart('chart_dis_age', 'pie', rep.age_groups);
      makeChart('chart_dis_monthly', 'line', rep.monthly_trends);
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

  function loadTab(tab) {
    if (loaders[tab]) loaders[tab]();
  }

  function exportUrl(fmt) {
    var q = new URLSearchParams(Object.assign({ type: activeTab, format: fmt === 'excel' ? 'excel' : 'csv' }, filters()));
    return base + '/app/api/bhw/export_report.php?' + q.toString();
  }

  ready(function () {
    var root = document.getElementById('bhwReportsRoot');
    if (!root) return;

    loadSummary().then(function () { return loadTab('patients'); });

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
      loadSummary().then(function () { return loadTab(activeTab); });
    });

    document.getElementById('rf_reset')?.addEventListener('click', function () {
      ['rf_date_from', 'rf_date_to', 'rf_month', 'rf_year', 'rf_purok', 'rf_gender', 'rf_age'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
      });
      loadSummary().then(function () { return loadTab(activeTab); });
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
