<?php
/**
 * API: Login handler
 * Moved from root/login.php
 * URL: /app/api/login.php
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

set_exception_handler(function ($e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
});

require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/provider_verification.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/profile_picture.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/provider_settings.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/patient_account_security.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/remember_me.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/recaptcha.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/login_security.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/security_throttle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email    = trim($_POST['email']    ?? '');
$password = $_POST['password']      ?? '';
$recaptchaToken = (string) ($_POST['recaptcha_token'] ?? ($_POST['g-recaptcha-response'] ?? ''));

if (empty($email) || empty($password)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// IP-based throttle (blocks bots/stuffing even for unknown emails)
$ip = login_security_ip();

try {
    require_once dirname(dirname(__DIR__)) . '/app/includes/superadmin/security.php';
    require_once dirname(dirname(__DIR__)) . '/app/includes/superadmin/schema.php';
    if ($ip !== '' && superadmin_is_ip_blocked($pdo, $ip)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Access from your network has been blocked. Contact support.', 'code' => 'ip_blocked']);
        exit;
    }
} catch (Throwable $e) { /* non-fatal */ }

try {
    $ipKey = security_throttle_key('login_ip', $ip ?: 'unknown');
    $ipState = security_throttle_check($pdo, $ipKey);
    if (!empty($ipState['locked'])) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Too many failed login attempts. Please try again after 15 minutes.',
            'code' => 'locked',
            'locked_until' => (string) ($ipState['locked_until'] ?? ''),
        ]);
        exit;
    }
} catch (Throwable $e) { /* non-fatal */ }

try {
    patient_security_ensure_schema($pdo);
    $user_cols = patient_security_user_columns($pdo);
    $has_barangay = in_array('barangay_id', $user_cols, true);
    $has_profile_picture = in_array('profile_picture', $user_cols, true);
    $has_must_change = in_array('must_change_password', $user_cols, true);
    $has_lockout = in_array('lockout_until', $user_cols, true);
    $select = 'id, first_name, last_name, email, password, role, is_active';
    if ($has_barangay) {
        $select .= ', barangay_id';
    }
    if ($has_profile_picture) {
        $select .= ', profile_picture';
    }
    if ($has_must_change) {
        $select .= ', must_change_password';
    }
    if ($has_lockout) {
        $select .= ', lockout_until, failed_attempts';
    }
    $stmt = $pdo->prepare("SELECT $select FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}

// Account lockout (best-effort; only if schema supports it)
if ($user && !empty($user['lockout_until'])) {
    $lockoutUntil = strtotime((string) $user['lockout_until']) ?: 0;
    if ($lockoutUntil > time()) {
        ob_clean();
        $mins = (int) ceil(($lockoutUntil - time()) / 60);
        $mins = max(1, $mins);
        echo json_encode([
            'success' => false,
            'message' => 'Too many failed login attempts. Please try again after 15 minutes.',
            'code' => 'locked',
            'locked_until' => (string) $user['lockout_until'],
            'retry_after_minutes' => $mins,
        ]);
        exit;
    }
}

// Google reCAPTCHA after repeated failures (server-verified)
if ($user && isset($user['failed_attempts']) && (int) $user['failed_attempts'] >= 3) {
    $captchaConfigured = recaptcha_is_configured();
    if ($captchaConfigured) {
        $ip = login_security_ip();
        $verify = recaptcha_verify_token($recaptchaToken, 'login', $ip);
        if (empty($verify['ok'])) {
            try {
                require_once BASE_PATH . '/app/includes/audit_log.php';
                audit_log($pdo, [
                    'patient_id'  => (int) $user['id'],
                    'action_type' => 'captcha_failed',
                    'description' => 'reCAPTCHA verification failed during login.',
                    'meta'        => [
                        'version' => $verify['version'] ?? null,
                        'errors'  => $verify['error_codes'] ?? [],
                    ],
                ]);
            } catch (Throwable $e) { /* non-fatal */ }

            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Please verify that you are not a robot.',
                'code' => 'captcha_failed',
                'captcha_required' => true,
                'captcha' => [
                    'required' => true,
                    'version' => recaptcha_version(),
                    'site_key' => recaptcha_client_key(),
                ],
            ]);
            exit;
        }
    } else {
        // If reCAPTCHA isn't configured, preserve existing behavior by allowing login (no bypass in production once keys are set).
        // Intentionally no client-trust: server can't verify without keys.
    }
}

