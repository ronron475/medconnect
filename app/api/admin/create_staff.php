<?php
/**
 * API: Create staff account (admin)
 * URL: /app/api/admin/create_staff.php
 */
session_start();
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_verification.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/PRCVerificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
portal_api_require_admin_portal();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$first_name = trim($_POST['first_name'] ?? '');
$last_name  = trim($_POST['last_name'] ?? '');
$email      = trim($_POST['email'] ?? '');
$phone      = trim($_POST['phone'] ?? '');
$password   = $_POST['password'] ?? '';
$role       = $_POST['role'] ?? 'provider';
$prc_license = trim($_POST['prc_license_number'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$birthdate = trim($_POST['birthdate'] ?? $_POST['date_of_birth'] ?? '');
$specialization = trim($_POST['specialization'] ?? $_POST['specialty'] ?? '');
$facility = trim($_POST['facility'] ?? $_POST['hospital_clinic'] ?? '');
$prc_confirmed = !empty($_POST['prc_verification_confirmed'])
    || (string) ($_POST['prc_verification_confirmed'] ?? '') === '1';

if (!$first_name || !$last_name || !$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'First name, last name, email, and password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

if (!in_array($role, ['provider', 'admin', 'bhw'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid role.']);
    exit;
}

if ($role === 'admin' && !portal_is_superadmin()) {
    echo json_encode(['success' => false, 'message' => 'Only Super Admin can create administrator accounts.']);
    exit;
}

if ($role === 'bhw') {
    echo json_encode([
        'success' => false,
        'message' => 'BHW accounts require Maker-Checker approval. Use the BHW Applications workflow to submit for Super Administrator review.',
    ]);
    exit;
}

if ($role === 'provider') {
    echo json_encode([
        'success' => false,
        'message' => 'Doctor accounts require Maker-Checker approval. Use the Doctor Applications workflow to submit for Super Administrator review.',
    ]);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}

try {
    provider_verification_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $hasVerified = in_array('is_email_verified', $columns, true);
    $hasPhone = in_array('phone', $columns, true);
    $hasBarangay = in_array('barangay_id', $columns, true);

    $hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Doctors pre-verified at creation are active immediately; other roles default active.
    $is_active = 1;
    $admin_id = (int) ($_SESSION['user_id'] ?? 0);

    $fields = ['first_name', 'last_name', 'email', 'password', 'role', 'is_active'];
    $values = [$first_name, $last_name, $email, $hashed_password, $role, $is_active];
    $placeholders = array_fill(0, count($fields), '?');

    if ($hasPhone) {
        $fields[] = 'phone';
        $values[] = $phone !== '' ? $phone : null;
        $placeholders[] = '?';
    }

    if ($hasVerified) {
        $fields[] = 'is_email_verified';
        $fields[] = 'email_verified_at';
        $values[] = 1;
        $values[] = date('Y-m-d H:i:s');
        $placeholders[] = '?';
        $placeholders[] = '?';
    }

    if (in_array('created_at', $columns, true)) {
        $fields[] = 'created_at';
        $values[] = date('Y-m-d H:i:s');
        $placeholders[] = '?';
    }
    if (in_array('updated_at', $columns, true)) {
        $fields[] = 'updated_at';
        $values[] = date('Y-m-d H:i:s');
        $placeholders[] = '?';
    }

    $sql = 'INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($values);
    $new_user_id = (int) $pdo->lastInsertId();

    if ($role === 'provider') {
        $profileCols = ['user_id', 'prc_license_number', 'verification_status', 'verified_by', 'verified_at', 'created_by'];
        $profileVals = [$new_user_id, $prc_license, 'verified', $admin_id, date('Y-m-d H:i:s'), $admin_id];
        $profilePlace = array_fill(0, count($profileCols), '?');

        $optionalProfile = [
            'middle_name' => $middle_name !== '' ? $middle_name : null,
            'birthdate'   => $birthdate !== '' ? $birthdate : null,
            'specialty'   => $specialization !== '' ? $specialization : null,
            'facility'    => $facility !== '' ? $facility : null,
        ];

        $profileColumnCheck = $pdo->query('SHOW COLUMNS FROM provider_profiles')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($optionalProfile as $col => $val) {
            if (in_array($col, $profileColumnCheck, true)) {
                $profileCols[] = $col;
                $profileVals[] = $val;
                $profilePlace[] = '?';
            }
        }

        $sqlProfile = 'INSERT INTO provider_profiles (' . implode(', ', $profileCols) . ') VALUES (' . implode(', ', $profilePlace) . ')';
        $pdo->prepare($sqlProfile)->execute($profileVals);
    }

    $role_label = $role === 'provider' ? 'Doctor' : ucfirst($role);
    $doctor_display = trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name);

    try {
        require_once BASE_PATH . '/app/includes/audit_log.php';

        $admin_stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $admin_stmt->execute([$admin_id]);
        $admin_row = $admin_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $admin_name = trim(($admin_row['first_name'] ?? 'Administrator') . ' ' . ($admin_row['last_name'] ?? ''));

        if ($role === 'provider') {
            $audit_description = PRCVerificationService::buildManualVerificationAuditMessage(
                $admin_name,
                $doctor_display,
                $prc_license
            );
            $action_type = 'doctor_prc_verified_created';
        } else {
            $audit_description = "Admin created {$role_label} account for {$email}.";
            $action_type = 'staff_account_created';
        }

        audit_log($pdo, [
            'patient_id'  => $admin_id,
            'action_type' => $action_type,
            'description' => $audit_description,
            'meta'        => [
                'role'                 => $role,
                'email'                => $email,
                'doctor_name'          => $role === 'provider' ? $doctor_display : null,
                'prc_license_number'   => $role === 'provider' ? $prc_license : null,
                'verification_status'  => $role === 'provider' ? 'verified' : null,
                'verified_by'          => $role === 'provider' ? $admin_id : null,
                'verified_at'          => $role === 'provider' ? date('c') : null,
                'admin_name'           => $admin_name,
                'birthdate'            => $role === 'provider' ? $birthdate : null,
                'manual_prc_portal'    => $role === 'provider' ? PRCVerificationService::PORTAL_URL : null,
            ],
        ]);
    } catch (Throwable $e) { /* non-fatal */ }

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    $staffName = trim($first_name . ' ' . $last_name);
    if ($role === 'provider') {
        NotificationEvents::providerRegistered($pdo, $new_user_id, $staffName, (int) $_SESSION['user_id']);
    } elseif ($role === 'bhw') {
        NotificationEvents::bhwRegistered($pdo, $new_user_id, $staffName, (int) $_SESSION['user_id']);
    }

    $success_message = $role === 'provider'
        ? 'Doctor account created successfully. PRC license was manually verified and the account is active.'
        : $role_label . ' account created successfully.';

    echo json_encode([
        'success' => true,
        'message' => $success_message,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
