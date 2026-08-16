<?php
$active_page = 'triage';
$page_title  = 'Active Triage Review';
$page_styles = ['provider_triage.css'];
require __DIR__ . '/partials/icons.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';
require __DIR__ . '/partials/data.php';
require __DIR__ . '/partials/layout_open.php';

$module_tab = ($_GET['tab'] ?? 'active') === 'history' ? 'history' : 'active';
if ($module_tab === 'active') {
    $display_cases = array_values(array_filter($triage_cases, 'provider_triage_case_is_active'));
} else {
    $display_cases = $triage_cases;
}

$urgent_count     = count(array_filter($display_cases, fn($t) => $t['urgency'] === 'Urgent'));
$non_urgent_count = count(array_filter($display_cases, fn($t) => $t['urgency'] === 'Non-Urgent'));
$tips_pending_count = count(array_filter($display_cases, fn($t) => !empty($t['needs_tips_approval'])));
$reviewed_count   = count(array_filter($display_cases, fn($t) => !empty($t['reviewed']) && empty($t['needs_tips_approval'])));
$pending_count    = count(array_filter($display_cases, fn($t) => empty($t['reviewed'])));
?>

<div class="greeting-banner" style="margin-bottom:16px;">
  <div>
    <h2 class="text-h2">Triage</h2>
    <p class="text-muted text-sm" style="margin:0;">Active case review and historical triage records.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="?tab=active" class="mc-btn <?= $module_tab === 'active' ? 'mc-btn--primary' : 'mc-btn--outline' ?>">Active Cases</a>
    <a href="?tab=history" class="mc-btn <?= $module_tab === 'history' ? 'mc-btn--primary' : 'mc-btn--outline' ?>">History</a>
  </div>
</div>

<div class="triage-banner">
  <?= icon_col('alert', '#3b82f6') ?>
  <span>AI triage is for <strong>clinical decision support only</strong>. The healthcare provider makes the final assessment and treatment decision for every case.</span>
</div>

<div class="triage-stats">
  <div class="triage-stat-card triage-stat-card--urgent">
    <div class="triage-stat-icon"><?= icon('activity') ?></div>
    <div>
      <div class="triage-stat-value" id="triageStatUrgent"><?= $urgent_count ?></div>
      <div class="triage-stat-label">Urgent Cases</div>
    </div>
  </div>
  <div class="triage-stat-card triage-stat-card--routine">
    <div class="triage-stat-icon"><?= icon('check') ?></div>
    <div>
      <div class="triage-stat-value" id="triageStatRoutine"><?= $non_urgent_count ?></div>
      <div class="triage-stat-label">Non-Urgent Cases</div>
    </div>
  </div>
  <div class="triage-stat-card triage-stat-card--reviewed">
    <div class="triage-stat-icon"><?= icon('eye') ?></div>
    <div>
      <div class="triage-stat-value" id="triageStatReviewed"><?= $reviewed_count ?></div>
      <div class="triage-stat-label">Reviewed</div>
    </div>
  </div>
  <div class="triage-stat-card triage-stat-card--urgent">
    <div class="triage-stat-icon"><?= icon('file') ?></div>
    <div>
      <div class="triage-stat-value" id="triageStatTips"><?= $tips_pending_count ?></div>
      <div class="triage-stat-label">Tips Pending</div>
    </div>
  </div>
</div>

<?php if ($tips_pending_count > 0 && $module_tab === 'active'): ?>
<div class="triage-banner" style="margin-top:0;">
  <?= icon_col('alert', '#b45309') ?>
  <span><strong><?= (int) $tips_pending_count ?></strong> case(s) need a review decision. Open the case and choose <strong>Approve for Patient</strong> or <strong>Withhold Guidance</strong>.</span>
</div>
<?php endif; ?>

