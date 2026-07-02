<?php
/**
 * update_contact.php
 * Handles two form_type values:
 *   (default) — updates email, contact_number, barangay on users + patient_registrations
 *   "emergency" — updates emergency_contact_name/phone/relation on patient_registrations
 */
session_start();
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';

// ── Guards ────────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'patient') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/views/patient/dashboard.php#view-profile');
    exit;
}

if (
    empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
) {
    $_SESSION['identity_error'] = 'Invalid request token. Please refresh and try again.';
    header('Location: ' . BASE_URL . '/views/patient/dashboard.php#view-profile');
    exit;
}

$user_id   = (int) $_SESSION['user_id'];
$form_type = trim($_POST['form_type'] ?? 'contact');

// ── Ensure emergency contact columns exist (safe ALTER, ignored if already present) ──
try {
    $pdo->exec("
        ALTER TABLE patient_registrations
            ADD COLUMN IF NOT EXISTS emergency_contact_name     VARCHAR(100) NULL,
            ADD COLUMN IF NOT EXISTS emergency_contact_phone    VARCHAR(20)  NULL,
            ADD COLUMN IF NOT EXISTS emergency_contact_relation VARCHAR(60)  NULL
    ");
} catch (PDOException $e) {
    // MySQL < 8.0 doesn't support IF NOT EXISTS on ALTER COLUMN — try one by one silently
    $alters = [
        "ALTER TABLE patient_registrations ADD COLUMN emergency_contact_name     VARCHAR(100) NULL",
        "ALTER TABLE patient_registrations ADD COLUMN emergency_contact_phone    VARCHAR(20)  NULL",
        "ALTER TABLE patient_registrations ADD COLUMN emergency_contact_relation VARCHAR(60)  NULL",
    ];
    foreach ($alters as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $inner) { /* column already exists — skip */ }
    }
}

// ────────────────────────────────────────────────────────────────────────────
if ($form_type === 'emergency') {
    // ── Emergency Contact Update ────────────────────────────────────────────
    $name     = trim($_POST['emergency_contact_name']     ?? '');
    $phone    = trim($_POST['emergency_contact_phone']    ?? '');
    $relation = trim($_POST['emergency_contact_relation'] ?? '');

    // Validate phone if provided
    if ($phone !== '' && !preg_match('/^(09|\+639)\d{9}$/', $phone)) {
        $_SESSION['emergency_errors']['emergency_contact_phone'] =
            'Enter a valid PH mobile number (e.g. 09171234567).';
        header('Location: ' . BASE_URL . '/views/patient/dashboard.php#view-profile');
        exit;
    }

    try {
        $pdo->prepare("
            UPDATE patient_registrations
               SET emergency_contact_name     = ?,
                   emergency_contact_phone    = ?,
                   emergency_contact_relation = ?
             WHERE email = (SELECT email FROM users WHERE id = ? LIMIT 1)
        ")->execute([$name ?: null, $phone ?: null, $relation ?: null, $user_id]);

        $_SESSION['identity_success'] = 'Emergency contact saved successfully.';

    } catch (PDOException $e) {
        error_log('update_contact emergency error: ' . $e->getMessage());
        $_SESSION['identity_error'] = 'Could not save emergency contact. Please try again.';
    }

} else {
    // ── Contact Details Update ──────────────────────────────────────────────
    $email          = trim($_POST['email']          ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $barangay       = trim($_POST['barangay']       ?? '');
    $errors         = [];

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        // Check not taken by another account
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $chk->execute([$email, $user_id]);
        if ($chk->fetch()) {
            $errors['email'] = 'This email is already in use by another account.';
        }
    }

    if ($contact_number === '') {
        $errors['contact_number'] = 'Contact number is required.';
    } elseif (!preg_match('/^(09|\+639)\d{9}$/', $contact_number)) {
        $errors['contact_number'] = 'Enter a valid PH mobile number (e.g. 09171234567).';
    }

    if ($barangay === '') {
        $errors['barangay'] = 'Barangay is required.';
    } elseif (strlen($barangay) > 120) {
        $errors['barangay'] = 'Barangay name is too long (max 120 characters).';
    }

    if (!empty($errors)) {
        $_SESSION['contact_errors'] = $errors;
        header('Location: ' . BASE_URL . '/views/patient/dashboard.php#view-profile');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Fetch current email BEFORE the update so we can match patient_registrations
        $old_email_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
        $old_email_stmt->execute([$user_id]);
        $old_email = $old_email_stmt->fetchColumn();

        // Update users table
        $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")
            ->execute([$email, $user_id]);

        // Update patient_registrations using the OLD email as the lookup key,
        // and set the new email + contact fields at the same time
        $pdo->prepare("
            UPDATE patient_registrations
               SET email          = ?,
                   contact_number = ?,
                   barangay       = ?
             WHERE email = ?
        ")->execute([$email, $contact_number, $barangay, $old_email]);

        $pdo->commit();

        // Sync GIS location when barangay/contact changes
        $profile = $pdo->prepare("
            SELECT pr.province, pr.city_municipality, pr.barangay,
                   COALESCE(pr.full_address, pr.address, '') AS address
            FROM patient_registrations pr
            INNER JOIN users u ON u.email = pr.email
            WHERE u.id = ?
            LIMIT 1
        ");
        $profile->execute([$user_id]);
        $profileRow = $profile->fetch(PDO::FETCH_ASSOC);
        if ($profileRow) {
            require_once BASE_PATH . '/app/core/GisDashboardService.php';
            $gis = new GisDashboardService($pdo);
            $gis->savePatientLocation(
                $user_id,
                (string) ($profileRow['province'] ?? 'Negros Occidental'),
                (string) ($profileRow['city_municipality'] ?? 'Bago City'),
                (string) ($profileRow['barangay'] ?? ''),
                (string) ($profileRow['address'] ?? ''),
                null,
                null,
                'manual'
            );
        }

        // Keep session email in sync if it was stored
        $_SESSION['user_email'] = $email;

        // Audit log
        require_once BASE_PATH . '/app/includes/audit_log.php';
        if (defined('AuditAction::CONTACT_UPDATED') || class_exists('AuditAction')) {
            audit_log($pdo, [
                'patient_id'  => $user_id,
                'action_type' => AuditAction::CONTACT_UPDATED,
                'description' => 'Patient updated contact information.',
                'meta'        => ['fields_changed' => ['email', 'contact_number', 'barangay']],
            ]);
        }

        $_SESSION['identity_success'] = 'Contact details updated successfully.';

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('update_contact error: ' . $e->getMessage());
        $_SESSION['identity_error'] = 'Update failed. Please try again later.';
    }
}

header('Location: ' . BASE_URL . '/views/patient/dashboard.php#view-profile');
exit;
