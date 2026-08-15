<?php
/**
 * Dashboard card: active consultation (scheduled / in progress).
 * Shows the CURRENT consultation only — never past completed cases.
 *
 * Expects: $active_consultation, $pdo (optional for complaint lookup)
 */
$active = $active_consultation ?? null;
if (!$active || empty($active['id'])) {
    return;
}

$asset = defined('ASSET_BASE') ? ASSET_BASE : '';
$status = strtolower(trim((string) ($active['status'] ?? 'scheduled')));
$providerName = trim((string) ($active['provider_name'] ?? ''));
$schedDate = !empty($active['consult_date']) ? date('M j, Y', strtotime((string) $active['consult_date'])) : '—';
$schedTime = !empty($active['consult_time']) ? date('g:i A', strtotime((string) $active['consult_time'])) : '—';
$consultId = (int) ($active['id'] ?? 0);

$concern = trim((string) ($active['consult_type'] ?? ''));
if (isset($pdo) && $pdo instanceof PDO && $consultId > 0) {
    require_once BASE_PATH . '/app/includes/patient_chief_complaints.php';
    $pcc = patient_chief_complaint_for_consultation($pdo, $consultId);
    if ($pcc && trim((string) ($pcc['complaint'] ?? '')) !== '') {
        $concern = trim((string) $pcc['complaint']);
    }
}

$joinAccess = function_exists('consultation_patient_join_access')
    ? consultation_patient_join_access($active)
    : ['allowed' => false, 'mode' => '', 'reason' => ''];

$isLive = $status === 'in_consultation' || (!empty($joinAccess['allowed']));
$statusLabel = $isLive ? 'Ready to join' : ($status === 'in_consultation' ? 'In progress' : 'Scheduled');
$title = $isLive ? 'Your consultation is ready' : 'Consultation Scheduled';
$chipClass = $isLive ? 'pdash-care__status-chip--ready' : 'pdash-care__status-chip--scheduled';

$providerInitials = 'DR';
if ($providerName !== '') {
    $parts = preg_split('/\s+/', preg_replace('/^dr\.?\s*/i', '', $providerName));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = mb_substr($parts[count($parts) - 1] ?? '', 0, 1);
    $providerInitials = strtoupper($first . $last) ?: 'DR';
}
?>
<section class="pdash-card pdash-card--active-consult pdash-care" id="pdashActiveConsultation" aria-labelledby="pdashActiveConsultTitle">
  <div class="pdash-care__top">
    <div class="pdash-care__title-wrap">
      <span class="pdash-care__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </span>
      <div>
        <h2 class="pdash-card__title pdash-care__title" id="pdashActiveConsultTitle"><?= htmlspecialchars($title) ?></h2>
        <p class="pdash-care__lead">This is your current consultation. Past visits stay in My Sessions.</p>
      </div>
    </div>
    <span class="pdash-care__status-chip <?= htmlspecialchars($chipClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
  </div>

  <div class="pdash-care-panel" role="status">
    <div class="pdash-care-panel__grid">
      <div class="pdash-care-doctor">
        <span class="pdash-care-doctor__avatar" aria-hidden="true"><?= htmlspecialchars($providerInitials) ?></span>
        <div class="pdash-care-doctor__body">
          <span class="pdash-care-doctor__eyebrow">Assigned doctor</span>
          <strong class="pdash-care-doctor__name">Dr. <?= htmlspecialchars($providerName !== '' ? $providerName : 'Provider') ?></strong>
          <p class="pdash-care-doctor__note"><?= htmlspecialchars($schedDate) ?> · <?= htmlspecialchars($schedTime) ?></p>
        </div>
      </div>

      <?php if ($concern !== ''): ?>
      <div class="pdash-care-concern">
        <span class="pdash-care-concern__label">Patient complaint</span>
        <p class="pdash-care-concern__text"><?= htmlspecialchars($concern) ?></p>
      </div>
      <?php endif; ?>
    </div>

    <div class="pdash-care-actions">
      <?php if (!empty($joinAccess['allowed']) && !empty($active['room_token'])): ?>
      <button type="button" class="pdash-btn pdash-btn--join pdash-care-actions__btn" data-mc-video-join
        data-token="<?= htmlspecialchars((string) $active['room_token'], ENT_QUOTES, 'UTF-8') ?>"
        data-consultation-id="<?= $consultId ?>"
        data-label="Consultation with Dr. <?= htmlspecialchars($providerName !== '' ? $providerName : 'your provider', ENT_QUOTES, 'UTF-8') ?>">
        Join Consultation
      </button>
      <?php elseif (($joinAccess['mode'] ?? '') === 'scheduled_wait'): ?>
      <span class="pdash-btn pdash-btn--waiting pdash-care-actions__btn" title="<?= htmlspecialchars((string) ($joinAccess['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        Opens at scheduled time
      </span>
      <?php elseif (($joinAccess['mode'] ?? '') === 'waiting'): ?>
      <span class="pdash-btn pdash-btn--waiting pdash-care-actions__btn">Waiting for Provider</span>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($asset . '/views/patient/consultations.php') ?>" class="pdash-btn pdash-btn--outline pdash-care-actions__btn">
        View Appointment
      </a>
    </div>
  </div>
</section>
