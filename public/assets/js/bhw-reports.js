(function () {
  var charts = {};
  var activeTab = 'patients';
  var base = document.body.dataset.assetBase || '';

  var SUMMARY_CARDS = [
    ['total_patients', 'Total Registered Patients'],
    ['new_patients_month', 'New Patients This Month'],
    ['male_patients', 'Male Patients'],
    ['female_patients', 'Female Patients'],
    ['senior_citizens', 'Senior Citizens'],
    ['children', 'Children'],
    ['high_risk_patients', 'High-Risk Patients'],
    ['ai_emergency_cases', 'AI Emergency Cases'],
    ['pending_consultations', 'Pending Consultations'],
    ['completed_consultations', 'Completed Consultations'],
    ['cancelled_consultations', 'Cancelled Consultations'],
    ['pending_referrals', 'Pending Referrals'],
    ['completed_referrals', 'Completed Referrals'],
    ['home_visits_completed', 'Home Visits Completed'],
    ['overdue_followups', 'Overdue Follow-ups'],
  ];

  var CHART_COLORS = ['#018a93', '#0369a1', '#0d9488', '#0284c7', '#14b8a6', '#0891b2', '#2dd4bf', '#38bdf8'];

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
        borderWidth: 0,
      }],
    };
  }

  function makeChart(canvasId, type, rows, options) {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    destroyChart(canvasId);
    charts[canvasId] = new Chart(el, {
      type: type,
      data: type === 'line' || type === 'bar' ? {
        labels: (rows || []).map(function (r) { return r.label; }),
        datasets: [{
          label: 'Count',
          data: (rows || []).map(function (r) { return r.value; }),
          borderColor: '#018a93',
          backgroundColor: type === 'line' ? 'rgba(1,138,147,0.15)' : 'rgba(1,138,147,0.7)',
          fill: type === 'line',
          tension: 0.35,
        }],
      } : chartData(rows),
      options: Object.assign({
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { display: type === 'pie' || type === 'doughnut' } },
        scales: type === 'pie' || type === 'doughnut' ? {} : { y: { beginAtZero: true, ticks: { precision: 0 } } },
      }, options || {}),
    });
  }

  function renderSummary(summary) {
    var row = document.getElementById('bhwSummaryRow');
    var skel = document.getElementById('bhwSummarySkeleton');
    if (!row) return;
    row.innerHTML = SUMMARY_CARDS.map(function (pair) {
      var key = pair[0];
      var label = pair[1];
      var val = summary[key] != null ? summary[key] : 0;
      return '<div class="col-6 col-md-4 col-xl-3"><div class="bhw-report-metric">' +
        '<div class="bhw-report-metric-label">' + label + '</div>' +
        '<div class="bhw-report-metric-val">' + val + '</div></div></div>';
    }).join('');
    if (skel) skel.style.display = 'none';
    row.style.display = 'flex';
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
