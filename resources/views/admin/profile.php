<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}

if (!defined('MC_PORTAL_SHELL') || MC_PORTAL_SHELL !== 'superadmin') {
    require_once __DIR__ . '/_portal_access.php';
} else {
    require_once BASE_PATH . '/app/includes/auth_guard.php';
    auth_require_role(['admin', 'superadmin']);
}

require_once BASE_PATH . '/app/includes/profile_picture.php';
require_once BASE_PATH . '/app/includes/admin_settings.php';
profile_picture_ensure_schema($pdo);

$uid = (int) $_SESSION['user_id'];
profile_picture_sync_session($pdo, $uid);

$stmt = $pdo->prepare('
    SELECT id, first_name, last_name, email, phone, role, is_active, is_email_verified, created_at, updated_at
    FROM users WHERE id = ? LIMIT 1
');
$stmt->execute([$uid]);
$admin_user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$is_superadmin_portal = (defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin')
    || (($admin_user['role'] ?? $_SESSION['user_role'] ?? '') === 'superadmin');

$page_title = 'My Profile';
$profile_initials = profile_picture_initials($admin_user['first_name'] ?? '', $admin_user['last_name'] ?? '');
$profile_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);
$profile_display_name = trim(($admin_user['first_name'] ?? '') . ' ' . ($admin_user['last_name'] ?? '')) ?: 'Administrator';
$profile_role_label = $is_superadmin_portal ? 'Super Administrator' : 'System Administrator';
$profile_upload_layout = 'portal';
$portal_eyebrow = $is_superadmin_portal ? 'Super Administration · Account Settings' : 'Administration · Account Settings';
$portal_heading = $is_superadmin_portal ? 'Super Administrator Profile' : 'Administrator Profile';
$portal_portal_name = $is_superadmin_portal ? 'Super Admin portal' : 'admin portal';

$member_since = !empty($admin_user['created_at'])
    ? date('M j, Y', strtotime($admin_user['created_at']))
    : '—';
$last_updated = !empty($admin_user['updated_at'])
    ? date('M j, Y', strtotime($admin_user['updated_at']))
    : '—';
$is_active = !empty($admin_user['is_active']);
$is_verified = !empty($admin_user['is_email_verified']);
$admin_sessions = admin_settings_list_sessions($pdo, $uid, (string) ($admin_user['role'] ?? $_SESSION['user_role'] ?? 'admin'));

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.1">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-portal-profile.css?v=1.0">

<article class="portal-profile-page staff-apps-page">

<header class="staff-apps-hero">
    <div class="staff-apps-hero__content">
        <span class="staff-apps-hero__eyebrow"><?= htmlspecialchars($portal_eyebrow) ?></span>
        <h1 class="staff-apps-hero__title"><?= htmlspecialchars($portal_heading) ?></h1>
        <p class="staff-apps-hero__desc">Manage your profile photo and review your account details for the medConnect <?= htmlspecialchars($portal_portal_name) ?>.</p>
    </div>
</header>

<div class="portal-profile-grid">
    <section class="portal-profile-card portal-profile-card--photo" aria-labelledby="portalProfilePhotoTitle">
        <div class="portal-profile-card__head">
            <div class="portal-profile-card__icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </div>
            <div>
                <h2 class="portal-profile-card__title" id="portalProfilePhotoTitle">Profile Photo</h2>
                <p class="portal-profile-card__sub">Your photo appears in the sidebar, header, and across the portal.</p>
            </div>
        </div>
        <div class="portal-profile-card__body">
            <?php require VIEWS_PATH . '/partials/profile_upload_card.php'; ?>
            <span class="portal-profile-role-badge<?= $is_superadmin_portal ? ' portal-profile-role-badge--super' : '' ?>"><?= htmlspecialchars($profile_role_label) ?></span>
            <div class="portal-profile-photo-tips">
                <p class="portal-profile-photo-tips__title">Photo guidelines</p>
                <ul>
                    <li>Use a clear, professional headshot</li>
                    <li>JPG, PNG, or WEBP up to 2 MB</li>
                    <li>Square images work best</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="portal-profile-card portal-profile-card--details" aria-labelledby="portalProfileDetailsTitle">
        <div class="portal-profile-card__head">
            <div class="portal-profile-card__icon portal-profile-card__icon--indigo" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div>
                <h2 class="portal-profile-card__title" id="portalProfileDetailsTitle">Account Details</h2>
                <p class="portal-profile-card__sub">Contact your system owner if any of these details need to be changed.</p>
            </div>
        </div>
        <div class="portal-profile-card__body">
            <div class="portal-profile-fields">
                <div class="portal-profile-field portal-profile-field--full">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Full Name
                    </span>
                    <span class="portal-profile-field__value"><?= htmlspecialchars($profile_display_name) ?></span>
                </div>

                <div class="portal-profile-field">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Email
                    </span>
                    <span class="portal-profile-field__value"><?= htmlspecialchars($admin_user['email'] ?? '—') ?></span>
                </div>

                <div class="portal-profile-field">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Phone
                    </span>
                    <span class="portal-profile-field__value<?= empty($admin_user['phone']) ? ' portal-profile-field__value--muted' : '' ?>"><?= htmlspecialchars($admin_user['phone'] ?? '—') ?></span>
                </div>

                <div class="portal-profile-field">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        User ID
                    </span>
                    <span class="portal-profile-field__value"><code>#<?= (int) ($admin_user['id'] ?? 0) ?></code></span>
                </div>

                <div class="portal-profile-field">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Role
                    </span>
                    <span class="portal-profile-field__value"><?= htmlspecialchars($profile_role_label) ?></span>
                </div>

                <div class="portal-profile-field">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Account Status
                    </span>
                    <span class="portal-profile-field__value">
                        <span class="portal-profile-pill<?= $is_active ? ' portal-profile-pill--active' : ' portal-profile-pill--inactive' ?>"><?= $is_active ? 'Active' : 'Inactive' ?></span>
                    </span>
                </div>

                <div class="portal-profile-field">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        Email Verified
                    </span>
                    <span class="portal-profile-field__value">
                        <span class="portal-profile-pill<?= $is_verified ? ' portal-profile-pill--verified' : ' portal-profile-pill--unverified' ?>"><?= $is_verified ? 'Verified' : 'Not Verified' ?></span>
                    </span>
                </div>

                <div class="portal-profile-field">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Member Since
                    </span>
                    <span class="portal-profile-field__value portal-profile-field__value--muted"><?= htmlspecialchars($member_since) ?></span>
                </div>

                <div class="portal-profile-field">
                    <span class="portal-profile-field__label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Last Updated
                    </span>
                    <span class="portal-profile-field__value portal-profile-field__value--muted"><?= htmlspecialchars($last_updated) ?></span>
                </div>
            </div>
        </div>
    </section>
</div>

<section class="portal-profile-card portal-profile-card--security" aria-labelledby="portalProfileSecurityTitle">
    <div class="portal-profile-card__head">
        <div class="portal-profile-card__icon portal-profile-card__icon--indigo" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <div>
            <h2 class="portal-profile-card__title" id="portalProfileSecurityTitle">Active Sessions</h2>
            <p class="portal-profile-card__sub">Devices signed in to your administrator account in the last 7 days.</p>
        </div>
        <button type="button" class="portal-profile-btn portal-profile-btn--ghost" id="adminLogoutAllBtn">Logout All Devices</button>
    </div>
    <div class="portal-profile-card__body">
        <div id="adminSessionsAlert" class="portal-profile-alert" role="status" hidden></div>
        <?php if (empty($admin_sessions)): ?>
            <p class="portal-profile-card__sub">No active sessions recorded in the last 7 days.</p>
        <?php else: ?>
            <ul class="portal-profile-session-list">
                <?php foreach ($admin_sessions as $sess): ?>
                <li class="portal-profile-session-item<?= !empty($sess['is_current']) ? ' is-current' : '' ?>">
                    <div>
                        <strong><?= htmlspecialchars((string) ($sess['browser'] ?? 'Unknown browser')) ?></strong>
                        <span class="portal-profile-card__sub"><?= htmlspecialchars(ucfirst((string) ($sess['device'] ?? 'desktop'))) ?> · Last active <?= htmlspecialchars((string) ($sess['last_activity_label'] ?? '—')) ?></span>
                    </div>
                    <?php if (!empty($sess['is_current'])): ?>
                        <span class="portal-profile-pill portal-profile-pill--active">This device</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<div class="staff-apps-note" role="note">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><strong>Profile photo only.</strong> You can update your photo here. For name, email, or role changes, contact the system owner or Super Administrator.</span>
</div>

</article>

<script src="<?= ASSET_BASE ?>/assets/js/admin-portal-profile.js?v=<?= (int) @filemtime(ASSETS_PATH . '/js/admin-portal-profile.js') ?>"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
