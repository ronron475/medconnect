<?php
// ── Hero data — all sourced from $pt (MySQL row) passed by dashboard.php ──
// Replace the fallback strings with real DB values as they become available.
$hero_name     = htmlspecialchars(($pt['first_name'] ?? 'Patient') . ' ' . ($pt['last_name'] ?? ''));
$hero_initials = strtoupper(substr($pt['first_name'] ?? 'P', 0, 1) . substr($pt['last_name'] ?? '', 0, 1));
$hero_age      = $pt['age']               ?? '—';
$hero_gender   = ucfirst($pt['gender']    ?? '—');
$hero_barangay = htmlspecialchars($pt['barangay']          ?? '—');
$hero_city     = htmlspecialchars($pt['city_municipality'] ?? '—');
$hero_address  = $hero_barangay !== '—' ? $hero_barangay . ', ' . $hero_city : $hero_city;
$hero_status   = ucfirst($pt['status']    ?? 'Active');
$hero_blood    = $pt['blood_type']        ?? '—';
$hero_greeting = (int)date('H') < 12 ? 'Good morning' : ((int)date('H') < 18 ? 'Good afternoon' : 'Good evening');
?>

<div class="hero-card mb-md">
  <div class="flex-between" style="width: 100%; flex-wrap: wrap; gap: 24px;">
    <!-- Identity -->
    <div class="flex-center gap-md">
      <div class="hero-avatar"><?= $hero_initials ?></div>
      <div>
        <div class="hero-greeting"><?= $hero_greeting ?>,</div>
        <h2 class="hero-name"><?= $hero_name ?></h2>
        <div class="mc-badge mc-badge--success">
          <span style="width: 6px; height: 6px; background: currentColor; border-radius: 50%; margin-right: 6px;"></span>
          <?= htmlspecialchars($hero_status) ?>
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="flex-center gap-md" style="flex-wrap: wrap;">
      <div class="hero-stat-item">
        <div style="color: var(--mc-secondary);"><?= icon('user') ?></div>
        <div>
          <div class="hero-stat-label">Age</div>
          <div class="hero-stat-value"><?= $hero_age ?> yrs</div>
        </div>
      </div>
      <div class="hero-stat-item">
        <div style="color: var(--mc-primary);"><?= icon('activity') ?></div>
        <div>
          <div class="hero-stat-label">Gender</div>
          <div class="hero-stat-value"><?= $hero_gender ?></div>
        </div>
      </div>
      <a href="<?= ASSET_BASE ?>/views/patient/profile.php" class="hero-edit-btn">
        <?= icon('edit') ?> Edit Profile
      </a>
    </div>
  </div>
</div>
