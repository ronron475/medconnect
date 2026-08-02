<?php
/**
 * Patient settings — schema, preferences, sessions, password helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/patient_account_security.php';
require_once __DIR__ . '/password_history.php';
require_once __DIR__ . '/remember_me.php';
require_once __DIR__ . '/superadmin/schema.php';
require_once __DIR__ . '/superadmin/security.php';
require_once __DIR__ . '/login_security.php';

function patient_settings_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    patient_security_ensure_schema($pdo);
    password_history_ensure_schema($pdo);
    remember_me_ensure_schema($pdo);
    superadmin_ensure_schema($pdo);
    login_security_ensure_schema($pdo);

    $prCols = patient_security_pr_columns($pdo);
    $prAdds = [
        'medical_profile_updated_at' => 'DATETIME NULL DEFAULT NULL',
        'medical_profile_updated_by' => 'INT UNSIGNED NULL DEFAULT NULL',
    ];
    foreach ($prAdds as $col => $def) {
        if (!in_array($col, $prCols, true)) {
            try {
                $pdo->exec("ALTER TABLE patient_registrations ADD COLUMN {$col} {$def}");
            } catch (PDOException $e) { /* column may exist */ }
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS patient_medical_update_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id INT UNSIGNED NOT NULL,
            status ENUM('pending', 'in_review', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
            patient_note TEXT NULL,
            proposed_blood_type VARCHAR(20) NULL,
            proposed_allergies TEXT NULL,
            proposed_conditions TEXT NULL,
            proposed_medications TEXT NULL,
            provider_id INT UNSIGNED NULL DEFAULT NULL,
            provider_note TEXT NULL,
            reviewed_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_patient_status (patient_id, status),
            KEY idx_provider (provider_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pmurCols = [];
    try {
        $colStmt = $pdo->query('SHOW COLUMNS FROM patient_medical_update_requests');
        if ($colStmt) {
            while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $pmurCols[(string) $c['Field']] = true;
            }
        }
    } catch (PDOException $e) { /* table may not exist yet */ }

    $pmurAdds = [
        'proposed_blood_type'  => 'VARCHAR(20) NULL',
        'proposed_allergies'   => 'TEXT NULL',
        'proposed_conditions'  => 'TEXT NULL',
        'proposed_medications' => 'TEXT NULL',
    ];
    foreach ($pmurAdds as $col => $def) {
        if (isset($pmurCols[$col])) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE patient_medical_update_requests ADD COLUMN `{$col}` {$def}");
        } catch (PDOException $e) { /* ignore */ }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS patient_notification_preferences (
            user_id INT UNSIGNED NOT NULL,
            appointment_reminders TINYINT(1) NOT NULL DEFAULT 1,
            consultation_updates TINYINT(1) NOT NULL DEFAULT 1,
            followup_reminders TINYINT(1) NOT NULL DEFAULT 1,
            prescription_notifications TINYINT(1) NOT NULL DEFAULT 1,
            system_announcements TINYINT(1) NOT NULL DEFAULT 1,
            in_app_notifications TINYINT(1) NOT NULL DEFAULT 1,
            email_notifications TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS patient_privacy_preferences (
            user_id INT UNSIGNED NOT NULL,
            share_medical_records TINYINT(1) NOT NULL DEFAULT 1,
            emergency_access_consent TINYINT(1) NOT NULL DEFAULT 1,
            data_privacy_acknowledged TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

function patient_settings_require_patient(): int
{
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'patient') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    return (int) $_SESSION['user_id'];
}

function patient_settings_require_patient_ready(PDO $pdo): int
{
    $userId = patient_settings_require_patient();
    if (patient_requires_account_setup($pdo, $userId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Please complete account setup before continuing.',
            'code' => 'account_setup_required',
            'redirect' => (defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/account_setup.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $userId;
}

function patient_settings_verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Refresh the page and try again.']);
        exit;
    }
}

/** @return array<string, int> */
function patient_settings_default_notifications(): array
{
    return [
        'appointment_reminders'      => 1,
        'consultation_updates'       => 1,
        'followup_reminders'         => 1,
        'prescription_notifications' => 1,
        'system_announcements'       => 1,
        'in_app_notifications'       => 1,
        'email_notifications'        => 1,
    ];
}

/** @return array<string, int> */
function patient_settings_default_privacy(): array
{
    return [
        'share_medical_records'     => 1,
        'emergency_access_consent'  => 1,
        'data_privacy_acknowledged' => 1,
    ];
}

function patient_settings_ensure_defaults(PDO $pdo, int $userId): void
{
    patient_settings_ensure_schema($pdo);

    $n = $pdo->prepare('SELECT user_id FROM patient_notification_preferences WHERE user_id = ? LIMIT 1');
    $n->execute([$userId]);
    if (!$n->fetch()) {
        $pdo->prepare('INSERT INTO patient_notification_preferences (user_id) VALUES (?)')->execute([$userId]);
    }

    $p = $pdo->prepare('SELECT user_id FROM patient_privacy_preferences WHERE user_id = ? LIMIT 1');
    $p->execute([$userId]);
    if (!$p->fetch()) {
        $pdo->prepare('INSERT INTO patient_privacy_preferences (user_id) VALUES (?)')->execute([$userId]);
    }
}

/**
 * @return array<string, mixed>
 */
function patient_settings_load(PDO $pdo, int $userId): array
{
    patient_settings_ensure_defaults($pdo, $userId);

    $cols = patient_security_user_columns($pdo);
    $select = 'id, email, last_login, password_changed_at';
    if (in_array('first_login', $cols, true)) {
        $select .= ', first_login';
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $nstmt = $pdo->prepare('SELECT * FROM patient_notification_preferences WHERE user_id = ? LIMIT 1');
    $nstmt->execute([$userId]);
    $notifications = $nstmt->fetch(PDO::FETCH_ASSOC) ?: patient_settings_default_notifications();

    $pstmt = $pdo->prepare('SELECT * FROM patient_privacy_preferences WHERE user_id = ? LIMIT 1');
    $pstmt->execute([$userId]);
    $privacy = $pstmt->fetch(PDO::FETCH_ASSOC) ?: patient_settings_default_privacy();

    return [
        'security' => [
            'email' => (string) ($user['email'] ?? ''),
            'last_login' => $user['last_login'] ?? null,
            'last_login_label' => !empty($user['last_login'])
                ? date('M j, Y \a\t g:i A', strtotime((string) $user['last_login']))
                : 'Not recorded',
            'password_changed_at' => $user['password_changed_at'] ?? null,
            'password_changed_label' => !empty($user['password_changed_at'])
                ? date('M j, Y \a\t g:i A', strtotime((string) $user['password_changed_at']))
                : 'Not changed since registration',
        ],
        'notifications' => [
            'appointment_reminders'      => (int) ($notifications['appointment_reminders'] ?? 1),
            'consultation_updates'       => (int) ($notifications['consultation_updates'] ?? 1),
            'followup_reminders'         => (int) ($notifications['followup_reminders'] ?? 1),
            'prescription_notifications' => (int) ($notifications['prescription_notifications'] ?? 1),
            'system_announcements'       => (int) ($notifications['system_announcements'] ?? 1),
            'in_app_notifications'       => (int) ($notifications['in_app_notifications'] ?? 1),
            'email_notifications'        => (int) ($notifications['email_notifications'] ?? 1),
        ],
        'privacy' => [
            'share_medical_records'     => (int) ($privacy['share_medical_records'] ?? 1),
            'emergency_access_consent'  => (int) ($privacy['emergency_access_consent'] ?? 1),
            'data_privacy_acknowledged' => (int) ($privacy['data_privacy_acknowledged'] ?? 1),
        ],
        'sessions' => patient_settings_list_sessions($pdo, $userId),
        'devices' => patient_settings_list_devices($pdo, $userId),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function patient_settings_list_sessions(PDO $pdo, int $userId): array
{
    $currentSid = session_id();
    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT id, session_id, ip_address, user_agent, browser, device, last_activity, created_at
            FROM active_sessions
            WHERE user_id = ? AND role = 'patient'
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
            'last_activity' => $row['last_activity'] ?? null,
            'last_activity_label' => !empty($row['last_activity'])
                ? date('M j, Y g:i A', strtotime((string) $row['last_activity']))
                : '—',
            'created_at_label' => !empty($row['created_at'])
                ? date('M j, Y g:i A', strtotime((string) $row['created_at']))
                : '—',
        ];
    }, $rows);
}

/**
 * @return list<array<string, mixed>>
 */
function patient_settings_list_devices(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT device_fingerprint, last_seen_at, last_ip, last_user_agent
            FROM user_devices
            WHERE user_id = ? AND role = 'patient'
            ORDER BY last_seen_at DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    return array_map(static function (array $row): array {
        $ua = (string) ($row['last_user_agent'] ?? '');
        $meta = login_security_parse_ua($ua);
        return [
            'browser' => $meta['browser'],
            'os' => $meta['os'],
            'device_type' => $meta['device_type'],
            'last_seen_label' => !empty($row['last_seen_at'])
                ? date('M j, Y g:i A', strtotime((string) $row['last_seen_at']))
                : '—',
            'last_ip' => (string) ($row['last_ip'] ?? ''),
        ];
    }, $rows);
}

/**
 * Password strength score 0–4 for UI indicator.
 *
 * @return array{score: int, label: string}
 */
function patient_settings_password_strength(string $password): array
{
    $score = 0;
    if (strlen($password) >= 12) {
        $score++;
    }
    if (preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password)) {
        $score++;
    }
    if (preg_match('/\d/', $password)) {
        $score++;
    }
    if (preg_match('/[^A-Za-z0-9]/', $password)) {
        $score++;
    }

    $labels = ['Very weak', 'Weak', 'Fair', 'Strong', 'Very strong'];
    return ['score' => $score, 'label' => $labels[$score] ?? 'Very weak'];
}

/**
 * @return array{success: bool, message: string}
 */
function patient_settings_change_password(PDO $pdo, int $userId, string $current, string $newPass, string $confirm): array
{
    if ($current === '' || $newPass === '' || $confirm === '') {
        return ['success' => false, 'message' => 'All password fields are required.'];
    }
    if ($newPass !== $confirm) {
        return ['success' => false, 'message' => 'New password and confirmation do not match.'];
    }

    $policyError = patient_validate_password_policy($newPass);
    if ($policyError !== null) {
        return ['success' => false, 'message' => $policyError];
    }

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND role = ? LIMIT 1');
    $stmt->execute([$userId, 'patient']);
    $hash = (string) ($stmt->fetchColumn() ?: '');
    if ($hash === '' || !password_verify($current, $hash)) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }
    if (password_verify($newPass, $hash)) {
        return ['success' => false, 'message' => 'New password must be different from your current password.'];
    }
    if (password_history_is_reused($pdo, $userId, $newPass)) {
        return ['success' => false, 'message' => 'You cannot reuse a recent password. Choose a different one.'];
    }

    password_history_add($pdo, $userId, $hash);
    $newHash = patient_hash_password($newPass);
    $pdo->prepare("
        UPDATE users SET password = ?, password_changed_at = NOW(), updated_at = NOW()
        WHERE id = ? AND role = 'patient'
    ")->execute([$newHash, $userId]);

    remember_me_revoke_for_user($pdo, $userId);
    remember_me_clear_cookie();

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => AuditAction::PASSWORD_CHANGED,
        'description' => 'Patient changed account password from Settings.',
        'meta'        => ['source' => 'patient_settings'],
    ]);

    return ['success' => true, 'message' => 'Password updated successfully.'];
}