if (!$user || !password_verify($password, $user['password'])) {
    try {
        // Throttle by IP (e.g., 10 failures/5min => 15min lock)
        if (!empty($ip)) {
            security_throttle_fail($pdo, security_throttle_key('login_ip', $ip), 'login_ip', 300, 10, 15);
        }
        if ($user && in_array('failed_attempts', patient_security_user_columns($pdo), true)) {
            $pdo->prepare('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?')->execute([(int) $user['id']]);
            // Lockout after 5 consecutive failures (15 minutes)
            if (in_array('lockout_until', patient_security_user_columns($pdo), true)) {
                $pdo->prepare("
                    UPDATE users
                    SET lockout_until = IF(failed_attempts >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), lockout_until)
                    WHERE id = ?
                ")->execute([(int) $user['id']]);

                // If lockout likely triggered, notify admins (best-effort).
                $s = $pdo->prepare('SELECT failed_attempts, lockout_until FROM users WHERE id = ? LIMIT 1');
                $s->execute([(int) $user['id']]);
                $r = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($r && (int) ($r['failed_attempts'] ?? 0) >= 5 && !empty($r['lockout_until'])) {
                    require_once dirname(dirname(__DIR__)) . '/app/includes/notification_events.php';
                    NotificationManager::notifyAdmins($pdo, [
                        'type'       => NotificationManager::TYPE_SECURITY,
                        'title'      => 'Account Lockout Triggered',
                        'message'    => "Account lockout triggered for {$email}.",
                        'priority'   => 'critical',
                        'action_url' => '/views/admin/audit_logs.php',
                        'email'      => false,
                    ]);
                    audit_log($pdo, [
                        'patient_id'  => (int) $user['id'],
                        'action_type' => 'account_lockout',
                        'description' => 'Account temporarily locked due to failed login attempts.',
                        'meta'        => ['email' => $email, 'locked_until' => (string) ($r['lockout_until'] ?? '')],
                    ]);
                }
            }
        }
        require_once BASE_PATH . '/app/includes/audit_log.php';
        if ($user) {
            audit_log($pdo, [
                'patient_id'  => (int) $user['id'],
                'action_type' => AuditAction::LOGIN_FAILED,
                'description' => 'Failed login attempt.',
                'meta'        => ['email' => $email],
            ]);
            require_once dirname(dirname(__DIR__)) . '/app/includes/notification_events.php';
            NotificationEvents::loginFailed($pdo, (int) $user['id'], $email, (string) ($user['role'] ?? 'patient'));
            try {
                require_once dirname(dirname(__DIR__)) . '/app/includes/superadmin/security.php';
                superadmin_record_failed_login($pdo, $email, (int) $user['id'], 'invalid_password');
            } catch (Throwable $e) { /* non-fatal */ }
        } else {
            try {
                require_once dirname(dirname(__DIR__)) . '/app/includes/superadmin/security.php';
                superadmin_record_failed_login($pdo, $email, null, 'invalid_credentials');
            } catch (Throwable $e) { /* non-fatal */ }
        }
    } catch (Exception $e) { /* non-fatal */ }
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

if (!$user['is_active']) {
    ob_clean();
    $inactive_msg = ($user['role'] === 'provider')
        ? 'Your doctor account is not active yet. Please wait for admin PRC verification.'
        : 'Your account is inactive.';
    echo json_encode(['success' => false, 'message' => $inactive_msg]);
    exit;
}

if ($user['role'] === 'provider') {
    try {
        provider_verification_ensure_schema($pdo);
        $p_stmt = $pdo->prepare('SELECT verification_status FROM provider_profiles WHERE user_id = ? LIMIT 1');
        $p_stmt->execute([(int) $user['id']]);
        $verification = $p_stmt->fetchColumn();
        if ($verification && $verification !== 'verified') {
            ob_clean();
            $msg = $verification === 'rejected'
                ? 'Your doctor account was rejected. Contact the administrator for assistance.'
                : 'Your PRC license is pending admin verification. You cannot sign in yet.';
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
    } catch (PDOException $e) { /* non-fatal */ }
}

// Prevent session fixation after authentication
session_regenerate_id(true);
$_SESSION['last_activity'] = time();
$_SESSION['login_time'] = time();

$_SESSION['user_id']    = $user['id'];
$_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role']  = $user['role'];
$_SESSION['first_name'] = $user['first_name'];
$_SESSION['last_name']  = $user['last_name'];
$_SESSION['profile_picture'] = !empty($user['profile_picture']) ? (string) $user['profile_picture'] : null;

try {
    require_once dirname(dirname(__DIR__)) . '/app/includes/theme_preferences.php';
    theme_preferences_sync_session($pdo, (int) $user['id'], (string) $user['role']);
    if ($user['role'] === 'provider') {
        require_once dirname(dirname(__DIR__)) . '/app/includes/system_preferences.php';
        $provider_prefs = system_preferences_get($pdo, (int) $user['id']);
        system_preferences_apply_to_session($provider_prefs);
    }
} catch (Exception $e) { /* non-fatal */ }

unset($_SESSION['user_barangay_id'], $_SESSION['user_barangay_name']);
if ($user['role'] === 'bhw' && !empty($user['barangay_id'])) {
    $_SESSION['user_barangay_id'] = (int) $user['barangay_id'];
    try {
        $b_stmt = $pdo->prepare('SELECT name FROM barangays WHERE id = ? LIMIT 1');
        $b_stmt->execute([(int) $user['barangay_id']]);
        $b_name = $b_stmt->fetchColumn();
        if ($b_name) {
            $_SESSION['user_barangay_name'] = $b_name;
        }
    } catch (PDOException $e) { /* non-fatal */ }
}

patient_record_login($pdo, (int) $user['id']);
if ($user['role'] === 'patient') {
    patient_record_first_login($pdo, (int) $user['id']);
}

// Login notifications & device tracking
try {
    login_security_record_success($pdo, (int) $user['id'], (string) $user['role']);
    require_once dirname(dirname(__DIR__)) . '/app/includes/superadmin/security.php';
    superadmin_record_active_session($pdo, (int) $user['id'], (string) $user['role']);
    if ($user['role'] === 'superadmin') {
        require_once dirname(dirname(__DIR__)) . '/app/includes/superadmin/schema.php';
        superadmin_link_user($pdo, (int) $user['id'], null);
        superadmin_security_log($pdo, 'login_success', 'auth', 'success', 'Super Admin logged in', (int) $user['id'], 'superadmin');
    }
} catch (Throwable $e) { /* non-fatal */ }

// Reset IP throttle on success
try {
    if (!empty($ip)) {
        security_throttle_reset($pdo, security_throttle_key('login_ip', $ip));
    }
} catch (Throwable $e) { /* non-fatal */ }

// Optional remember-me token issuance
$remember = (string) ($_POST['remember_me'] ?? '');
if ($remember === '1' || strtolower($remember) === 'true' || strtolower($remember) === 'on') {
    try {
        remember_me_issue_token($pdo, (int) $user['id']);
        $_SESSION['remember_me_extended'] = true;
    } catch (Throwable $e) { /* non-fatal */ }
} else {
    try {
        remember_me_revoke_current_cookie($pdo);
    } catch (Throwable $e) { /* non-fatal */ }
    unset($_SESSION['remember_me_extended']);
}

// ── Audit Log ───────────────────────────────────────────────
try {
    require_once BASE_PATH . '/app/includes/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $user['id'],
        'action_type' => AuditAction::LOGIN_SUCCESS,
        'description' => 'User logged in successfully.',
    ]);
    if ($user['role'] === 'bhw') {
        require_once dirname(dirname(__DIR__)) . '/app/includes/bhw_activity.php';
        bhw_activity_log($pdo, 'bhw_login', 'BHW logged in to the portal.');
    }
    require_once dirname(dirname(__DIR__)) . '/app/includes/notification_events.php';
    NotificationEvents::loginSuccess($pdo, (int) $user['id'], (string) $user['role']);
} catch (Exception $e) { /* non-fatal */ }

$requiresSetup = $user['role'] === 'patient' && patient_requires_account_setup($pdo, (int) $user['id']);

$redirect = match ($user['role']) {
    'patient'  => $requiresSetup
        ? ASSET_BASE . '/views/patient/account_setup.php'
        : ASSET_BASE . '/views/patient/dashboard.php',
    'provider' => ASSET_BASE . '/views/provider/dashboard.php',
    'admin'    => ASSET_BASE . '/views/admin/dashboard.php',
    'superadmin' => ASSET_BASE . '/views/superadmin/dashboard.php',
    'bhw'      => ASSET_BASE . '/views/bhw/dashboard.php',
    default    => ASSET_BASE . '/index.php',
};

ob_clean();
echo json_encode([
    'success' => true,
    'redirect' => $redirect,
    'requires_account_setup' => $requiresSetup,
]);
