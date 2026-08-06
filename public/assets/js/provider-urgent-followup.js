/**
 * Provider urgent follow-up queue — accept / start video actions.
 */
(function () {
  'use strict';

  const APP_BASE = window.APP_BASE || '';

  function getCsrfToken() {
    const body = document.body;
    if (body && body.dataset && body.dataset.csrf) {
      return body.dataset.csrf;
    }
    const root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset && root.dataset.csrf) {
      return root.dataset.csrf;
    }
    return '';
  }

  async function acceptUrgentFollowup(caseId, startVideo) {
    const csrf = getCsrfToken();
    if (!csrf) {
      window.alert('Security token missing. Please refresh the page.');
      return;
    }

    const label = startVideo ? 'start video consultation for' : 'accept';
    if (!window.confirm('Are you sure you want to ' + label + ' this urgent follow-up?')) {
      return;
    }

    const fd = new FormData();
    fd.set('case_id', String(caseId));
    fd.set('start_video', startVideo ? '1' : '0');
    fd.set('csrf_token', csrf);

    try {
      const res = await fetch(APP_BASE + '/app/api/provider/accept_urgent_followup.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      });
      const data = await res.json();
      if (!data.success) {
        window.alert(data.message || 'Unable to accept follow-up.');
        return;
      }
      const url = data.session_url
        ? (APP_BASE + data.session_url)
        : (APP_BASE + '/views/provider/consultation_session.php?id=' + (data.consultation_id || ''));
      window.location.href = url;
    } catch (err) {
      window.alert('Network error. Please try again.');
    }
  }

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.uf-accept-btn');
    if (!btn) return;
    e.preventDefault();
    const caseId = parseInt(btn.getAttribute('data-case-id') || '0', 10);
    const startVideo = btn.getAttribute('data-start-video') === '1';
    if (caseId > 0) {
      acceptUrgentFollowup(caseId, startVideo);
    }
  });
})();