function patient_settings_save_notifications(PDO $pdo, int $userId, array $input): array
{
    patient_settings_ensure_defaults($pdo, $userId);
    $keys = array_keys(patient_settings_default_notifications());
    $vals = [];
    foreach ($keys as $key) {
        $vals[$key] = !empty($input[$key]) ? 1 : 0;
    }

    $pdo->prepare("
        UPDATE patient_notification_preferences SET
            appointment_reminders = ?,
            consultation_updates = ?,
            followup_reminders = ?,
            prescription_notifications = ?,
            system_announcements = ?,
            in_app_notifications = ?,
            email_notifications = ?,
            updated_at = NOW()
        WHERE user_id = ?
    ")->execute([
        $vals['appointment_reminders'],
        $vals['consultation_updates'],
        $vals['followup_reminders'],
        $vals['prescription_notifications'],
        $vals['system_announcements'],
        $vals['in_app_notifications'],
        $vals['email_notifications'],
        $userId,
    ]);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => 'notification_preferences_updated',
        'description' => 'Patient updated notification preferences.',
        'meta'        => $vals,
    ]);

    return ['success' => true, 'message' => 'Notification preferences saved.'];
}

function patient_settings_save_privacy(PDO $pdo, int $userId, array $input): array
{
    patient_settings_ensure_defaults($pdo, $userId);

    $share = !empty($input['share_medical_records']) ? 1 : 0;
    $emergency = !empty($input['emergency_access_consent']) ? 1 : 0;
    $ack = !empty($input['data_privacy_acknowledged']) ? 1 : 0;

    if (!$ack) {
        return ['success' => false, 'message' => 'You must acknowledge the data privacy policy to continue using medConnect.'];
    }

    $pdo->prepare("
        UPDATE patient_privacy_preferences SET
            share_medical_records = ?,
            emergency_access_consent = ?,
            data_privacy_acknowledged = ?,
            updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$share, $emergency, $ack, $userId]);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => 'privacy_preferences_updated',
        'description' => 'Patient updated privacy preferences.',
        'meta'        => [
            'share_medical_records' => $share,
            'emergency_access_consent' => $emergency,
            'data_privacy_acknowledged' => $ack,
        ],
    ]);

    return ['success' => true, 'message' => 'Privacy preferences saved.'];
}

