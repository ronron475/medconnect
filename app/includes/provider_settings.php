<?php
/**
 * Provider settings — schema, load/save helpers.
 */

require_once __DIR__ . '/provider_verification.php';
require_once __DIR__ . '/profile_picture.php';
require_once __DIR__ . '/system_preferences.php';
require_once __DIR__ . '/remember_me.php';
require_once __DIR__ . '/login_security.php';

function provider_settings_ensure_schema(PDO $pdo): void
{
    provider_verification_ensure_schema($pdo);
    profile_picture_ensure_schema($pdo);

    $cols = $pdo->query('SHOW COLUMNS FROM provider_profiles')->fetchAll(PDO::FETCH_COLUMN);
    $alters = [];
    if (!in_array('specialty', $cols, true)) {
        $alters[] = "ADD COLUMN specialty VARCHAR(120) NULL DEFAULT NULL AFTER prc_license_number";
    }
    if (!in_array('facility', $cols, true)) {
        $alters[] = "ADD COLUMN facility VARCHAR(200) NULL DEFAULT 'City Health Office' AFTER specialty";
    }
    if ($alters) {
        $pdo->exec('ALTER TABLE provider_profiles ' . implode(', ', $alters));
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_notification_preferences (
            user_id INT UNSIGNED NOT NULL,
            new_messages TINYINT(1) NOT NULL DEFAULT 1,
            consultation_requests TINYINT(1) NOT NULL DEFAULT 1,
            triage_alerts TINYINT(1) NOT NULL DEFAULT 1,
            system_notifications TINYINT(1) NOT NULL DEFAULT 1,
            email_notifications TINYINT(1) NOT NULL DEFAULT 1,
            sms_notifications TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_pnp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    system_preferences_ensure_schema($pdo);
}

function provider_settings_require_provider(): int
{
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    return (int) $_SESSION['user_id'];
}

function provider_settings_verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Refresh the page and try again.']);
        exit;
    }
}

function provider_settings_default_notifications(): array
{
    return [
        'new_messages' => 1,
        'consultation_requests' => 1,
        'triage_alerts' => 1,
        'system_notifications' => 1,
        'email_notifications' => 1,
        'sms_notifications' => 0,
    ];
}

function provider_settings_default_system(): array
{
    return system_preferences_normalize(null);
}

function provider_settings_ensure_defaults(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT user_id FROM provider_notification_preferences WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        $pdo->prepare('
            INSERT INTO provider_notification_preferences (user_id) VALUES (?)
        ')->execute([$userId]);
    }

    system_preferences_ensure_defaults($pdo, $userId);
}

/**
 * @return array<string, mixed>
 */
function provider_settings_load(PDO $pdo, int $userId): array
{
    provider_settings_ensure_schema($pdo);
    provider_settings_ensure_defaults($pdo, $userId);

    $stmt = $pdo->prepare('
        SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.profile_picture,
               pp.prc_license_number, pp.specialty, pp.facility, pp.verification_status,
               pp.middle_name, pp.birthdate
        FROM users u
        LEFT JOIN provider_profiles pp ON pp.user_id = u.id
        WHERE u.id = ? AND u.role = ?
        LIMIT 1
    ');
    $stmt->execute([$userId, 'provider']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return [];
    }

    $nstmt = $pdo->prepare('SELECT * FROM provider_notification_preferences WHERE user_id = ? LIMIT 1');
    $nstmt->execute([$userId]);
    $notifications = $nstmt->fetch(PDO::FETCH_ASSOC) ?: provider_settings_default_notifications();

    $system = system_preferences_get($pdo, $userId);

    $initials = profile_picture_initials($user['first_name'], $user['last_name']);
    $birthdate = (string) ($user['birthdate'] ?? '');
    if ($birthdate === '0000-00-00' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
        $birthdate = '';
    }

    return [
        'profile' => [
            'first_name' => (string) $user['first_name'],
            'middle_name' => (string) ($user['middle_name'] ?? ''),
            'last_name' => (string) $user['last_name'],
            'birthdate' => $birthdate,
            'email' => (string) $user['email'],
            'phone' => (string) ($user['phone'] ?? ''),
            'specialty' => (string) ($user['specialty'] ?? 'General Medicine'),
            'license_number' => (string) ($user['prc_license_number'] ?? ''),
            'facility' => (string) ($user['facility'] ?? 'City Health Office'),
            'verification_status' => (string) ($user['verification_status'] ?? 'pending'),
            'initials' => $initials,
            'picture_url' => profile_picture_public_url($user['profile_picture'] ?? null),
        ],
        'notifications' => [
            'new_messages' => (int) ($notifications['new_messages'] ?? 1),
            'consultation_requests' => (int) ($notifications['consultation_requests'] ?? 1),
            'triage_alerts' => (int) ($notifications['triage_alerts'] ?? 1),
            'system_notifications' => (int) ($notifications['system_notifications'] ?? 1),
            'email_notifications' => (int) ($notifications['email_notifications'] ?? 1),
            'sms_notifications' => (int) ($notifications['sms_notifications'] ?? 0),
        ],
        'system' => $system,
        'sessions' => provider_settings_list_sessions($pdo, $userId),
    ];
}

function provider_settings_sync_session(array $settings): void
{
    if (empty($settings['profile'])) {
        return;
    }
    $p = $settings['profile'];
    $_SESSION['first_name'] = $p['first_name'];
    $_SESSION['last_name'] = $p['last_name'];
    $_SESSION['user_name'] = trim($p['first_name'] . ' ' . $p['last_name']);
    $_SESSION['user_email'] = $p['email'];

    if (!empty($settings['system'])) {
        system_preferences_apply_to_session($settings['system']);
    }
}

function provider_settings_apply_system_to_session(array $system): void
{
    system_preferences_apply_to_session($system);
}

function provider_settings_validate_phone(string $phone): ?string
{
    require_once __DIR__ . '/patient_account_security.php';
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }
    $digits = patient_normalize_phone($phone);
    if ($digits === '') {
        return null;
    }
    return patient_phone_validation_error($phone);
}

