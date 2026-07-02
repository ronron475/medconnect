<!-- Archived account: details, restore, audit log modals -->
<div id="archivedDetailsModal" class="admin-action-modal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="admin-action-dialog" style="width:min(560px,100%);">
        <div class="admin-action-header">
            <div class="admin-action-icon admin-action-icon--verify">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width:2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <div class="admin-action-eyebrow">Archive Details</div>
                <h3 class="admin-action-title" id="archivedDetailsTitle">Account Details</h3>
            </div>
            <button type="button" class="admin-action-close js-archived-close" aria-label="Close">&times;</button>
        </div>
        <div class="admin-action-body" id="archivedDetailsBody">
            <p class="text-muted">Loading...</p>
        </div>
        <div class="admin-action-footer">
            <button type="button" class="mc-btn mc-btn--outline js-archived-close">Close</button>
        </div>
    </div>
</div>

<div id="archivedRestoreModal" class="admin-action-modal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="admin-action-dialog">
        <div class="admin-action-header">
            <div class="admin-action-icon admin-action-icon--verify">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
            </div>
            <div>
                <div class="admin-action-eyebrow">Restore Account</div>
                <h3 class="admin-action-title">Restore this account?</h3>
            </div>
            <button type="button" class="admin-action-close js-restore-close" aria-label="Close">&times;</button>
        </div>
        <div class="admin-action-body">
            <p class="admin-action-message" id="archivedRestoreMessage">The user will regain access to MEDCONNECT.</p>
            <div class="admin-action-fields">
                <label class="admin-form-label" for="archivedRestoreReason">Restore reason <span class="text-muted">(optional)</span></label>
                <textarea id="archivedRestoreReason" class="admin-form-input" rows="3" placeholder="e.g. Verified legitimate account"></textarea>
            </div>
            <p id="archivedRestoreError" class="admin-form-error" style="display:none;"></p>
        </div>
        <div class="admin-action-footer">
            <button type="button" class="mc-btn mc-btn--outline js-restore-close">Cancel</button>
            <button type="button" class="mc-btn mc-btn--primary" id="archivedRestoreConfirm">Restore Account</button>
        </div>
    </div>
</div>

<div id="archivedAuditModal" class="admin-action-modal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="admin-action-dialog" style="width:min(640px,100%);">
        <div class="admin-action-header">
            <div class="admin-action-icon admin-action-icon--verify">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div>
                <div class="admin-action-eyebrow">Audit History</div>
                <h3 class="admin-action-title" id="archivedAuditTitle">Status Change Log</h3>
            </div>
            <button type="button" class="admin-action-close js-audit-close" aria-label="Close">&times;</button>
        </div>
        <div class="admin-action-body" id="archivedAuditBody" style="max-height:360px;overflow-y:auto;">
            <p class="text-muted">Loading...</p>
        </div>
        <div class="admin-action-footer">
            <button type="button" class="mc-btn mc-btn--outline js-audit-close">Close</button>
        </div>
    </div>
</div>

