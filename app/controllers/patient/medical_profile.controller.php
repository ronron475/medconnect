<?php
require_once dirname(__DIR__, 3) . '/bootstrap/app.php';
require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/app/includes/auth_guard.php';

$patientProfileUrl = ASSET_BASE . '/views/patient/profile.php';

if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'patient') {
    header('Location: ' . auth_signin_required_url());
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $patientProfileUrl);
    exit;
}

// CSRF
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['medical_errors']['general'] = 'Invalid request. Please try again.';
    header('Location: ' . $patientProfileUrl);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$errors  = [];

$allowed_blood      = ['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'];
$allowed_philhealth = ['Active','Inactive','Pending','Exempt'];

$blood_type          = trim($_POST['blood_type']          ?? '');
$philhealth_status   = trim($_POST['philhealth_status']   ?? '');
$existing_conditions = trim($_POST['existing_conditions'] ?? '');
$allergies           = trim($_POST['allergies']           ?? '');
$current_medications = trim($_POST['current_medications'] ?? '');

if (!in_array($blood_type, $allowed_blood, true)) {
    $errors['blood_type'] = 'Please select a valid blood type.';
}
if (!in_array($philhealth_status, $allowed_philhealth, true)) {
    $errors['philhealth_status'] = 'Please select a valid PhilHealth status.';
}
if (strlen($existing_conditions) > 500) {
    $errors['existing_conditions'] = 'Maximum 500 characters allowed.';
}
if (strlen($allergies) > 500) {
    $errors['allergies'] = 'Maximum 500 characters allowed.';
}
if (strlen($current_medications) > 500) {
    $errors['current_medications'] = 'Maximum 500 characters allowed.';
}

if (!empty($errors)) {
    $_SESSION['medical_errors'] = $errors;
    header('Location: ' . $patientProfileUrl);
    exit;
}

try {
    // Fetch current patient email to match patient_registrations row
    $row = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $row->execute([$user_id]);
    $user_email = $row->fetchColumn();

    $pdo->prepare("
        UPDATE patient_registrations
        SET blood_type          = ?,
            philhealth_status   = ?,
            existing_conditions = ?,
            allergies           = ?,
            current_medications = ?
        WHERE email = ?
    ")->execute([
        $blood_type,
        $philhealth_status,
        $existing_conditions,
        $allergies,
        $current_medications,
        $user_email,
    ]);

    $_SESSION['medical_success'] = 'Medical profile updated successfully.';

    // Audit log — record the medical profile update
    require_once dirname(__DIR__, 2) . '/includes/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $user_id,
        'action_type' => AuditAction::MEDICAL_PROFILE_UPDATED,
        'description' => 'Patient updated medical profile information.',
        'meta'        => [
            'fields_changed' => ['blood_type', 'philhealth_status',
                                 'existing_conditions', 'allergies', 'current_medications'],
        ],
    ]);

} catch (PDOException $e) {
    $_SESSION['medical_errors']['general'] = 'Update failed. Please try again later.';
}

header('Location: ' . $patientProfileUrl);
exit;
