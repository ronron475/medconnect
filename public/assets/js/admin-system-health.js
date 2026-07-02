(function () {
  'use strict';

  const root = document.getElementById('sysHealthRoot');
  if (!root) return;

  const api = root.dataset.api || '';
  const refreshBtn = document.getElementById('sysHealthRefreshBtn');
  const updatedEl = document.getElementById('sysHealthUpdated');
  const overallEl = document.getElementById('sysHealthOverall');
  const overallTitle = document.getElementById('sysHealthOverallTitle');
  const overallSub = document.getElementById('sysHealthOverallSub');
  const servicesEl = document.getElementById('sysHealthServices');
  const metricsEl = document.getElementById('sysHealthMetrics');
  const storageBar = document.getElementById('sysHealthStorageBar');
  const storageMeta = document.getElementById('sysHealthStorageMeta');
  const dbLatencyEl = document.getElementById('sysHealthDbLatency');
  const dbSizeEl = document.getElementById('sysHealthDbSize');
  const backupText = document.getElementById('sysHealthBackupText');
  const backupSub = document.getElementById('sysHealthBackupSub');

  const STATUS_LABELS = {
    online: 'Online',
    healthy: 'Healthy',
    warning: 'Warning',
    critical: 'Critical',
    disabled: 'Disabled',
    unknown: 'Unknown',
    offline: 'Offline',
  };

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function statusLabel(status) {
    return STATUS_LABELS[status] || esc(status);
  }

  function overallTitleText(status) {
    if (status === 'healthy') return 'All Systems Operational';
    if (status === 'warning') return 'Some Systems Need Attention';
    return 'Critical Issues Detected';
  }

  function render(data) {
    if (!data) return;

    const overall = data.overall_status || 'unknown';
    if (overallEl) {
      overallEl.className = 'sys-health-overall sys-health-overall--' + overall;
    }
    if (overallTitle) overallTitle.textContent = overallTitleText(overall);
    if (overallSub) overallSub.textContent = 'Last checked: ' + (data.generated_label || '—');
    if (updatedEl) updatedEl.textContent = 'Updated ' + (data.generated_label || '—');

    if (servicesEl) {
      servicesEl.innerHTML = (data.services || []).map(function (svc) {
        const status = svc.status || 'unknown';
        return (
          '<article class="sys-health-service">' +
          '<div class="sys-health-service__head">' +
          '<h3 class="sys-health-service__label">' + esc(svc.label) + '</h3>' +
          '<span class="sys-health-pill sys-health-pill--' + esc(status) + '">' + esc(statusLabel(status)) + '</span>' +
          '</div>' +
          '<p class="sys-health-service__detail">' + esc(svc.detail || '') + '</p>' +
          '</article>'
        );
      }).join('');
    }

    if (metricsEl) {
      metricsEl.innerHTML = (data.metrics || []).map(function (m) {
        const tone = m.tone && m.tone !== 'neutral' ? ' sys-health-metric__value--' + m.tone : '';
        const unit = m.unit ? '<span class="sys-health-metric__unit">' + esc(m.unit) + '</span>' : '';
        return (
          '<div class="sys-health-metric">' +
          '<div class="sys-health-metric__value' + tone + '">' + esc(String(m.value)) + unit + '</div>' +
          '<div class="sys-health-metric__label">' + esc(m.label) + '</div>' +
          '</div>'
        );
      }).join('');
    }

    const storage = data.storage || {};
    const pct = parseFloat(storage.used_pct) || 0;
    if (storageBar) {
      storageBar.style.width = pct + '%';
      storageBar.className = 'sys-health-progress__bar' +
        (pct >= 90 ? ' sys-health-progress__bar--critical' : pct >= 80 ? ' sys-health-progress__bar--warning' : '');
    }
    if (storageMeta) {
      storageMeta.innerHTML =
        '<span>' + esc(String(storage.used_mb || 0)) + ' MB used</span>' +
        '<span>' + esc(String(storage.free_mb || 0)) + ' MB free</span>';
    }

    const db = data.database || {};
    if (dbLatencyEl) dbLatencyEl.textContent = db.latency_ms != null ? db.latency_ms + ' ms' : '—';
    if (dbSizeEl) dbSizeEl.textContent = (db.size_mb != null ? db.size_mb : '—') + ' MB';

    const backup = data.backup || {};
    if (backupText) backupText.textContent = backup.label || 'No backups logged';
    if (backupSub) {
      backupSub.textContent = backup.filename
        ? 'File: ' + backup.filename
        : 'Run a backup from Super Admin → Backup Management';
    }
  }

  async function refresh() {
    if (refreshBtn) refreshBtn.classList.add('is-loading');
    try {
      const res = await fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const json = await res.json();
      if (json.success && json.data) {
        render(json.data);
      }
    } catch (e) {
      if (overallTitle) overallTitle.textContent = 'Could not refresh health data';
    } finally {
      if (refreshBtn) refreshBtn.classList.remove('is-loading');
    }
  }

  if (refreshBtn) refreshBtn.addEventListener('click', refresh);

  setInterval(refresh, 60000);
})();
