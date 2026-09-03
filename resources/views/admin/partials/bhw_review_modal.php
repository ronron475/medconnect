<?php
/**
 * Super Admin BHW approval review modal (Checker workflow).
 * Included from BHW Management hub when the portal shell is Super Admin.
 */
?>
<div id="bhwReviewModal" class="admin-modal-overlay mc-staff-modal staff-approval-modal" style="display:none;" role="dialog" aria-modal="true">
    <div class="mc-card admin-modal-dialog admin-modal-dialog--wide">
        <div class="admin-modal-header">
            <div>
                <h3 class="admin-modal-title" id="bhwReviewTitle">Review BHW Application</h3>
                <p class="admin-modal-subtitle">Complete the approval checklist after verifying documents and identity.</p>
            </div>
            <button type="button" class="admin-modal-close" id="bhwReviewClose" aria-label="Close">&times;</button>
        </div>
        <div class="admin-modal-body">
        <div id="bhwReviewContent"></div>
        <div class="bhw-checklist" id="bhwChecklist">
            <h4 class="admin-form-section-title">Approval Checklist</h4>
            <label class="bhw-check-item"><input type="checkbox" id="check_identity"> Identity verified</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_barangay"> Barangay assignment confirmed</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_appointment"> Appointment letter or resolution verified</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_government_id"> Government-issued ID verified</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_cho"> CHO endorsement verified <span class="text-muted">(if applicable)</span></label>
            <label class="bhw-check-item"><input type="checkbox" id="check_no_duplicate"> No duplicate BHW record exists</label>
        </div>
        <p id="bhwReviewError" class="admin-form-error"></p>
        <div class="admin-modal-actions">
            <button type="button" class="mc-btn mc-btn--outline" id="bhwRequestDocsBtn">Request Additional Documents</button>
            <button type="button" class="mc-btn mc-btn--outline bhw-btn-reject" id="bhwRejectBtn">Reject</button>
            <button type="button" class="mc-btn mc-btn--primary" id="bhwApproveBtn" disabled>Approve &amp; Activate</button>
        </div>
        </div>
    </div>
</div>

<div id="bhwDocPreviewModal" class="bhw-doc-preview-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bhwDocPreviewTitle">
    <div class="bhw-doc-preview-dialog">
        <div class="bhw-doc-preview-header">
            <div>
                <h3 class="bhw-doc-preview-title" id="bhwDocPreviewTitle">Document preview</h3>
                <p class="bhw-doc-preview-sub" id="bhwDocPreviewSub"></p>
            </div>
            <div class="bhw-doc-preview-actions">
                <a id="bhwDocPreviewDownload" class="mc-btn mc-btn--outline" href="#">Download</a>
                <button type="button" class="admin-modal-close" id="bhwDocPreviewClose" aria-label="Close preview">&times;</button>
            </div>
        </div>
        <div class="bhw-doc-preview-body" id="bhwDocPreviewBody"></div>
    </div>
</div>
