<?php
/**
 * My Health page toolbar — metrics + quick actions (no greeting block).
 * Expects: $pt, $history, $completed_visits, $counts, $care_tips_active_count, $active_tab, $care_timeline
 */
$scheduled_visits = count(array_filter($history ?? [], static function ($h) {
    $s = strtolower((string) ($h['status'] ?? ''));
    return in_array($s, ['scheduled', 'pending', 'in_consultation'], true);
}));
$totalVisits = count($history ?? []);
$healthFiles = (int) ($counts['all'] ?? 0);
$activeTips = (int) $care_tips_active_count;

// Only show real counts (visits / files / tips). Timeline activity is already
// reflected in the Care Timeline tab badge — avoid a vague "On timeline" chip.
$metrics = [];
if ($totalVisits > 0) {
    $metrics[] = [
        'tab' => 'timeline',
        'value' => $totalVisits,
        'label' => 'Visits',
        'hint' => $scheduled_visits > 0 ? $scheduled_visits . ' upcoming' : '',
    ];
}
if ((int) $completed_visits > 0) {
    $metrics[] = [
        'tab' => 'timeline',
        'value' => (int) $completed_visits,
        'label' => 'Completed',
        'hint' => '',
    ];
}
if ($healthFiles > 0) {
    $metrics[] = [
        'tab' => 'files',
        'value' => $healthFiles,
        'label' => 'Files',
        'hint' => '',
    ];
}
if ($activeTips > 0) {
    $metrics[] = [
        'tab' => 'care-tips',
        'value' => $activeTips,
        'label' => 'Care tips',
        'hint' => '',
    ];
}
$showMetrics = $metrics !== [];
?>
<header class="pmh-hero pmh-hero--toolbar" aria-label="My Health actions">
  <?php if ($showMetrics): ?>
  <div class="pmh-hero__metrics" role="list" aria-label="Health overview">
    <?php foreach ($metrics as $metric): ?>
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=<?= htmlspecialchars($metric['tab']) ?>"
       class="pmh-metric-pill<?= $active_tab === $metric['tab'] ? ' is-active' : '' ?>"
       role="listitem">
      <span class="pmh-metric-pill__value"><?= (int) $metric['value'] ?></span>
      <span class="pmh-metric-pill__copy">
        <span class="pmh-metric-pill__label"><?= htmlspecialchars($metric['label']) ?></span>
        <?php if ($metric['hint'] !== ''): ?>
          <span class="pmh-metric-pill__hint"><?= htmlspecialchars($metric['hint']) ?></span>
        <?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="pmh-hero__actions">
    <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php" class="pmh-btn pmh-btn--outline">Health Summary</a>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Book consultation</a>
  </div>
</header>