function provider_settings_canonical_phone(string $phone): string
{
    require_once __DIR__ . '/patient_account_security.php';
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    $canonical = patient_canonical_ph_mobile($phone);
    return preg_match('/^09\d{9}$/', $canonical) ? $canonical : $phone;
}

function provider_settings_password_strength(string $password): ?string
{
    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one special character.';
    }
    return null;
}

/**
 * Provider identity is admin-managed. Settings cannot change profile fields.
 *
 * @return array{success:bool,message:string}
 */
function provider_settings_save_profile(PDO $pdo, int $userId, array $input): array
{
    unset($pdo, $userId, $input);

    return [
        'success' => false,
        'message' => 'Profile information is read-only. Ask an administrator if this information needs to be updated.',
    ];
}

/**
 * @return array{status:string,success:bool,message:string}
 */
function provider_settings_change_password(PDO $pdo, int $userId, string $current, string $newPassword, string $confirm): array
{
    require_once __DIR__ . '/provider_password.php';
    return provider_password_change($pdo, $userId, $current, $newPassword, $confirm);
}

/**
 * @return array{success:bool,message:string,data?:array}
 */
function provider_settings_save_notifications(PDO $pdo, int $userId, array $input): array
{
    provider_settings_ensure_schema($pdo);
    provider_settings_ensure_defaults($pdo, $userId);

    $flags = [
        'new_messages' => !empty($input['new_messages']) ? 1 : 0,
        'consultation_requests' => !empty($input['consultation_requests']) ? 1 : 0,
        'triage_alerts' => !empty($input['triage_alerts']) ? 1 : 0,
        'system_notifications' => !empty($input['system_notifications']) ? 1 : 0,
        'email_notifications' => !empty($input['email_notifications']) ? 1 : 0,
        'sms_notifications' => !empty($input['sms_notifications']) ? 1 : 0,
    ];

    $pdo->prepare('
        UPDATE provider_notification_preferences
        SET new_messages = ?, consultation_requests = ?, triage_alerts = ?,
            system_notifications = ?, email_notifications = ?, sms_notifications = ?
        WHERE user_id = ?
    ')->execute([
        $flags['new_messages'],
        $flags['consultation_requests'],
        $flags['triage_alerts'],
        $flags['system_notifications'],
        $flags['email_notifications'],
        $flags['sms_notifications'],
        $userId,
    ]);

    return [
        'success' => true,
        'message' => 'Notification preferences saved.',
        'data' => ['notifications' => $flags],
    ];
}

/**
 * @return array{success:bool,message:string,data?:array}
 */
function provider_settings_save_system(PDO $pdo, int $userId, array $input): array
{
    if (isset($input['theme']) && !isset($input['theme_preference'])) {
        $input['theme_preference'] = $input['theme'];
    }
    if (isset($input['auto_logout_minutes']) && !isset($input['auto_logout_duration'])) {
        $input['auto_logout_duration'] = $input['auto_logout_minutes'];
    }
    return system_preferences_save($pdo, $userId, $input);
}

/**
 * @return list<array<string, mixed>>
 */
function provider_settings_list_sessions(PDO $pdo, int $userId): array
{
    $currentSid = session_id();
    $rows = [];
    try {
        remember_me_ensure_schema($pdo);
        $stmt = $pdo->prepare("
            SELECT id, session_id, ip_address, user_agent, browser, device, last_activity, created_at
            FROM active_sessions
            WHERE user_id = ? AND role = 'provider'
              AND last_activity >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY last_activity DESC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    return array_map(static function (array $row) use ($currentSid): array {
        return [
            'id' => (int) $row['id'],
            'is_current' => ($row['session_id'] ?? '') === $currentSid,
            'browser' => (string) ($row['browser'] ?? 'Unknown'),
            'device' => (string) ($row['device'] ?? 'desktop'),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'last_activity_label' => !empty($row['last_activity'])
                ? date('M j, Y g:i A', strtotime((string) $row['last_activity']))
                : '—',
        ];
    }, $rows);
}

/**
 * @return array{success: bool, message: string}
 */
function provider_settings_logout_all_devices(PDO $pdo, int $userId): array
{
    try {
        $pdo->prepare("DELETE FROM active_sessions WHERE user_id = ? AND role = 'provider'")->execute([$userId]);
    } catch (PDOException $e) { /* non-fatal */ }

    try {
        remember_me_revoke_for_user($pdo, $userId);
    } catch (PDOException $e) { /* non-fatal */ }

    remember_me_clear_cookie();
    unset($_SESSION['remember_me_extended']);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => 'logout_all_devices',
        'description' => 'Provider signed out of all devices from Settings.',
        'meta'        => ['role' => 'provider'],
    ]);

    return ['success' => true, 'message' => 'All other devices have been signed out.'];
}
