<?php
/**
 * Unified My Health page header.
 * Expects: $pt, $history, $completed_visits, $counts, $care_tips_active_count, $active_tab
 */
$firstName = trim((string) ($pt['first_name'] ?? ''));
$patientNumber = (string) ($pt['patient_number'] ?? '');
$scheduled_visits = count(array_filter($history ?? [], static function ($h) {
    $s = strtolower((string) ($h['status'] ?? ''));
    return in_array($s, ['scheduled', 'pending', 'in_consultation'], true);
}));
?>
<header class="pmh-hero">
  <div class="pmh-hero__top">
    <div class="pmh-hero__intro">
      <p class="pmh-hero__eyebrow">My Health</p>
      <h2 class="pmh-hero__title">
        <?= $firstName !== '' ? 'Hi, ' . htmlspecialchars($firstName) : 'Your care hub' ?>
      </h2>
      <p class="pmh-hero__text">
        <?php if ($patientNumber !== ''): ?>
          <span class="pmh-hero__id"><?= htmlspecialchars($patientNumber) ?></span>
          <span class="pmh-hero__sep" aria-hidden="true">·</span>
        <?php endif; ?>
        Visits, provider records, and approved self-care guidance in one place.
        Permanent profile details live in
        <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php">Health Summary</a>.
      </p>
    </div>
    <div class="pmh-hero__actions">
      <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php" class="pmh-btn pmh-btn--outline">Health Summary</a>
      <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Book consultation</a>
    </div>
  </div>

  <div class="pmh-hero__metrics" role="list" aria-label="Health overview">
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=timeline"
       class="pmh-metric-pill<?= $active_tab === 'timeline' ? ' is-active' : '' ?>"
       role="listitem">
      <span class="pmh-metric-pill__value"><?= count($history ?? []) ?></span>
      <span class="pmh-metric-pill__label">Total visits</span>
      <?php if ($scheduled_visits > 0): ?>
        <span class="pmh-metric-pill__hint"><?= (int) $scheduled_visits ?> upcoming</span>
      <?php endif; ?>
    </a>
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=timeline"
       class="pmh-metric-pill<?= $active_tab === 'timeline' ? ' is-active' : '' ?>"
       role="listitem">
      <span class="pmh-metric-pill__value"><?= (int) $completed_visits ?></span>
      <span class="pmh-metric-pill__label">Completed</span>
    </a>
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=files"
       class="pmh-metric-pill<?= $active_tab === 'files' ? ' is-active' : '' ?>"
       role="listitem">
      <span class="pmh-metric-pill__value"><?= (int) ($counts['all'] ?? 0) ?></span>
      <span class="pmh-metric-pill__label">Health files</span>
    </a>
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=care-tips"
       class="pmh-metric-pill<?= $active_tab === 'care-tips' ? ' is-active' : '' ?>"
       role="listitem">
      <span class="pmh-metric-pill__value"><?= (int) $care_tips_active_count ?></span>
      <span class="pmh-metric-pill__label">Active care tips</span>
      <?php if ($care_tips_active_count > 0): ?>
        <span class="pmh-metric-pill__hint">Needs attention</span>
      <?php endif; ?>
    </a>
  </div>
</header>
