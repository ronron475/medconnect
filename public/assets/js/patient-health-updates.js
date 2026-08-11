/**
 * Poll for consultation completion and show patient banners without full page reload.
 */
(function (global) {
  'use strict';

  const APP_BASE = global.APP_BASE || global.MC_ASSET_BASE || '';
  const POLL_MS = 10000;
  let sinceTs = Math.floor(Date.now() / 1000) - 30;
  let pollTimer = null;
  const seenCompleted = new Set();

  function ensureBannerHost() {
    let host = document.getElementById('pmh-record-update-banner');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'pmh-record-update-banner';
    host.className = 'pmh-record-banner-host';
    host.setAttribute('aria-live', 'polite');
    const page = document.querySelector('.patient-page, .pdash-page, main');
    if (page && page.parentNode) {
      page.parentNode.insertBefore(host, page);
    } else {
      document.body.insertBefore(host, document.body.firstChild);
    }
    return host;
  }

  function showCompletionBanner(item) {
    if (!item || !item.id || seenCompleted.has(String(item.id))) return;
    seenCompleted.add(String(item.id));

    const host = ensureBannerHost();
    const banner = document.createElement('div');
    banner.className = 'pmh-record-banner';
    banner.innerHTML =
      '<div class="pmh-record-banner__body">' +
      '<strong>Consultation completed</strong>' +
      '<p>Your doctor has finalized your consultation record.</p>' +
      '</div>' +
      '<a class="pmh-btn pmh-btn--primary pmh-record-banner__cta" href="' +
      (item.detail_url || (APP_BASE + '/views/patient/consultation_detail.php?id=' + item.id)) +
      '">View Medical Record</a>';

    host.appendChild(banner);

    const bucket = String(item.final_case_bucket || '').toLowerCase();
    if (window.mcPatientUrgencyModal) {
      if (bucket === 'emergency' && typeof window.mcPatientUrgencyModal.showEmergency === 'function') {
        window.mcPatientUrgencyModal.showEmergency(
          'Your doctor has finalized this consultation as an EMERGENCY. Go to the nearest emergency department or call local emergency services if symptoms worsen.'
        );
      } else if (bucket === 'urgent' && typeof window.mcPatientUrgencyModal.showUrgent === 'function') {
        window.mcPatientUrgencyModal.showUrgent(
          'Your doctor has finalized this consultation as URGENT. Follow your care plan and seek care promptly if symptoms worsen.',
          item.detail_url || ''
        );
      }
    }

    document.dispatchEvent(new CustomEvent('medconnect:consultation-completed', { detail: item }));

    if (typeof global.McNotifications !== 'undefined' && typeof global.McNotifications.poll === 'function') {
      try { global.McNotifications.poll(); } catch (_) {}
    }
  }

  async function pollRecordUpdates() {
    if (!APP_BASE || document.hidden) return;
    try {
      const res = await fetch(
        APP_BASE + '/app/api/consultations/patient_record_updates.php?since=' + sinceTs + '&_=' + Date.now(),
        { credentials: 'same-origin', cache: 'no-store', headers: { 'X-MC-No-Loader': '1' } }
      );
      const json = await res.json();
      if (!json || !json.success) return;

      if (json.server_ts) {
        sinceTs = Math.max(sinceTs, json.server_ts - 5);
      }

      (json.completed || []).forEach(showCompletionBanner);

      const active = json.active || [];
      active.forEach((item) => {
        const el = document.querySelector('[data-consult-status="' + item.id + '"]');
        if (el && item.status) {
          el.textContent = item.status.replace(/_/g, ' ');
          el.dataset.status = item.status;
        }
      });
    } catch (_) {
      /* non-fatal */
    }
  }

  function startPolling() {
    if (!APP_BASE) return;
    pollRecordUpdates();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollRecordUpdates, POLL_MS);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollRecordUpdates();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPolling);
  } else {
    startPolling();
  }

  global.McPatientRecordUpdates = {
    poll: pollRecordUpdates,
    resetSince: function () {
      sinceTs = Math.floor(Date.now() / 1000) - 30;
    },
  };
})(window);