<div class="triage-tabs">
  <button type="button" class="triage-tab active" data-filter="all">
    All Cases <span class="triage-tab-count"><?= count($display_cases) ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="urgent">
    Urgent <span class="triage-tab-count"><?= $urgent_count ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="non-urgent">
    Non-Urgent <span class="triage-tab-count"><?= $non_urgent_count ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="pending">
    Pending <span class="triage-tab-count"><?= $pending_count ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="tips">
    Tips Pending <span class="triage-tab-count"><?= $tips_pending_count ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="reviewed">
    Reviewed <span class="triage-tab-count"><?= $reviewed_count ?></span>
  </button>
</div>

<div class="mc-card" style="padding: 0; overflow: hidden;">
  <div class="mc-card-header" style="padding: 16px 20px; border-bottom: 1px solid var(--mc-border-thin);">
    <h3 class="text-h3" style="margin: 0;"><?= icon('activity') ?> AI Triage Case Review</h3>
    <span class="text-xs text-muted" id="triageTableSummary"><?= count($display_cases) ?> total · <?= $pending_count ?> pending review<?= $tips_pending_count ? ' · ' . (int) $tips_pending_count . ' tips pending' : '' ?></span>
    <span class="text-xs text-muted" id="triageRefreshStatus" style="margin-left: 12px;">Auto-refresh on</span>
  </div>

  <div class="mc-table-wrap">
    <table class="mc-table" id="triageTable">
      <thead>
        <tr>
          <th>Patient</th>
          <th>Patient Complaint</th>
          <th>AI Classification</th>
          <th>Submitted</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($display_cases)): ?>
        <tr>
          <td colspan="6">
            <div class="triage-empty">
              <p>No triage cases yet. New patient assessments will appear here.</p>
            </div>
          </td>
        </tr>
      <?php else: foreach ($display_cases as $t):
        $is_urgent = $t['urgency'] === 'Urgent';
      ?>
        <tr
          class="<?= $is_urgent ? 'triage-row-urgent' : '' ?><?= !empty($t['expired']) ? ' triage-row-expired' : '' ?>"
          data-urgency="<?= $is_urgent ? 'urgent' : 'non-urgent' ?>"
          data-reviewed="<?= $t['reviewed'] ? 'true' : 'false' ?>"
          data-pending="<?= $t['reviewed'] ? 'false' : 'true' ?>"
          data-expired="<?= !empty($t['expired']) ? 'true' : 'false' ?>"
        >
          <td data-label="Patient" style="font-weight: 700; color: var(--mc-navy-dark);">
            <?= htmlspecialchars($t['name']) ?>
          </td>
          <td data-label="Patient Complaint">
            <span class="triage-complaint" title="<?= htmlspecialchars($t['complaint'] ?: '—') ?>">
              <?= htmlspecialchars($t['complaint'] ?: '—') ?>
            </span>
          </td>
          <td data-label="AI Classification">
            <?php if ($is_urgent): ?>
            <span class="triage-badge triage-badge--urgent">Urgent</span>
            <?php else: ?>
            <span class="triage-badge triage-badge--routine">Non-Urgent</span>
            <?php endif; ?>
            <?php if (!empty($t['label'])): ?>
            <div class="text-xs text-muted" style="margin-top: 4px;"><?= htmlspecialchars($t['label']) ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Submitted" style="white-space: nowrap; font-size: 12px; color: var(--mc-slate-muted);">
            <?= htmlspecialchars($t['date']) ?><br><?= htmlspecialchars($t['time']) ?>
          </td>
          <td data-label="Status">
            <?php foreach ($t['workflow_badges'] ?? [] as $badge): ?>
            <span class="triage-badge triage-badge--<?= htmlspecialchars((string) $badge['class']) ?>">
              <?= htmlspecialchars((string) $badge['label']) ?>
            </span>
            <?php endforeach; ?>
            <?php if (empty($t['workflow_badges'])): ?>
            <span class="triage-badge triage-badge--pending">Pending</span>
            <?php endif; ?>
          </td>
          <td data-label="Action">
            <div class="triage-actions">
              <button type="button" class="mc-btn mc-btn--outline triage-view-btn" style="padding: 6px 12px; font-size: 11px;" data-triage-id="<?= (int) $t['id'] ?>">View Details</button>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="triageModal" class="triage-modal" aria-hidden="true">
  <div class="triage-modal__dialog triage-modal__dialog--review" role="dialog" aria-modal="true" aria-labelledby="triageModalTitle">
    <div class="triage-modal__header">
      <div class="triage-modal__header-text">
        <p class="triage-modal__eyebrow">Clinical decision support</p>
        <h2 id="triageModalTitle" class="triage-modal__title">AI Assessment Review</h2>
        <p class="triage-modal__lead">Verify the assessment below. For non-urgent cases, choose <strong>Approve for Patient</strong> or <strong>Withhold Guidance</strong> to complete your review.</p>
      </div>
      <button type="button" class="triage-modal__close" onclick="closeTriageModal()" aria-label="Close">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="triage-modal__body">
      <div class="triage-modal__scroll">

      <section class="triage-review-section" aria-labelledby="triagePatientHeading">
        <div class="triage-review-section__head">
          <h3 id="triagePatientHeading" class="triage-review-section__title">Patient Information</h3>
        </div>
        <div class="triage-review-hero" aria-label="Patient summary">
          <div class="triage-review-hero__main">
            <span class="triage-field-label">Patient name</span>
            <div id="modalName" class="triage-field-value triage-review-hero__name"></div>
            <p id="modalPatientMeta" class="triage-review-hero__meta"></p>
          </div>
          <div class="triage-review-hero__urgency">
            <span class="triage-field-label">AI urgency</span>
            <div id="modalUrgency"></div>
          </div>
        </div>
      </section>

      <section class="triage-review-section" aria-labelledby="triageChiefComplaintHeading">
        <div class="triage-review-section__head">
          <h3 id="triageChiefComplaintHeading" class="triage-review-section__title">Patient Complaint</h3>
          <p class="triage-review-section__hint">Patient's reported concern in their own words.</p>
        </div>
        <div id="modalComplaint" class="triage-modal-box triage-modal-box--complaint"></div>
      </section>

      <section id="modalEvidenceSection" class="triage-review-section triage-evidence-section" aria-labelledby="triageEvidenceHeading" hidden>
        <div class="triage-review-section__head">
          <h3 id="triageEvidenceHeading" class="triage-review-section__title">Supporting Evidence</h3>
          <p class="triage-review-section__hint">Patient-uploaded evidence for clinical review. This does not determine the AI triage classification.</p>
        </div>
        <div id="modalEvidenceList" class="triage-evidence-list"></div>
      </section>

      <section class="triage-review-section" aria-labelledby="triagePatientWordsHeading">
        <div class="triage-review-section__head">
          <h3 id="triagePatientWordsHeading" class="triage-review-section__title">Symptoms Selected</h3>
          <p class="triage-review-section__hint">Structured symptoms recorded with this submission.</p>
        </div>
        <div id="modalSymptoms" class="triage-modal-box"></div>
      </section>

      <section id="modalNlpAnalysis" class="triage-review-section triage-nlp-panel" aria-labelledby="triageNlpHeading" hidden>
        <div class="triage-review-section__head triage-review-section__head--row">
          <div>
            <h3 id="triageNlpHeading" class="triage-review-section__title">AI Triage Assessment</h3>
            <p class="triage-review-section__hint">Classification and NLP details for clinician reference.</p>
          </div>
          <span class="triage-review-pill">Internal use</span>
        </div>

        <div class="triage-nlp-grid">
          <div class="triage-nlp-card">
            <span class="triage-nlp-label">English translation</span>
            <div id="modalEnglishComplaint" class="triage-modal-box"></div>
          </div>
          <div class="triage-nlp-card">
            <span class="triage-nlp-label">Identified symptoms</span>
            <div id="modalDetectedSymptoms" class="triage-modal-box"></div>
          </div>
          <div class="triage-nlp-card triage-nlp-card--wide">
            <span class="triage-nlp-label">Possible clinical interpretation</span>
            <div id="modalPossibleConditions" class="triage-modal-box"></div>
          </div>
          <div class="triage-nlp-card">
            <span class="triage-nlp-label">Model confidence</span>
            <div id="modalConfidence" class="triage-modal-box triage-modal-box--metric"></div>
          </div>
          <div class="triage-nlp-card">
            <span class="triage-nlp-label">Assigned triage level</span>
            <div id="modalTriageLevel" class="triage-modal-box"></div>
          </div>
          <div class="triage-nlp-card">
            <span class="triage-nlp-label">Assessment timestamp</span>
            <div id="modalAssessedAt" class="triage-modal-box"></div>
          </div>
        </div>
      </section>

      <section id="modalQuestionsSection" class="triage-review-section" aria-labelledby="triageQuestionsHeading" hidden>
        <div class="triage-review-section__head">
          <h3 id="triageQuestionsHeading" class="triage-review-section__title">Suggested Clarifying Questions</h3>
          <p class="triage-review-section__hint">Use these prompts if you need more detail from the patient.</p>
        </div>
        <div id="modalSuggestedQuestions" class="triage-modal-box triage-modal-box--scroll"></div>
      </section>

      <section id="modalGuidanceSection" class="triage-review-section triage-care-tips-block" aria-labelledby="triageGuidanceHeading">
        <div class="triage-review-section__head">
          <h3 id="triageGuidanceHeading" class="triage-review-section__title">Patient-Facing Self-Care Guidance</h3>
          <p class="triage-care-tips-block__hint">Revise the text as needed. Content is disclosed to the patient only upon approval.</p>
        </div>
        <div id="modalRecommendations" class="triage-modal-box" hidden></div>
        <label class="triage-field-label" for="modalRecommendationsEdit">Guidance text (one recommendation per line)</label>
        <textarea
          id="modalRecommendationsEdit"
          class="triage-rec-edit"
          rows="10"
          placeholder="Enter or revise self-care recommendations prior to patient release."
        ></textarea>
        <p id="modalRecommendationGateHint" class="triage-gate-hint"></p>
      </section>

      <details class="triage-override-box triage-review-section triage-review-section--last">
        <summary class="triage-override-box__summary">
          <span class="triage-review-section__title" style="margin:0;">Doctor Review &amp; Decision</span>
          <span class="triage-override-box__chev" aria-hidden="true">▾</span>
        </summary>
        <div class="triage-override-box__body">
          <p class="triage-override-box__intro">Manual clinical override — priority changes are recorded in the system audit log.</p>
          <div class="triage-override-row">
            <select id="overrideLevel" class="triage-override-select" aria-label="Override triage priority level">
              <option value="1">Urgent (Priority 1)</option>
              <option value="2">Urgent (Priority 2)</option>
              <option value="3">Non-Urgent (Priority 3)</option>
              <option value="4">Routine (Priority 4)</option>
              <option value="5">Routine (Priority 5)</option>
            </select>
            <button type="button" class="mc-btn mc-btn--outline triage-override-btn" onclick="applyOverride()">Apply override</button>
          </div>
          <p class="triage-override-box__foot">Use <strong>Approve for Patient</strong> or <strong>Withhold Guidance</strong> below to complete your review. Priority overrides are optional.</p>
        </div>
      </details>

      <div class="triage-case-actions" id="triageCaseActions" hidden>
        <h3 class="triage-case-actions__title">Case administration</h3>
        <p class="triage-case-actions__note">Reporting a case does not suspend the patient account. Terminating closes only this consultation case.</p>
        <div class="triage-case-actions__buttons">
          <button type="button" id="modalReportCaseBtn" class="mc-btn mc-btn--outline mc-btn--sm">Report Case</button>
          <button type="button" id="modalTerminateCaseBtn" class="mc-btn mc-btn--danger-outline mc-btn--sm">Terminate Case</button>
        </div>
        <p id="modalActiveReportNote" class="triage-case-actions__warn" hidden></p>
      </div>

      </div>
    </div>

    <div class="triage-modal__footer">
      <button type="button" class="mc-btn mc-btn--ghost" onclick="closeTriageModal()">Close</button>
      <div class="triage-modal__footer-actions">
        <button type="button" id="modalRejectRecBtn" class="mc-btn mc-btn--danger-outline" style="display:none;" onclick="rejectRecommendationsFromModal()">Withhold Guidance</button>
        <button type="button" id="modalApproveRecBtn" class="mc-btn mc-btn--primary" style="display:none;" onclick="approveRecommendationsFromModal()">Approve for Patient</button>
        <span id="modalReviewStatusNote" class="triage-modal__review-note" hidden></span>
      </div>
    </div>
  </div>
