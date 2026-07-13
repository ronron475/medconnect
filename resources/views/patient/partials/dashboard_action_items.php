<?php
/**
 * Dashboard action items — follow-ups from providers.
 * Expects: $patient_followups (list)
 */
$followup_count = count($patient_followups ?? []);
?>
<section class="pdash-card" id="dashboardActionItems" aria-labelledby="dash-action-items-title">
  <div class="pdash-card__head">
    <h2 class="pdash-card__title" id="dash-action-items-title">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
      Action Items
    </h2>
    <span class="pdash-card__badge"><?= $followup_count ?> follow-up<?= $followup_count !== 1 ? 's' : '' ?></span>
  </div>
  <?php if (empty($patient_followups)): ?>
    <div class="pdash-empty" style="padding:20px 12px;">
      <p>No follow-up tasks right now. Your provider will add instructions here when needed.</p>
    </div>
  <?php else: ?>
    <div class="pdash-actions">
      <?php foreach (array_slice($patient_followups, 0, 5) as $f): ?>
        <article class="pdash-action">
          <div class="pdash-action__date"><?= date('M j, Y', strtotime($f['followup_date'])) ?></div>
          <div class="pdash-action__provider">Dr. <?= htmlspecialchars(trim(($f['provider_first'] ?? '') . ' ' . ($f['provider_last'] ?? ''))) ?></div>
          <p class="pdash-action__msg"><?= htmlspecialchars($f['message'] ?: 'No instructions provided.') ?></p>
          <span class="pdash-action__status"><?= htmlspecialchars(ucfirst((string) ($f['status'] ?? 'scheduled'))) ?></span>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
