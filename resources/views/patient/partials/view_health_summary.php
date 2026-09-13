<?php
/**
 * Permanent medical profile — registration data only (read-only).
 * UI presentation only; triage/profile data come from existing loaders.
 */

if (!function_exists('phs_icon')) {
    function phs_icon(string $name): string
    {
        $icons = [
            'activity' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
            'stethoscope' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 2v6a3 3 0 0 0 6 0V2"/><path d="M14 8a7 7 0 1 0 7 7"/><circle cx="21" cy="15" r="2"/><path d="M4 14v2a6 6 0 0 0 12 0v-2"/></svg>',
            'droplet' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>',
            'alert' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'clipboard' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>',
            'pill' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>',
            'clock' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'user' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'spark' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><path d="m5.6 5.6 2.8 2.8"/><path d="m15.6 15.6 2.8 2.8"/><path d="m5.6 18.4 2.8-2.8"/><path d="m15.6 8.4 2.8-2.8"/></svg>',
        ];

        return $icons[$name] ?? '';
    }
}

if (!function_exists('phs_status_class')) {
    function phs_status_class(string $key): string
    {
        return match ($key) {
            'emergency' => 'phs-status--emergency',
            'urgent' => 'phs-status--urgent',
            'non_urgent' => 'phs-status--routine',
            default => 'phs-status--muted',
        };
    }
}

/**
 * Dashboard assessment pair — same triage display rules as mc_render_triage_assessment_stack.
 *
 * @param array<string, mixed> $row
 */
if (!function_exists('phs_render_assessment_pair')) {
    function phs_render_assessment_pair(array $row, bool $compact = false): void
    {
        $ai = triage_ai_preliminary_label($row);
        $aiKey = triage_ai_preliminary_key($row);
        // Surface doctor assessment only when the row has explicit clinician evidence.
        // Never invent a final classification from AI-only triage rows.
        $finalKey = triage_doctor_final_key($row);
        $approvedBy = (int) ($row['recommendation_approved_by'] ?? 0);
        $hasOverride = !empty($row['doctor_override']) || !empty($row['manual_urgency']);
        $bookingCompleted = strtolower((string) ($row['_booking_state'] ?? '')) === 'completed';
        $hasExplicitDoctorFinal = $finalKey !== 'unknown'
            && (
                $finalKey !== $aiKey
                || $hasOverride
                || $approvedBy > 0
                || $bookingCompleted
            );
        if (!$hasExplicitDoctorFinal) {
            $finalKey = 'unknown';
        }

        $showDoctor = $hasExplicitDoctorFinal;
        $finalLabel = $showDoctor
            ? triage_urgency_display_label($finalKey)
            : 'Not recorded';
        $assessedAt = !empty($row['assessed_at'])
            ? date('M j, Y g:i A', strtotime((string) $row['assessed_at']))
            : '';
        $finalizedBy = trim((string) ($row['finalized_by_name'] ?? $row['finalized_by'] ?? ''));
        $isEmergency = $showDoctor && $finalKey === 'emergency';
        $wrapClass = 'phs-assess-grid' . ($compact ? ' phs-assess-grid--compact' : '');
        ?>
    <div class="<?= $wrapClass ?>">
      <article class="phs-assess-card phs-assess-card--ai">
        <div class="phs-assess-card__head">
          <span class="phs-assess-card__icon" aria-hidden="true"><?= phs_icon('spark') ?></span>
          <h4 class="phs-assess-card__label">Preliminary AI Assessment</h4>
        </div>
        <span class="phs-status <?= htmlspecialchars(phs_status_class($aiKey)) ?>"><?= htmlspecialchars($ai) ?></span>
        <?php if ($assessedAt !== ''): ?>
        <p class="phs-assess-card__meta"><span class="phs-assess-card__meta-icon" aria-hidden="true"><?= phs_icon('clock') ?></span><?= htmlspecialchars($assessedAt) ?></p>
        <?php endif; ?>
        <p class="phs-assess-card__hint">Automated triage result from medConnect AI</p>
      </article>

      <article class="phs-assess-card phs-assess-card--doctor<?= $isEmergency ? ' is-emergency' : '' ?>">
        <div class="phs-assess-card__head">
          <span class="phs-assess-card__icon" aria-hidden="true"><?= phs_icon('stethoscope') ?></span>
          <h4 class="phs-assess-card__label">Final Doctor Assessment</h4>
        </div>
        <span class="phs-status <?= htmlspecialchars(phs_status_class($showDoctor ? $finalKey : 'unknown')) ?>"><?= htmlspecialchars($finalLabel) ?></span>
        <?php if ($showDoctor && $finalizedBy !== ''): ?>
        <p class="phs-assess-card__meta"><span class="phs-assess-card__meta-icon" aria-hidden="true"><?= phs_icon('user') ?></span><?= htmlspecialchars($finalizedBy) ?></p>
        <?php elseif ($assessedAt !== '' && $showDoctor): ?>
        <p class="phs-assess-card__meta"><span class="phs-assess-card__meta-icon" aria-hidden="true"><?= phs_icon('clock') ?></span><?= htmlspecialchars($assessedAt) ?></p>
        <?php endif; ?>
        <p class="phs-assess-card__hint"><?= $showDoctor
            ? 'Clinician-verified case classification'
            : 'No doctor assessment on file yet' ?></p>
        <?php if ($isEmergency): ?>
        <p class="phs-assess-emergency-note">
          Your doctor classified this case as an EMERGENCY. Seek immediate in-person medical attention.
          You may continue the live consultation while arranging transfer.
        </p>
        <?php endif; ?>
      </article>
    </div>
        <?php
    }
}
?>
<header class="phs-hero" aria-label="Health Summary overview">
  <div class="phs-hero__content">
    <h1 class="phs-hero__title">Health Summary</h1>
    <p class="phs-hero__sub">Your health information at a glance.</p>
  </div>
  <div class="phs-hero__actions">
    <button type="button" class="pdash-btn pdash-btn--outline" id="phsRequestUpdateBtn" hidden>
      Request update
    </button>
  </div>