</div>

<div id="triageReportModal" class="triage-modal triage-confirm-modal" aria-hidden="true">
  <div class="triage-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="triageReportModalTitle">
    <div class="triage-modal__header">
      <h2 id="triageReportModalTitle" class="triage-modal__title">Report this case?</h2>
      <p class="triage-modal__lead">Reporting a case does not automatically suspend the patient's account. An administrator will review the report.</p>
    </div>
    <div class="triage-modal__body">
      <label class="mc-label" for="triageReportReason">Reason <span aria-hidden="true">*</span></label>
      <select id="triageReportReason" class="mc-input" required>
        <option value="">Select a reason…</option>
        <option value="prank_fake">Suspected prank / fake submission</option>
        <option value="spam_irrelevant">Spam / irrelevant submission</option>
        <option value="abusive_inappropriate">Abusive / inappropriate content</option>
        <option value="false_misleading">False or misleading information</option>
        <option value="repeated_suspicious">Repeated suspicious submission</option>
        <option value="other">Other concern</option>
      </select>
      <label class="mc-label" for="triageReportNotes" style="margin-top:12px;">Additional notes (optional)</label>
      <textarea id="triageReportNotes" class="mc-input" rows="3" placeholder="Optional context for administrators"></textarea>
    </div>
    <div class="triage-modal__footer">
      <button type="button" class="mc-btn mc-btn--ghost" data-triage-report-cancel>Cancel</button>
      <button type="button" class="mc-btn mc-btn--primary" data-triage-report-submit>Submit Report</button>
    </div>
  </div>
