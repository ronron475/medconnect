<?php
/**
 * Dashboard card: submit symptoms for provider-reviewed self-care (non-urgent).
 *
 * Expects: $symptoms_review_pending, $symptoms_review_default_complaint, $symptoms_review_booking (optional)
 *          $symptoms_review_registration_nlp (optional)
 */
$symptoms_review_pending = $symptoms_review_pending ?? [
    'has_pending' => false,
    'triage_id' => 0,
    'complaint' => '',
    'provider_id' => 0,
    'provider_name' => '',
];
$symptoms_review_default_complaint = trim((string) ($symptoms_review_default_complaint ?? ''));
$symptoms_review_registration_nlp = trim((string) ($symptoms_review_registration_nlp ?? ''));
$symptoms_complaint_locked = $symptoms_review_default_complaint !== '';
$has_pending = !empty($symptoms_review_pending['has_pending']);
$review_provider_name = trim((string) ($symptoms_review_pending['provider_name'] ?? ''));
$symptoms_review_booking = $symptoms_review_booking ?? [
    'assigned_has_slots_today' => true,
    'alternate_available' => false,
];
$asset = defined('ASSET_BASE') ? ASSET_BASE : '';
$book_url = $asset . '/views/patient/triage.php';
$care_tips_url = $asset . '/views/patient/my_health.php?tab=care-tips';

$provider_initials = 'DR';
if ($review_provider_name !== '') {
    $parts = preg_split('/\s+/', preg_replace('/^dr\.?\s*/i', '', $review_provider_name));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = mb_substr($parts[count($parts) - 1] ?? '', 0, 1);
    $provider_initials = strtoupper($first . $last) ?: 'DR';
}

