<?php
/**
 * Permanent medical profile — registration data only (read-only).
 */
?>
<section class="phs-hero" aria-label="Health Summary overview">
  <div class="phs-hero__content">
    <p class="phs-hero__eyebrow">Permanent Medical Profile</p>
    <h1 class="phs-hero__title">Health Summary</h1>
    <p class="phs-hero__sub">
      Verified registration data for quick reference. This is not consultation records or visit history.
    </p>
  </div>
  <div class="phs-hero__actions">
    <button type="button" class="phs-btn phs-btn--outline" id="phsRequestUpdateBtn" hidden>
      Request Update
    </button>
  </div>
</section>

<div id="phsAlert" class="phs-alert" role="alert" hidden></div>

<div id="phsPendingBanner" class="phs-pending-banner" hidden role="status">
  <span class="phs-pending-banner__icon" aria-hidden="true">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
  </span>
  <div class="phs-pending-banner__text">
    <strong>Update request pending</strong>
    <p>A provider will review your request during or after your next consultation.</p>
  </div>
</div>

<div id="phsSkeleton" class="phs-skeleton-grid" aria-busy="true" aria-label="Loading health summary">
  <?php for ($i = 0; $i < 4; $i++): ?>
  <div class="phs-skeleton-card">
    <div class="phs-skeleton-line phs-skeleton-line--short"></div>
    <div class="phs-skeleton-line"></div>
    <div class="phs-skeleton-line phs-skeleton-line--medium"></div>
  </div>
  <?php endfor; ?>
</div>

<div id="phsContent" class="phs-content" hidden>
  <div class="phs-grid">
    <article class="phs-card phs-card--blood" aria-labelledby="phs-blood-title">
      <header class="phs-card__head">
        <span class="phs-card__icon phs-card__icon--blood" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>
        </span>
        <div class="phs-card__head-text">
          <h2 class="phs-card__title" id="phs-blood-title">Blood Type</h2>
          <p class="phs-card__hint">From registration profile</p>
        </div>
      </header>
      <div class="phs-card__body">
        <div class="phs-value phs-value--blood" id="phsBloodType">—</div>
      </div>
    </article>

    <article class="phs-card phs-card--allergy" aria-labelledby="phs-allergy-title">
      <header class="phs-card__head">
        <span class="phs-card__icon phs-card__icon--allergy" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </span>
        <div class="phs-card__head-text">
          <h2 class="phs-card__title" id="phs-allergy-title">Allergies</h2>
          <p class="phs-card__hint">Known drug &amp; substance allergies</p>
        </div>
      </header>
      <div class="phs-card__body">
        <ul id="phsAllergies" class="phs-chip-list"></ul>
        <p id="phsAllergiesEmpty" class="phs-empty" hidden>No known allergies</p>
      </div>
    </article>

    <article class="phs-card phs-card--conditions" aria-labelledby="phs-conditions-title">
      <header class="phs-card__head">
        <span class="phs-card__icon phs-card__icon--conditions" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        </span>
        <div class="phs-card__head-text">
          <h2 class="phs-card__title" id="phs-conditions-title">Medical Conditions</h2>
          <p class="phs-card__hint">Chronic or permanent conditions</p>
        </div>
      </header>
      <div class="phs-card__body">
        <ul id="phsConditions" class="phs-chip-list"></ul>
        <p id="phsConditionsEmpty" class="phs-empty" hidden>None recorded</p>
      </div>
    </article>

    <article class="phs-card phs-card--meds" aria-labelledby="phs-meds-title">
      <header class="phs-card__head">
        <span class="phs-card__icon phs-card__icon--meds" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><line x1="8.5" y1="8.5" x2="15.5" y2="15.5"/></svg>
        </span>
        <div class="phs-card__head-text">
          <h2 class="phs-card__title" id="phs-meds-title">Maintenance Meds</h2>
          <p class="phs-card__hint">Medications taken regularly</p>
        </div>
      </header>
      <div class="phs-card__body">
        <ul id="phsMedications" class="phs-chip-list phs-chip-list--meds"></ul>
        <p id="phsMedicationsEmpty" class="phs-empty" hidden>No maintenance medications</p>
      </div>
    </article>
  </div>

  <footer class="phs-meta-strip" aria-labelledby="phs-meta-title">
    <h2 class="phs-meta-strip__title" id="phs-meta-title">Profile metadata</h2>
    <div class="phs-meta-strip__grid">
      <div class="phs-meta-item">
        <span class="phs-meta-item__label">Last updated</span>
        <strong class="phs-meta-item__value" id="phsLastUpdated">—</strong>
      </div>
      <div class="phs-meta-item">
        <span class="phs-meta-item__label">Updated by</span>
        <strong class="phs-meta-item__value" id="phsLastProvider">—</strong>
      </div>
    </div>
    <p class="phs-meta-strip__note">
      Profile changes require provider verification. Use <strong>Request Update</strong> if information needs correction.
    </p>
  </footer>
</div>

<div id="phsRequestModal" class="phs-modal" hidden role="dialog" aria-modal="true" aria-labelledby="phsRequestModalTitle">
  <div class="phs-modal__backdrop" data-phs-close-modal></div>
  <div class="phs-modal__card">
    <h3 id="phsRequestModalTitle" class="phs-modal__title">Request Medical Profile Update</h3>
    <p class="phs-modal__lead">You cannot edit blood type, allergies, conditions, or medications directly. Your request will be sent to a healthcare provider for verification.</p>
    <label class="phs-field" for="phsRequestNote">
      <span class="phs-field__label">Additional note (optional)</span>
      <textarea id="phsRequestNote" class="phs-field__input" rows="3" maxlength="500" placeholder="Describe what needs to be updated…"></textarea>
    </label>
    <div class="phs-modal__actions">
      <button type="button" class="phs-btn phs-btn--outline" data-phs-close-modal>Cancel</button>
      <button type="button" class="phs-btn phs-btn--primary" id="phsRequestSubmit">Submit Request</button>
    </div>
  </div>
</div>
