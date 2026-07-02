<!-- Doctor PRC verify / reject modal -->
<div id="doctorVerifyModal" class="admin-action-modal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="admin-action-dialog">
        <div class="admin-action-header">
            <div class="admin-action-icon admin-action-icon--verify" id="doctorVerifyModalIcon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <div class="admin-action-eyebrow" id="doctorVerifyModalEyebrow">PRC Verification</div>
                <h3 class="admin-action-title" id="doctorVerifyModalTitle">Verify doctor?</h3>
            </div>
            <button type="button" class="admin-action-close" id="doctorVerifyModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="admin-action-body">
            <p id="doctorVerifyModalMessage" class="admin-action-message"></p>
            <div id="doctorVerifyFields" class="admin-action-fields">
                <label class="admin-form-label" for="doctorVerifyReason">Reason <span style="color:#dc2626;">*</span></label>
                <textarea id="doctorVerifyReason" class="admin-form-input" rows="3" placeholder="Provide a reason for this action (required for audit)..."></textarea>
            </div>
            <div id="doctorRejectFields" class="admin-action-fields" style="display:none;">
                <label class="admin-form-label" for="doctorRejectNote">Rejection reason</label>
                <textarea id="doctorRejectNote" class="admin-form-input" rows="3" placeholder="Explain why this PRC license was rejected..."></textarea>
            </div>
            <p id="doctorVerifyModalError" class="admin-form-error" style="display:none;"></p>
        </div>
        <div class="admin-action-footer">
            <button type="button" class="mc-btn mc-btn--outline" id="doctorVerifyModalCancel">Cancel</button>
            <button type="button" class="mc-btn mc-btn--primary" id="doctorVerifyModalConfirm">Confirm</button>
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
.admin-action-icon--reject {
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
.admin-action-body {
    padding: 20px 24px;
}
.admin-action-message {
    margin: 0;
    color: #475569;
    font-size: 14px;
    line-height: 1.6;
}
.admin-action-fields textarea {
    resize: vertical;
    min-height: 88px;
    margin-top: 4px;
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
.admin-action-footer .mc-btn--primary.is-danger:hover {
    background: #b91c1c;
}
</style>

<script>
(function () {
    const modal = document.getElementById('doctorVerifyModal');
    if (!modal) return;

    const titleEl = document.getElementById('doctorVerifyModalTitle');
    const messageEl = document.getElementById('doctorVerifyModalMessage');
    const eyebrowEl = document.getElementById('doctorVerifyModalEyebrow');
    const iconEl = document.getElementById('doctorVerifyModalIcon');
    const rejectFields = document.getElementById('doctorRejectFields');
    const verifyFields = document.getElementById('doctorVerifyFields');
    const verifyReason = document.getElementById('doctorVerifyReason');
    const rejectNote = document.getElementById('doctorRejectNote');
    const errorEl = document.getElementById('doctorVerifyModalError');
    const confirmBtn = document.getElementById('doctorVerifyModalConfirm');
    const cancelBtn = document.getElementById('doctorVerifyModalCancel');
    const closeBtn = document.getElementById('doctorVerifyModalClose');
    const verifyApi = <?= json_encode(ASSET_BASE . '/app/api/admin/verify_doctor.php') ?>;

    let pendingAction = null;

    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        pendingAction = null;
        if (errorEl) {
            errorEl.style.display = 'none';
            errorEl.textContent = '';
        }
        if (rejectNote) rejectNote.value = '';
        if (verifyReason) verifyReason.value = '';
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm';
        }
    }

    function showError(msg) {
        if (!errorEl) return;
        errorEl.textContent = msg;
        errorEl.style.display = 'block';
    }

    window.openDoctorVerifyModal = function (userId, name, prc) {
        pendingAction = { type: 'verify', userId: userId, name: name, prc: prc };
        eyebrowEl.textContent = 'PRC Verification';
        titleEl.textContent = 'Verify this doctor?';
        messageEl.innerHTML = 'Confirm that <strong>' + escapeHtml(name) + '</strong> is a licensed doctor with PRC number <strong>' + escapeHtml(prc) + '</strong>. The account will be activated after verification.';
        rejectFields.style.display = 'none';
        if (verifyFields) verifyFields.style.display = 'block';
        iconEl.className = 'admin-action-icon admin-action-icon--verify';
        iconEl.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        confirmBtn.textContent = 'Verify Doctor';
        confirmBtn.classList.remove('is-danger');
        openModal();
        if (verifyReason) verifyReason.focus();
    };

    window.openDoctorRejectModal = function (userId, name) {
        pendingAction = { type: 'reject', userId: userId, name: name };
        eyebrowEl.textContent = 'PRC Verification';
        titleEl.textContent = 'Reject this doctor?';
        messageEl.innerHTML = 'Reject <strong>' + escapeHtml(name) + '</strong> and deactivate the account. A reason is required.';
        rejectFields.style.display = 'block';
        if (verifyFields) verifyFields.style.display = 'none';
        iconEl.className = 'admin-action-icon admin-action-icon--reject';
        iconEl.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
        confirmBtn.textContent = 'Reject Doctor';
        confirmBtn.classList.add('is-danger');
        openModal();
        if (rejectNote) rejectNote.focus();
    };

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    async function submitAction() {
        if (!pendingAction) return;

        let note = '';
        if (pendingAction.type === 'reject') {
            note = (rejectNote && rejectNote.value.trim()) || '';
        } else {
            note = (verifyReason && verifyReason.value.trim()) || '';
        }

        if (!note || note.length < 5) {
            showError('Please provide a detailed reason (at least 5 characters).');
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Please wait...';

        const fd = new FormData();
        fd.append('user_id', pendingAction.userId);
        fd.append('action', pendingAction.type);
        fd.append('note', note);

        try {
            const res = await fetch(verifyApi, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) {
                const param = pendingAction.type === 'verify' ? 'verified=1' : 'rejected=1';
                window.location.href = window.location.pathname + '?role=provider&' + param;
                return;
            }
            showError(data.message || 'Action failed. Please try again.');
            confirmBtn.disabled = false;
            confirmBtn.textContent = pendingAction.type === 'verify' ? 'Verify Doctor' : 'Reject Doctor';
        } catch (e) {
            showError('Network error. Please try again.');
            confirmBtn.disabled = false;
            confirmBtn.textContent = pendingAction.type === 'verify' ? 'Verify Doctor' : 'Reject Doctor';
        }
    }

    confirmBtn.addEventListener('click', submitAction);
    cancelBtn.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
})();
</script>
