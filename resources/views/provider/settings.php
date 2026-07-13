<?php
$active_page = 'settings';
$page_title  = 'Settings';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require_once BASE_PATH . '/app/includes/provider_i18n.php';

$settings = provider_settings_load($pdo, (int) $_SESSION['user_id']);
$profile = $settings['profile'] ?? [];
$notifications = $settings['notifications'] ?? provider_settings_default_notifications();
$system = $settings['system'] ?? provider_settings_default_system();
$sessions = $settings['sessions'] ?? [];
$lang = $_SESSION['provider_language'] ?? ($system['language'] ?? 'en');

$page_styles = ['provider-settings.css', 'provider_session_alert.css', 'messages-delete.css'];
$provider_settings_css_ver = (int) @filemtime(ASSETS_PATH . '/css/provider-settings.css');

require __DIR__.'/partials/layout_open.php';

$verification = $profile['verification_status'] ?? 'pending';
$verification_class = in_array($verification, ['verified', 'pending', 'rejected'], true) ? $verification : 'pending';
$profile_initials = $profile['initials'] ?? $provider['initials'];
$profile_picture_url = $profile['picture_url'] ?? $provider['picture_url'] ?? null;
$profile_display_name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$profile_role_label = $profile['specialty'] ?? $provider['role'] ?? 'General Medicine';

$tabs = [
    ['profile', 'user', 'profile_information', 'Update your professional identity and contact details.'],
    ['security', 'lock', 'security_password', 'Change your password and secure your account.'],
    ['notifications', 'bell', 'notification_preferences', 'Choose how you receive clinical alerts.'],
    ['system', 'monitor', 'system_preferences', 'Theme, language, and session preferences.'],
];
?>

<div
  id="providerSettingsRoot"
  class="ps-page"
  data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
  data-asset-base="<?= htmlspecialchars(ASSET_BASE) ?>"
  data-provider-theme="<?= htmlspecialchars($system['theme'] ?? 'system') ?>"
