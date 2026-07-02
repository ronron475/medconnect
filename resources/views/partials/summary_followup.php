<?php
// $summary_data  — set by dashboard.php from DB, null if no record
// $followup_data — set by dashboard.php from DB, null if no record

$fu_completed = $followup_data
    ? strtolower($followup_data['status']) === 'completed'
    : false;
?>

<div class="sf-grid">

  <!-- ── Consultation Summary Card ── -->
  <div class="sf-card mc-card sf-card--summary">

    <div class="sf-card-header">
      <div class="sf-icon sf-icon--blue">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="9" y1="13" x2="15" y2="13"/>
          <line x1="9" y1="17" x2="13" y2="17"/>
        </svg>
      </div>
      <div class="sf-card-titles">
        <h3 class="sf-card-title">Consultation Summary</h3>
        <p class="sf-card-sub">Most recent visit</p>
      </div>
    </div>

    <?php if ($summary_data): ?>
    <div class="sf-summary-meta">
      <span class="sf-meta-item">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="4" width="18" height="18" rx="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/>
          <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        <?= htmlspecialchars($summary_data['date']) ?>
      </span>
      <span class="sf-meta-dot" aria-hidden="true"></span>
      <span class="sf-meta-item">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        <?= htmlspecialchars($summary_data['provider']) ?>
      </span>
    </div>
    <div class="sf-summary-body">
      <div class="sf-field">
        <span class="sf-field-label">Diagnosis / Visit Purpose</span>
        <p class="sf-field-value sf-field-value--diagnosis"><?= htmlspecialchars($summary_data['diagnosis']) ?></p>
      </div>
      <div class="sf-field">
        <span class="sf-field-label">Recommendation</span>
        <p class="sf-field-value"><?= htmlspecialchars($summary_data['recommendation']) ?></p>
      </div>
    </div>
    <a href="<?= ASSET_BASE . htmlspecialchars($summary_data['url']) ?>" class="sf-btn sf-btn--primary">
      View Summary
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    </a>
    <?php else: ?>
    <div class="sf-empty-state">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
      <p>No consultation summary available.</p>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── Follow-Up Reminder Card ── -->
  <div class="sf-card mc-card sf-card--followup">

    <div class="sf-card-header">
      <div class="sf-icon sf-icon--teal">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
      </div>
      <div class="sf-card-titles">
        <h3 class="sf-card-title">Follow-Up Reminder</h3>
        <p class="sf-card-sub">Next scheduled check-in</p>
      </div>
      <?php if ($followup_data): ?>
      <span class="sf-status-badge <?= $fu_completed ? 'sf-badge--done' : 'sf-badge--pending' ?>">
        <span class="sf-badge-dot"></span>
        <?= htmlspecialchars($followup_data['status']) ?>
      </span>
      <?php endif; ?>
    </div>

    <?php if ($followup_data): ?>
    <div class="sf-followup-date-block">
      <div class="sf-date-icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="4" width="18" height="18" rx="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8"  y1="2" x2="8"  y2="6"/>
          <line x1="3"  y1="10" x2="21" y2="10"/>
          <polyline points="9 16 11 18 15 14"/>
        </svg>
      </div>
      <div>
        <span class="sf-date-label">Follow-Up Date</span>
        <span class="sf-date-value"><?= htmlspecialchars($followup_data['date']) ?></span>
      </div>
    </div>
    <div class="sf-followup-message">
      <span class="sf-field-label">Reminder</span>
      <p class="sf-field-value"><?= htmlspecialchars($followup_data['message']) ?></p>
    </div>
    <a href="<?= ASSET_BASE . htmlspecialchars($followup_data['url']) ?>" class="sf-btn <?= $fu_completed ? 'sf-btn--outline' : 'sf-btn--teal' ?>">
      View Follow-Up
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    </a>
    <?php else: ?>
    <div class="sf-empty-state">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <p>No follow-up reminders available.</p>
    </div>
    <?php endif; ?>

  </div>

</div>
