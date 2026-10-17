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

$allowed_tabs = ['security', 'profile', 'privacy', 'notifications', 'sessions', 'language'];
$initial_tab = 'security';
if (isset($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs, true)) {
    $initial_tab = $_GET['tab'];
}

$nav_groups = [
    ['label' => 'Account', 'key' => 'group_account', 'items' => [
        ['id' => 'profile', 'label' => 'Profile & preferences', 'i18n' => 'nav_profile', 'search' => 'profile preferences email login'],
        ['id' => 'notifications', 'label' => 'Notifications', 'i18n' => 'nav_notifications', 'search' => 'notifications alerts email'],
    ]],
    ['label' => 'Connections', 'key' => 'group_connections', 'items' => [
        ['id' => 'sessions', 'label' => 'Devices & sessions', 'i18n' => 'nav_devices', 'search' => 'devices sessions logout access'],
    ]],
    ['label' => 'Preferences', 'key' => 'group_preferences', 'items' => [
        ['id' => 'language', 'label' => 'Language', 'i18n' => 'language', 'search' => 'language filipino hiligaynon english'],
    ]],
    ['label' => 'Security', 'key' => 'group_security', 'items' => [
        ['id' => 'security', 'label' => 'Change password', 'i18n' => 'change_password', 'search' => 'change password security'],
        ['id' => 'privacy', 'label' => 'Data privacy', 'i18n' => 'nav_data_privacy', 'search' => 'privacy data sharing consent'],
    ]],
];

