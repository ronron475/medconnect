<?php
/**
 * API: Patient registration handler
 * Moved from root/register.php
 * URL: /app/api/register.php
 */
session_start();
header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/recaptcha.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/login_security.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/security_throttle.php';

$protocol   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$appRootUrl = $protocol . '://' . $host;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// reCAPTCHA (protect against automated registrations)
$recaptchaToken = (string) ($_POST['recaptcha_token'] ?? ($_POST['g-recaptcha-response'] ?? ''));
if (recaptcha_is_configured()) {
    $ip = login_security_ip();
    $verify = recaptcha_verify_token($recaptchaToken, 'register', $ip);
    if (empty($verify['ok'])) {
        echo json_encode(['success' => false, 'message' => 'Please verify that you are not a robot.']);
        exit;
    }
}

// IP throttle for registration submissions (best-effort; avoids spam account creation)
try {
    $ip = login_security_ip();
    if ($ip) {
        $key = security_throttle_key('register_ip', $ip);
        $state = security_throttle_check($pdo, $key);
        if (!empty($state['locked'])) {
            echo json_encode(['success' => false, 'message' => 'Too many registration attempts. Please try again later.']);
            exit;
        }
        security_throttle_fail($pdo, $key, 'register_ip', 600, 8, 15);
    }
} catch (Throwable $e) { /* non-fatal */ }

// ── Helpers ─────────────────────────────────────────────────
$ip         = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

function logActivity(PDO $pdo, ?int $reg_id, string $action, string $result, string $detail, string $id_hash, string $ip, string $ua): void
{
    try {
        $s = $pdo->prepare("INSERT INTO registration_activity_logs
            (registration_id, action, result, detail, national_id_hash, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $s->execute([$reg_id, $action, $result, $detail, $id_hash, $ip, $ua]);
    } catch (Exception $e) { /* non-fatal */ }
}

// ── OTP gate ────────────────────────────────────────────────
if (empty($_SESSION['otp_verified']) || empty($_SESSION['otp_verified_email'])) {
    echo json_encode(['success' => false, 'message' => 'Email verification required. Please verify your email with OTP first.']);
    exit;
}
$email = $_SESSION['otp_verified_email'];

// ── Collect inputs ──────────────────────────────────────────
$first_name           = trim($_POST['first_name']              ?? '');
$middle_name          = trim($_POST['middle_name']             ?? '');
$last_name            = trim($_POST['last_name']               ?? '');
$dob                  = trim($_POST['date_of_birth']           ?? '');
$age                  = intval($_POST['age']                   ?? 0);
$gender               = trim($_POST['gender']                  ?? '');
$reg_password         = trim($_POST['reg_password']            ?? '');
$reg_confirm_password = trim($_POST['reg_confirm_password']    ?? '');
$civil_status         = trim($_POST['civil_status']            ?? '');
$region               = trim($_POST['region']                  ?? '');
$province             = trim($_POST['province']                ?? '');
$city_municipality    = trim($_POST['city_municipality']       ?? '');
$barangay             = trim($_POST['barangay']                ?? '');
$street_address       = trim($_POST['street_address']          ?? '');
$employment_status    = trim($_POST['employment_status']       ?? '');
$monthly_income       = trim($_POST['monthly_income_bracket']  ?? '');
$contact_number       = trim($_POST['contact_number']          ?? '');
$philhealth_status    = trim($_POST['philhealth_status']       ?? '');
$national_id_raw      = trim($_POST['national_id']             ?? '');
$blood_type           = trim($_POST['blood_type']              ?? '');
$existing_conditions  = trim($_POST['existing_conditions']     ?? '');
$allergies            = trim($_POST['allergies']               ?? '');
$current_medications  = trim($_POST['current_medications']     ?? '');
$consent_given        = isset($_POST['consent_given']) && $_POST['consent_given'] === '1' ? 1 : 0;

$full_name        = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
$address_parts    = array_filter([$street_address, $barangay, $city_municipality, $province, $region]);
$full_address     = implode(', ', $address_parts);
$national_id_hash = hash('sha256', preg_replace('/[\s\-]/', '', $national_id_raw));

// ── Validation ──────────────────────────────────────────────
$errors = [];

if (empty($first_name))  $errors[] = 'First name is required.';
if (empty($last_name))   $errors[] = 'Last name is required.';

if (empty($dob)) {
    $errors[] = 'Date of birth is required.';
} else {
    $dobDate = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$dobDate || $dobDate >= new DateTime('today')) {
        $errors[] = 'Invalid date of birth.';
    } else {
        $calcAge = (new DateTime())->diff($dobDate)->y;
        if ($calcAge > 120) $errors[] = 'Please enter a valid date of birth.';
    }
}

if (empty($gender))       $errors[] = 'Gender is required.';
elseif (!in_array(strtolower($gender), ['male', 'female'], true)) {
    $errors[] = 'Gender must be Male or Female.';
}
if (empty($civil_status)) $errors[] = 'Civil status is required.';

if (empty($email)) {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid email address.';
}

if (empty($reg_password)) {
    $errors[] = 'Password is required.';
} else {
    if (strlen($reg_password) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $reg_password))
        $errors[] = 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $reg_password))
        $errors[] = 'Password must contain at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $reg_password))
        $errors[] = 'Password must contain at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $reg_password))
        $errors[] = 'Password must contain at least one special character.';

    // Block common weak passwords
    $weak = ['password','12345678','qwerty123','admin123','iloveyou','welcome1','letmein1','abc12345'];
    if (in_array(strtolower($reg_password), $weak))
        $errors[] = 'This password is too common. Please choose a stronger one.';

    // Block personal info in password
    $pwLower = strtolower($reg_password);
    if (strlen($first_name) > 2 && stripos($pwLower, strtolower($first_name)) !== false)
        $errors[] = 'Password must not contain your first name.';
    if (strlen($last_name) > 2 && stripos($pwLower, strtolower($last_name)) !== false)
        $errors[] = 'Password must not contain your last name.';
    $emailLocal = strtolower(explode('@', $email)[0]);
    if (strlen($emailLocal) > 2 && stripos($pwLower, $emailLocal) !== false)
        $errors[] = 'Password must not contain your email address.';
}