>
  <header class="ps-page-header">
    <h2 class="ps-page-title">Account Settings</h2>
    <span class="ps-verification-badge ps-verification-badge--header <?= htmlspecialchars($verification_class) ?>">
      PRC <?= htmlspecialchars(ucfirst($verification)) ?>
    </span>
  </header>

  <div class="ps-layout">
    <aside class="ps-nav" aria-label="Settings sections">
      <?php foreach ($tabs as [$id, $ic, $lblKey, $desc]):
        $is_active = $id === 'profile';
      ?>
      <button
        type="button"
        class="ps-nav__item <?= $is_active ? 'is-active' : '' ?>"
        data-settings-tab="<?= htmlspecialchars($id) ?>"
        aria-selected="<?= $is_active ? 'true' : 'false' ?>"
      >
        <span class="ps-nav__icon"><?= icon($ic) ?></span>
        <span class="ps-nav__text">
          <span class="ps-nav__label" data-i18n="<?= htmlspecialchars($lblKey) ?>"><?= provider_i18n($lblKey, $lang) ?></span>
          <span class="ps-nav__desc"><?= htmlspecialchars($desc) ?></span>
        </span>
      </button>
      <?php endforeach; ?>
    </aside>

    <div class="ps-content">

      <!-- Profile -->
      <section class="ps-panel is-active" data-settings-panel="profile">
        <div class="ps-card">
          <div class="ps-card__head">
            <div>
              <h3 class="ps-card__title"><?= icon('user') ?> Profile Information</h3>
              <p class="ps-card__sub">Your public-facing provider details for patients and staff.</p>
            </div>
          </div>

          <div class="ps-profile-hero">
            <?php
            $profile_upload_layout = 'settings';
            require VIEWS_PATH . '/partials/profile_upload_card.php';
            ?>
          </div>

          <div class="ps-card__body">
            <div id="psAlertProfile" class="ps-alert" role="status"></div>

            <form id="providerProfileForm" class="ps-form-grid" novalidate>
              <div class="ps-field">
                <label for="firstName">First Name</label>
                <input class="ps-input" type="text" id="firstName" name="first_name" required maxlength="80"
                  value="<?= htmlspecialchars($profile['first_name'] ?? '') ?>">
              </div>
              <div class="ps-field">
                <label for="lastName">Last Name</label>
                <input class="ps-input" type="text" id="lastName" name="last_name" required maxlength="80"
                  value="<?= htmlspecialchars($profile['last_name'] ?? '') ?>">
              </div>
              <div class="ps-field">
                <label for="email">Email</label>
                <input class="ps-input" type="email" id="email" name="email" required maxlength="180"
                  value="<?= htmlspecialchars($profile['email'] ?? '') ?>">
              </div>
              <div class="ps-field">
                <label for="phone">Phone Number</label>
                <input class="ps-input" type="tel" id="phone" name="phone" maxlength="20" placeholder="09XXXXXXXXX"
                  value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
              </div>
              <div class="ps-field">
                <label for="specialty">Specialty</label>
                <input class="ps-input" type="text" id="specialty" name="specialty" required maxlength="120"
                  value="<?= htmlspecialchars($profile['specialty'] ?? 'General Medicine') ?>">
              </div>
              <div class="ps-field">
                <label for="licenseNumber">License Number (PRC)</label>
                <input class="ps-input" type="text" id="licenseNumber" name="license_number" required maxlength="32"
                  value="<?= htmlspecialchars($profile['license_number'] ?? '') ?>">
              </div>
              <div class="ps-field">
                <label for="facility">Facility / Assignment</label>
                <input class="ps-input" type="text" id="facility" name="facility" required maxlength="200"
                  value="<?= htmlspecialchars($profile['facility'] ?? 'City Health Office') ?>">
              </div>
              <div class="ps-actions ps-span-2">
                <button type="submit" class="mc-btn mc-btn--primary ps-save-btn">Save Changes</button>
              </div>
            </form>
          </div>
        </div>
      </section>

      <!-- Security -->
      <section class="ps-panel" data-settings-panel="security">
        <div class="ps-card">
          <div class="ps-card__head">
            <div>
              <h3 class="ps-card__title ps-card__title--security"><?= icon('lock') ?> Change Password</h3>
              <p class="ps-card__sub">Protect patient data with a strong password. Requirements update as you type.</p>
            </div>
          </div>
          <div class="ps-card__body">
            <div id="psAlertSecurity" class="ps-alert" role="status"></div>

            <div class="ps-security-module">
            <form id="providerPasswordForm" class="ps-form-grid" novalidate>
              <div class="ps-field ps-span-2">
                <label for="currentPassword">Current Password</label>
                <div class="ps-password-wrap">
                  <input class="ps-input" type="password" id="currentPassword" name="current_password" required autocomplete="current-password" maxlength="128">
                  <button type="button" class="ps-toggle-pw" data-toggle-password="currentPassword" aria-label="Show password">Show</button>
                </div>
              </div>
              <div class="ps-field">
                <label for="newPassword">New Password</label>
                <div class="ps-password-wrap">
                  <input class="ps-input" type="password" id="newPassword" name="new_password" required autocomplete="new-password" minlength="12" maxlength="128" aria-describedby="psPwStrengthLabel newPasswordError">
                  <button type="button" class="ps-toggle-pw" data-toggle-password="newPassword" aria-label="Show password">Show</button>
                </div>
                <div class="ps-validation" aria-label="Password strength and requirements">
                  <div class="ps-validation__head">
                    <div class="ps-validation__title">Password Strength</div>
                    <div id="psPwStrengthLabel" class="ps-validation__label" aria-live="polite">Weak</div>
                  </div>
                  <div class="ps-validation__bar" aria-hidden="true">
                    <span id="psPwStrengthFill" class="ps-validation__fill ps-validation__fill--weak"></span>
                  </div>
                  <ul class="ps-validation__grid" id="psPwReqList" aria-label="Password requirements">
                    <li data-req="len"><span class="ps-req-dot"></span>At least 12 characters</li>
                    <li data-req="upper"><span class="ps-req-dot"></span>One uppercase letter</li>
                    <li data-req="lower"><span class="ps-req-dot"></span>One lowercase letter</li>
                    <li data-req="digit"><span class="ps-req-dot"></span>One number</li>
                    <li data-req="special"><span class="ps-req-dot"></span>One special character</li>
                  </ul>
                </div>
                <p id="newPasswordError" class="ps-field-error" role="alert"></p>
                <p class="ps-field-hint">Tip: Longer passphrases are easier to remember and harder to guess.</p>
              </div>
              <div class="ps-field">
                <label for="confirmPassword">Confirm New Password</label>
                <div class="ps-password-wrap">
                  <input class="ps-input" type="password" id="confirmPassword" name="confirm_password" required autocomplete="new-password" minlength="12" maxlength="128" aria-describedby="confirmPasswordError">
                  <button type="button" class="ps-toggle-pw" data-toggle-password="confirmPassword" aria-label="Show password">Show</button>
                </div>
                <p id="confirmPasswordError" class="ps-field-error" role="alert"></p>
              </div>
              <div class="ps-actions ps-span-2">
                <button type="submit" class="mc-btn mc-btn--primary ps-save-btn">Update Password</button>
              </div>
            </form>
            </div>
          </div>
        </div>

        <div class="ps-card" style="margin-top:16px;">
          <div class="ps-card__head ps-card__head--row">
            <div>
              <h3 class="ps-card__title"><?= icon('monitor') ?> Active Sessions</h3>
              <p class="ps-card__sub">Devices signed in to your provider account in the last 7 days.</p>
            </div>
            <button type="button" class="mc-btn mc-btn--ghost" id="psLogoutAllBtn">Logout All Devices</button>
          </div>
          <div class="ps-card__body">
            <div id="psSessionsAlert" class="ps-alert" role="status"></div>
            <?php if (empty($sessions)): ?>
              <p class="ps-card__sub">No active sessions recorded in the last 7 days.</p>
            <?php else: ?>
              <ul class="ps-session-list">
                <?php foreach ($sessions as $sess): ?>
                <li class="ps-session-item<?= !empty($sess['is_current']) ? ' is-current' : '' ?>">
                  <div>
                    <strong><?= htmlspecialchars((string) ($sess['browser'] ?? 'Unknown browser')) ?></strong>
                    <span class="ps-card__sub"><?= htmlspecialchars(ucfirst((string) ($sess['device'] ?? 'desktop'))) ?> · Last active <?= htmlspecialchars((string) ($sess['last_activity_label'] ?? '—')) ?></span>
                  </div>
                  <?php if (!empty($sess['is_current'])): ?>
                    <span class="ps-badge">This device</span>
                  <?php endif; ?>
                </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- Notifications -->
      <section class="ps-panel" data-settings-panel="notifications">
        <div class="ps-card">
          <div class="ps-card__head">
            <div>
              <h3 class="ps-card__title"><?= icon('bell') ?> Notification Preferences</h3>
              <p class="ps-card__sub">Control which alerts you receive in the portal and by email/SMS.</p>
            </div>
          </div>
          <div class="ps-card__body">
            <div id="psAlertNotifications" class="ps-alert" role="status"></div>

            <form id="providerNotificationsForm">
              <?php
              $notification_items = [
                ['new_messages', 'New Messages', 'Alert when a patient sends a message.'],
                ['consultation_requests', 'Consultation Requests', 'Notify when a new consultation is requested.'],
                ['triage_alerts', 'Triage Alerts', 'Urgent triage cases and priority escalations.'],
                ['system_notifications', 'System Notifications', 'In-app announcements and system updates.'],
                ['email_notifications', 'Email Notifications', 'Receive copies of important alerts by email.'],
                ['sms_notifications', 'SMS Notifications', 'Text message alerts for critical events.'],
              ];
              foreach ($notification_items as [$key, $label, $hint]):
                $checked = !empty($notifications[$key]);
              ?>
              <div class="ps-toggle-row">
                <div class="ps-toggle-row__text">
                  <span class="ps-toggle-label"><?= htmlspecialchars($label) ?></span>
                  <span class="ps-toggle-hint"><?= htmlspecialchars($hint) ?></span>
                </div>
                <label class="ps-switch">
                  <input type="checkbox" name="<?= htmlspecialchars($key) ?>" value="1" <?= $checked ? 'checked' : '' ?>>
                  <span class="ps-switch-slider"></span>
                </label>
              </div>
              <?php endforeach; ?>
              <div class="ps-actions">
                <button type="submit" class="mc-btn mc-btn--primary ps-save-btn">Save Preferences</button>
              </div>
            </form>
          </div>
        </div>
      </section>

      <!-- System -->
      <section class="ps-panel" data-settings-panel="system">
        <div class="ps-card">
          <div class="ps-card__head">
            <div>
              <h3 class="ps-card__title"><?= icon('monitor') ?> <span data-i18n="system_preferences"><?= provider_i18n('system_preferences', $lang) ?></span></h3>
              <p class="ps-card__sub">Personalize appearance, language, and session timeout.</p>
            </div>
          </div>
          <div class="ps-card__body">
            <div id="psAlertSystem" class="ps-alert" role="status"></div>

            <form id="providerSystemForm" class="ps-form-grid">
              <div class="ps-field">
                <label for="theme" data-i18n="theme_preference"><?= provider_i18n('theme_preference', $lang) ?></label>
                <select class="ps-select" id="theme" name="theme">
                  <?php foreach (['system' => 'theme_system', 'light' => 'theme_light', 'dark' => 'theme_dark'] as $val => $lblKey): ?>
                  <option value="<?= $val ?>" <?= ($system['theme'] ?? 'system') === $val ? 'selected' : '' ?>><?= provider_i18n($lblKey, $lang) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ps-field">
                <label for="language" data-i18n="language"><?= provider_i18n('language', $lang) ?></label>
                <select class="ps-select" id="language" name="language">
                  <option value="en" <?= ($system['language'] ?? 'en') === 'en' ? 'selected' : '' ?>><?= provider_i18n('lang_en', $lang) ?></option>
                  <option value="fil" <?= ($system['language'] ?? 'en') === 'fil' ? 'selected' : '' ?>><?= provider_i18n('lang_fil', $lang) ?></option>
                </select>
              </div>
              <div class="ps-field">
                <label for="timeFormat" data-i18n="time_format"><?= provider_i18n('time_format', $lang) ?></label>
                <select class="ps-select" id="timeFormat" name="time_format">
                  <option value="12h" <?= ($system['time_format'] ?? '12h') === '12h' ? 'selected' : '' ?>><?= provider_i18n('time_12h', $lang) ?></option>
                  <option value="24h" <?= ($system['time_format'] ?? '12h') === '24h' ? 'selected' : '' ?>><?= provider_i18n('time_24h', $lang) ?></option>
                </select>
              </div>
              <div class="ps-field">
                <label for="dateFormat" data-i18n="date_format"><?= provider_i18n('date_format', $lang) ?></label>
                <select class="ps-select" id="dateFormat" name="date_format">
                  <?php
                  $date_opts = [
                    'M j, Y' => 'Jun 12, 2026',
                    'j M Y' => '12 Jun 2026',
                    'Y-m-d' => '2026-06-12',
                  ];
                  foreach ($date_opts as $val => $lbl):
                  ?>
                  <option value="<?= htmlspecialchars($val) ?>" <?= ($system['date_format'] ?? 'M j, Y') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ps-field ps-span-2">
                <label for="autoLogout" data-i18n="auto_logout"><?= provider_i18n('auto_logout', $lang) ?></label>
                <select class="ps-select" id="autoLogout" name="auto_logout_minutes">
                  <?php
                  $logout_opts = [15 => 'logout_15', 30 => 'logout_30', 60 => 'logout_60', 120 => 'logout_120'];
                  foreach ($logout_opts as $val => $lblKey):
                  ?>
                  <option value="<?= (int) $val ?>" <?= (int) ($system['auto_logout_minutes'] ?? 30) === (int) $val ? 'selected' : '' ?>><?= provider_i18n($lblKey, $lang) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ps-actions ps-span-2">
                <button type="submit" class="mc-btn mc-btn--primary ps-save-btn" data-i18n-label="save_preferences" data-i18n="save_preferences"><?= provider_i18n('save_preferences', $lang) ?></button>
              </div>
            </form>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>

<?php
$psJsVer = (int) @filemtime(ASSETS_PATH . '/js/provider-settings.js');
$pwJsVer = (int) @filemtime(ASSETS_PATH . '/js/provider-password.js');
?>
<script src="<?= ASSET_BASE ?>/assets/js/provider-settings.js?v=<?= $psJsVer ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/provider-password.js?v=<?= $pwJsVer ?>"></script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
