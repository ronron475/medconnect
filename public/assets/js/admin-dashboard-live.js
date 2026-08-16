/**
 * MedConnect — Admin / Super Admin dashboard live refresh
 * Polls /app/api/admin/dashboard_live.php (metrics + tables).
 * Charts already refresh via admin-dashboard-charts.js.
 */
(function (global) {
  'use strict';

  if (global.MedConnectAdminDashboardLive) return;

  var API_PATH = '/app/api/admin/dashboard_live.php';
  var POLL_MS = (global.McChartTheme && global.McChartTheme.REFRESH_MS) || 15000;
  var lastFingerprint = '';
  var timer = null;
  var inFlight = false;

  function assetBase() {
    return (document.body && document.body.dataset.assetBase) || '';
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function setText(sel, value) {
    document.querySelectorAll(sel).forEach(function (el) {
      el.textContent = String(value);
    });
  }

  function touchSync(updatedAt) {
    document.querySelectorAll('[data-live-sync]').forEach(function (el) {
      var t = updatedAt ? new Date(updatedAt) : new Date();
      if (Number.isNaN(t.getTime())) t = new Date();
      el.textContent = 'Updated ' + t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    });
  }

  function roleClass(role) {
    if (role === 'patient') return 'adm-role-badge--patient';
    if (role === 'provider') return 'adm-role-badge--provider';
    if (role === 'admin') return 'adm-role-badge--admin';
    return 'adm-role-badge--default';
  }

  function updateAdmin(payload) {
    var m = payload.metrics || {};
    setText('[data-live-metric="patients"]', m.patients || 0);
    setText('[data-live-metric="total_users"]', (m.total_users || 0) + ' total users');
    setText('[data-live-metric="providers"]', m.providers || 0);
    setText('[data-live-metric="bhw"]', (m.bhw || 0) + ' BHW active');
    setText('[data-live-metric="consults_today"]', m.consults_today || 0);
    setText('[data-live-metric="active_sessions"]', (m.active_sessions || 0) + ' in session now');
    setText('[data-live-metric="urgent_triage"]', m.urgent_triage || 0);

    var q = payload.queue || {};
    var queueHost = document.querySelector('[data-live-maker-queue]');
    if (queueHost) {
      var parts = [];
      if (q.draft_doctor > 0) {
        parts.push(
          '<a href="' + esc(assetBase()) + '/views/admin/doctor_applications.php" class="adm-pending-item">' +
          '<span>' + q.draft_doctor + ' Doctor application' + (q.draft_doctor === 1 ? '' : 's') + ' in progress</span>' +
          '<span class="adm-pending-badge">Draft</span></a>'
        );
      }
      if (q.draft_bhw > 0) {
        parts.push(
          '<a href="' + esc(assetBase()) + '/views/admin/bhw_applications.php" class="adm-pending-item">' +
          '<span>' + q.draft_bhw + ' BHW application' + (q.draft_bhw === 1 ? '' : 's') + ' in progress</span>' +
          '<span class="adm-pending-badge">Draft</span></a>'
        );
      }
      if (q.pending_doctor > 0) {
        parts.push(
          '<div class="adm-pending-item adm-pending-item--info">' +
          '<span>' + q.pending_doctor + ' Doctor' + (q.pending_doctor === 1 ? '' : 's') + ' pending Super Admin approval</span></div>'
        );
      }
      if (q.pending_bhw > 0) {
        parts.push(
          '<div class="adm-pending-item adm-pending-item--info">' +
          '<span>' + q.pending_bhw + ' BHW' + (q.pending_bhw === 1 ? '' : 's') + ' pending Super Admin approval</span></div>'
        );
      }
      var card = queueHost.closest('[data-live-maker-card]');
      if (parts.length === 0) {
        if (card) card.hidden = true;
        queueHost.innerHTML = '';
      } else {
        if (card) card.hidden = false;
        queueHost.innerHTML = parts.join('');
      }
    }

    var tbody = document.querySelector('[data-live-recent-users]');
    if (tbody) {
      var users = payload.recent_users || [];
      if (!users.length) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:32px;color:#94a3b8;">No registrations yet.</td></tr>';
      } else {
        tbody.innerHTML = users.map(function (u) {
          return '<tr><td><div class="adm-user-cell">' +
            '<div class="adm-user-avatar">' + esc(u.initials || 'U') + '</div>' +
            '<div><div class="adm-user-name">' + esc(u.name || '') + '</div>' +
            '<div class="adm-user-email">' + esc(u.email || '') + '</div></div></div></td>' +
            '<td><span class="adm-role-badge ' + roleClass(u.role) + '">' + esc(u.role_label || u.role || '') + '</span></td>' +
            '<td><span class="adm-status-badge ' + (u.is_active ? 'adm-status-badge--active' : 'adm-status-badge--inactive') + '">' +
            '<span class="adm-status-dot"></span>' + (u.is_active ? 'Active' : 'Inactive') + '</span></td>' +
            '<td class="adm-date-cell">' + esc(u.joined || '—') + '</td></tr>';
        }).join('');
      }
    }
  }

  function updateSuperadmin(payload) {
    var m = payload.metrics || {};
    setText('[data-live-metric="patients"]', m.patients || 0);
    setText('[data-live-metric="providers"]', m.providers || 0);
    setText('[data-live-metric="consultations"]', m.consultations || 0);
    setText('[data-live-metric="emergency_cases"]', m.emergency_cases || 0);
    setText('[data-live-metric="barangays"]', m.barangays || 0);
    setText('[data-live-metric="facilities"]', m.facilities || 0);
    setText('[data-live-metric="failed24h"]', m.failed24h || 0);
    setText('[data-live-metric="active_sessions"]', m.active_sessions || 0);

    var emergEl = document.querySelector('[data-live-metric="emergency_cases"]');
    if (emergEl) {
      emergEl.style.color = (m.emergency_cases > 0) ? '#ef233c' : '';
    }

    var health = String(m.system_health || 'healthy');
    var healthPill = document.querySelector('[data-live-system-health]');
    if (healthPill) {
      healthPill.className = 'superadmin-health-pill superadmin-health-pill--' +
        (health === 'critical' ? 'critical' : (health === 'warning' ? 'warning' : 'healthy'));
      healthPill.textContent = 'System ' + health.toUpperCase();
    }

    var a = payload.approvals || {};
    var strip = document.querySelector('[data-live-approval-strip]');
    if (strip) {
      var total = Number(a.total || 0);
      strip.hidden = total <= 0;
      var html = '';
      if (a.doctor > 0) {
        html += '<a href="' + esc(assetBase()) + '/views/superadmin/doctor_approvals.php" class="superadmin-approval-card">' +
          '<strong>' + a.doctor + '</strong><span>Doctor' + (a.doctor === 1 ? '' : 's') + ' awaiting approval</span></a>';
      }
      if (a.bhw > 0) {
        html += '<a href="' + esc(assetBase()) + '/views/superadmin/bhw_approvals.php" class="superadmin-approval-card">' +
          '<strong>' + a.bhw + '</strong><span>BHW' + (a.bhw === 1 ? '' : 's') + ' awaiting approval</span></a>';
      }
      strip.innerHTML = html;
    }

    var bannerPending = document.querySelector('[data-live-pending-pill]');
    if (bannerPending) {
      var tot = Number(a.total || 0);
      bannerPending.hidden = tot <= 0;
      bannerPending.textContent = tot + ' approval' + (tot === 1 ? '' : 's') + ' pending';
    }

    setText('[data-live-badge-doctor]', a.doctor || '');
    setText('[data-live-badge-bhw]', a.bhw || '');
    document.querySelectorAll('[data-live-badge-doctor-wrap]').forEach(function (el) {
      el.hidden = !(a.doctor > 0);
    });
    document.querySelectorAll('[data-live-badge-bhw-wrap]').forEach(function (el) {
      el.hidden = !(a.bhw > 0);
    });

    var actBody = document.querySelector('[data-live-activities]');
    if (actBody) {
      var acts = payload.activities || [];
      actBody.innerHTML = acts.length
        ? acts.map(function (row) {
            return '<tr><td>' + esc(row.user) + '</td><td><code class="text-xs">' + esc(row.action) +
              '</code></td><td>' + esc(row.module) + '</td><td class="adm-date-cell">' + esc(row.time) + '</td></tr>';
          }).join('')
        : '<tr><td colspan="4" style="text-align:center;padding:28px;color:#94a3b8;">No recent activities.</td></tr>';
    }

    var loginBody = document.querySelector('[data-live-logins]');
    if (loginBody) {
      var logs = payload.logins || [];
      loginBody.innerHTML = logs.length
        ? logs.map(function (row) {
            return '<tr><td>' + esc(row.user) + '</td><td><span class="adm-role-badge adm-role-badge--default">' +
              esc(row.role) + '</span></td><td class="text-xs">' + esc(row.ip) +
              '</td><td class="adm-date-cell">' + esc(row.time) + '</td></tr>';
          }).join('')
        : '<tr><td colspan="4" style="text-align:center;padding:28px;color:#94a3b8;">No login events recorded.</td></tr>';
    }

    var healthHost = document.querySelector('[data-live-health]');
    if (healthHost) {
      healthHost.innerHTML = (payload.health || []).map(function (svc) {
        return '<div class="flex-between" style="padding:8px 0;border-bottom:1px solid #f1f5f9;">' +
          '<span class="text-sm">' + esc(svc.label) + '</span>' +
          '<span class="superadmin-health-pill superadmin-health-pill--' + esc(svc.pill) + '">' + esc(svc.status) + '</span></div>';
      }).join('');
    }
  }

  function fingerprint(payload) {
    try {
      var copy = Object.assign({}, payload);
      delete copy.updated_at;
      return JSON.stringify(copy);
    } catch (e) {
      return String(Date.now());
    }
  }

  function apply(payload) {
    if (!payload || !payload.success) return;
    var fp = fingerprint(payload);
    if (fp === lastFingerprint) {
      touchSync(payload.updated_at);
      return;
    }
    lastFingerprint = fp;
    if (payload.scope === 'superadmin') updateSuperadmin(payload);
    else updateAdmin(payload);
    touchSync(payload.updated_at);
    if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
  }

  async function refresh() {
    if (inFlight || document.hidden) return;
    inFlight = true;
    try {
      var res = await fetch(assetBase() + API_PATH + '?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      apply(await res.json());
    } catch (e) {
      // quiet
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timer) return;
    refresh();
    timer = global.setInterval(refresh, POLL_MS);
  }

  function stop() {
    if (!timer) return;
    global.clearInterval(timer);
    timer = null;
  }

  function boot() {
    if (!document.querySelector('[data-live-dashboard="admin"], [data-live-dashboard="superadmin"]')) return;
    start();
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop();
      else start();
    });
    document.addEventListener('medconnect:live-sync', function (ev) {
      var changed = (ev.detail && ev.detail.changed) || [];
      if (changed.indexOf('dashboard') !== -1 || changed.indexOf('queue') !== -1 || changed.indexOf('triage') !== -1) {
        refresh();
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  global.MedConnectAdminDashboardLive = { refresh: refresh, start: start, stop: stop };
})(window);
