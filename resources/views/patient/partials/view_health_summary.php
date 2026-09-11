<?php
/**
 * Permanent medical profile — registration data only (read-only).
 */
?>
<section class="phs-hero" aria-label="Health Summary overview">
  <div class="phs-hero__content">
    <p class="phs-hero__eyebrow">Health Summary</p>
    <h2 class="phs-hero__title">Your medical overview</h2>
    <p class="phs-hero__sub">
      Verified registration details and your latest triage result for quick reference.
    </p>
  </div>
  <div class="phs-hero__actions">
    <button type="button" class="pdash-btn pdash-btn--outline" id="phsRequestUpdateBtn" hidden>
      Request update
    </button>
  </div>
</section>

<?php if (!empty($latest_triage)): ?>
<section class="phs-panel" aria-label="Latest triage assessment">
  <header class="phs-panel__head">
    <div>
      <h3 class="phs-panel__title">Latest triage assessment</h3>
      <p class="phs-panel__meta">
        <?= !empty($latest_triage['assessed_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $latest_triage['assessed_at']))) : 'Most recent case' ?>
        <?php if (trim((string) ($latest_triage['chief_complaint'] ?? '')) !== ''): ?>
          · <?= htmlspecialchars((string) $latest_triage['chief_complaint']) ?>
        <?php endif; ?>
      </p>
    </div>
  </header>
  <div class="phs-panel__body">
    <?php mc_render_triage_assessment_stack($latest_triage, false); ?>
  </div>

  <?php if (count($triage_history) > 1): ?>
  <div class="phs-history">
    <h4 class="phs-history__title">Earlier assessments</h4>
    <ul class="phs-history__list">
      <?php foreach (array_slice($triage_history, 1) as $histRow): ?>
      <li class="phs-history__item">
        <div class="phs-history__meta">
          <strong><?= !empty($histRow['assessed_at']) ? htmlspecialchars(date('M j, Y', strtotime((string) $histRow['assessed_at']))) : '—' ?></strong>
          <span><?= htmlspecialchars(trim((string) ($histRow['chief_complaint'] ?? '')) !== '' ? (string) $histRow['chief_complaint'] : 'Health concern on file') ?></span>
        </div>
        <?php mc_render_triage_assessment_stack($histRow, false); ?>
      </li>
      <?php endforeach; ?>
    </ul>
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
    <div>
      <h3 class="phs-panel__title">Medical profile</h3>
      <p class="phs-panel__meta">Verified registration data · read-only</p>
    </div>
  </header>

  <dl class="phs-fields">
    <div class="phs-fields__row">
      <dt id="phs-blood-title">Blood type</dt>
      <dd>
        <div class="phs-value" id="phsBloodType">—</div>
      </dd>
    </div>
    <div class="phs-fields__row">
      <dt id="phs-allergy-title">Allergies</dt>
      <dd>
        <ul id="phsAllergies" class="phs-chip-list"></ul>
        <p id="phsAllergiesEmpty" class="phs-empty" hidden>No known allergies</p>
      </dd>
    </div>
    <div class="phs-fields__row">
      <dt id="phs-conditions-title">Medical conditions</dt>
      <dd>
        <ul id="phsConditions" class="phs-chip-list"></ul>
        <p id="phsConditionsEmpty" class="phs-empty" hidden>None recorded</p>
      </dd>
    </div>
    <div class="phs-fields__row">
      <dt id="phs-meds-title">Maintenance medications</dt>
      <dd>
        <ul id="phsMedications" class="phs-chip-list"></ul>
        <p id="phsMedicationsEmpty" class="phs-empty" hidden>No maintenance medications</p>
      </dd>
    </div>
  </dl>

  <footer class="phs-meta" aria-labelledby="phs-meta-title">
    <h4 class="phs-meta__title" id="phs-meta-title">Profile metadata</h4>
    <p class="phs-meta__note">
      Verified information cannot be edited directly. Use <strong>Request update</strong> if something needs correction.
    </p>
    <div class="phs-meta__grid">
      <div class="phs-meta__item">
        <span class="phs-meta__label">Last updated</span>
        <strong class="phs-meta__value" id="phsLastUpdated">—</strong>
      </div>
      <div class="phs-meta__item">
        <span class="phs-meta__label">Updated by</span>
        <strong class="phs-meta__value" id="phsLastProvider">—</strong>
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
