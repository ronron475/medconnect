<?php
require_once dirname(__DIR__, 3) . '/bootstrap/app.php';
require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/app/includes/auth_guard.php';

$patientProfileUrl = ASSET_BASE . '/views/patient/profile.php';

// Auth guard
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'patient') {
    header('Location: ' . auth_signin_required_url());
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $patientProfileUrl);
    exit;
}

// CSRF check
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['contact_errors']['general'] = 'Invalid request. Please try again.';
    header('Location: ' . $patientProfileUrl);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$errors  = [];

// ── Sanitise & validate ──
$contact_number   = trim($_POST['contact_number']   ?? '');
$email            = trim($_POST['email']            ?? '');
$barangay         = trim($_POST['barangay']         ?? '');
$city_municipality= trim($_POST['city_municipality']?? '');

if ($contact_number === '') {
    $errors['contact_number'] = 'Contact number is required.';
} elseif (!preg_match('/^(09|\+639)\d{9}$/', $contact_number)) {
    $errors['contact_number'] = 'Enter a valid PH mobile number (e.g. 09171234567).';
}

if ($email === '') {
    $errors['email'] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Enter a valid email address.';
} else {
    // Check email not taken by another user
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $chk->execute([$email, $user_id]);
    if ($chk->fetch()) {
        $errors['email'] = 'This email is already in use by another account.';
    }
}

if ($barangay === '') {
    $errors['barangay'] = 'Barangay is required.';
} elseif (strlen($barangay) > 120) {
    $errors['barangay'] = 'Barangay name is too long.';
}

if (!empty($errors)) {
    $_SESSION['contact_errors'] = $errors;
    header('Location: ' . $patientProfileUrl);
    exit;
}

// ── Persist ──
try {
    $pdo->beginTransaction();

    // Update email on users table
    $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")
        ->execute([$email, $user_id]);

    // Update contact fields on patient_registrations (matched by user email before update)
    $pdo->prepare("
        UPDATE patient_registrations
        SET contact_number = ?, barangay = ?
        WHERE email = (SELECT email FROM users WHERE id = ? LIMIT 1)
    ")->execute([$contact_number, $barangay, $user_id]);

    $pdo->commit();

    // Audit log — record the contact update
    require_once dirname(__DIR__, 2) . '/includes/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $user_id,
        'action_type' => AuditAction::CONTACT_UPDATED,
        'description' => 'Patient updated contact information.',
        'meta'        => [
            'fields_changed' => ['contact_number', 'email', 'barangay'],
        ],
    ]);

    $_SESSION['contact_success'] = 'Contact information updated successfully.';

} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['contact_errors']['general'] = 'Update failed. Please try again later.';
}

header('Location: ' . $patientProfileUrl);
exit;