if ($reg_password !== $reg_confirm_password) $errors[] = 'Passwords do not match.';

if (empty($barangay))          $errors[] = 'Barangay is required.';

if (empty($contact_number)) {
    $errors[] = 'Contact number is required.';
} elseif (!preg_match('/^(09|\+639)\d{9}$/', preg_replace('/\s+/', '', $contact_number))) {
    $errors[] = 'Enter a valid PH mobile number.';
}

if (empty($blood_type))        $errors[] = 'Blood type is required.';

if (empty($national_id_raw)) {
    $errors[] = 'National ID number is required.';
} elseif (!preg_match('/^[\d\-]{12,20}$/', preg_replace('/\s+/', '', $national_id_raw))) {
    $errors[] = 'Enter a valid National ID number.';
}

if (!$consent_given) $errors[] = 'You must agree to the data privacy consent to proceed.';

if (!empty($city_municipality) && stripos($city_municipality, 'bago') === false) {
    $errors[] = 'Only residents of Bago City may register.';
}

if (!empty($errors)) {
    logActivity($pdo, null, 'submit_attempt', 'failure', implode(' ', $errors), $national_id_hash, $ip, $user_agent);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── OCR verification gate ───────────────────────────────────
$ocr_verified_id = preg_replace('/[\s\-]/', '', $_SESSION['ocr_national_id'] ?? '');
$submitted_id    = preg_replace('/[\s\-]/', '', $national_id_raw);

if (empty($_SESSION['ocr_verified']) || $ocr_verified_id !== $submitted_id) {
    logActivity($pdo, null, 'submit_attempt', 'blocked', 'OCR not verified or ID mismatch.', $national_id_hash, $ip, $user_agent);
    echo json_encode(['success' => false, 'message' => 'Identity verification is required. Please verify your National ID before submitting.']);
    exit;
}

if (empty($_SESSION['ocr_bago_city'])) {
    logActivity($pdo, null, 'submit_attempt', 'blocked', 'Bago City residency not confirmed by OCR.', $national_id_hash, $ip, $user_agent);
    echo json_encode(['success' => false, 'message' => 'Only verified Bago City residents may create an account.']);
    exit;
}

// ── Uniqueness checks ───────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM patient_registrations WHERE national_id = ? LIMIT 1");
$stmt->execute([$national_id_hash]);
if ($stmt->fetch()) {
    logActivity($pdo, null, 'submit_attempt', 'blocked', 'Duplicate National ID.', $national_id_hash, $ip, $user_agent);
    echo json_encode(['success' => false, 'message' => 'An account with this National ID already exists.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM patient_registrations WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    logActivity($pdo, null, 'submit_attempt', 'blocked', 'Duplicate email.', $national_id_hash, $ip, $user_agent);
    echo json_encode(['success' => false, 'message' => 'An account with this email address already exists.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM patient_registrations WHERE contact_number = ? LIMIT 1");
$stmt->execute([$contact_number]);
if ($stmt->fetch()) {
    logActivity($pdo, null, 'submit_attempt', 'blocked', 'Duplicate contact number.', $national_id_hash, $ip, $user_agent);
    echo json_encode(['success' => false, 'message' => 'An account with this contact number already exists.']);
    exit;
}

// ── ID document path from session ──────────────────────────
$id_document_path = $_SESSION['ocr_id_document_path'] ?? null;
$ocr_result       = $_SESSION['ocr_final_state']       ?? 'verified';

// ── Insert ──────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO patient_registrations
            (first_name, middle_name, last_name, full_name,
             date_of_birth, age, gender, email, civil_status,
             barangay, city_municipality, province, region, full_address,
             employment_status, monthly_income_bracket,
             contact_number, philhealth_status,
             national_id, blood_type, existing_conditions, allergies, current_medications,
             ocr_result, ocr_bago_confirmed, id_document_path,
             consent_given, consent_timestamp, consent_version,
             status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), '1.0', 'ocr_verified', NOW())
    ");
    $stmt->execute([
        $first_name, $middle_name, $last_name, $full_name,
        $dob, $age, $gender, $email, $civil_status,
        $barangay, $city_municipality, $province, $region, $full_address,
        '',   // employment_status — removed from form, default empty
        '',   // monthly_income_bracket — removed from form, default empty
        $contact_number,
        '',   // philhealth_status — removed from form, default empty
        $national_id_hash, $blood_type,
        $existing_conditions ?: null,
        $allergies           ?: null,
        $current_medications ?: null,
        $ocr_result, $id_document_path,
        $consent_given,
    ]);

    $registration_id = (int) $pdo->lastInsertId();

    $password_hash = password_hash($reg_password, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, password, role, is_active, is_email_verified, created_at)
        VALUES (?, ?, ?, ?, 'patient', 1, 1, NOW())
    ")->execute([$first_name, $last_name, $email, $password_hash]);

    $patient_user_id = (int) $pdo->lastInsertId();

    require_once dirname(dirname(__DIR__)) . '/app/core/BagoBarangayCentroids.php';
    require_once dirname(dirname(__DIR__)) . '/app/core/GisDashboardService.php';
    $gis = new GisDashboardService($pdo);
    $gis->savePatientLocation(
        $patient_user_id,
        $province,
        $city_municipality,
        $barangay,
        $street_address ?: $full_address,
        null,
        null,
        'barangay_centroid'
    );

    $pdo->commit();

    logActivity($pdo, $registration_id, 'registration_submitted', 'success',
        'Patient registered and account activated.', $national_id_hash, $ip, $user_agent);

    require_once dirname(dirname(__DIR__)) . '/app/includes/notification_events.php';
    NotificationEvents::patientRegistered($pdo, $patient_user_id, trim($first_name . ' ' . $last_name));

    unset(
        $_SESSION['ocr_verified'], $_SESSION['ocr_national_id'],
        $_SESSION['ocr_final_state'], $_SESSION['ocr_bago_city'],
        $_SESSION['ocr_id_document_path'],
        $_SESSION['otp_verified'], $_SESSION['otp_verified_email'],
        $_SESSION['otp_email'], $_SESSION['otp_attempts'], $_SESSION['otp_last_sent']
    );

    echo json_encode([
        'success'  => true,
        'message'  => 'Account created successfully! You can now sign in.',
        'redirect' => BASE_URL . '/index.php',
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    logActivity($pdo, null, 'registration_submitted', 'failure', 'DB error: ' . $e->getMessage(), $national_id_hash, $ip, $user_agent);
    echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
}
