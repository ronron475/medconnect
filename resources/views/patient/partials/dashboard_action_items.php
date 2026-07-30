<?php
/**
 * Dashboard action items — provider follow-ups for the patient.
 * Expects: $patient_followups (list)
 */
$followups = is_array($patient_followups ?? null) ? $patient_followups : [];
$followup_count = count($followups);
$today = date('Y-m-d');

$cleanFollowupMessage = static function (?string $message): string {
    $text = trim((string) $message);
    if ($text === '') {
        return 'Please follow your provider’s instructions for this visit.';
    }
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^Contact\s*\(registration\)\s*:/i', $line)) {
            continue;
        }
        if (preg_match('/^Registered mobile\s*:/i', $line)) {
            continue;
        }
        if (preg_match('/^Email\s*:/i', $line)) {
            continue;
        }
        $kept[] = $line;
    }
    $out = trim(implode(' ', $kept));
    return $out !== '' ? $out : 'Please follow your provider’s instructions for this visit.';
};
?>
<section class="pdash-card pdash-card--followups" id="action-items" aria-labelledby="dash-action-items-title">
  <div id="dashboardActionItems"></div>
  <div class="pdash-card__head">
    <h2 class="pdash-card__title" id="dash-action-items-title">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
      Action Items
    </h2>
    <span class="pdash-card__badge"><?= (int) $followup_count ?> follow-up<?= $followup_count !== 1 ? 's' : '' ?></span>
  </div>
  <p class="pdash-followups__lead">Follow-ups scheduled by your doctor after consultation. Check your email for reminders too.</p>

  <?php if ($followup_count === 0): ?>
    <div class="pdash-empty" style="padding:20px 12px;">
      <p>No follow-up tasks right now. When your provider schedules one, it will appear here automatically.</p>
    </div>
  <?php else: ?>
    <div class="pdash-actions">
      <?php foreach ($followups as $f): ?>
        <?php
        $status = strtolower(trim((string) ($f['status'] ?? 'scheduled')));
        $dateRaw = (string) ($f['followup_date'] ?? '');
        $isOverdue = ($status === 'scheduled' && $dateRaw !== '' && $dateRaw < $today);
        $isToday = ($status === 'scheduled' && $dateRaw === $today);
        $providerName = trim(($f['provider_first'] ?? '') . ' ' . ($f['provider_last'] ?? ''));
        $msg = $cleanFollowupMessage($f['message'] ?? '');
        $statusLabel = $isOverdue ? 'Overdue' : ($isToday ? 'Due today' : ucfirst($status));
        $statusClass = $isOverdue ? 'is-overdue' : ($isToday ? 'is-today' : ('is-' . preg_replace('/[^a-z_]/', '', $status)));
        ?>
        <article class="pdash-action <?= htmlspecialchars($statusClass) ?>">
          <div class="pdash-action__top">
            <div class="pdash-action__date"><?= $dateRaw !== '' ? date('M j, Y', strtotime($dateRaw)) : 'Date TBD' ?></div>
            <span class="pdash-action__status <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
          </div>
          <div class="pdash-action__provider">Dr. <?= htmlspecialchars($providerName !== '' ? $providerName : 'Provider') ?></div>
          <p class="pdash-action__msg"><?= htmlspecialchars($msg) ?></p>
          <?php if (!empty($f['contact_number'])): ?>
            <p class="pdash-action__meta">Registered mobile on file: <?= htmlspecialchars((string) $f['contact_number']) ?></p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