</div>

<div id="triageTerminateModal" class="triage-modal triage-confirm-modal" aria-hidden="true">
  <div class="triage-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="triageTerminateModalTitle">
    <div class="triage-modal__header">
      <h2 id="triageTerminateModalTitle" class="triage-modal__title">Terminate this case?</h2>
      <p class="triage-modal__lead">This will close the current consultation case. The patient's account and previous medical records will remain available.</p>
    </div>
    <div class="triage-modal__body">
      <label class="mc-label" for="triageTerminateReason">Reason <span aria-hidden="true">*</span></label>
      <textarea id="triageTerminateReason" class="mc-input" rows="3" required placeholder="Brief clinical or administrative reason"></textarea>
    </div>
    <div class="triage-modal__footer">
      <button type="button" class="mc-btn mc-btn--ghost" data-triage-terminate-cancel>Cancel</button>
      <button type="button" class="mc-btn mc-btn--danger" data-triage-terminate-submit>Terminate Case</button>
    </div>
  </div>
</div>

<div
  id="triageApproveConfirm"
  class="triage-review-confirm"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="triageApproveConfirmTitle"
  aria-describedby="triageApproveConfirmLead"
>
  <div class="triage-review-confirm__backdrop" data-triage-approve-cancel tabindex="-1" aria-hidden="true"></div>
  <div class="triage-review-confirm__dialog">
    <div class="triage-review-confirm__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
        <polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
    </div>
    <h2 id="triageApproveConfirmTitle" class="triage-review-confirm__title">Approve self-care recommendations?</h2>
    <p id="triageApproveConfirmLead" class="triage-review-confirm__lead">
      Approve these self-care recommendations for the patient? This will complete your review.
    </p>
    <ul class="triage-review-confirm__list">
      <li>The patient will see the approved guidance in their Care Tips chat.</li>
      <li>This Active Triage Review case will be marked complete.</li>
    </ul>
    <p class="triage-review-confirm__when">You can still edit the recommendation text in the review panel before confirming.</p>
    <div class="triage-review-confirm__actions">
      <button type="button" class="mc-btn mc-btn--ghost" data-triage-approve-cancel>Cancel</button>
      <button type="button" class="mc-btn mc-btn--primary" data-triage-approve-confirm data-mc-autofocus>Approve for Patient</button>
    </div>
  </div>
