<?php
/**
 * Super Admin Doctor approval review modal (Checker workflow).
 * Included from Doctors hub when the portal shell is Super Admin.
 */
?>
<div id="doctorReviewModal" class="admin-modal-overlay mc-staff-modal staff-approval-modal" style="display:none;" role="dialog" aria-modal="true">
    <div class="mc-card admin-modal-dialog admin-modal-dialog--wide">
        <div class="admin-modal-header">
            <div>
                <h3 class="admin-modal-title" id="doctorReviewTitle">Review Doctor Application</h3>
                <p class="admin-modal-subtitle">Complete the approval checklist after verifying PRC credentials and supporting documents.</p>
            </div>
            <button type="button" class="admin-modal-close" id="doctorReviewClose" aria-label="Close">&times;</button>
        </div>
        <div class="admin-modal-body">
        <div id="doctorReviewContent"></div>
        <div class="bhw-checklist" id="doctorChecklist">
            <h4 class="admin-form-section-title">Approval Checklist</h4>
            <label class="bhw-check-item"><input type="checkbox" id="check_prc_verified"> PRC License was verified</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_prc_id"> PRC ID matches applicant</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_gov_id"> Government ID matches applicant</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_license_active"> License Status is Active</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_license_expiry"> License is not expired</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_profession"> Profession is Physician</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_facility"> Hospital/Clinic information is valid</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_email"> Email address is correct</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_no_dup_prc"> No duplicate PRC License exists</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_no_dup_doctor"> No duplicate Doctor account exists</label>
        </div>
        <p id="doctorReviewError" class="admin-form-error"></p>
        <div class="admin-modal-actions">
            <button type="button" class="mc-btn mc-btn--outline" id="doctorRequestDocsBtn">Request Additional Documents</button>
            <button type="button" class="mc-btn mc-btn--outline bhw-btn-reject" id="doctorRejectBtn">Reject</button>
            <button type="button" class="mc-btn mc-btn--primary" id="doctorApproveBtn" disabled>Approve &amp; Activate</button>
        </div>
        </div>
    </div>
</div>
