(function () {
  'use strict';

  const modal = document.getElementById('auditLogDetailModal');
  if (!modal) return;

  const titleEl = document.getElementById('auditLogDetailTitle');
  const timeEl = document.getElementById('auditLogDetailTime');
  const userEl = document.getElementById('auditLogDetailUser');
  const actionEl = document.getElementById('auditLogDetailAction');
  const descEl = document.getElementById('auditLogDetailDesc');
  const metaWrap = document.getElementById('auditLogDetailMetaWrap');
  const metaEl = document.getElementById('auditLogDetailMeta');
  const ipEl = document.getElementById('auditLogDetailIp');
  const agentWrap = document.getElementById('auditLogDetailAgentWrap');
  const agentEl = document.getElementById('auditLogDetailAgent');
  const closeBtns = modal.querySelectorAll('.js-audit-detail-close');

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function openModal(data) {
    if (!data) return;

    titleEl.textContent = data.action_label || 'Audit Log Entry';
    timeEl.textContent = [data.created_date, data.created_time].filter(Boolean).join(' · ');

    const roleClass = data.role_key && /^[a-z]+$/.test(data.role_key)
      ? ' audit-logs-role--' + data.role_key
      : '';

    userEl.innerHTML =
      '<span class="audit-logs-avatar" aria-hidden="true">' + escapeHtml(data.initials || '?') + '</span>' +
      '<div>' +
        '<div class="audit-logs-user__name">' + escapeHtml(data.user_name) + '</div>' +
        (data.user_email ? '<div class="audit-logs-user__email">' + escapeHtml(data.user_email) + '</div>' : '') +
        '<span class="audit-logs-role' + roleClass + '">' + escapeHtml(data.user_role) + '</span>' +
      '</div>';

    const tone = data.action_tone || 'default';
    actionEl.innerHTML =
      '<span class="audit-logs-action audit-logs-action--' + escapeHtml(tone) + '">' +
        escapeHtml(data.action_label) +
      '</span>';

    descEl.textContent = data.description || '—';

    if (data.meta) {
      metaWrap.hidden = false;
      metaEl.textContent = data.meta;
    } else {
      metaWrap.hidden = true;
      metaEl.textContent = '';
    }

    ipEl.textContent = data.ip_address || '—';

    if (data.user_agent) {
      agentWrap.hidden = false;
      agentEl.textContent = data.user_agent;
    } else {
      agentWrap.hidden = true;
      agentEl.textContent = '';
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    closeBtns[0]?.focus();
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('.js-audit-detail-open').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const raw = btn.getAttribute('data-audit');
      if (!raw) return;
      try {
        openModal(JSON.parse(raw));
      } catch (e) {
        console.error('Invalid audit payload', e);
      }
    });
  });

  closeBtns.forEach(function (btn) {
    btn.addEventListener('click', closeModal);
  });

  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) {
      closeModal();
    }
  });
})();
