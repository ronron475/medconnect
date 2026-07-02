<!-- Account status change confirmation modal (Super Administrator) -->
<div id="accountStatusModal" class="admin-action-modal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="admin-action-dialog">
        <div class="admin-action-header">
            <div class="admin-action-icon admin-action-icon--verify" id="accountStatusModalIcon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div>
                <div class="admin-action-eyebrow">Account Status</div>
                <h3 class="admin-action-title" id="accountStatusModalTitle">Confirm action</h3>
            </div>
            <button type="button" class="admin-action-close" id="accountStatusModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="admin-action-body">
            <p id="accountStatusModalMessage" class="admin-action-message"></p>
            <div class="admin-action-fields">
                <label class="admin-form-label" for="accountStatusReason">Reason <span style="color:#dc2626;">*</span></label>
                <textarea id="accountStatusReason" class="admin-form-input" rows="3" placeholder="Provide a clear reason for this action (required for audit)..."></textarea>
            </div>
            <p id="accountStatusModalError" class="admin-form-error" style="display:none;"></p>
        </div>
        <div class="admin-action-footer">
            <button type="button" class="mc-btn mc-btn--outline" id="accountStatusModalCancel">Cancel</button>
            <button type="button" class="mc-btn mc-btn--primary" id="accountStatusModalConfirm">Confirm</button>
        </div>
    </div>
</div>

<style>
.admin-action-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1100;
    background: rgba(7, 20, 40, 0.55);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    padding: 20px;
    pointer-events: none;
}
.admin-action-modal.is-open {
    display: flex;
    pointer-events: auto;
}
.admin-action-dialog {
    width: min(480px, 100%);
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.2);
    overflow: hidden;
    animation: adminActionIn 0.2s ease;
}
@keyframes adminActionIn {
    from { opacity: 0; transform: translateY(10px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.admin-action-header {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 22px 24px 16px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    position: relative;
}
.admin-action-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.admin-action-icon--verify {
    background: rgba(1, 138, 147, 0.12);
    color: #018a93;
}
.admin-action-icon--danger {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.admin-action-eyebrow {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #018a93;
    margin-bottom: 4px;
}
.admin-action-title {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: #012a4a;
    line-height: 1.25;
    padding-right: 28px;
}
.admin-action-close {
    position: absolute;
    top: 16px;
    right: 18px;
    background: none;
    border: none;
    font-size: 26px;
    line-height: 1;
    color: #94a3b8;
    cursor: pointer;
}
.admin-action-body { padding: 20px 24px; }
.admin-action-message {
    margin: 0 0 16px;
    color: #475569;
    font-size: 14px;
    line-height: 1.6;
}
.admin-action-fields textarea {
    resize: vertical;
    min-height: 88px;
    width: 100%;
    box-sizing: border-box;
}
.admin-action-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 0 24px 22px;
}
.admin-action-footer .mc-btn--primary.is-danger {
    background: #dc2626;
    border-color: #dc2626;
}
.mc-status-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.mc-status-actions .mc-btn {
    padding: 5px 10px;
    font-size: 11px;
}
.mc-status-restricted {
    font-size: 11px;
    color: #64748b;
    max-width: 200px;
    line-height: 1.4;
}
</style>

<script>
(function () {
    const modal = document.getElementById('accountStatusModal');
    if (!modal) return;

    const apiUrl = <?= json_encode($account_status_api ?? (ASSET_BASE . '/app/api/admin/account_status.php')) ?>;
    const titleEl = document.getElementById('accountStatusModalTitle');
    const messageEl = document.getElementById('accountStatusModalMessage');
    const reasonEl = document.getElementById('accountStatusReason');
    const errorEl = document.getElementById('accountStatusModalError');
    const confirmBtn = document.getElementById('accountStatusModalConfirm');
    const cancelBtn = document.getElementById('accountStatusModalCancel');
    const closeBtn = document.getElementById('accountStatusModalClose');
    const iconEl = document.getElementById('accountStatusModalIcon');

    let pending = null;

    const dangerActions = ['deactivate', 'suspend', 'reject', 'archive'];
    const actionLabels = {
        approve: 'Approve',
        activate: 'Activate',
        deactivate: 'Deactivate',
        suspend: 'Suspend',
        reactivate: 'Reactivate',
        reject: 'Reject',
        archive: 'Archive'
    };

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        pending = null;
        if (errorEl) { errorEl.style.display = 'none'; errorEl.textContent = ''; }
        if (reasonEl) reasonEl.value = '';
        if (confirmBtn) { confirmBtn.disabled = false; }
    }

    window.openAccountStatusModal = function (userId, userName, action) {
        pending = { userId: userId, userName: userName, action: action };
        const label = actionLabels[action] || action;
        titleEl.textContent = label + ' account?';
        messageEl.innerHTML = 'You are about to <strong>' + escapeHtml(label.toLowerCase()) + '</strong> the account of <strong>' + escapeHtml(userName) + '</strong>. This action will be recorded in the audit log.';
        confirmBtn.textContent = label;
        confirmBtn.classList.toggle('is-danger', dangerActions.indexOf(action) >= 0);
        iconEl.className = 'admin-action-icon ' + (dangerActions.indexOf(action) >= 0 ? 'admin-action-icon--danger' : 'admin-action-icon--verify');
        openModal();
        if (reasonEl) reasonEl.focus();
    };

    async function submitAction() {
        if (!pending) return;
        const reason = (reasonEl && reasonEl.value.trim()) || '';
        if (reason.length < 5) {
            errorEl.textContent = 'Please provide a detailed reason (at least 5 characters).';
            errorEl.style.display = 'block';
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Please wait...';

        const fd = new FormData();
        fd.append('user_id', pending.userId);
        fd.append('action', pending.action);
        fd.append('reason', reason);

        try {
            const res = await fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
                return;
            }
            errorEl.textContent = data.message || 'Action failed.';
            errorEl.style.display = 'block';
            confirmBtn.disabled = false;
            confirmBtn.textContent = actionLabels[pending.action] || 'Confirm';
        } catch (e) {
            errorEl.textContent = 'Network error. Please try again.';
            errorEl.style.display = 'block';
            confirmBtn.disabled = false;
            confirmBtn.textContent = actionLabels[pending.action] || 'Confirm';
        }
    }

    confirmBtn.addEventListener('click', submitAction);
    cancelBtn.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });

    document.querySelectorAll('.js-account-status-action').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openAccountStatusModal(btn.dataset.userId, btn.dataset.userName || '', btn.dataset.action || '');
        });
    });
})();
</script>
