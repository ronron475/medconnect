/**
 * Barangay-first BHW Management hub.
 * List/search barangays → View opens BHW list with docs + approval status.
 * Live refresh on invite / approve / reject / deactivate / reactivate.
 */
(function () {
  'use strict';

  const cfg = window.MC_BHW_APP || {};
  const api = cfg.api || '';
  const accountApi = cfg.accountStatusApi || '';
  const canManage = !!cfg.canManageAccounts;
  const checkerMode = !!cfg.checkerMode;
  const POLL_MS = 15000;

  const hub = document.getElementById('bhwBarangayHub');
  if (!hub || !api) return;

  const listCard = document.getElementById('bhwHubListCard');
  const detailCard = document.getElementById('bhwHubDetail');
  const bodyEl = document.getElementById('bhwBrgyBody');
  const detailBody = document.getElementById('bhwHubDetailBody');
  const searchEl = document.getElementById('bhwBrgySearch');
  const countEl = document.getElementById('bhwBrgyCount');
  const liveEl = document.getElementById('bhwHubLive');
  const docsModal = document.getElementById('bhwHubDocsModal');
  const docsList = document.getElementById('bhwHubDocsList');
  const docsTitle = document.getElementById('bhwHubDocsTitle');
  const docsSub = document.getElementById('bhwHubDocsSub');
  const docsEmpty = document.getElementById('bhwHubDocsEmpty');

  let rows = [];
  let selectedId = 0;
  let detailData = null;
  let inFlight = false;
  let searchQ = '';
  let openDocsItem = null;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtDate(v) {
    if (!v) return '—';
    const d = new Date(String(v).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return esc(String(v));
    return d.toLocaleDateString();
  }

  function setLive(msg) {
    if (liveEl) liveEl.textContent = msg;
  }

  function setTotals(t) {
    t = t || {};
    const map = {
      bhwHubTotal: t.total,
      bhwHubActive: t.active,
      bhwHubPending: t.pending_approval,
      bhwHubInactive: t.inactive,
    };
    Object.keys(map).forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.textContent = map[id] == null ? '—' : String(map[id]);
    });
  }

  function approvalBadge(code, label) {
    const c = String(code || '');
    let cls = 'bhw-approval';
    if (c === 'active') cls += ' bhw-approval--active';
    else if (c === 'pending_approval') cls += ' bhw-approval--pending';
    else if (c === 'rejected' || c === 'deactivated') cls += ' bhw-approval--inactive';
    else cls += ' bhw-approval--other';
    return '<span class="' + cls + '">' + esc(label || c || '—') + '</span>';
  }

  function filteredRows() {
    const q = searchQ.trim().toLowerCase();
    if (!q) return rows.slice();
    return rows.filter(function (r) {
      return String(r.barangay_name || '')
        .toLowerCase()
        .indexOf(q) !== -1;
    });
  }

  function renderList() {
    if (!bodyEl) return;
    const list = filteredRows();
    if (countEl) {
      countEl.textContent = list.length + ' barangay' + (list.length === 1 ? '' : 's');
    }
    if (!list.length) {
      bodyEl.innerHTML =
        '<tr><td colspan="6"><div class="mc-table-empty">No barangays match that name.</div></td></tr>';
      return;
    }
    bodyEl.innerHTML = list
      .map(function (r) {
        const id = Number(r.barangay_id || 0);
        return (
          '<tr>' +
          '<td class="bhw-brgy-col--name" data-label="Barangay"><strong>' +
          esc(r.barangay_name || '—') +
          '</strong></td>' +
          '<td class="bhw-brgy-col--num" data-label="Total">' +
          Number(r.total || 0) +
          '</td>' +
          '<td class="bhw-brgy-col--num" data-label="Active">' +
          Number(r.active || 0) +
          '</td>' +
          '<td class="bhw-brgy-col--num" data-label="Pending">' +
          Number(r.pending_approval || 0) +
          '</td>' +
          '<td class="bhw-brgy-col--num" data-label="Inactive">' +
          Number(r.inactive || 0) +
          '</td>' +
          '<td class="bhw-brgy-col--actions" data-label="Actions">' +
          '<button type="button" class="mc-btn mc-btn--outline mc-btn--sm js-bhw-view-brgy" data-barangay-id="' +
          id +
          '">View</button>' +
          '</td>' +
          '</tr>'
        );
      })
      .join('');
  }

  function docTypeLabel(t) {
    const map = {
      appointment_letter: 'Appointment letter',
      cho_endorsement: 'CHO endorsement',
      government_id: 'Government ID',
      other: 'Other',
    };
    return map[t] || t || 'Document';
  }

  function closeDocsModal() {
    openDocsItem = null;
    if (docsModal) {
      docsModal.style.display = 'none';
      docsModal.classList.remove('is-open');
      docsModal.style.pointerEvents = 'none';
    }
    if (docsList) docsList.innerHTML = '';
    if (docsEmpty) docsEmpty.hidden = true;
  }

  function previewDoc(doc) {
    if (window.MCBhwInvite && typeof window.MCBhwInvite.openDocPreview === 'function') {
      window.MCBhwInvite.openDocPreview({
        id: doc.id,
        name: doc.original_name || 'Document',
        type: docTypeLabel(doc.document_type),
        mime: doc.mime_type || '',
      });
      return;
    }
    const viewUrl = api + '?action=view&document_id=' + encodeURIComponent(String(doc.id));
    window.open(viewUrl, '_blank', 'noopener');
  }

  function openDocsModal(item) {
    if (!docsModal || !docsList) return;
    openDocsItem = item || null;
    const docs = item && Array.isArray(item.documents) ? item.documents : [];
    const name = (item && item.display_name) || 'BHW';

    if (docsTitle) docsTitle.textContent = 'Documents';
    if (docsSub) {
      docsSub.textContent = docs.length
        ? name + ' · ' + docs.length + ' file' + (docs.length === 1 ? '' : 's')
        : name + ' · no files uploaded';
    }

    if (!docs.length) {
      docsList.innerHTML = '';
      if (docsEmpty) docsEmpty.hidden = false;
    } else {
      if (docsEmpty) docsEmpty.hidden = true;
      docsList.innerHTML = docs
        .map(function (d, i) {
          const id = Number(d.id || 0);
          const dlUrl = api + '?action=download&document_id=' + id;
          return (
            '<li class="bhw-doc-list__item">' +
            '<div class="bhw-doc-list__meta">' +
            '<strong>' +
            esc(docTypeLabel(d.document_type)) +
            '</strong>' +
            '<span class="staff-apps-meta">' +
            esc(d.original_name || 'Untitled file') +
            '</span>' +
            '</div>' +
            '<div class="bhw-doc-list__actions">' +
            '<button type="button" class="mc-btn mc-btn--primary mc-btn--sm js-bhw-hub-doc-view" data-doc-idx="' +
            i +
            '">View</button>' +
            '<a class="mc-btn mc-btn--outline mc-btn--sm" href="' +
            esc(dlUrl) +
            '">Download</a>' +
            '</div></li>'
          );
        })
        .join('');
    }

    docsModal.style.display = 'flex';
    docsModal.classList.add('is-open');
    docsModal.style.pointerEvents = 'auto';
  }

  function showDocs(item) {
    if (!item || !Number(item.document_count || 0)) {
      closeDocsModal();
      return;
    }
    openDocsModal(item);
  }

  function renderDetail() {
    if (!detailCard || !detailBody || !detailData) return;
    detailCard.hidden = false;
    if (listCard) listCard.hidden = true;

    const title = document.getElementById('bhwHubDetailTitle');
    const sub = document.getElementById('bhwHubDetailSub');
    if (title) title.textContent = (detailData.barangay_name || 'Barangay') + ' — BHW list';
    const c = detailData.counts || {};
    if (sub) {
      sub.textContent =
        Number(c.total || 0) +
        ' total · ' +
        Number(c.active || 0) +
        ' active · ' +
        Number(c.pending_approval || 0) +
        ' pending · ' +
        Number(c.inactive || 0) +
        ' inactive/deactivated';
    }

    const items = detailData.items || [];
    if (!items.length) {
      detailBody.innerHTML =
        '<tr><td colspan="7"><div class="mc-table-empty">No BHWs assigned to this barangay yet.</div></td></tr>';
      showDocs(null);
      return;
    }

    detailBody.innerHTML = items
      .map(function (item, idx) {
        let actions = '';
        if (checkerMode && item.can_review && item.application_id) {
          actions +=
            '<button type="button" class="mc-btn mc-btn--primary mc-btn--sm js-bhw-hub-review" data-id="' +
            Number(item.application_id) +
            '">Review</button> ';
        }
        if (!checkerMode && item.can_edit_invite && item.application_id) {
          actions +=
            '<button type="button" class="mc-btn mc-btn--outline mc-btn--sm js-bhw-hub-edit" data-id="' +
            Number(item.application_id) +
            '">Edit invite</button> ';
        }
        if (!checkerMode && item.can_resend && item.application_id) {
          actions +=
            '<button type="button" class="mc-btn mc-btn--outline mc-btn--sm js-bhw-hub-edit" data-id="' +
            Number(item.application_id) +
            '">View / Resend</button> ';
        }
        if (item.document_count > 0) {
          actions +=
            '<button type="button" class="mc-btn mc-btn--outline mc-btn--sm js-bhw-hub-docs" data-idx="' +
            idx +
            '">Documents</button> ';
        }
        if (canManage && item.user_id) {
          if (item.can_deactivate) {
            actions +=
              '<button type="button" class="mc-btn mc-btn--outline mc-btn--danger mc-btn--sm js-bhw-hub-deactivate" data-user-id="' +
              Number(item.user_id) +
              '" data-name="' +
              esc(item.display_name || '') +
              '">Deactivate</button> ';
          }
          if (item.can_reactivate) {
            const act =
              String(item.account_status || '').toLowerCase() === 'suspended' ? 'reactivate' : 'activate';
            actions +=
              '<button type="button" class="mc-btn mc-btn--outline mc-btn--success mc-btn--sm js-bhw-hub-reactivate" data-user-id="' +
              Number(item.user_id) +
              '" data-action="' +
              act +
              '" data-name="' +
              esc(item.display_name || '') +
              '">Reactivate</button> ';
          }
        }
        if (!actions) actions = '<span class="staff-apps-meta">—</span>';

        const docCount = Number(item.document_count || 0);
        const docsCell =
          docCount > 0
            ? '<button type="button" class="bhw-hub-doc-count js-bhw-hub-docs" data-idx="' +
              idx +
              '">' +
              docCount +
              ' file' +
              (docCount === 1 ? '' : 's') +
              '</button>'
            : '<span class="staff-apps-meta">0 files</span>';

        return (
          '<tr>' +
          '<td data-label="Name"><strong>' +
          esc(item.display_name || '—') +
          '</strong></td>' +
          '<td data-label="Email"><span class="staff-apps-meta">' +
          esc(item.email || '—') +
          '</span></td>' +
          '<td data-label="Status"><span class="staff-apps-meta">' +
          esc(item.status_label || item.status || '—') +
          '</span></td>' +
          '<td data-label="Documents">' +
          docsCell +
          '</td>' +
          '<td data-label="Appointment"><span class="staff-apps-meta">' +
          fmtDate(item.appointment_date) +
          '</span></td>' +
          '<td data-label="Approval">' +
          approvalBadge(item.approval_status, item.approval_label) +
          '</td>' +
          '<td data-label="Actions">' +
          actions +
          '</td>' +
          '</tr>'
        );
      })
      .join('');
  }

  function showList() {
    selectedId = 0;
    detailData = null;
    closeDocsModal();
    if (detailCard) detailCard.hidden = true;
    if (listCard) listCard.hidden = false;
  }

  async function loadSummary() {
    const res = await fetch(api + '?action=barangay_hub_summary', { credentials: 'same-origin' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Failed to load barangays');
    rows = (json.data && json.data.barangays) || [];
    setTotals((json.data && json.data.totals) || {});
    renderList();
    return json.data;
  }

  async function loadDetail(id) {
    const res = await fetch(api + '?action=barangay_hub_detail&barangay_id=' + encodeURIComponent(String(id)), {
      credentials: 'same-origin',
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Failed to load barangay');
    selectedId = id;
    detailData = json.data;
    renderDetail();
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

  bodyEl?.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-bhw-view-brgy');
    if (!btn) return;
    const id = Number(btn.getAttribute('data-barangay-id') || 0);
    if (!id) return;
    loadDetail(id).catch(function () {
      setLive('Could not open barangay.');
    });
  });

  document.getElementById('bhwHubBackBtn')?.addEventListener('click', showList);

  detailBody?.addEventListener('click', function (e) {
    const docsBtn = e.target.closest('.js-bhw-hub-docs');
    if (docsBtn && detailData) {
      const idx = Number(docsBtn.getAttribute('data-idx') || -1);
      showDocs((detailData.items || [])[idx] || null);
      return;
    }
    const reviewBtn = e.target.closest('.js-bhw-hub-review');
    if (reviewBtn) {
      const id = Number(reviewBtn.getAttribute('data-id') || 0);
      if (window.MCBhwApproval && typeof window.MCBhwApproval.openReview === 'function') {
        window.MCBhwApproval.openReview(id);
      }
      return;
    }
    const editBtn = e.target.closest('.js-bhw-hub-edit');
    if (editBtn) {
      const id = Number(editBtn.getAttribute('data-id') || 0);
      if (window.MCBhwInvite && typeof window.MCBhwInvite.open === 'function') {
        window.MCBhwInvite.open(id);
      } else {
        window.dispatchEvent(new CustomEvent('mc:bhw-open-invite', { detail: { application_id: id } }));
      }
      return;
    }
    const deact = e.target.closest('.js-bhw-hub-deactivate');
    if (deact) {
      changeAccount(Number(deact.getAttribute('data-user-id')), 'deactivate', deact.getAttribute('data-name'));
      return;
    }
    const react = e.target.closest('.js-bhw-hub-reactivate');
    if (react) {
      changeAccount(
        Number(react.getAttribute('data-user-id')),
        react.getAttribute('data-action') || 'activate',
        react.getAttribute('data-name')
      );
    }
  });

  docsList?.addEventListener('click', function (e) {
    const viewBtn = e.target.closest('.js-bhw-hub-doc-view');
    if (!viewBtn || !openDocsItem) return;
    const idx = Number(viewBtn.getAttribute('data-doc-idx') || -1);
    const doc = (openDocsItem.documents || [])[idx];
    if (doc) previewDoc(doc);
  });

  document.getElementById('bhwHubDocsClose')?.addEventListener('click', closeDocsModal);
  document.getElementById('bhwHubDocsDone')?.addEventListener('click', closeDocsModal);
  docsModal?.addEventListener('click', function (e) {
    if (e.target === docsModal) closeDocsModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && docsModal && docsModal.classList.contains('is-open')) {
      closeDocsModal();
    }
  });

  searchEl?.addEventListener('input', function () {
    searchQ = searchEl.value || '';
    renderList();
  });

  window.addEventListener('mc:bhw-hub-refresh', function (ev) {
    const bid = parseInt(String((ev && ev.detail && ev.detail.barangay_id) || 0), 10) || 0;
    if (bid > 0) {
      selectedId = bid;
    }
    refresh();
  });

  document.addEventListener('medconnect:live-sync', function (ev) {
    const changed = (ev && ev.detail && ev.detail.changed) || [];
    if (changed.indexOf('staff_applications') !== -1 || changed.indexOf('dashboard') !== -1) {
      refresh();
    }
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refresh();
  });

  window.MCBhwBarangayHub = { refresh: refresh, openBarangay: loadDetail };

  refresh();
  window.setInterval(function () {
    if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 8000) {
      return;
    }
    refresh();
  }, POLL_MS);
})();