function pts_icon(string $name): string {
    $icons = [
        'lock' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'shield' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'bell' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'monitor' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
        'mail' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
        'clock' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'key' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
        'check' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$session_count = is_array($sessions) ? count($sessions) : 0;
?>
<div class="pts-page" data-initial-tab="<?= htmlspecialchars($initial_tab) ?>">
  <div class="pts-shell">
    <aside class="pts-menu">
      <h2 class="pts-menu__title" data-ui-i18n="nav_settings">Settings</h2>
      <nav class="pts-nav" aria-label="Settings sections">
        <?php foreach ($nav_groups as $group): ?>
        <div class="pts-nav__group">
          <p class="pts-nav__group-label" data-ui-i18n="<?= htmlspecialchars($group['key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($group['label']) ?></p>
          <?php foreach ($group['items'] as $item): ?>
          <button type="button"
            class="pts-nav__item <?= $initial_tab === $item['id'] ? 'is-active' : '' ?>"
            data-pts-tab="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>"
            data-search="<?= htmlspecialchars($item['search'], ENT_QUOTES, 'UTF-8') ?>"
            aria-selected="<?= $initial_tab === $item['id'] ? 'true' : 'false' ?>"
            aria-controls="pts-panel-<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>">
            <span data-ui-i18n="<?= htmlspecialchars($item['i18n'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['label']) ?></span>
          </button>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </nav>
    </aside>

    <div class="pts-stage">
      <div class="pts-stage__bar">
        <label class="pts-search">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" id="ptsSettingsSearch" placeholder="Search" data-ui-i18n="search_settings" data-ui-i18n-attr="placeholder" autocomplete="off" aria-label="Search settings">
        </label>
      </div>
      <p id="ptsSearchEmpty" class="pts-search-empty" hidden data-ui-i18n="search_empty">No matching settings.</p>

      <div class="pts-content">

      <section class="pts-panel <?= $initial_tab === 'profile' ? 'is-active' : '' ?>"
               data-pts-panel="profile"
               id="pts-panel-profile"
               role="tabpanel"
               <?= $initial_tab !== 'profile' ? 'hidden' : '' ?>>
        <header class="pts-panel-head">
          <h2 data-ui-i18n="profile_title">Profile &amp; preferences</h2>
          <p data-ui-i18n="profile_sub">Your account email and recent sign-in details.</p>
        </header>
        <div class="pts-stats">
          <article class="pts-stat">
            <span class="pts-stat__icon pts-stat__icon--teal"><?= pts_icon('clock') ?></span>
            <div class="pts-stat__body">
              <span class="pts-stat__label" data-ui-i18n="last_login">Last login</span>
              <strong class="pts-stat__value"<?= (($sec['last_login_label'] ?? 'Not recorded') === 'Not recorded') ? ' data-ui-i18n="not_recorded"' : '' ?>><?= htmlspecialchars($sec['last_login_label'] ?? 'Not recorded') ?></strong>
            </div>
          </article>
          <article class="pts-stat">
            <span class="pts-stat__icon pts-stat__icon--violet"><?= pts_icon('key') ?></span>
            <div class="pts-stat__body">
              <span class="pts-stat__label" data-ui-i18n="password_changed">Password changed</span>
              <strong class="pts-stat__value"<?= (($sec['password_changed_label'] ?? '') === 'Not changed since registration') ? ' data-ui-i18n="not_changed"' : '' ?>><?= htmlspecialchars($sec['password_changed_label'] ?? 'Not changed') ?></strong>
            </div>
          </article>
          <article class="pts-stat pts-stat--email">
            <span class="pts-stat__icon pts-stat__icon--blue"><?= pts_icon('mail') ?></span>
            <div class="pts-stat__body">
              <span class="pts-stat__label" data-ui-i18n="account_email">Account email</span>
              <strong class="pts-stat__value pts-stat__value--email"><?= htmlspecialchars($sec['email'] ?? '') ?></strong>
            </div>
          </article>
        </div>
      </section>

      <section class="pts-panel <?= $initial_tab === 'security' ? 'is-active' : '' ?>"
               data-pts-panel="security"
               id="pts-panel-security"
               role="tabpanel"
               <?= $initial_tab !== 'security' ? 'hidden' : '' ?>>
        <header class="pts-panel-head">
          <h2 data-ui-i18n="change_password">Change password</h2>
          <p data-ui-i18n="password_lead">Keep your account secure by using a strong, unique password.</p>
        </header>
        <div id="ptsPasswordAlert" class="pts-alert" role="alert" hidden></div>
        <form id="ptsPasswordForm" class="pts-pw-stack" novalidate>
          <div class="pts-pw-box">
            <label for="ptsCurrentPassword" data-ui-i18n="current_password">Current password</label>
            <div class="pts-input-group">
              <span class="pts-input-lead" aria-hidden="true"><?= pts_icon('lock') ?></span>
              <input type="password" id="ptsCurrentPassword" name="current_password" class="pts-input pts-input--pw" autocomplete="current-password" required placeholder="Enter current password" data-ui-i18n="ph_current" data-ui-i18n-attr="placeholder">
              <button type="button" class="pts-input-action pts-toggle-pw" data-target="ptsCurrentPassword" aria-label="Show current password" aria-pressed="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>
          <div class="pts-pw-box">
            <label for="ptsNewPassword" data-ui-i18n="new_password">New password</label>
            <div class="pts-input-group">
              <input type="password" id="ptsNewPassword" name="new_password" class="pts-input pts-input--pw" autocomplete="new-password" required minlength="12" placeholder="Enter at least 12 characters" data-ui-i18n="ph_new" data-ui-i18n-attr="placeholder">
              <button type="button" class="pts-input-action pts-toggle-pw" data-target="ptsNewPassword" aria-label="Show new password" aria-pressed="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <div class="pts-validation" aria-label="Password strength and requirements">
              <div class="pts-validation__bar" aria-hidden="true">
                <span id="ptsStrengthFill" class="pts-validation__fill"></span>
              </div>
              <div class="pts-validation__head">
                <div class="pts-validation__title" data-ui-i18n="password_strength">Password strength</div>
                <div id="ptsStrengthLabel" class="pts-validation__label" aria-live="polite" data-ui-i18n="pw_weak">Weak</div>
              </div>
              <ul class="pts-validation__grid" id="ptsReqList" aria-label="Password requirements">
                <li data-req="len"><span class="pts-req-dot" aria-hidden="true"></span><span data-ui-i18n="req_len">At least 12 characters</span></li>
                <li data-req="upper"><span class="pts-req-dot" aria-hidden="true"></span><span data-ui-i18n="req_upper">One uppercase letter</span></li>
                <li data-req="lower"><span class="pts-req-dot" aria-hidden="true"></span><span data-ui-i18n="req_lower">One lowercase letter</span></li>
                <li data-req="digit"><span class="pts-req-dot" aria-hidden="true"></span><span data-ui-i18n="req_digit">One number</span></li>
                <li data-req="special"><span class="pts-req-dot" aria-hidden="true"></span><span data-ui-i18n="req_special">One special character</span></li>
              </ul>
            </div>
          </div>
          <div class="pts-pw-box">
            <label for="ptsConfirmPassword" data-ui-i18n="confirm_password">Confirm new password</label>
            <div class="pts-input-group">
              <input type="password" id="ptsConfirmPassword" name="confirm_password" class="pts-input pts-input--pw" autocomplete="new-password" required placeholder="Re-enter new password" data-ui-i18n="ph_confirm" data-ui-i18n-attr="placeholder">
              <button type="button" class="pts-input-action pts-toggle-pw" data-target="ptsConfirmPassword" aria-label="Show confirm password" aria-pressed="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <p id="ptsMatchHint" class="pts-match-hint" hidden></p>
          </div>
          <button type="submit" class="pts-btn pts-btn--primary pts-btn--block" id="ptsPasswordSubmit">
            <span class="pts-btn__text" data-ui-i18n="save_changes">Save changes</span>
            <span class="pts-btn__spinner" hidden aria-hidden="true"></span>
          </button>
        </form>
      </section>

      <section class="pts-panel <?= $initial_tab === 'language' ? 'is-active' : '' ?>"
               data-pts-panel="language"
               id="pts-panel-language"
               role="tabpanel"
               <?= $initial_tab !== 'language' ? 'hidden' : '' ?>>
        <header class="pts-panel-head">
          <h2 data-ui-i18n="language">Language</h2>
          <p data-ui-i18n="language_help">Your choice is saved to this account and applied immediately.</p>
        </header>
        <div id="ptsLanguageAlert" class="pts-alert" role="alert" hidden></div>
        <form id="ptsLanguageForm" class="pts-pw-stack">
          <div class="pts-pw-box">
            <label for="ptsLanguage" data-ui-i18n="language">Language</label>
            <select id="ptsLanguage" name="language" class="pts-select">
              <?php foreach (['en' => 'English', 'fil' => 'Filipino', 'hil' => 'Hiligaynon'] as $code => $label): ?>
              <option value="<?= $code ?>" <?= ($ui_language ?? 'en') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="pts-btn pts-btn--primary pts-btn--block" id="ptsLanguageSubmit">
            <span class="pts-btn__text" data-ui-i18n="save_changes">Save changes</span>
            <span class="pts-btn__spinner" hidden aria-hidden="true"></span>
          </button>
        </form>
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
            <div class="pts-card__head-copy">
              <h3 class="pts-card__title" data-ui-i18n="privacy_title">Privacy Preferences</h3>
              <p class="pts-card__sub" data-ui-i18n="privacy_sub">Control how your medical data is shared within the medConnect care network.</p>
            </div>
          </div>
          <div id="ptsPrivacyAlert" class="pts-alert" role="alert" hidden></div>
          <form id="ptsPrivacyForm" class="pts-toggle-list">
            <label class="pts-toggle">
              <input type="checkbox" name="share_medical_records" value="1" <?= !empty($privacy['share_medical_records']) ? 'checked' : '' ?>>
              <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
              <span class="pts-toggle__text">
                <strong data-ui-i18n="privacy_records">Provider access to medical records</strong>
                <small data-ui-i18n="privacy_records_sub">Allow authorized healthcare providers to view your permanent profile and care records during consultations.</small>
              </span>
            </label>
            <label class="pts-toggle">
              <input type="checkbox" name="share_prescriptions_with_barangay_bhw" value="1" <?= !empty($privacy['share_prescriptions_with_barangay_bhw']) ? 'checked' : '' ?>>
              <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
              <span class="pts-toggle__text">
                <strong data-ui-i18n="privacy_bhw_rx">Barangay BHW prescription access</strong>
                <small data-ui-i18n="privacy_bhw_rx_sub">Allow authorized Barangay Health Workers assigned to your barangay to view your prescription details for barangay healthcare assistance. You can turn this off at any time.</small>
              </span>
            </label>
            <label class="pts-toggle">
              <input type="checkbox" name="emergency_access_consent" value="1" <?= !empty($privacy['emergency_access_consent']) ? 'checked' : '' ?>>
              <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
              <span class="pts-toggle__text">
                <strong data-ui-i18n="privacy_emergency">Emergency access consent</strong>
                <small data-ui-i18n="privacy_emergency_sub">Allow emergency clinical staff to access critical health information when medically necessary.</small>
              </span>
            </label>
            <label class="pts-toggle pts-toggle--required">
              <input type="checkbox" name="data_privacy_acknowledged" value="1" <?= !empty($privacy['data_privacy_acknowledged']) ? 'checked' : '' ?>>
              <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
              <span class="pts-toggle__text">
                <strong data-ui-i18n="privacy_ack">Data privacy acknowledgment</strong>
                <small data-ui-i18n="privacy_ack_sub">I understand how medConnect collects, stores, and protects my personal health information.</small>
              </span>
            </label>
            <div class="pts-form-actions">
              <button type="submit" class="pts-btn pts-btn--primary">
                <span class="pts-btn__text" data-ui-i18n="save_privacy">Save Privacy Preferences</span>
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
            <div class="pts-card__head-copy">
              <h3 class="pts-card__title" data-ui-i18n="notif_title">Notification Settings</h3>
              <p class="pts-card__sub" data-ui-i18n="notif_sub">Choose which alerts you receive in-app and by email.</p>
            </div>
          </div>
          <div id="ptsNotifAlert" class="pts-alert" role="alert" hidden></div>
          <form id="ptsNotifForm">
            <div class="pts-notif-group">
              <h4 class="pts-notif-group__title" data-ui-i18n="delivery">Delivery channels</h4>
              <div class="pts-toggle-list pts-toggle-list--compact">
                <label class="pts-toggle">
                  <input type="checkbox" name="in_app_notifications" value="1" <?= !empty($notif['in_app_notifications']) ? 'checked' : '' ?>>
                  <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
                  <span class="pts-toggle__text"><strong data-ui-i18n="in_app">In-app notifications</strong><small data-ui-i18n="in_app_sub">Bell alerts inside medConnect.</small></span>
                </label>
                <label class="pts-toggle">
                  <input type="checkbox" name="email_notifications" value="1" <?= !empty($notif['email_notifications']) ? 'checked' : '' ?>>
                  <span class="pts-toggle__track"><span class="pts-toggle__thumb"></span></span>
                  <span class="pts-toggle__text"><strong data-ui-i18n="email_notif">Email notifications</strong><small data-ui-i18n="email_notif_sub">Sent to your registered email when available.</small></span>
                </label>
              </div>
            </div>
            <div class="pts-notif-group">
              <h4 class="pts-notif-group__title" data-ui-i18n="alert_types">Alert types</h4>
              <div class="pts-notif-grid">
                <?php
                $notif_items = [
                  'appointment_reminders' => ['Appointment reminders', 'Before scheduled visits', 'appt_rem', 'appt_rem_sub'],
                  'consultation_updates' => ['Consultation updates', 'Join links & status changes', 'consult_upd', 'consult_upd_sub'],
                  'followup_reminders' => ['Follow-up reminders', 'Provider action items', 'follow_rem', 'follow_rem_sub'],
                  'prescription_notifications' => ['Prescription notifications', 'New or updated prescriptions', 'rx_notif', 'rx_notif_sub'],
                  'system_announcements' => ['System announcements', 'Platform news & maintenance', 'sys_ann', 'sys_ann_sub'],
                ];
                foreach ($notif_items as $key => [$title, $sub, $titleKey, $subKey]):
                ?>
                <label class="pts-notif-chip">
                  <input type="checkbox" name="<?= $key ?>" value="1" <?= !empty($notif[$key]) ? 'checked' : '' ?>>
                  <span class="pts-notif-chip__box">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                  </span>
                  <span class="pts-notif-chip__text">
                    <strong data-ui-i18n="<?= htmlspecialchars($titleKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($title) ?></strong>
                    <small data-ui-i18n="<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sub) ?></small>
                  </span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="pts-form-actions">
              <button type="submit" class="pts-btn pts-btn--primary">
                <span class="pts-btn__text" data-ui-i18n="save_notif">Save Notification Settings</span>
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
              <div class="pts-card__head-copy">
                <h3 class="pts-card__title" data-ui-i18n="active_sessions_title">Active Sessions</h3>
                <p class="pts-card__sub" data-ui-i18n="active_sessions_sub">Devices signed in to your account in the last 7 days.</p>
              </div>
            </div>
            <button type="button" class="pts-btn pts-btn--danger-outline" id="ptsLogoutAllBtn" data-ui-i18n="logout_all">Logout All Devices</button>
          </div>
          <div id="ptsSessionsAlert" class="pts-alert" role="alert" hidden></div>

          <?php if (empty($sessions)): ?>
            <div class="pts-empty-state">
              <span class="pts-empty-state__icon"><?= pts_icon('monitor') ?></span>
              <p data-ui-i18n="no_sessions">No active sessions recorded in the last 7 days.</p>
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
                  <span class="pts-badge pts-badge--current" data-ui-i18n="this_device">This device</span>
                <?php else: ?>
                  <button type="button" class="pts-btn pts-btn--ghost pts-session-end" data-session-id="<?= (int) $sess['id'] ?>" data-ui-i18n="end_session">End session</button>
                <?php endif; ?>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <?php if (!empty($devices)): ?>
        <div class="pts-card">
          <div class="pts-card__head">
            <div class="pts-card__head-icon pts-card__head-icon--monitor"><?= pts_icon('monitor') ?></div>
            <div class="pts-card__head-copy">
              <h3 class="pts-card__title" data-ui-i18n="known_devices">Known Devices</h3>
              <p class="pts-card__sub" data-ui-i18n="known_devices_sub">Browsers and devices previously used with this account.</p>
            </div>
          </div>
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

      </div>
    </div>
  </div>
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
