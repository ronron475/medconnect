<?php
/**
 * Dashboard card: care tips approved — ready to schedule with reviewing doctor.
 *
 * Expects: $care_tips_ready_to_schedule, $symptoms_review_booking (optional).
 */
$care_tips_ready_to_schedule = $care_tips_ready_to_schedule ?? [
    'ready' => false,
    'triage_id' => 0,
    'complaint' => '',
    'provider_id' => 0,
    'provider_name' => '',
];
if (empty($care_tips_ready_to_schedule['ready'])) {
    return;
}

$review_provider_name = trim((string) ($care_tips_ready_to_schedule['provider_name'] ?? ''));
$complaint = trim((string) ($care_tips_ready_to_schedule['complaint'] ?? ''));
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
    $booking_hint = $review_provider_name . ' has open clinic times today — pick a slot to schedule this consultation.';
    $booking_hint_type = 'success';
} elseif ($review_provider_name !== '' && !empty($symptoms_review_booking['alternate_available'])) {
    $booking_hint = 'Your doctor has no slots today—you can request the next available doctor when booking.';
    $booking_hint_type = 'warn';
} elseif ($review_provider_name !== '') {
    $booking_hint = 'No clinic slots right now. Try again on the next clinic day or contact the health office.';
    $booking_hint_type = 'warn';
}
?>
<section class="pdash-card pdash-card--review pdash-care" id="pdashCareTipsReady" aria-labelledby="pdashCareTipsReadyTitle">
  <div class="pdash-care__top">
    <div class="pdash-care__title-wrap">
      <span class="pdash-care__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </span>
      <div>
        <h2 class="pdash-card__title pdash-care__title" id="pdashCareTipsReadyTitle">Care Tips Approved</h2>
        <p class="pdash-care__lead">Your reviewing doctor approved your care guidance. You can now schedule this consultation.</p>
      </div>
    </div>
    <span class="pdash-care__status-chip pdash-care__status-chip--ready">Ready to schedule</span>
  </div>

  <ol class="pdash-care-steps" aria-label="Care tips progress">
    <li class="pdash-care-steps__item is-done">
      <span class="pdash-care-steps__dot" aria-hidden="true">✓</span>
      <span class="pdash-care-steps__label">Submitted</span>
    </li>
    <li class="pdash-care-steps__item is-done">
      <span class="pdash-care-steps__dot" aria-hidden="true">✓</span>
      <span class="pdash-care-steps__label">Doctor reviewed</span>
    </li>
    <li class="pdash-care-steps__item is-done">
      <span class="pdash-care-steps__dot" aria-hidden="true">✓</span>
      <span class="pdash-care-steps__label">Care tips ready</span>
    </li>
    <li class="pdash-care-steps__item is-current" aria-current="step">
      <span class="pdash-care-steps__dot" aria-hidden="true">4</span>
      <span class="pdash-care-steps__label">Schedule visit</span>
    </li>
  </ol>

  <div class="pdash-care-panel" role="status">
    <div class="pdash-care-panel__grid">
      <?php if ($review_provider_name !== ''): ?>
      <div class="pdash-care-doctor">
        <span class="pdash-care-doctor__avatar" aria-hidden="true"><?= htmlspecialchars($provider_initials) ?></span>
        <div class="pdash-care-doctor__body">
          <span class="pdash-care-doctor__eyebrow">Assigned doctor</span>
          <strong class="pdash-care-doctor__name"><?= htmlspecialchars($review_provider_name) ?></strong>
          <p class="pdash-care-doctor__note">Same doctor for care tips review and your video consultation.</p>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($complaint !== ''): ?>
      <div class="pdash-care-concern">
        <span class="pdash-care-concern__label">Your concern</span>
        <p class="pdash-care-concern__text"><?= htmlspecialchars($complaint) ?></p>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($booking_hint !== ''): ?>
    <p class="pdash-care-hint pdash-care-hint--<?= htmlspecialchars($booking_hint_type) ?>"><?= htmlspecialchars($booking_hint) ?></p>
    <?php endif; ?>

    <div class="pdash-care-actions">
      <a href="<?= htmlspecialchars($care_tips_url) ?>" class="pdash-btn pdash-btn--outline pdash-care-actions__btn">
        View care tips
      </a>
      <a href="<?= htmlspecialchars($book_url) ?>" class="pdash-btn pdash-btn--primary pdash-care-actions__btn">
        Book Consultation
      </a>
    </div>
  </div>
</section>