</header>

<?php if (!empty($latest_triage)): ?>
<section class="phs-panel phs-panel--assess" aria-label="Latest triage assessment">
  <header class="phs-panel__head">
    <div class="phs-panel__head-main">
      <span class="phs-panel__head-icon" aria-hidden="true"><?= phs_icon('activity') ?></span>
      <div>
        <h2 class="phs-panel__title">Current assessment</h2>
        <p class="phs-panel__meta">
          <?= !empty($latest_triage['assessed_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $latest_triage['assessed_at']))) : 'Most recent case' ?>
          <?php if (trim((string) ($latest_triage['chief_complaint'] ?? '')) !== ''): ?>
            · <?= htmlspecialchars((string) $latest_triage['chief_complaint']) ?>
          <?php endif; ?>
        </p>
      </div>
    </div>
  </header>
  <div class="phs-panel__body">
    <?php phs_render_assessment_pair($latest_triage, false); ?>
  </div>

  <?php if (count($triage_history) > 1): ?>
  <div class="phs-history">
    <h3 class="phs-history__title">Earlier assessments</h3>
    <ol class="phs-history__list">
      <?php foreach (array_slice($triage_history, 1) as $histRow): ?>
      <li class="phs-history__item">
        <div class="phs-history__rail" aria-hidden="true"></div>
        <div class="phs-history__card">
          <div class="phs-history__meta">
            <time class="phs-history__date">
              <?= !empty($histRow['assessed_at']) ? htmlspecialchars(date('M j, Y', strtotime((string) $histRow['assessed_at']))) : '—' ?>
            </time>
            <span class="phs-history__complaint"><?= htmlspecialchars(trim((string) ($histRow['chief_complaint'] ?? '')) !== '' ? (string) $histRow['chief_complaint'] : 'Health concern on file') ?></span>
          </div>
          <?php phs_render_assessment_pair($histRow, true); ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div id="phsAlert" class="phs-alert" role="alert" hidden></div>

<div id="phsPendingBanner" class="phs-banner phs-banner--pending" hidden role="status">
  <div class="phs-banner__text">
    <strong>Update request pending</strong>
    <p id="phsPendingMessage">Your request is awaiting doctor review. Your official Health Summary will not change until approved.</p>
  </div>
</div>

<div id="phsRejectedBanner" class="phs-banner phs-banner--rejected" hidden role="status">
  <div class="phs-banner__text">
    <strong>Last update request was not approved</strong>
    <p id="phsRejectedMessage">You may submit a new request with corrected information.</p>
  </div>
</div>

<div id="phsSkeleton" class="phs-skeleton" aria-busy="true" aria-label="Loading health summary">
  <div class="phs-skeleton-line phs-skeleton-line--short"></div>
  <div class="phs-skeleton-line"></div>
  <div class="phs-skeleton-line phs-skeleton-line--medium"></div>
  <div class="phs-skeleton-line"></div>
</div>

<section id="phsContent" class="phs-panel phs-content" hidden aria-label="Medical profile">
  <header class="phs-panel__head">
    <div class="phs-panel__head-main">
      <span class="phs-panel__head-icon" aria-hidden="true"><?= phs_icon('clipboard') ?></span>
      <div>
        <h2 class="phs-panel__title">Medical Profile</h2>
        <p class="phs-panel__meta">Verified registration data · read-only</p>
      </div>
    </div>
  </header>

  <div class="phs-info-grid" role="list">
    <article class="phs-info-card" role="listitem" aria-labelledby="phs-blood-title">
      <div class="phs-info-card__head">
        <span class="phs-info-card__icon phs-info-card__icon--blood" aria-hidden="true"><?= phs_icon('droplet') ?></span>
        <h3 class="phs-info-card__label" id="phs-blood-title">Blood type</h3>
      </div>
      <div class="phs-value" id="phsBloodType">—</div>
      <p class="phs-info-card__hint" id="phsBloodHint">Not yet recorded</p>
    </article>

    <article class="phs-info-card" role="listitem" aria-labelledby="phs-allergy-title">
      <div class="phs-info-card__head">
        <span class="phs-info-card__icon phs-info-card__icon--allergy" aria-hidden="true"><?= phs_icon('alert') ?></span>
        <h3 class="phs-info-card__label" id="phs-allergy-title">Allergies</h3>
      </div>
      <ul id="phsAllergies" class="phs-chip-list"></ul>
      <p id="phsAllergiesEmpty" class="phs-empty" hidden>No known allergies</p>
      <p class="phs-info-card__hint" id="phsAllergiesHint">No recorded allergies</p>
    </article>

    <article class="phs-info-card" role="listitem" aria-labelledby="phs-conditions-title">
      <div class="phs-info-card__head">
        <span class="phs-info-card__icon phs-info-card__icon--conditions" aria-hidden="true"><?= phs_icon('clipboard') ?></span>
        <h3 class="phs-info-card__label" id="phs-conditions-title">Medical conditions</h3>
      </div>
      <ul id="phsConditions" class="phs-chip-list"></ul>
      <p id="phsConditionsEmpty" class="phs-empty" hidden>None recorded</p>
      <p class="phs-info-card__hint" id="phsConditionsHint">No existing medical conditions</p>
    </article>

    <article class="phs-info-card phs-info-card--wide" role="listitem" aria-labelledby="phs-meds-title">
      <div class="phs-info-card__head">
        <span class="phs-info-card__icon phs-info-card__icon--meds" aria-hidden="true"><?= phs_icon('pill') ?></span>
        <h3 class="phs-info-card__label" id="phs-meds-title">Maintenance medications</h3>
      </div>
      <ul id="phsMedications" class="phs-chip-list phs-chip-list--meds"></ul>
      <p id="phsMedicationsEmpty" class="phs-empty" hidden>No maintenance medications</p>
      <p class="phs-info-card__hint" id="phsMedicationsHint">You are not currently taking any maintenance medications</p>
    </article>
  </div>

  <footer class="phs-meta" aria-labelledby="phs-meta-title">
    <div class="phs-meta__intro">
      <h3 class="phs-meta__title" id="phs-meta-title">Profile metadata</h3>
      <p class="phs-meta__note">
        Verified information cannot be edited directly. Use <strong>Request update</strong> if something needs correction.
      </p>
    </div>
    <div class="phs-meta__grid">
      <div class="phs-meta__item">
        <span class="phs-meta__icon" aria-hidden="true"><?= phs_icon('clock') ?></span>
        <div class="phs-meta__copy">
          <span class="phs-meta__label">Last updated</span>
          <strong class="phs-meta__value" id="phsLastUpdated">—</strong>
        </div>
      </div>
      <div class="phs-meta__item">
        <span class="phs-meta__icon" aria-hidden="true"><?= phs_icon('user') ?></span>
        <div class="phs-meta__copy">
          <span class="phs-meta__label">Updated by</span>
          <strong class="phs-meta__value" id="phsLastProvider">—</strong>
        </div>
      </div>
    </div>
  </footer>
</section>

<div id="phsRequestModal" class="phs-modal" hidden role="dialog" aria-modal="true" aria-labelledby="phsRequestModalTitle">
  <div class="phs-modal__backdrop" data-phs-close-modal></div>
  <div class="phs-modal__card phs-modal__card--wide">
    <header class="phs-modal__head">
      <h3 id="phsRequestModalTitle" class="phs-modal__title">Request Health Information Update</h3>
      <p class="phs-modal__lead">You cannot edit verified health information directly. Submit your requested corrections below. Your assigned doctor will review and approve changes before your official Health Summary is updated.</p>
    </header>

    <div class="phs-modal__body">
      <div class="phs-request-grid">
        <div class="phs-request-col">
          <h4 class="phs-request-col__title">Current (verified)</h4>
          <div class="phs-request-readonly">
            <span class="phs-request-readonly__label">Blood type</span>
            <span class="phs-request-readonly__value" id="phsCurrentBlood">—</span>
          </div>
          <div class="phs-request-readonly">
            <span class="phs-request-readonly__label">Allergies</span>
            <span class="phs-request-readonly__value" id="phsCurrentAllergies">—</span>
          </div>
          <div class="phs-request-readonly">
            <span class="phs-request-readonly__label">Medical conditions</span>
            <span class="phs-request-readonly__value" id="phsCurrentConditions">—</span>
          </div>
          <div class="phs-request-readonly">
            <span class="phs-request-readonly__label">Maintenance medications</span>
            <span class="phs-request-readonly__value" id="phsCurrentMeds">—</span>
          </div>
        </div>
        <div class="phs-request-col">
          <h4 class="phs-request-col__title">Requested changes</h4>
          <label class="phs-field" for="phsProposedBlood">
            <span class="phs-field__label">Blood type</span>
            <select id="phsProposedBlood" class="phs-field__input">
              <option value="">— No change —</option>
              <option value="A+">A+</option><option value="A-">A-</option>
              <option value="B+">B+</option><option value="B-">B-</option>
              <option value="AB+">AB+</option><option value="AB-">AB-</option>
              <option value="O+">O+</option><option value="O-">O-</option>
              <option value="Unknown">Unknown</option>
            </select>
          </label>
          <label class="phs-field" for="phsProposedAllergies">
            <span class="phs-field__label">Allergies</span>
            <textarea id="phsProposedAllergies" class="phs-field__input" rows="2" maxlength="500" placeholder="Leave blank if no change"></textarea>
          </label>
          <label class="phs-field" for="phsProposedConditions">
            <span class="phs-field__label">Medical conditions</span>
            <textarea id="phsProposedConditions" class="phs-field__input" rows="2" maxlength="500" placeholder="Leave blank if no change"></textarea>
          </label>
          <label class="phs-field" for="phsProposedMeds">
            <span class="phs-field__label">Maintenance medications</span>
            <textarea id="phsProposedMeds" class="phs-field__input" rows="2" maxlength="500" placeholder="Leave blank if no change"></textarea>
          </label>
        </div>
      </div>

      <label class="phs-field" for="phsRequestNote">
        <span class="phs-field__label">Additional note (optional)</span>
        <textarea id="phsRequestNote" class="phs-field__input" rows="3" maxlength="500" placeholder="Describe what needs to be updated…"></textarea>
      </label>
    </div>

    <div class="phs-modal__actions">
      <button type="button" class="pdash-btn pdash-btn--outline" data-phs-close-modal>Cancel</button>
      <button type="button" class="pdash-btn pdash-btn--primary" id="phsRequestSubmit">Submit Request</button>
    </div>
  </div>
</div>
