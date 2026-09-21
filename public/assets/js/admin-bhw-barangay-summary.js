/**
 * Barangay BHW Summary — live counts + barangay detail (Admin / Super Admin hub).
 * Polls existing bhw_applications API; Super Admin-only deactivate/reactivate via account_status.
 */
(function () {
  'use strict';

  const cfg = window.MC_BHW_APP || {};
  const api = cfg.api || '';
  const accountApi = cfg.accountStatusApi || '';
  const canManage = !!cfg.canManageAccounts;
  const POLL_MS = 15000;

  const selectEl = document.getElementById('bhwBrgySelect');
  const bodyEl = document.getElementById('bhwBrgyBody');
  const detailEl = document.getElementById('bhwBrgyDetail');
  const detailBody = document.getElementById('bhwBrgyDetailBody');
  const detailTitle = document.getElementById('bhwBrgyDetailTitle');
  const detailSub = document.getElementById('bhwBrgyDetailSub');
  const pendingWrap = document.getElementById('bhwBrgyPendingWrap');
  const pendingList = document.getElementById('bhwBrgyPendingList');
  const liveHint = document.getElementById('bhwBrgyLiveHint');

  if (!api || !document.getElementById('bhwBrgySummary')) {
    return;
  }

  let selectedId = 0;
  let pollTimer = null;
  let inFlight = false;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtTime(v) {
    if (!v) return '—';
    const d = new Date(String(v).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return esc(String(v));
    return d.toLocaleString();
  }

  function setLive(msg) {
    if (liveHint) liveHint.textContent = msg;
  }

  function setTotals(t) {
    t = t || {};
    const map = {
      bhwSumTotal: t.total,
      bhwSumOnline: t.online,
      bhwSumOffline: t.offline,
      bhwSumPending: t.pending_approval,
    };
    Object.keys(map).forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.textContent = map[id] == null ? '—' : String(map[id]);
    });
  }

  function presenceBadge(presence, label) {
    const p = String(presence || '');
    const cls =
      p === 'online'
        ? 'bhw-presence bhw-presence--online'
        : p === 'offline'
          ? 'bhw-presence bhw-presence--offline'
          : 'bhw-presence bhw-presence--deactivated';
    return '<span class="' + cls + '">' + esc(label || p) + '</span>';
  }

  function fillSelect(rows) {
    if (!selectEl) return;
    const cur = String(selectedId || selectEl.value || '');
    selectEl.innerHTML = '<option value="">All barangays overview…</option>';
    (rows || []).forEach(function (r) {
      const opt = document.createElement('option');
      opt.value = String(r.barangay_id);
      const pending = Number(r.pending_approval || 0);
      opt.textContent =
        (r.barangay_name || 'Barangay') +
        ' · ' +
        Number(r.total || 0) +
        ' BHW' +
        (pending ? ' · ' + pending + ' pending' : '');
      selectEl.appendChild(opt);
    });
    if (cur) selectEl.value = cur;
  }

  function renderSummaryRows(rows) {
    if (!bodyEl) return;
    if (!rows || !rows.length) {
      bodyEl.innerHTML =
        '<tr><td colspan="6"><div class="mc-table-empty">No barangays found.</div></td></tr>';
      return;
    }
    bodyEl.innerHTML = rows
      .map(function (r) {
        const id = Number(r.barangay_id || 0);
        const selected = id === selectedId ? ' bhw-brgy-row--selected' : '';
        return (
          '<tr class="bhw-brgy-row' +
          selected +
          '" data-barangay-id="' +
          id +
          '" tabindex="0" role="button" aria-label="View BHWs in ' +
          esc(r.barangay_name || '') +
          '">' +
          '<td data-label="Barangay"><strong>' +
          esc(r.barangay_name || '—') +
          '</strong></td>' +
          '<td data-label="Total">' +
          Number(r.total || 0) +
          '</td>' +
          '<td data-label="Online">' +
          Number(r.online || 0) +
          '</td>' +
          '<td data-label="Offline">' +
          Number(r.offline || 0) +
          '</td>' +
          '<td data-label="Deactivated">' +
          Number(r.deactivated || 0) +
          '</td>' +
          '<td data-label="Pending">' +
          Number(r.pending_approval || 0) +
          '</td>' +
          '</tr>'
        );
      })
      .join('');
  }

  function renderDetail(data) {
    if (!detailEl || !detailBody) return;
    if (!data || !selectedId) {
      detailEl.hidden = true;
      return;
    }
    detailEl.hidden = false;
    if (detailTitle) {
      detailTitle.textContent = (data.barangay_name || 'Barangay') + ' — Assigned BHWs';
    }
    const c = data.counts || {};
    if (detailSub) {
      detailSub.textContent =
        Number(c.total || 0) +
        ' assigned · ' +
        Number(c.online || 0) +
        ' online · ' +
        Number(c.offline || 0) +
        ' offline · ' +
        Number(c.deactivated || 0) +
        ' deactivated · ' +
        Number(c.pending_approval || 0) +
        ' pending';
    }

    const bhws = data.bhws || [];
    if (!bhws.length) {
      detailBody.innerHTML =
        '<tr><td colspan="6"><div class="mc-table-empty">No BHW accounts assigned to this barangay yet.</div></td></tr>';
    } else {
      detailBody.innerHTML = bhws
        .map(function (b) {
          const uid = Number(b.user_id || 0);
          let actions = '<span class="text-muted">—</span>';
          if (canManage && uid > 0) {
            if (b.can_deactivate) {
              actions =
                '<button type="button" class="mc-btn mc-btn--outline mc-btn--danger mc-btn--sm js-bhw-deactivate" data-user-id="' +
                uid +
                '" data-name="' +
                esc(b.display_name || '') +
                '">Deactivate</button>';
            } else if (b.can_reactivate) {
              const reactAction =
                String(b.account_status || '').toLowerCase() === 'suspended' ? 'reactivate' : 'activate';
              actions =
                '<button type="button" class="mc-btn mc-btn--outline mc-btn--success mc-btn--sm js-bhw-reactivate" data-user-id="' +
                uid +
                '" data-action="' +
                reactAction +
                '" data-name="' +
                esc(b.display_name || '') +
                '">Reactivate</button>';
            }
          } else if (!canManage) {
            actions = '<span class="staff-apps-meta">Super Admin only</span>';
          }
          return (
            '<tr>' +
            '<td data-label="BHW"><strong>' +
            esc(b.display_name || '—') +
            '</strong></td>' +
            '<td data-label="Contact"><span class="staff-apps-meta">' +
            esc(b.email || '—') +
            (b.phone ? '<br>' + esc(b.phone) : '') +
            '</span></td>' +
            '<td data-label="Presence">' +
            presenceBadge(b.presence, b.presence_label) +
            '</td>' +
            '<td data-label="Account"><span class="staff-apps-meta">' +
            esc(b.account_status || (b.is_active ? 'active' : 'inactive')) +
            '</span></td>' +
            '<td data-label="Last activity"><span class="staff-apps-meta">' +
            fmtTime(b.last_activity) +
            '</span></td>' +
            '<td data-label="Actions">' +
            actions +
            '</td>' +
            '</tr>'
          );
        })
        .join('');
    }

    const pending = data.pending || [];
    if (pendingWrap && pendingList) {
      if (!pending.length) {
        pendingWrap.hidden = true;
        pendingList.innerHTML = '';
      } else {
        pendingWrap.hidden = false;
        pendingList.innerHTML = pending
          .map(function (p) {
            return (
              '<li><strong>' +
              esc(p.display_name || '—') +
              '</strong> · ' +
              esc(p.email || '') +
              ' · Pending Approval</li>'
            );
          })
          .join('');
      }
    }
  }

  async function loadSummary() {
    const res = await fetch(api + '?action=barangay_summary', { credentials: 'same-origin' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Failed to load summary');
    const rows = (json.data && json.data.barangays) || [];
    setTotals((json.data && json.data.totals) || {});
    fillSelect(rows);
    renderSummaryRows(rows);
    return json.data;
  }

  async function loadDetail(id) {
    if (!id) {
      renderDetail(null);
      return null;
    }
    const res = await fetch(api + '?action=barangay_detail&barangay_id=' + encodeURIComponent(String(id)), {
      credentials: 'same-origin',
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Failed to load barangay');
    renderDetail(json.data);
    return json.data;
  }

  async function refresh() {
    if (inFlight) return;
    inFlight = true;
    try {
      await loadSummary();
      if (selectedId) await loadDetail(selectedId);
      setLive('Live · updated ' + new Date().toLocaleTimeString());
    } catch (e) {
      setLive('Could not refresh — retrying…');
    } finally {
      inFlight = false;
    }
  }

  async function changeAccount(userId, action, name) {
    if (!canManage || !accountApi || !userId) return;
    const verb = action === 'deactivate' ? 'deactivate' : 'reactivate';
    const reason = window.prompt(
      (action === 'deactivate' ? 'Deactivate' : 'Reactivate') +
        ' BHW ' +
        (name || '') +
        '? Enter a reason (required):'
    );
    if (reason == null) return;
    if (!String(reason).trim()) {
      window.alert('A reason is required.');
      return;
    }
    const fd = new FormData();
    fd.append('user_id', String(userId));
    fd.append('action', action);
    fd.append('reason', String(reason).trim());
    const res = await fetch(accountApi, { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (!json.success) {
      window.alert(json.message || 'Action failed.');
      return;
    }
    await refresh();
  }

  function selectBarangay(id) {
    selectedId = Number(id) || 0;
    if (selectEl) selectEl.value = selectedId ? String(selectedId) : '';
    loadDetail(selectedId).catch(function () {
      setLive('Could not load barangay detail.');
    });
    // Re-render row highlight from last summary body classes
    if (bodyEl) {
      bodyEl.querySelectorAll('.bhw-brgy-row').forEach(function (tr) {
        const rid = Number(tr.getAttribute('data-barangay-id') || 0);
        tr.classList.toggle('bhw-brgy-row--selected', rid === selectedId);
      });
    }
  }

  selectEl?.addEventListener('change', function () {
    selectBarangay(selectEl.value);
  });

  bodyEl?.addEventListener('click', function (e) {
    const tr = e.target.closest('.bhw-brgy-row');
    if (!tr) return;
    selectBarangay(tr.getAttribute('data-barangay-id'));
  });

  bodyEl?.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const tr = e.target.closest('.bhw-brgy-row');
    if (!tr) return;
    e.preventDefault();
    selectBarangay(tr.getAttribute('data-barangay-id'));
  });

  detailBody?.addEventListener('click', function (e) {
    const deact = e.target.closest('.js-bhw-deactivate');
    if (deact) {
      changeAccount(Number(deact.getAttribute('data-user-id')), 'deactivate', deact.getAttribute('data-name'));
      return;
    }
    const react = e.target.closest('.js-bhw-reactivate');
    if (react) {
      const act = react.getAttribute('data-action') || 'activate';
      changeAccount(Number(react.getAttribute('data-user-id')), act, react.getAttribute('data-name'));
    }
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refresh();
  });

  refresh();
  pollTimer = window.setInterval(refresh, POLL_MS);
})();