</div>

<div
  id="triageWithholdConfirm"
  class="triage-review-confirm triage-review-confirm--danger"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="triageWithholdConfirmTitle"
  aria-describedby="triageWithholdConfirmLead"
>
  <div class="triage-review-confirm__backdrop" data-triage-withhold-cancel tabindex="-1" aria-hidden="true"></div>
  <div class="triage-review-confirm__dialog">
    <div class="triage-review-confirm__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
      </svg>
    </div>
    <h2 id="triageWithholdConfirmTitle" class="triage-review-confirm__title">Withhold self-care guidance?</h2>
    <p id="triageWithholdConfirmLead" class="triage-review-confirm__lead">
      Withhold self-care guidance from the patient? This will complete your review.
    </p>
    <ul class="triage-review-confirm__list">
      <li>The patient will not receive these AI self-care tips.</li>
      <li>This Active Triage Review case will be marked complete.</li>
    </ul>
    <p class="triage-review-confirm__when">Use this when the guidance is not appropriate for the patient’s case.</p>
    <div class="triage-review-confirm__actions">
      <button type="button" class="mc-btn mc-btn--ghost" data-triage-withhold-cancel>Cancel</button>
      <button type="button" class="mc-btn mc-btn--danger" data-triage-withhold-confirm data-mc-autofocus>Withhold Guidance</button>
    </div>
  </div>
