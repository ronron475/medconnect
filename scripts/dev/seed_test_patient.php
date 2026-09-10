<?php
/**
 * Create/update a localhost-only test patient for triage testing.
 *
 * Usage:
 *   php scripts/dev/seed_test_patient.php
 *
 * Login (local):
 *   Email:    test.patient@medconnect.local
 *   Password: Test@1234
 *
 * Refuses to run against Hostinger / remote DB names.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/patient_account_security.php';

$email = 'test.patient@medconnect.local';
$password = 'Test@1234';
$firstName = 'Test';
$lastName = 'Patient';
$middleName = 'Local';
$fullName = 'Test Local Patient';
$dob = '1995-05-15';
$age = 30;
$nationalIdRaw = 'TEST-LOCAL-000001';
$nationalIdHash = hash('sha256', $nationalIdRaw);
$barangay = 'Poblacion';
$contact = '09170000001';

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$host = (string) ($pdo->query('SELECT @@hostname')->fetchColumn() ?: '');
$blocked = preg_match('/u\d+_|(hstgr|hostinger|remote|prod)/i', $dbName . ' ' . $host) === 1
    || (!in_array(strtolower($dbName), ['medconnect', 'medconnect_local', 'medconnect_dev', 'test'], true)
        && getenv('MC_ALLOW_REMOTE_SEED') !== '1');

// Allow common local DB name "medconnect"; block Hostinger-style names.
if (preg_match('/^u\d+_/i', $dbName) === 1 || stripos($dbName, 'hstgr') !== false) {
    fwrite(STDERR, "REFUSED: database '{$dbName}' looks remote/Hostinger. Localhost only.\n");
    exit(1);
}

echo "Database: {$dbName}\n";
echo "Seeding localhost test patient...\n\n";

patient_security_ensure_schema($pdo);

$userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$prCols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$hash = patient_hash_password($password);

$pdo->beginTransaction();
try {
    $find = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $find->execute([$email]);
    $existingId = (int) ($find->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $sql = "UPDATE users SET
            first_name = ?, last_name = ?, password = ?, role = 'patient',
            is_active = 1, account_status = 'active', must_change_password = 0,
            updated_at = NOW()";
        $params = [$firstName, $lastName, $hash];
        if (in_array('is_email_verified', $userCols, true)) {
            $sql .= ', is_email_verified = 1, email_verified_at = COALESCE(email_verified_at, NOW())';
        }
        if (in_array('terms_accepted_at', $userCols, true)) {
            $sql .= ', terms_accepted_at = COALESCE(terms_accepted_at, NOW())';
        }
        if (in_array('privacy_accepted_at', $userCols, true)) {
            $sql .= ', privacy_accepted_at = COALESCE(privacy_accepted_at, NOW())';
        }
        $sql .= ' WHERE id = ?';
        $params[] = $existingId;
        $pdo->prepare($sql)->execute($params);
        $userId = $existingId;
        echo "OK  Updated users.id={$userId}\n";
    } else {
        $cols = ['first_name', 'last_name', 'email', 'password', 'role', 'is_active', 'account_status', 'must_change_password'];
        $vals = [$firstName, $lastName, $email, $hash, 'patient', 1, 'active', 0];
        if (in_array('is_email_verified', $userCols, true)) {
            $cols[] = 'is_email_verified';
            $cols[] = 'email_verified_at';
            $vals[] = 1;
            $vals[] = date('Y-m-d H:i:s');
        }
        if (in_array('terms_accepted_at', $userCols, true)) {
            $cols[] = 'terms_accepted_at';
            $vals[] = date('Y-m-d H:i:s');
        }
        if (in_array('privacy_accepted_at', $userCols, true)) {
            $cols[] = 'privacy_accepted_at';
            $vals[] = date('Y-m-d H:i:s');
        }
        $cols[] = 'created_at';
        $cols[] = 'updated_at';
        $placeholders = implode(',', array_fill(0, count($vals), '?')) . ', NOW(), NOW()';
        $pdo->prepare('INSERT INTO users (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')')
            ->execute($vals);
        $userId = (int) $pdo->lastInsertId();
        echo "OK  Created users.id={$userId}\n";
    }

    // Ensure terms/privacy accepted for portal access.
    if (in_array('terms_accepted_at', $userCols, true) || in_array('privacy_accepted_at', $userCols, true)) {
        $pdo->prepare("
            UPDATE users SET
                terms_accepted_at = COALESCE(terms_accepted_at, NOW()),
                privacy_accepted_at = COALESCE(privacy_accepted_at, NOW())
            WHERE id = ?
        ")->execute([$userId]);
    }

    $patientCode = 'MC-' . str_pad((string) $userId, 6, '0', STR_PAD_LEFT);
    $regFind = $pdo->prepare('SELECT id FROM patient_registrations WHERE user_id = ? OR LOWER(email) = LOWER(?) LIMIT 1');
    $regFind->execute([$userId, $email]);
    $regId = (int) ($regFind->fetchColumn() ?: 0);

    $address = $barangay . ', Bago City, Negros Occidental';
    $fullAddress = $address;

    if ($regId > 0) {
        $pdo->prepare("
            UPDATE patient_registrations SET
                user_id = ?,
                first_name = ?, middle_name = ?, last_name = ?, full_name = ?,
                email = ?, date_of_birth = ?, age = ?, gender = 'Female',
                civil_status = 'Single',
                barangay = ?, city_municipality = 'Bago City', province = 'Negros Occidental', region = 'Region VI',
                full_address = ?, address = ?,
                contact_number = ?, national_id = ?,
                status = 'verified', workflow_status = 'verified',
                verified_at = COALESCE(verified_at, NOW()),
                consent_given = 1, ocr_bago_confirmed = 1, ocr_result = 'verified',
                patient_code = COALESCE(NULLIF(patient_code, ''), ?),
                pending_chief_complaint = NULL,
                pending_nlp_json = NULL,
                registration_urgency = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $userId, $firstName, $middleName, $lastName, $fullName,
            $email, $dob, $age,
            $barangay, $fullAddress, $address,
            $contact, $nationalIdHash,
            $patientCode,
            $regId,
        ]);
        echo "OK  Updated patient_registrations.id={$regId}\n";
    } else {
        $pdo->prepare("
            INSERT INTO patient_registrations (
                user_id, first_name, middle_name, last_name, full_name, email,
                date_of_birth, age, gender, civil_status,
                barangay, city_municipality, province, region, full_address, address,
                contact_number, national_id, blood_type,
                status, workflow_status, verified_at,
                consent_given, consent_timestamp, consent_version,
                ocr_result, ocr_bago_confirmed, patient_code, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, 'Female', 'Single',
                ?, 'Bago City', 'Negros Occidental', 'Region VI', ?, ?,
                ?, ?, 'O+',
                'verified', 'verified', NOW(),
                1, NOW(), '1.0',
                'verified', 1, ?, NOW(), NOW()
            )
        ")->execute([
            $userId, $firstName, $middleName, $lastName, $fullName, $email,
            $dob, $age,
            $barangay, $fullAddress, $address,
            $contact, $nationalIdHash,
            $patientCode,
        ]);
        $regId = (int) $pdo->lastInsertId();
        echo "OK  Created patient_registrations.id={$regId}\n";
    }

    // Optional barangay_id link
    if (in_array('barangay_id', $prCols, true)) {
        try {
            $bid = (int) ($pdo->query("SELECT id FROM barangays WHERE name LIKE '%Poblacion%' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
            if ($bid > 0) {
                $pdo->prepare('UPDATE patient_registrations SET barangay_id = ? WHERE id = ?')->execute([$bid, $regId]);
                if (in_array('barangay_id', $userCols, true)) {
                    $pdo->prepare('UPDATE users SET barangay_id = ? WHERE id = ?')->execute([$bid, $userId]);
                }
            }
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$mc = 'MC-' . str_pad((string) $userId, 6, '0', STR_PAD_LEFT);
$base = defined('BASE_URL') ? BASE_URL : 'http://localhost/medconnect';

echo "\n========================================\n";
echo " LOCAL TEST PATIENT READY\n";
echo "========================================\n";
echo "  Name:     {$fullName}\n";
echo "  User ID:  {$userId}\n";
echo "  Code:     {$mc}\n";
echo "  Email:    {$email}\n";
echo "  Password: {$password}\n";
echo "  Login:    {$base}/views/auth/login.php\n";
echo "  Dashboard:{$base}/views/patient/dashboard.php\n";
echo "========================================\n";
echo "Use this account only on localhost for triage / Start New Consultation tests.\n";