function patient_settings_logout_all_devices(PDO $pdo, int $userId): array
{
    try {
        $pdo->prepare("DELETE FROM active_sessions WHERE user_id = ? AND role = 'patient'")->execute([$userId]);
    } catch (PDOException $e) { /* non-fatal */ }

    try {
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
    } catch (PDOException $e) { /* non-fatal */ }

    remember_me_clear_cookie();
    unset($_SESSION['remember_me_extended']);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => 'logout_all_devices',
        'description' => 'Patient signed out of all devices from Settings.',
    ]);

    return ['success' => true, 'message' => 'All other devices have been signed out.'];
}

function patient_settings_terminate_session(PDO $pdo, int $userId, int $sessionRowId): array
{
    $stmt = $pdo->prepare("
        SELECT id FROM active_sessions
        WHERE id = ? AND user_id = ? AND role = 'patient'
        LIMIT 1
    ");
    $stmt->execute([$sessionRowId, $userId]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'message' => 'Session not found.'];
    }

    superadmin_terminate_session($pdo, $sessionRowId, $userId);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => 'session_terminated',
        'description' => 'Patient terminated an active session from Settings.',
        'meta'        => ['session_row_id' => $sessionRowId],
    ]);

    return ['success' => true, 'message' => 'Session ended.'];
}