</div>

<div
  id="triageReviewNotice"
  class="triage-review-confirm triage-review-confirm--success"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="triageReviewNoticeTitle"
  aria-describedby="triageReviewNoticeLead"
>
  <div class="triage-review-confirm__backdrop" data-triage-notice-ok tabindex="-1" aria-hidden="true"></div>
  <div class="triage-review-confirm__dialog">
    <div class="triage-review-confirm__icon" aria-hidden="true">
      <svg data-triage-notice-icon-success viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
        <polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
      <svg data-triage-notice-icon-error hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
    </div>
    <h2 id="triageReviewNoticeTitle" class="triage-review-confirm__title">Review complete</h2>
    <p id="triageReviewNoticeLead" class="triage-review-confirm__lead">
      Self-care recommendations are now available to the patient.
    </p>
    <div class="triage-review-confirm__actions">
      <button type="button" class="mc-btn mc-btn--primary" data-triage-notice-ok data-mc-autofocus>OK</button>
    </div>
  </div>
</div>

<?php $triageLiveJsVer = (int) @filemtime(ASSETS_PATH . '/js/provider-triage-live.js'); ?>
<script type="application/json" id="triageCasesBootstrap"><?= json_encode($display_cases, JSON_UNESCAPED_UNICODE) ?></script>
<script>
window.MedConnectTriage = {
  listApi: <?= json_encode(ASSET_BASE . '/app/api/provider/get_triage.php') ?>,
  evidenceApi: <?= json_encode(ASSET_BASE . '/app/api/provider/get_triage_evidence.php') ?>,
  updateApi: <?= json_encode(ASSET_BASE . '/app/api/provider/update_triage.php') ?>,
  reportApi: <?= json_encode(ASSET_BASE . '/app/api/provider/case_report.php') ?>,
  terminateApi: <?= json_encode(ASSET_BASE . '/app/api/provider/terminate_case.php') ?>,
  tab: <?= json_encode($module_tab) ?>,
  refreshMs: 15000,
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/provider-triage-live.js?v=<?= $triageLiveJsVer ?>"></script>

<?php require __DIR__ . '/partials/layout_close.php'; ?>
