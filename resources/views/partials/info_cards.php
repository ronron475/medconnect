<?php
// ── Profile info cards — all values from $pt (MySQL row) ──
$card_blood     = htmlspecialchars($pt['blood_type']       ?? '—');
$card_contact   = htmlspecialchars($pt['contact_number']   ?? '—');
$card_philhealth= htmlspecialchars($pt['philhealth_status']?? '—');
$card_email     = htmlspecialchars($pt['email']            ?? '—');
$card_dob       = htmlspecialchars($pt['date_of_birth']    ?? '—');
$card_barangay  = htmlspecialchars($pt['barangay']         ?? '—');

// PhilHealth badge colour
$ph_active = strtolower($card_philhealth) === 'active';
?>

<section class="info-cards-section" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">

  <!-- Blood Type -->
  <div class="info-card-item mc-card" style="border-top: 3px solid #ef4444; padding: 16px;">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
      <div class="ic-icon-wrap" style="background: #fef2f2; color: #ef4444; width: 32px; height: 32px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2C6 8 4 12.5 4 15a8 8 0 0 0 16 0c0-2.5-2-7-8-13z"/>
        </svg>
      </div>
      <span class="ic-label" style="font-size: 11px; font-weight: 700; color: var(--mc-text-muted); text-transform: uppercase;">Blood Type</span>
    </div>
    <div class="ic-value" style="font-size: 20px; font-weight: 800; color: var(--mc-navy-deep);"><?= $card_blood ?></div>
  </div>

  <!-- Contact Number -->
  <div class="info-card-item mc-card" style="border-top: 3px solid #14b8a6; padding: 16px;">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
      <div class="ic-icon-wrap" style="background: #f0fdfa; color: #14b8a6; width: 32px; height: 32px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
        </svg>
      </div>
      <span class="ic-label" style="font-size: 11px; font-weight: 700; color: var(--mc-text-muted); text-transform: uppercase;">Contact</span>
    </div>
    <div class="ic-value" style="font-size: 15px; font-weight: 700; color: var(--mc-navy-deep);"><?= $card_contact ?></div>
  </div>

  <!-- PhilHealth Status -->
  <div class="info-card-item mc-card" style="border-top: 3px solid #3b82f6; padding: 16px;">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
      <div class="ic-icon-wrap" style="background: #eff6ff; color: #3b82f6; width: 32px; height: 32px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
      </div>
      <span class="ic-label" style="font-size: 11px; font-weight: 700; color: var(--mc-text-muted); text-transform: uppercase;">PhilHealth</span>
    </div>
    <div class="ic-value">
      <span class="mc-badge" style="background: <?= $ph_active ? '#dcfce7' : '#fee2e2' ?>; color: <?= $ph_active ? '#16a34a' : '#991b1b' ?>; padding: 2px 8px; font-size: 11px;">
        <?= $card_philhealth ?>
      </span>
    </div>
  </div>

  <!-- Email Address -->
  <div class="info-card-item mc-card" style="border-top: 3px solid #06b6d4; padding: 16px;">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
      <div class="ic-icon-wrap" style="background: #ecfeff; color: #06b6d4; width: 32px; height: 32px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
      </div>
      <span class="ic-label" style="font-size: 11px; font-weight: 700; color: var(--mc-text-muted); text-transform: uppercase;">Email</span>
    </div>
    <div class="ic-value" style="font-size: 13px; font-weight: 700; color: var(--mc-navy-deep); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= $card_email ?></div>
  </div>

  <!-- Date of Birth -->
  <div class="info-card-item mc-card" style="border-top: 3px solid #8b5cf6; padding: 16px;">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
      <div class="ic-icon-wrap" style="background: #f5f3ff; color: #8b5cf6; width: 32px; height: 32px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8"  y1="2" x2="8"  y2="6"/>
          <line x1="3"  y1="10" x2="21" y2="10"/>
        </svg>
      </div>
      <span class="ic-label" style="font-size: 11px; font-weight: 700; color: var(--mc-text-muted); text-transform: uppercase;">Birthday</span>
    </div>
    <div class="ic-value" style="font-size: 14px; font-weight: 700; color: var(--mc-navy-deep);"><?= $card_dob ?></div>
  </div>

  <!-- Barangay -->
  <div class="info-card-item mc-card" style="border-top: 3px solid #64748b; padding: 16px;">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
      <div class="ic-icon-wrap" style="background: #f8fafc; color: #64748b; width: 32px; height: 32px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </div>
      <span class="ic-label" style="font-size: 11px; font-weight: 700; color: var(--mc-text-muted); text-transform: uppercase;">Location</span>
    </div>
    <div class="ic-value" style="font-size: 14px; font-weight: 700; color: var(--mc-navy-deep);"><?= $card_barangay ?></div>
  </div>

</section>