$booking_hint = '';
$booking_hint_type = 'muted';
if ($review_provider_name !== '' && !empty($symptoms_review_booking['assigned_has_slots_today'])) {
    $booking_hint = $review_provider_name . ' has open clinic times today.';
    $booking_hint_type = 'success';
} elseif ($review_provider_name !== '' && !empty($symptoms_review_booking['alternate_available'])) {
    $booking_hint = 'Your doctor has no slots today—you can request the next available doctor when booking.';
    $booking_hint_type = 'warn';
} elseif ($review_provider_name !== '') {
    $booking_hint = 'No clinic slots right now. Try again on the next clinic day or contact the health office.';
    $booking_hint_type = 'warn';
}
?>
<section class="pdash-card pdash-card--review pdash-care" id="pdashSymptomsReview" aria-labelledby="pdashSymptomsReviewTitle">
  <div class="pdash-care__top">
    <div class="pdash-care__title-wrap">
      <span class="pdash-care__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
      </span>
      <div>
        <h2 class="pdash-card__title pdash-care__title" id="pdashSymptomsReviewTitle">Reviewed care tips</h2>
        <p class="pdash-care__lead">AI drafts self-care steps; your doctor approves them before you see them.</p>
      </div>
    </div>
    <?php if ($has_pending): ?>
    <span class="pdash-care__status-chip pdash-care__status-chip--review">In review</span>
    <?php else: ?>
    <span class="pdash-care__status-chip pdash-care__status-chip--idle">Not started</span>
    <?php endif; ?>
  </div>

  <?php if ($has_pending): ?>
  <ol class="pdash-care-steps" aria-label="Care tips progress">
    <li class="pdash-care-steps__item is-done">
      <span class="pdash-care-steps__dot" aria-hidden="true">✓</span>
      <span class="pdash-care-steps__label">Submitted</span>
    </li>
    <li class="pdash-care-steps__item is-current" aria-current="step">
      <span class="pdash-care-steps__dot" aria-hidden="true">2</span>
      <span class="pdash-care-steps__label">Doctor reviewing</span>
    </li>
    <li class="pdash-care-steps__item">
      <span class="pdash-care-steps__dot" aria-hidden="true">3</span>
      <span class="pdash-care-steps__label">Care tips ready</span>
    </li>
    <li class="pdash-care-steps__item">
      <span class="pdash-care-steps__dot" aria-hidden="true">4</span>
      <span class="pdash-care-steps__label">Video visit <span class="pdash-care-steps__optional">(optional)</span></span>
    </li>
  </ol>

  <div class="pdash-care-panel" role="status">
    <div class="pdash-care-panel__grid">
      <?php if ($review_provider_name !== ''): ?>
      <div class="pdash-care-doctor">
        <span class="pdash-care-doctor__avatar" aria-hidden="true"><?= htmlspecialchars($provider_initials) ?></span>
        <div class="pdash-care-doctor__body">
          <span class="pdash-care-doctor__eyebrow">Assigned reviewing doctor</span>
          <strong class="pdash-care-doctor__name"><?= htmlspecialchars($review_provider_name) ?></strong>
          <p class="pdash-care-doctor__note">Same doctor for care tips and booking (unless you switch due to no slots).</p>
        </div>
      </div>
      <?php else: ?>
      <div class="pdash-care-doctor pdash-care-doctor--pending">
        <p class="pdash-care-doctor__note">A doctor will be assigned to your case shortly.</p>
      </div>
      <?php endif; ?>

      <?php if (!empty($symptoms_review_pending['complaint'])): ?>
      <div class="pdash-care-concern">
        <span class="pdash-care-concern__label">Your concern</span>
        <p class="pdash-care-concern__text"><?= htmlspecialchars($symptoms_review_pending['complaint']) ?></p>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($booking_hint !== ''): ?>
    <p class="pdash-care-hint pdash-care-hint--<?= htmlspecialchars($booking_hint_type) ?>"><?= htmlspecialchars($booking_hint) ?></p>
    <?php endif; ?>

    <div class="pdash-care-actions">
      <a href="<?= htmlspecialchars($care_tips_url) ?>" class="pdash-btn pdash-btn--outline pdash-care-actions__btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"/></svg>
        Track in Care tips
      </a>
      <a href="<?= htmlspecialchars($book_url) ?>" class="pdash-btn pdash-btn--primary pdash-care-actions__btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
        Book consultation
      </a>
    </div>
  </div>

  <details class="pdash-care-how">
    <summary>How this works</summary>
    <ul>
      <li>Non-urgent only—emergency symptoms need in-person or ER care.</li>
      <li>The system assigns a doctor with the lightest review queue (you don’t pick on this step).</li>
      <li>When approved, open <strong>Care tips</strong> to read doctor-approved guidance.</li>
    </ul>
  </details>

  <?php else: ?>

  <ol class="pdash-care-steps pdash-care-steps--idle" aria-label="Care tips progress">
    <li class="pdash-care-steps__item is-current" aria-current="step">
      <span class="pdash-care-steps__dot" aria-hidden="true">1</span>
      <span class="pdash-care-steps__label">Describe concern</span>
    </li>
    <li class="pdash-care-steps__item">
      <span class="pdash-care-steps__dot" aria-hidden="true">2</span>
      <span class="pdash-care-steps__label">Doctor reviews</span>
    </li>
    <li class="pdash-care-steps__item">
      <span class="pdash-care-steps__dot" aria-hidden="true">3</span>
      <span class="pdash-care-steps__label">Care tips ready</span>
    </li>
  </ol>

  <form
    id="pdashSymptomsReviewForm"
    class="pdash-review-form"
    novalidate
    <?= $symptoms_complaint_locked ? 'data-complaint-locked="1"' : '' ?>
  >
    <?php if ($symptoms_review_registration_nlp !== ''): ?>
    <input type="hidden" id="pdashSymptomsRegistrationNlp" value="<?= htmlspecialchars($symptoms_review_registration_nlp, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <label class="form-label pdash-care-form__label" for="pdashSymptomsComplaint">
      Chief complaint
      <?php if ($symptoms_complaint_locked): ?>
      <span class="pdash-care-form__lock-badge">From registration</span>
      <?php endif; ?>
    </label>
    <textarea
      id="pdashSymptomsComplaint"
      name="chief_complaint"
      class="form-control pdash-care-form__input<?= $symptoms_complaint_locked ? ' pdash-care-form__input--locked' : '' ?>"
      rows="3"
      maxlength="500"
      placeholder="e.g. mild sore throat and runny nose for two days…"
      <?= $symptoms_complaint_locked ? 'readonly aria-readonly="true"' : 'required' ?>
    ><?= htmlspecialchars($symptoms_review_default_complaint) ?></textarea>
    <p class="pdash-care-form__hint">
      <?php if ($symptoms_complaint_locked): ?>
      This is the chief complaint you entered during registration. Submit it for your doctor to review.
      <?php else: ?>
      At least a short sentence helps the doctor review your case faster.
      <?php endif; ?>
    </p>
    <div id="pdashSymptomsReviewAlert" class="patient-triage-alert" role="alert" hidden></div>
    <button type="submit" class="pdash-btn pdash-btn--primary pdash-care-form__submit" id="pdashSymptomsReviewSubmit">
      Submit for doctor review
    </button>
  </form>

  <details class="pdash-care-how">
    <summary>How this works</summary>
    <ul>
      <li>AI suggests self-care steps; a licensed doctor must approve before you see them.</li>
      <li>A doctor is assigned automatically—you’ll see their name after you submit.</li>
      <li>Track progress here and under <strong>My Health → Care tips</strong>.</li>
    </ul>
  </details>

  <?php endif; ?>
</section>