/**
 * Create a medical profile update request from the patient.
 *
 * @param array<string, string> $proposed
 */
function patient_settings_request_medical_update(PDO $pdo, int $userId, string $note = '', array $proposed = []): array
{
    patient_settings_ensure_schema($pdo);
    require_once __DIR__ . '/patient_health_summary.php';

    $pending = $pdo->prepare("
        SELECT id FROM patient_medical_update_requests
        WHERE patient_id = ? AND status IN ('pending', 'in_review')
        LIMIT 1
    ");
    $pending->execute([$userId]);
    if ($pending->fetch()) {
        return ['success' => false, 'message' => 'You already have a pending update request. Please wait for your doctor to review it.'];
    }

    $summary = patient_health_summary_load($pdo, $userId);
    $blood = trim((string) ($proposed['blood_type'] ?? ''));
    $allergies = trim((string) ($proposed['allergies'] ?? ''));
    $conditions = trim((string) ($proposed['existing_conditions'] ?? ''));
    $medications = trim((string) ($proposed['current_medications'] ?? ''));

    $allowedBlood = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown', ''];
    if ($blood !== '' && !in_array($blood, $allowedBlood, true)) {
        return ['success' => false, 'message' => 'Invalid blood type selected.'];
    }

    $currentBlood = trim((string) ($summary['blood_type'] ?? ''));
    $currentAllergies = implode(', ', $summary['allergies'] ?? []);
    $currentConditions = implode(', ', $summary['conditions'] ?? []);
    $currentMeds = implode(', ', $summary['medications'] ?? []);

    $hasChange = ($blood !== '' && $blood !== $currentBlood)
        || ($allergies !== '' && strcasecmp($allergies, $currentAllergies) !== 0)
        || ($conditions !== '' && strcasecmp($conditions, $currentConditions) !== 0)
        || ($medications !== '' && strcasecmp($medications, $currentMeds) !== 0);

    if (!$hasChange && $note === '') {
        return ['success' => false, 'message' => 'Update at least one health field or add a note describing the correction.'];
    }

    $assignedProviderId = patient_medical_resolve_assigned_provider($pdo, $userId);
    if ($assignedProviderId <= 0) {
        return ['success' => false, 'message' => 'No healthcare provider is available to review your request. Please try again later or book a consultation.'];
    }

    $pdo->prepare("
        INSERT INTO patient_medical_update_requests (
            patient_id, status, patient_note,
            proposed_blood_type, proposed_allergies, proposed_conditions, proposed_medications,
            provider_id, created_at
        ) VALUES (?, 'pending', ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $userId,
        $note !== '' ? $note : null,
        $blood !== '' ? $blood : null,
        $allergies !== '' ? $allergies : null,
        $conditions !== '' ? $conditions : null,
        $medications !== '' ? $medications : null,
        $assignedProviderId,
    ]);

    $requestId = (int) $pdo->lastInsertId();
    $assignedLabel = patient_medical_provider_label($pdo, $assignedProviderId);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => 'medical_update_requested',
        'description' => 'Patient requested a verified update to permanent medical profile.',
        'meta'        => [
            'request_id' => $requestId,
            'note' => $note,
            'assigned_provider_id' => $assignedProviderId,
        ],
    ]);

    try {
        require_once dirname(__DIR__) . '/core/NotificationManager.php';
        $nameStmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $nameStmt->execute([$userId]);
        $name = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $patientLabel = trim(($name['first_name'] ?? '') . ' ' . ($name['last_name'] ?? ''));

        NotificationManager::create($pdo, $assignedProviderId, [
            'type'       => 'clinical',
            'title'      => 'Health Summary Update Request',
            'message'    => $patientLabel . ' requested a verified update to their permanent Health Summary.',
            'priority'   => 'normal',
            'action_url' => '/views/provider/medical_records.php?patient_id=' . $userId,
        ]);

        NotificationManager::create($pdo, $userId, [
            'type'       => 'clinical',
            'title'      => 'Health Summary Update Pending',
            'message'    => 'Your update request was sent to ' . $assignedLabel . ' for review. Your official Health Summary will not change until approved.',
            'priority'   => 'normal',
            'action_url' => '/views/patient/health_summary.php',
        ]);
    } catch (Throwable $e) { /* non-fatal */ }

    return [
        'success' => true,
        'message' => 'Your update request was sent to ' . $assignedLabel . ' for review. You will be notified when it is approved or rejected.',
        'request_id' => $requestId,
        'assigned_provider' => [
            'id' => $assignedProviderId,
            'label' => $assignedLabel,
        ],
    ];
}

/**
 * Read patient notification prefs for NotificationManager integration.
 *
 * @return array<string, int>
 */
function patient_notification_prefs(PDO $pdo, int $userId): array
{
    patient_settings_ensure_defaults($pdo, $userId);
    $stmt = $pdo->prepare('SELECT * FROM patient_notification_preferences WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: patient_settings_default_notifications();
    return [
        'in_app' => (int) ($row['in_app_notifications'] ?? 1),
        'email'  => (int) ($row['email_notifications'] ?? 1),
        'appointment_reminders' => (int) ($row['appointment_reminders'] ?? 1),
        'consultation_updates' => (int) ($row['consultation_updates'] ?? 1),
        'followup_reminders' => (int) ($row['followup_reminders'] ?? 1),
        'prescription_notifications' => (int) ($row['prescription_notifications'] ?? 1),
        'system_announcements' => (int) ($row['system_announcements'] ?? 1),
    ];
}
