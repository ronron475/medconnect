<?php
// $triage_data  — set by dashboard.php from DB, null if no record
// $consult_data — set by dashboard.php from DB, empty array if no records

// Risk badge config
$risk_map = [
    'low'      => ['label' => 'Low Risk',      'class' => 'badge--green'],
    'moderate' => ['label' => 'Moderate Risk', 'class' => 'badge--amber'],
    'high'     => ['label' => 'High Risk',     'class' => 'badge--red'],
];
?>

<div class="tc-grid">

  <!-- ── Triage Results Card ── -->
  <div class="tc-card mc-card tc-card--triage">

    <div class="tc-card-header">
      <div class="tc-card-icon tc-card-icon--triage">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
        </svg>
      </div>
      <div>
        <h3 class="tc-card-title">Triage Results</h3>
        <p class="tc-card-sub">Latest assessment</p>
      </div>
      <?php if ($triage_data): ?>
      <?php
        $risk_key   = strtolower($triage_data['level']);
        $risk_badge = $risk_map[$risk_key] ?? ['label' => htmlspecialchars($triage_data['level']), 'class' => 'badge--amber'];
      ?>
      <span class="tc-badge <?= $risk_badge['class'] ?>">
        <span class="tc-badge-dot"></span>
        <?= htmlspecialchars($risk_badge['label']) ?>
      </span>
      <?php endif; ?>
    </div>

    <?php if ($triage_data): ?>
    <div class="tc-triage-body">
      <div class="tc-triage-level">
        <span class="tc-triage-level-label">Triage Level</span>
        <span class="tc-triage-level-value level--<?= htmlspecialchars($risk_key) ?>">
          <?= htmlspecialchars($triage_data['level']) ?>
        </span>
      </div>
      <div class="tc-triage-symptoms">
        <span class="tc-field-label">Symptoms Reported</span>
        <p class="tc-field-value"><?= htmlspecialchars($triage_data['symptoms']) ?></p>
      </div>
      <div class="tc-triage-date">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="4" width="18" height="18" rx="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/>
          <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        Assessed on <?= htmlspecialchars($triage_data['date']) ?>
      </div>
    </div>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="tc-btn tc-btn--outline">
      View Full Result
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    </a>
    <?php else: ?>
    <div class="tc-empty-state">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
      </svg>
      <p>No triage results available yet.</p>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── Upcoming Consultations Card ── -->
  <div class="tc-card mc-card tc-card--consult">

    <div class="tc-card-header">
      <div class="tc-card-icon tc-card-icon--consult">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </div>
      <div>
        <h3 class="tc-card-title">Upcoming Consultations</h3>
        <p class="tc-card-sub">
          <?= count($consult_data) > 0 ? count($consult_data) . ' scheduled' : 'None scheduled' ?>
        </p>
      </div>
    </div>

    <?php if (!empty($consult_data)): ?>
    <ul class="tc-consult-list">
      <?php foreach ($consult_data as $c): ?>
      <?php $is_video = strtolower($c['type']) === 'video call'; ?>
      <li class="tc-consult-item">
        <div class="tc-consult-type-icon <?= $is_video ? 'type-icon--video' : 'type-icon--person' ?>">
          <?php if ($is_video): ?>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
          </svg>
          <?php else: ?>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
          </svg>
          <?php endif; ?>
        </div>
        <div class="tc-consult-info">
          <span class="tc-consult-provider"><?= htmlspecialchars($c['provider']) ?></span>
          <span class="tc-consult-meta">
            <?= htmlspecialchars($c['date']) ?> &middot; <?= htmlspecialchars($c['time']) ?>
          </span>
          <span class="tc-consult-type-label <?= $is_video ? 'type-label--video' : 'type-label--person' ?>">
            <?= htmlspecialchars($c['type']) ?>
          </span>
        </div>
        <a href="<?= ASSET_BASE . htmlspecialchars($c['url']) ?>" class="tc-consult-action <?= $is_video ? 'tc-btn--join' : 'tc-btn--details' ?>">
          <?= $is_video ? 'Join' : 'Details' ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="tc-empty-state">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <p>No upcoming consultations.</p>
    </div>
    <?php endif; ?>

    <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="tc-btn tc-btn--outline">
      View All Consultations
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    </a>

  </div>

</div>
