<?php
/**
 * Patient Settings — security, privacy, notifications, sessions.
 * Expects: $settings from patient_settings_load()
 */
$sec = $settings['security'] ?? [];
$notif = $settings['notifications'] ?? [];
$privacy = $settings['privacy'] ?? [];
$sessions = $settings['sessions'] ?? [];
$devices = $settings['devices'] ?? [];

$initial_tab = 'security';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['security', 'privacy', 'notifications', 'sessions'], true)) {
    $initial_tab = $_GET['tab'];
}

function pts_icon(string $name): string {
    $icons = [
        'lock' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'shield' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'bell' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'monitor' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
        'mail' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
        'clock' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'key' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$nav_tabs = [
    'security' => ['icon' => 'lock', 'label' => 'Account Security', 'desc' => 'Password & login'],
    'privacy' => ['icon' => 'shield', 'label' => 'Privacy', 'desc' => 'Data sharing'],
    'notifications' => ['icon' => 'bell', 'label' => 'Notifications', 'desc' => 'Alerts & email'],
    'sessions' => ['icon' => 'monitor', 'label' => 'Sessions', 'desc' => 'Devices & access'],
];
?>
<div class="pts-page" data-initial-tab="<?= htmlspecialchars($initial_tab) ?>">

  <p class="pts-lead">Manage your account security, privacy preferences, notifications, and active sessions.</p>

  <div class="pts-layout">
    <aside class="pts-nav" role="tablist" aria-label="Settings sections">
      <?php foreach ($nav_tabs as $id => $tab): ?>
      <button type="button"
        class="pts-nav__item <?= $initial_tab === $id ? 'is-active' : '' ?>"
        data-pts-tab="<?= $id ?>"
        role="tab"
        aria-selected="<?= $initial_tab === $id ? 'true' : 'false' ?>"
        aria-controls="pts-panel-<?= $id ?>">
        <span class="pts-nav__icon"><?= pts_icon($tab['icon']) ?></span>
        <span class="pts-nav__text">
          <span class="pts-nav__label"><?= htmlspecialchars($tab['label']) ?></span>
          <span class="pts-nav__desc"><?= htmlspecialchars($tab['desc']) ?></span>
        </span>
      </button>
      <?php endforeach; ?>
    </aside>

    <div class="pts-content">

      <!-- Account Security -->
      <section class="pts-panel <?= $initial_tab === 'security' ? 'is-active' : '' ?>"
               data-pts-panel="security"
               id="pts-panel-security"
               role="tabpanel"
               <?= $initial_tab !== 'security' ? 'hidden' : '' ?>>

        <div class="pts-stats">
          <article class="pts-stat">
            <span class="pts-stat__icon pts-stat__icon--teal"><?= pts_icon('clock') ?></span>
            <div class="pts-stat__body">
              <span class="pts-stat__label">Last login</span>
              <strong class="pts-stat__value"><?= htmlspecialchars($sec['last_login_label'] ?? 'Not recorded') ?></strong>
            </div>
          </article>
          <article class="pts-stat">
            <span class="pts-stat__icon pts-stat__icon--violet"><?= pts_icon('key') ?></span>
            <div class="pts-stat__body">
              <span class="pts-stat__label">Password changed</span>
              <strong class="pts-stat__value"><?= htmlspecialchars($sec['password_changed_label'] ?? 'Not changed') ?></strong>
            </div>
          </article>
          <article class="pts-stat pts-stat--email">
            <span class="pts-stat__icon pts-stat__icon--blue"><?= pts_icon('mail') ?></span>
            <div class="pts-stat__body">
              <span class="pts-stat__label">Account email</span>
              <strong class="pts-stat__value pts-stat__value--email"><?= htmlspecialchars($sec['email'] ?? '') ?></strong>
            </div>
          </article>
        </div>

        <div class="pts-card">
          <div class="pts-card__head">
            <div class="pts-card__head-icon pts-card__head-icon--lock"><?= pts_icon('lock') ?></div>
            <div>
              <h3 class="pts-card__title">Change Password</h3>
              <p class="pts-card__sub">Minimum 12 characters with uppercase, lowercase, number, and special character.</p>
            </div>
          </div>

          <div id="ptsPasswordAlert" class="pts-alert" role="alert" hidden></div>

          <div class="pts-security-module">
          <form id="ptsPasswordForm" class="pts-form-grid" novalidate>
            <div class="pts-field pts-field--full">
              <label for="ptsCurrentPassword">Current password</label>
              <div class="pts-input-group">
                <input type="password" id="ptsCurrentPassword" name="current_password" class="pts-input pts-input--pw" autocomplete="current-password" required placeholder="Enter current password">
                <button type="button" class="pts-input-action pts-toggle-pw" data-target="ptsCurrentPassword" aria-label="Show current password" aria-pressed="false">
                  <svg class="pts-eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="pts-eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" hidden><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  <span class="pts-toggle-label">Show</span>
                </button>
              </div>
            </div>

            <div class="pts-field">
              <label for="ptsNewPassword">New password</label>
              <div class="pts-input-group">
                <input type="password" id="ptsNewPassword" name="new_password" class="pts-input pts-input--pw" autocomplete="new-password" required minlength="12" placeholder="At least 12 characters">
                <button type="button" class="pts-input-action pts-toggle-pw" data-target="ptsNewPassword" aria-label="Show new password" aria-pressed="false">
                  <svg class="pts-eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="pts-eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" hidden><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  <span class="pts-toggle-label">Show</span>
                </button>
              </div>
            </div>

            <div class="pts-field">
              <label for="ptsConfirmPassword">Confirm new password</label>
              <div class="pts-input-group">
                <input type="password" id="ptsConfirmPassword" name="confirm_password" class="pts-input pts-input--pw" autocomplete="new-password" required placeholder="Re-enter new password">
                <button type="button" class="pts-input-action pts-toggle-pw" data-target="ptsConfirmPassword" aria-label="Show confirm password" aria-pressed="false">
                  <svg class="pts-eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="pts-eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" hidden><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  <span class="pts-toggle-label">Show</span>
                </button>
              </div>
              <p id="ptsMatchHint" class="pts-match-hint" hidden></p>
            </div>

            <div class="pts-validation pts-field--full" aria-label="Password strength and requirements">
              <div class="pts-validation__head">
                <div class="pts-validation__title">Password Strength</div>
                <div id="ptsStrengthLabel" class="pts-validation__label" aria-live="polite">Weak</div>
              </div>
              <div class="pts-validation__bar" aria-hidden="true">
                <span id="ptsStrengthFill" class="pts-validation__fill"></span>
              </div>
              <ul class="pts-validation__grid" id="ptsReqList" aria-label="Password requirements">
                <li data-req="len"><span class="pts-req-dot"></span>At least 12 characters</li>
                <li data-req="upper"><span class="pts-req-dot"></span>One uppercase letter</li>
                <li data-req="lower"><span class="pts-req-dot"></span>One lowercase letter</li>
                <li data-req="digit"><span class="pts-req-dot"></span>One number</li>
                <li data-req="special"><span class="pts-req-dot"></span>One special character</li>
              </ul>
            </div>

            <div class="pts-form-actions pts-field--full">
              <button type="submit" class="pts-btn pts-btn--primary" id="ptsPasswordSubmit">
                <span class="pts-btn__text">Update Password</span>
                <span class="pts-btn__spinner" hidden aria-hidden="true"></span>
              </button>
            </div>
          </form>
          </div>
        </div>
      </section>

      <!-- Privacy -->
      <section class="pts-panel <?= $initial_tab === 'privacy' ? 'is-active' : '' ?>"
               data-pts-panel="privacy"
               id="pts-panel-privacy"
               role="tabpanel"
               <?= $initial_tab !== 'privacy' ? 'hidden' : '' ?>>

        <div class="pts-card">
          <div class="pts-card__head">
            <div class="pts-card__head-icon pts-card__head-icon--shield"><?= pts_icon('shield') ?></div>
            <div>
              <h3 class="pts-card__title">Privacy Preferences</h3>
              <p class="pts-card__sub">Control how your medical data is shared within the medConnect care network.</p>
            </div>
          </div>
          <div id="ptsPrivacyAlert" class="pts-alert" role="alert" hidden></div>
          <form id="ptsPrivacyForm" class="pts-toggle-list">
            <label class="pts-toggle">
              <input type="checkbox" name="share_medical_records" value="1" <?= !empty($privacy['share_medical_records']) ? 'checked' : '' ?>>
              <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
              <span class="pts-toggle__text">
                <strong>Provider access to medical records</strong>
                <small>Allow authorized healthcare providers to view your permanent profile and care records during consultations.</small>
              </span>
            </label>
            <label class="pts-toggle">
              <input type="checkbox" name="emergency_access_consent" value="1" <?= !empty($privacy['emergency_access_consent']) ? 'checked' : '' ?>>
              <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
              <span class="pts-toggle__text">
                <strong>Emergency access consent</strong>
                <small>Allow emergency clinical staff to access critical health information when medically necessary.</small>
              </span>
            </label>
            <label class="pts-toggle pts-toggle--required">
              <input type="checkbox" name="data_privacy_acknowledged" value="1" <?= !empty($privacy['data_privacy_acknowledged']) ? 'checked' : '' ?> required>
              <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
              <span class="pts-toggle__text">
                <strong>Data privacy acknowledgment</strong>
                <small>I understand how medConnect collects, stores, and protects my personal health information.</small>
              </span>
            </label>
            <div class="pts-form-actions">
              <button type="submit" class="pts-btn pts-btn--primary">
                <span class="pts-btn__text">Save Privacy Preferences</span>
                <span class="pts-btn__spinner" hidden></span>
              </button>
            </div>
          </form>
        </div>
      </section>

      <!-- Notifications -->
      <section class="pts-panel <?= $initial_tab === 'notifications' ? 'is-active' : '' ?>"
               data-pts-panel="notifications"
               id="pts-panel-notifications"
               role="tabpanel"
               <?= $initial_tab !== 'notifications' ? 'hidden' : '' ?>>

        <div class="pts-card">
          <div class="pts-card__head">
            <div class="pts-card__head-icon pts-card__head-icon--bell"><?= pts_icon('bell') ?></div>
            <div>
              <h3 class="pts-card__title">Notification Settings</h3>
              <p class="pts-card__sub">Choose which alerts you receive in-app and by email.</p>
            </div>
          </div>
          <div id="ptsNotifAlert" class="pts-alert" role="alert" hidden></div>
          <form id="ptsNotifForm">
            <div class="pts-notif-group">
              <h4 class="pts-notif-group__title">Delivery channels</h4>
              <div class="pts-toggle-list pts-toggle-list--compact">
                <label class="pts-toggle">
                  <input type="checkbox" name="in_app_notifications" value="1" <?= !empty($notif['in_app_notifications']) ? 'checked' : '' ?>>
                  <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
                  <span class="pts-toggle__text"><strong>In-app notifications</strong><small>Bell alerts inside medConnect.</small></span>
                </label>
                <label class="pts-toggle">
                  <input type="checkbox" name="email_notifications" value="1" <?= !empty($notif['email_notifications']) ? 'checked' : '' ?>>
                  <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
                  <span class="pts-toggle__text"><strong>Email notifications</strong><small>Sent to your registered email when available.</small></span>
                </label>
              </div>
            </div>
            <div class="pts-notif-group">
              <h4 class="pts-notif-group__title">Alert types</h4>
              <div class="pts-notif-grid">
                <?php
                $notif_items = [
                  'appointment_reminders' => ['Appointment reminders', 'Before scheduled visits'],
                  'consultation_updates' => ['Consultation updates', 'Join links & status changes'],
                  'followup_reminders' => ['Follow-up reminders', 'Provider action items'],
                  'prescription_notifications' => ['Prescription notifications', 'New or updated prescriptions'],
                  'system_announcements' => ['System announcements', 'Platform news & maintenance'],
                ];
                foreach ($notif_items as $key => [$title, $sub]):
                ?>
                <label class="pts-notif-chip">
                  <input type="checkbox" name="<?= $key ?>" value="1" <?= !empty($notif[$key]) ? 'checked' : '' ?>>
                  <span class="pts-notif-chip__box">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                  </span>
                  <span class="pts-notif-chip__text">
                    <strong><?= htmlspecialchars($title) ?></strong>
                    <small><?= htmlspecialchars($sub) ?></small>
                  </span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="pts-form-actions">
              <button type="submit" class="pts-btn pts-btn--primary">
                <span class="pts-btn__text">Save Notification Settings</span>
                <span class="pts-btn__spinner" hidden></span>
              </button>
            </div>
          </form>
        </div>
      </section>

      <!-- Sessions -->
      <section class="pts-panel <?= $initial_tab === 'sessions' ? 'is-active' : '' ?>"
               data-pts-panel="sessions"
               id="pts-panel-sessions"
               role="tabpanel"
               <?= $initial_tab !== 'sessions' ? 'hidden' : '' ?>>

        <div class="pts-card">
          <div class="pts-card__head pts-card__head--row">
            <div class="pts-card__head-main">
              <div class="pts-card__head-icon pts-card__head-icon--monitor"><?= pts_icon('monitor') ?></div>
              <div>
                <h3 class="pts-card__title">Active Sessions</h3>
                <p class="pts-card__sub">Devices signed in to your account in the last 7 days.</p>
              </div>
            </div>
            <button type="button" class="pts-btn pts-btn--danger-outline" id="ptsLogoutAllBtn">Logout All Devices</button>
          </div>
          <div id="ptsSessionsAlert" class="pts-alert" role="alert" hidden></div>

          <?php if (empty($sessions)): ?>
            <div class="pts-empty-state">
              <span class="pts-empty-state__icon"><?= pts_icon('monitor') ?></span>
              <p>No active sessions recorded in the last 7 days.</p>
            </div>
          <?php else: ?>
            <ul class="pts-session-list" id="ptsSessionList">
              <?php foreach ($sessions as $sess):
                $dev = (string) ($sess['device'] ?? 'desktop');
              ?>
              <li class="pts-session-item <?= !empty($sess['is_current']) ? 'is-current' : '' ?>" data-session-id="<?= (int) $sess['id'] ?>">
                <div class="pts-session-item__icon pts-session-item__icon--<?= htmlspecialchars($dev) ?>">
                  <?php if ($dev === 'mobile'): ?>
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                  <?php elseif ($dev === 'tablet'): ?>
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                  <?php else: ?>
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                  <?php endif; ?>
                </div>
                <div class="pts-session-item__body">
                  <strong><?= htmlspecialchars($sess['browser'] ?? 'Unknown browser') ?></strong>
                  <span><?= htmlspecialchars(ucfirst($dev)) ?> · Last active <?= htmlspecialchars($sess['last_activity_label'] ?? '—') ?></span>
                  <?php if (!empty($sess['ip_address'])): ?>
                  <span class="pts-session-item__ip">IP <?= htmlspecialchars($sess['ip_address']) ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($sess['is_current'])): ?>
                  <span class="pts-badge pts-badge--current">This device</span>
                <?php else: ?>
                  <button type="button" class="pts-btn pts-btn--ghost pts-session-end" data-session-id="<?= (int) $sess['id'] ?>">End session</button>
                <?php endif; ?>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <?php if (!empty($devices)): ?>
        <div class="pts-card">
          <h3 class="pts-card__title" style="margin-bottom:12px;">Known Devices</h3>
          <ul class="pts-device-list">
            <?php foreach ($devices as $dev): ?>
            <li class="pts-device-item">
              <span class="pts-device-item__icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/></svg>
              </span>
              <div>
                <strong><?= htmlspecialchars(($dev['browser'] ?? 'Unknown') . ' on ' . ($dev['os'] ?? 'Unknown')) ?></strong>
                <span>Last seen <?= htmlspecialchars($dev['last_seen_label'] ?? '—') ?></span>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </section>

    </div><!-- /.pts-content -->
  </div><!-- /.pts-layout -->
</div>

<div id="ptsConfirmModal" class="pts-modal" hidden role="dialog" aria-modal="true" aria-labelledby="ptsConfirmTitle">
  <div class="pts-modal__backdrop" data-pts-close></div>
  <div class="pts-modal__card">
    <div class="pts-modal__icon" aria-hidden="true">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <h3 id="ptsConfirmTitle" class="pts-modal__title">Confirm action</h3>
    <p id="ptsConfirmMessage" class="pts-modal__message"></p>
    <div class="pts-modal__actions">
      <button type="button" class="pts-btn pts-btn--ghost" data-pts-close>Cancel</button>
      <button type="button" class="pts-btn pts-btn--primary" id="ptsConfirmOk">Confirm</button>
    </div>
  </div>
</div>

<div id="ptsToast" class="pts-toast" role="status" aria-live="polite" hidden></div>
