<?php
// $ch_data — set by dashboard.php from DB, empty array if no records

$status_map = [
    'completed'  => 'ch-status--completed',
    'cancelled'  => 'ch-status--cancelled',
    'no-show'    => 'ch-status--noshow',
    'pending'    => 'ch-status--pending',
    'scheduled'  => 'ch-status--scheduled',
];
?>

<div class="ch-section mc-card">

  <div class="ch-section-header">
    <div class="ch-section-title-group">
      <div class="ch-section-icon">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="9" y1="13" x2="15" y2="13"/>
          <line x1="9" y1="17" x2="13" y2="17"/>
        </svg>
      </div>
      <div>
        <h3 class="ch-title">Consultation History</h3>
        <p class="ch-sub">
          <?= count($ch_data) > 0 ? count($ch_data) . ' record' . (count($ch_data) !== 1 ? 's' : '') . ' found' : 'No records' ?>
        </p>
      </div>
    </div>
    <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="ch-view-all">
      View All
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    </a>
  </div>

  <?php if (!empty($ch_data)): ?>

  <!-- Desktop table -->
  <div class="ch-table-wrap">
    <table class="ch-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Provider</th>
          <th>Type</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ch_data as $row): ?>
        <?php
          $is_video   = strtolower($row['type']) === 'video call';
          $status_key = strtolower(str_replace(' ', '-', $row['status']));
          $status_cls = $status_map[$status_key] ?? 'ch-status--pending';
          $provider_initial = strtoupper(substr(explode(' ', $row['provider'])[1] ?? $row['provider'], 0, 1));
        ?>
        <tr>
          <td>
            <span class="ch-date">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="4" width="18" height="18" rx="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
              <?= htmlspecialchars($row['date']) ?>
            </span>
          </td>
          <td>
            <div class="ch-provider">
              <div class="ch-provider-avatar"><?= $provider_initial ?></div>
              <span><?= htmlspecialchars($row['provider']) ?></span>
            </div>
          </td>
          <td>
            <span class="ch-type <?= $is_video ? 'ch-type--video' : 'ch-type--person' ?>">
              <?php if ($is_video): ?>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>
              </svg>
              <?php else: ?>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
              </svg>
              <?php endif; ?>
              <?= htmlspecialchars($row['type']) ?>
            </span>
          </td>
          <td>
            <span class="ch-status <?= $status_cls ?>">
              <span class="ch-status-dot"></span>
              <?= htmlspecialchars($row['status']) ?>
            </span>
          </td>
          <td>
            <a href="<?= ASSET_BASE . htmlspecialchars($row['url']) ?>" class="ch-action-btn" title="View Details">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile card list -->
  <ul class="ch-mobile-list">
    <?php foreach ($ch_data as $row): ?>
    <?php
      $is_video   = strtolower($row['type']) === 'video call';
      $status_key = strtolower(str_replace(' ', '-', $row['status']));
      $status_cls = $status_map[$status_key] ?? 'ch-status--pending';
      $provider_initial = strtoupper(substr(explode(' ', $row['provider'])[1] ?? $row['provider'], 0, 1));
    ?>
    <li class="ch-mobile-item">
      <div class="ch-mobile-top">
        <div class="ch-provider">
          <div class="ch-provider-avatar"><?= $provider_initial ?></div>
          <div>
            <span class="ch-mobile-provider"><?= htmlspecialchars($row['provider']) ?></span>
            <span class="ch-mobile-date"><?= htmlspecialchars($row['date']) ?></span>
          </div>
        </div>
        <span class="ch-status <?= $status_cls ?>">
          <span class="ch-status-dot"></span>
          <?= htmlspecialchars($row['status']) ?>
        </span>
      </div>
      <div class="ch-mobile-bottom">
        <span class="ch-type <?= $is_video ? 'ch-type--video' : 'ch-type--person' ?>">
          <?= htmlspecialchars($row['type']) ?>
        </span>
        <a href="<?= ASSET_BASE . htmlspecialchars($row['url']) ?>" class="ch-action-btn" title="View Details">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php else: ?>

  <div class="ch-empty-state">
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
      <polyline points="14 2 14 8 20 8"/>
      <line x1="9" y1="13" x2="15" y2="13"/>
      <line x1="9" y1="17" x2="13" y2="17"/>
    </svg>
    <p>No consultation history found.</p>
  </div>

  <?php endif; ?>

</div>
