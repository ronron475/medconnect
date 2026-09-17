<?php
/**
 * update_contact.php
 * Handles two form_type values:
 *   (default) — updates email, contact_number, barangay on users + patient_registrations
 *   "emergency" — updates emergency_contact_name/phone/relation on patient_registrations
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';

// ── Guards ────────────────────────────────────────────────────────────────────
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_settings.php';
$userId = patient_settings_require_patient_ready($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/views/patient/profile.php');
    exit;
}

if (
    empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
) {
    $_SESSION['identity_error'] = 'Invalid request token. Please refresh and try again.';
    header('Location: ' . BASE_URL . '/views/patient/profile.php');
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
    if ($phone !== '') {
        require_once BASE_PATH . '/app/includes/patient_account_security.php';
        $phoneErr = patient_phone_validation_error($phone);
        if ($phoneErr !== null) {
            $_SESSION['emergency_errors']['emergency_contact_phone'] = $phoneErr;
            header('Location: ' . BASE_URL . '/views/patient/profile.php');
            exit;
        }
        $phone = patient_canonical_ph_mobile($phone);
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

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } else {
        require_once BASE_PATH . '/app/includes/contact_validation.php';
        $email = mc_normalize_email($email);
        if ($emailErr = mc_email_validation_error($email, true)) {
            $errors['email'] = $emailErr;
        } elseif ($dup = mc_email_duplicate_error($pdo, $email, $user_id)) {
            $errors['email'] = $dup;
        }
    }

    if ($contact_number === '') {
        $errors['contact_number'] = 'Contact number is required.';
    } else {
        require_once BASE_PATH . '/app/includes/patient_account_security.php';
        $phoneErr = patient_phone_validation_error($contact_number);
        if ($phoneErr !== null) {
            $errors['contact_number'] = $phoneErr;
        } else {
            $contact_number = patient_canonical_ph_mobile($contact_number);
        }
    }

    if ($barangay === '') {
        $errors['barangay'] = 'Barangay is required.';
    } elseif (strlen($barangay) > 120) {
        $errors['barangay'] = 'Barangay name is too long (max 120 characters).';
    }

    if (!empty($errors)) {
        $_SESSION['contact_errors'] = $errors;
        header('Location: ' . BASE_URL . '/views/patient/profile.php');
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

        // Sync GIS address metadata when barangay/contact changes.
        // Preserve existing GPS/geocoded coordinates — never wipe precise pins on contact-only updates.
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

            $keepLat = null;
            $keepLng = null;
            $keepSource = 'barangay_centroid';
            try {
                $locStmt = $pdo->prepare("
                    SELECT latitude, longitude, location_source
                    FROM patient_locations
                    WHERE patient_id = ?
                    LIMIT 1
                ");
                $locStmt->execute([$user_id]);
                $existingLoc = $locStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existingLoc) {
                    $src = strtolower(trim((string) ($existingLoc['location_source'] ?? '')));
                    $latRaw = $existingLoc['latitude'] ?? null;
                    $lngRaw = $existingLoc['longitude'] ?? null;
                    $hasCoords = is_numeric($latRaw) && is_numeric($lngRaw)
                        && !(((float) $latRaw) == 0.0 && ((float) $lngRaw) == 0.0);
                    if ($hasCoords && in_array($src, ['gps', 'manual', 'imported', 'address_geocoded'], true)) {
                        $keepLat = (float) $latRaw;
                        $keepLng = (float) $lngRaw;
                        $keepSource = $src === 'address_geocoded' ? 'address_geocoded' : 'gps';
                    }
                }
            } catch (Throwable $e) {
                // Non-fatal — fall back to barangay centroid sync.
            }

            $gis->savePatientLocation(
                $user_id,
                (string) ($profileRow['province'] ?? 'Negros Occidental'),
                (string) ($profileRow['city_municipality'] ?? 'Bago City'),
                (string) ($profileRow['barangay'] ?? ''),
                (string) ($profileRow['address'] ?? ''),
                $keepLat,
                $keepLng,
                $keepSource
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

header('Location: ' . BASE_URL . '/views/patient/profile.php');
exit;
