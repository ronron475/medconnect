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
  <span><strong><?= (int) $tips_pending_count ?></strong> case(s) need self-care tip approval before patients can see Care tips. Open the case and choose <strong>Approve Self-Care for Patient</strong>.</span>
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
          <th>Symptoms</th>
          <th>Chief Complaint</th>
          <th>AI Classification</th>
          <th>Submitted</th>
          <th>Workflow</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($display_cases)): ?>
        <tr>
          <td colspan="7">
            <div class="triage-empty">
              <p>No triage cases yet. New patient assessments will appear here.</p>
            </div>
          </td>
        </tr>
      <?php else: foreach ($display_cases as $t):
        $is_urgent = $t['urgency'] === 'Urgent';
        $payload   = htmlspecialchars(json_encode($t, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
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
          <td data-label="Symptoms">
            <div class="triage-symptom-chips">
              <?php if (!empty($t['symptoms_list'])): ?>
                <?php foreach ($t['symptoms_list'] as $symptom): ?>
                <span class="triage-symptom-chip"><?= htmlspecialchars($symptom) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Complaint">
            <span class="triage-complaint" title="<?= htmlspecialchars($t['complaint'] ?: '—') ?>">
              <?= htmlspecialchars($t['complaint'] ?: '—') ?>
            </span>
          </td>
          <td data-label="Classification">
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
          <td data-label="Workflow">
            <?php if (!empty($t['needs_tips_approval'])): ?>
            <span class="triage-badge triage-badge--urgent">Tips pending</span>
            <?php endif; ?>
            <?php if ($t['reviewed'] && empty($t['needs_tips_approval'])): ?>
            <span class="triage-badge triage-badge--reviewed">Reviewed</span>
            <?php elseif ($t['reviewed'] && !empty($t['needs_tips_approval'])): ?>
            <span class="triage-badge triage-badge--reviewed">Booked</span>
            <?php elseif (!empty($t['expired'])): ?>
            <span class="triage-badge triage-badge--expired">Expired</span>
            <?php elseif (empty($t['needs_tips_approval'])): ?>
            <span class="triage-badge triage-badge--pending">Pending</span>
            <?php endif; ?>
          </td>
          <td data-label="Actions">
            <div class="triage-actions">
              <button type="button" class="mc-btn mc-btn--outline triage-view-btn" style="padding: 6px 12px; font-size: 11px;" data-triage="<?= $payload ?>">View Details</button>
              <?php if (!empty($t['can_accept'])): ?>
              <button type="button" class="mc-btn mc-btn--primary triage-accept-btn" style="padding: 6px 12px; font-size: 11px;" data-id="<?= (int) $t['id'] ?>">Mark reviewed</button>
              <?php elseif (!$t['reviewed'] && !empty($t['expired'])): ?>
              <span class="triage-expired-note" title="Only same-day triage cases can be marked reviewed.">Cannot mark reviewed</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="triageModal" class="triage-modal" aria-hidden="true">
  <div class="triage-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="triageModalTitle">
    <div class="triage-modal__header">
      <h2 id="triageModalTitle" class="triage-modal__title">Clinical Triage Summary</h2>
      <button type="button" class="triage-modal__close" onclick="closeTriageModal()" aria-label="Close">&times;</button>
    </div>

    <div class="triage-modal__body">
      <div class="triage-modal-grid">
        <div>
          <span class="triage-field-label">Patient Name</span>
          <div id="modalName" class="triage-field-value"></div>
        </div>
        <div>
          <span class="triage-field-label">AI Urgency Classification</span>
          <div id="modalUrgency"></div>
        </div>
      </div>

      <div style="margin-bottom: 18px;">
        <span class="triage-field-label">Symptoms Selected</span>
        <div id="modalSymptoms" class="triage-modal-box"></div>
      </div>

      <div style="margin-bottom: 18px;">
        <span class="triage-field-label">Chief Complaint</span>
        <div id="modalComplaint" class="triage-modal-box triage-modal-box--complaint"></div>
      </div>

      <div id="modalNlpAnalysis" class="triage-nlp-panel" hidden>
        <span class="triage-field-label">AI NLP Analysis <span class="text-xs text-muted">(provider only)</span></span>
        <div class="triage-nlp-grid">
          <div>
            <span class="triage-nlp-label">Translated Complaint</span>
            <div id="modalEnglishComplaint" class="triage-modal-box"></div>
          </div>
          <div>
            <span class="triage-nlp-label">Detected Symptoms</span>
            <div id="modalDetectedSymptoms" class="triage-modal-box"></div>
          </div>
          <div>
            <span class="triage-nlp-label">Possible Interpretation</span>
            <div id="modalPossibleConditions" class="triage-modal-box"></div>
          </div>
          <div>
            <span class="triage-nlp-label">Confidence</span>
            <div id="modalConfidence" class="triage-modal-box"></div>
          </div>
          <div>
            <span class="triage-nlp-label">Triage Level</span>
            <div id="modalTriageLevel" class="triage-modal-box"></div>
          </div>
          <div>
            <span class="triage-nlp-label">Assessed</span>
            <div id="modalAssessedAt" class="triage-modal-box"></div>
          </div>
        </div>
        <div style="margin-top: 12px;">
          <span class="triage-nlp-label">AI Recommendations (provider review)</span>
          <div id="modalRecommendations" class="triage-modal-box" style="display:none;"></div>
          <textarea
            id="modalRecommendationsEdit"
            class="triage-modal-box"
            rows="5"
            style="width:100%; min-height:110px; resize:vertical; font:inherit; color:inherit; background:inherit; border:1px solid rgba(148,163,184,.35); border-radius:10px; padding:10px 12px; box-sizing:border-box;"
            placeholder="Review and edit self-care advice before releasing to the patient."
          ></textarea>
          <p id="modalRecommendationGateHint" class="text-xs text-muted" style="margin:8px 0 0;"></p>
        </div>
      </div>

      <div class="triage-override-box">
        <span class="triage-field-label">Manual Override (Clinical Decision)</span>
        <div class="triage-override-row">
          <select id="overrideLevel">
            <option value="1">Urgent (Priority 1)</option>
            <option value="2">Urgent (Priority 2)</option>
            <option value="3">Non-Urgent (Priority 3)</option>
            <option value="4">Routine (Priority 4)</option>
            <option value="5">Routine (Priority 5)</option>
          </select>
          <button type="button" class="mc-btn mc-btn--primary" style="padding: 8px 16px; font-size: 12px;" onclick="applyOverride()">Apply Override</button>
        </div>
        <p class="text-xs text-muted" style="margin: 8px 0 0;">Changing the priority level will be recorded in the system audit trail.</p>
      </div>
    </div>

    <div class="triage-modal__footer">
      <button type="button" class="mc-btn mc-btn--outline" onclick="closeTriageModal()">Close</button>
      <button type="button" id="modalRejectRecBtn" class="mc-btn mc-btn--outline" style="display:none;" onclick="rejectRecommendationsFromModal()">Do Not Release to Patient</button>
      <button type="button" id="modalApproveRecBtn" class="mc-btn mc-btn--primary" style="display:none;" onclick="approveRecommendationsFromModal()">Approve Self-Care for Patient</button>
      <button type="button" id="modalAcceptBtn" class="mc-btn mc-btn--primary" onclick="acceptTriageFromModal()">Mark as Reviewed</button>
    </div>
  </div>
</div>

<?php $triageLiveJsVer = (int) @filemtime(ASSETS_PATH . '/js/provider-triage-live.js'); ?>
<script>
window.MedConnectTriage = {
  listApi: <?= json_encode(ASSET_BASE . '/app/api/provider/get_triage.php') ?>,
  updateApi: <?= json_encode(ASSET_BASE . '/app/api/provider/update_triage.php') ?>,
  tab: <?= json_encode($module_tab) ?>,
  refreshMs: 15000,
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/provider-triage-live.js?v=<?= $triageLiveJsVer ?>"></script>

<?php require __DIR__ . '/partials/layout_close.php'; ?>