<style>
.um-archived-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    font-weight: 800;
    color: #334155;
    margin-bottom: 16px;
}
.um-sort-link {
    color: inherit;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.um-sort-link.is-active { color: var(--mc-aqua-medium, #018a93); }
.um-restore-locked {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    font-size: 11px;
    color: #94a3b8;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    background: #f8fafc;
    cursor: not-allowed;
}
.um-filter-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.um-filter-bar select,
.um-filter-bar input[type="text"] {
    background: #fff;
    text-align: left;
    padding: 8px 14px;
    min-width: 160px;
}
.um-audit-item {
    padding: 12px 0;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}
.um-audit-item:last-child { border-bottom: none; }
.um-detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 20px;
    font-size: 13px;
}
.um-detail-grid dt { font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; margin-bottom: 4px; }
.um-detail-grid dd { margin: 0 0 8px; color: #1e293b; }
@media (max-width: 560px) { .um-detail-grid { grid-template-columns: 1fr; } }
</style>

<script>
(function () {
    const apiBase = <?= json_encode(ASSET_BASE . '/app/api/admin/archived_accounts.php') ?>;
    const statusApi = <?= json_encode(ASSET_BASE . '/app/api/admin/account_status.php') ?>;
    const isSuperadmin = <?= $is_superadmin ? 'true' : 'false' ?>;

    function esc(text) {
        const d = document.createElement('div');
        d.textContent = text || '';
        return d.innerHTML;
    }

    function openModal(el) {
        el.classList.add('is-open');
        el.setAttribute('aria-hidden', 'false');
    }
    function closeModal(el) {
        el.classList.remove('is-open');
        el.setAttribute('aria-hidden', 'true');
    }

    const detailsModal = document.getElementById('archivedDetailsModal');
    const restoreModal = document.getElementById('archivedRestoreModal');
    const auditModal = document.getElementById('archivedAuditModal');
    let restoreUserId = null;

    async function fetchJson(url) {
        const res = await fetch(url, { credentials: 'same-origin' });
        return res.json();
    }

    document.querySelectorAll('.js-archived-details').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const id = btn.dataset.userId;
            document.getElementById('archivedDetailsTitle').textContent = btn.dataset.userName || 'Account Details';
            document.getElementById('archivedDetailsBody').innerHTML = '<p class="text-muted">Loading...</p>';
            openModal(detailsModal);
            const data = await fetchJson(apiBase + '?action=details&user_id=' + encodeURIComponent(id));
            if (!data.success) {
                document.getElementById('archivedDetailsBody').innerHTML = '<p class="admin-form-error">' + esc(data.message) + '</p>';
                return;
            }
            const d = data.data;
            document.getElementById('archivedDetailsBody').innerHTML =
                '<dl class="um-detail-grid">' +
                '<div><dt>User</dt><dd>' + esc(d.name) + ' (#USR-' + String(d.id).padStart(5, '0') + ')</dd></div>' +
                '<div><dt>Role</dt><dd>' + esc(d.role_label) + '</dd></div>' +
                '<div><dt>Email</dt><dd>' + esc(d.email) + '</dd></div>' +
                '<div><dt>Status</dt><dd>' + esc(d.account_status) + '</dd></div>' +
                '<div><dt>Archived</dt><dd>' + esc(d.archived_at || '—') + '</dd></div>' +
                '<div><dt>Archived By</dt><dd>' + esc(d.archived_by || '—') + '</dd></div>' +
                '<div style="grid-column:1/-1"><dt>Archive Reason</dt><dd>' + esc(d.archive_reason || '—') + '</dd></div>' +
                (d.restored_at ? '<div><dt>Last Restored</dt><dd>' + esc(d.restored_at) + '</dd></div>' : '') +
                (d.restored_by ? '<div><dt>Restored By</dt><dd>' + esc(d.restored_by) + '</dd></div>' : '') +
                (d.restore_reason ? '<div style="grid-column:1/-1"><dt>Restore Reason</dt><dd>' + esc(d.restore_reason) + '</dd></div>' : '') +
                '</dl>';
        });
    });

    document.querySelectorAll('.js-archived-audit').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const id = btn.dataset.userId;
            document.getElementById('archivedAuditTitle').textContent = 'Audit Log — ' + (btn.dataset.userName || '');
            document.getElementById('archivedAuditBody').innerHTML = '<p class="text-muted">Loading...</p>';
            openModal(auditModal);
            const data = await fetchJson(apiBase + '?action=audit&user_id=' + encodeURIComponent(id));
            if (!data.success || !data.data.logs.length) {
                document.getElementById('archivedAuditBody').innerHTML = '<p class="text-muted">No audit entries found.</p>';
                return;
            }
            document.getElementById('archivedAuditBody').innerHTML = data.data.logs.map(function (log) {
                return '<div class="um-audit-item">' +
                    '<strong>' + esc(log.action) + '</strong> · ' + esc(log.previous_status) + ' → ' + esc(log.new_status) +
                    '<div class="text-muted" style="margin-top:4px;">By ' + esc(log.performed_by) + ' · ' + esc(log.created_at) + ' · IP ' + esc(log.ip_address) + '</div>' +
                    (log.reason ? '<div style="margin-top:6px;">Reason: ' + esc(log.reason) + '</div>' : '') +
                    '</div>';
            }).join('');
        });
    });

    document.querySelectorAll('.js-archived-restore').forEach(function (btn) {
        btn.addEventListener('click', function () {
            restoreUserId = btn.dataset.userId;
            document.getElementById('archivedRestoreMessage').innerHTML =
                'Restore <strong>' + esc(btn.dataset.userName || 'this user') + '</strong>? The user will regain access to MEDCONNECT.';
            document.getElementById('archivedRestoreReason').value = '';
            document.getElementById('archivedRestoreError').style.display = 'none';
            openModal(restoreModal);
        });
    });

    document.getElementById('archivedRestoreConfirm')?.addEventListener('click', async function () {
        if (!restoreUserId) return;
        const reason = document.getElementById('archivedRestoreReason').value.trim();
        if (reason.length > 0 && reason.length < 5) {
            const err = document.getElementById('archivedRestoreError');
            err.textContent = 'Restore reason must be at least 5 characters if provided.';
            err.style.display = 'block';
            return;
        }
        this.disabled = true;
        this.textContent = 'Restoring...';
        const fd = new FormData();
        fd.append('user_id', restoreUserId);
        fd.append('action', 'restore');
        fd.append('reason', reason);
        try {
            const res = await fetch(statusApi, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) {
                window.location.href = window.location.pathname + '?status=active&restored=1';
                return;
            }
            document.getElementById('archivedRestoreError').textContent = data.message || 'Restore failed.';
            document.getElementById('archivedRestoreError').style.display = 'block';
        } catch (e) {
            document.getElementById('archivedRestoreError').textContent = 'Network error.';
            document.getElementById('archivedRestoreError').style.display = 'block';
        }
        this.disabled = false;
        this.textContent = 'Restore Account';
    });

    document.querySelectorAll('.js-archived-close').forEach(function (b) { b.addEventListener('click', function () { closeModal(detailsModal); }); });
    document.querySelectorAll('.js-restore-close').forEach(function (b) { b.addEventListener('click', function () { closeModal(restoreModal); restoreUserId = null; }); });
    document.querySelectorAll('.js-audit-close').forEach(function (b) { b.addEventListener('click', function () { closeModal(auditModal); }); });

    [detailsModal, restoreModal, auditModal].forEach(function (m) {
        if (!m) return;
        m.addEventListener('click', function (e) { if (e.target === m) closeModal(m); });
    });
})();
</script>
