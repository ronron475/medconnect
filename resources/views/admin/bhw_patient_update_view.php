<?php
/**
 * Read-only Admin/SuperAdmin view of the latest BHW-entered patient profile.
 * Loads live data from users + patient_registrations. No edit/save endpoints.
 */
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo 'Method not allowed.';
    exit;
}

require_once BASE_PATH . '/app/includes/auth_guard.php';
require_once BASE_PATH . '/app/core/NotificationManager.php';

if (!defined('MC_PORTAL_SHELL') || MC_PORTAL_SHELL !== 'superadmin') {
    require_once __DIR__ . '/_portal_access.php';
} else {
    auth_require_role(['admin', 'superadmin']);
}

$patientId = (int) ($_GET['patient_id'] ?? 0);
$notificationId = (int) ($_GET['notification_id'] ?? 0);
$viewerId = (int) ($_SESSION['user_id'] ?? 0);

// Mark only this notification as read when opened from the inbox (idempotent).
if ($notificationId > 0 && $viewerId > 0) {
    NotificationManager::markRead($pdo, $viewerId, $notificationId);
}

$page_title = 'BHW Patient Update';
$profile = null;
$updatedByName = '';

if ($patientId > 0) {
    require_once BASE_PATH . '/app/includes/patient_settings.php';
    patient_settings_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT
            u.id, u.first_name, u.last_name, u.email, u.is_active,
            pr.contact_number, pr.barangay, pr.barangay_id, pr.purok, pr.blood_type,
            pr.existing_conditions, pr.allergies, pr.current_medications,
            pr.medical_profile_updated_at, pr.medical_profile_updated_by,
            pr.birthdate, pr.gender, pr.age, pr.patient_code
        FROM users u
        LEFT JOIN patient_registrations pr
          ON pr.user_id = u.id OR pr.email = u.email
        WHERE u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($profile) {
        $byId = (int) ($profile['medical_profile_updated_by'] ?? 0);
        if ($byId > 0) {
            $by = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = ? LIMIT 1");
            $by->execute([$byId]);
            $byRow = $by->fetch(PDO::FETCH_ASSOC) ?: [];
            $updatedByName = trim(($byRow['first_name'] ?? '') . ' ' . ($byRow['last_name'] ?? ''));
            if ($updatedByName !== '' && ($byRow['role'] ?? '') === 'bhw') {
                $updatedByName .= ' (BHW)';
            }
        }
    }
}

function bhw_patient_view_text(?string $value): string
{
    $v = trim((string) $value);
    return $v !== '' ? $v : '—';
}

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.1">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-case-viewer.css?v=1.0">

<article class="case-viewer-page staff-apps-page">

<header class="staff-apps-hero staff-apps-hero--intro">
    <div class="staff-apps-hero__content">
        <p class="staff-apps-hero__desc">Read-only view of the latest patient information entered by a Barangay Health Worker. Editing is disabled.</p>
    </div>
</header>

<div class="staff-apps-note" role="note">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    <span><strong>Read-only.</strong> This page loads live database values and does not accept create, update, or delete actions.</span>
</div>

<?php if ($patientId <= 0): ?>
<div class="case-viewer-empty" style="margin-top:1rem;">
    <p class="case-viewer-empty__title">Missing patient</p>
    <p class="case-viewer-empty__text">Open this view from a BHW Patient Update notification so the correct patient record is loaded.</p>
</div>
<?php elseif (!$profile): ?>
<div class="case-viewer-empty" style="margin-top:1rem;">
    <p class="case-viewer-empty__title">Patient not found</p>
    <p class="case-viewer-empty__text">No patient account matches this ID.</p>
</div>
<?php else: ?>
<div class="case-viewer-detail" style="margin-top:1rem;">
    <div class="case-viewer-profile">
        <div class="case-viewer-profile__top">
            <span class="case-viewer-profile__avatar" aria-hidden="true">
                <?= htmlspecialchars(strtoupper(substr((string) ($profile['first_name'] ?? ''), 0, 1) . substr((string) ($profile['last_name'] ?? ''), 0, 1)) ?: '?') ?>
            </span>
            <div>
                <h2 class="case-viewer-profile__name"><?= htmlspecialchars(trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))) ?></h2>
                <p class="case-viewer-profile__meta"><?= htmlspecialchars((string) ($profile['email'] ?? '')) ?></p>
            </div>
            <span class="case-viewer-profile__status">View only</span>
        </div>

        <dl class="case-viewer-profile__grid">
            <div class="case-viewer-profile__field"><dt>Patient ID</dt><dd>#<?= (int) $profile['id'] ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Barangay</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['barangay'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Purok</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['purok'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Contact</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['contact_number'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Email</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['email'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Blood type</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['blood_type'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Existing conditions</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['existing_conditions'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Allergies</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['allergies'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Current medications</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['current_medications'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Last BHW update</dt><dd><?= htmlspecialchars(bhw_patient_view_text($profile['medical_profile_updated_at'] ?? null)) ?></dd></div>
            <div class="case-viewer-profile__field"><dt>Updated by</dt><dd><?= htmlspecialchars(bhw_patient_view_text($updatedByName !== '' ? $updatedByName : null)) ?></dd></div>
        </dl>

        <div class="case-viewer-readonly">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            BHW-entered fields are read-only — no Edit, Save, Update, or Delete
        </div>
    </div>
</div>
<?php endif; ?>

</article>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
