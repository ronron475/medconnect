<?php
/**
 * Live consultations panel — existing monitoring.php live payload.
 */
declare(strict_types=1);

$liveApi = $liveApi ?? (ASSET_BASE . '/app/api/admin/monitoring.php?type=live');
$live_colspan = 7;
?>

<div class="header-row" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
  <p class="text-muted" style="margin:0;">Currently active consultations. Auto-refreshes every 30 seconds.</p>
  <span id="liveConsultUpdated" class="text-xs text-muted">Loading…</span>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;" id="liveConsultPanel" data-api="<?= htmlspecialchars($liveApi) ?>">
  <table class="mc-table admin-stack-table">
    <thead>
      <tr>
        <th>Patient</th>
        <th>Provider</th>
        <th>Status</th>
        <th>Start Time</th>
        <th>Duration</th>
        <th>Connection</th>
        <th>Urgency</th>
      </tr>
    </thead>
    <tbody id="liveConsultBody">
      <tr><td colspan="<?= $live_colspan ?>"><div class="mc-table-empty"><p>Loading…</p></div></td></tr>
    </tbody>
  </table>
</div>
