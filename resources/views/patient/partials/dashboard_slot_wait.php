<?php
/**
 * Dashboard card: NON-URGENT waiting queue / slot-available state.
 *
 * Expects: $slot_wait_state
 */
$slot_wait_state = $slot_wait_state ?? [];
if (empty($slot_wait_state['active'])) {
    return;
}

$status = (string) ($slot_wait_state['status'] ?? 'waiting');
$isAvailable = $status === 'slot_available';
$complaint = trim((string) ($slot_wait_state['complaint'] ?? ''));
$providerName = trim((string) ($slot_wait_state['eligible_provider_name'] ?? $slot_wait_state['assigned_provider_name'] ?? ''));
$waitingSince = trim((string) ($slot_wait_state['waiting_since_label'] ?? ''));
$queuePosition = (int) ($slot_wait_state['queue_position'] ?? 0);
$waitingCount = (int) ($slot_wait_state['waiting_count'] ?? 0);
$careStatus = (string) ($slot_wait_state['care_tips_status'] ?? '');
$careLabel = trim((string) ($slot_wait_state['care_tips_label'] ?? ''));
$bookUrl = (string) ($slot_wait_state['book_url'] ?? '');
$careTipsUrl = (string) ($slot_wait_state['care_tips_url'] ?? '');

$provider_initials = 'DR';
if ($providerName !== '') {
    $parts = preg_split('/\s+/', preg_replace('/^dr\.?\s*/i', '', $providerName));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = mb_substr($parts[count($parts) - 1] ?? '', 0, 1);
    $provider_initials = strtoupper($first . $last) ?: 'DR';
}

$careApproved = $careStatus === 'approved';
$carePending = $careStatus === 'pending_approval' || $careStatus === 'hidden';
$waitingLead = 'There is currently no available provider consultation slot. Your case is safely in the waiting queue. We will notify you when a provider opens an available consultation schedule.';
$availableLead = $providerName !== ''
    ? ($providerName . ' has an available consultation schedule.')
    : 'A consultation slot is now available.';
?>
<section class="pdash-card pdash-card--review pdash-care pdash-wait" id="pdashSlotWait" aria-labelledby="pdashSlotWaitTitle" data-wait-status="<?= htmlspecialchars($status) ?>">
  <div class="pdash-care__top">
    <div class="pdash-care__title-wrap">
      <span class="pdash-care__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </span>
      <div>
        <h2 class="pdash-card__title pdash-care__title" id="pdashSlotWaitTitle"><?= $isAvailable ? 'Consultation Slot Available' : 'Waiting for Doctor Availability' ?></h2>
        <p class="pdash-care__lead" id="pdashSlotWaitLead"><?= htmlspecialchars($isAvailable ? $availableLead : $waitingLead) ?></p>
      </div>
    </div>
    <span class="pdash-care__status-chip <?= $isAvailable ? 'pdash-care__status-chip--ready' : 'pdash-care__status-chip--wait' ?>" id="pdashSlotWaitChip">
      <?= $isAvailable ? 'Consultation Slot Available' : 'Waiting for Doctor Availability' ?>
    </span>
  </div>

  <div class="pdash-wait__badge" id="pdashSlotWaitTriageBadge">NON-URGENT — <?= $isAvailable ? 'CONSULTATION SLOT AVAILABLE' : 'WAITING FOR DOCTOR AVAILABILITY' ?></div>

  <div class="pdash-care-panel" role="status">
    <div class="pdash-care-panel__grid">
      <div class="pdash-care-concern">
        <span class="pdash-care-concern__label">Patient complaint</span>
        <p class="pdash-care-concern__text" id="pdashSlotWaitComplaint"><?= $complaint !== '' ? htmlspecialchars($complaint) : 'Your complaint is on file.' ?></p>
      </div>
      <div class="pdash-care-doctor">
        <span class="pdash-care-doctor__avatar" aria-hidden="true"><?= htmlspecialchars($provider_initials) ?></span>
        <div class="pdash-care-doctor__body">
          <span class="pdash-care-doctor__eyebrow" id="pdashSlotWaitProviderEyebrow"><?= $isAvailable ? 'Available provider' : 'Consultation status' ?></span>
          <strong class="pdash-care-doctor__name" id="pdashSlotWaitProvider"><?= htmlspecialchars($isAvailable
              ? ($providerName !== '' ? $providerName : 'A healthcare provider')
              : 'Waiting for Doctor Availability') ?></strong>
          <p class="pdash-care-doctor__note" id="pdashSlotWaitMeta">
            <?php if ($waitingSince !== ''): ?>
              Waiting since <?= htmlspecialchars($waitingSince) ?>
            <?php else: ?>
              Waiting for Doctor Availability
            <?php endif; ?>
            <?php if (!$isAvailable && $queuePosition > 0): ?>
              · Queue position <?= (int) $queuePosition ?><?= $waitingCount > 0 ? ' of ' . (int) $waitingCount : '' ?>
            <?php endif; ?>
          </p>
        </div>
      </div>
    </div>

    <div class="pdash-wait__care" id="pdashSlotWaitCare">
      <?php if ($careApproved): ?>
      <p class="pdash-care-hint pdash-care-hint--success"><?= htmlspecialchars($careLabel !== '' ? $careLabel : 'Reviewed by your provider') ?>. You may view the approved care guidance.</p>
      <?php elseif ($carePending): ?>
      <p class="pdash-care-hint pdash-care-hint--warn">Care Tips: Pending Provider Review. AI-generated guidance is not final medical advice until a provider reviews it.</p>
      <?php elseif ($careLabel !== ''): ?>
      <p class="pdash-care-hint pdash-care-hint--muted"><?= htmlspecialchars($careLabel) ?></p>
      <?php endif; ?>
      <?php if (!$isAvailable): ?>
      <p class="pdash-care-hint pdash-care-hint--muted" id="pdashSlotWaitFifo">When a provider opens a real slot, patients are offered a chance to book in waiting order. Appointments are not created automatically.</p>
      <?php endif; ?>
    </div>

    <div class="pdash-care-actions">
      <?php if ($careTipsUrl !== ''): ?>
      <a href="<?= htmlspecialchars($careTipsUrl) ?>" class="pdash-btn pdash-btn--outline pdash-care-actions__btn" id="pdashSlotWaitCareLink">
        <?= $careApproved ? 'View Care Guidance' : 'Track care tips' ?>
      </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($bookUrl) ?>" class="pdash-btn pdash-btn--primary pdash-care-actions__btn" id="pdashSlotWaitBook" <?= $isAvailable ? '' : 'hidden' ?>>
        Book Consultation
      </a>
    </div>
  </div>
</section>
