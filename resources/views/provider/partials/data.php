<?php
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/consultation_expiry.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';
require_once BASE_PATH . '/app/includes/provider_triage_cases.php';
require_once BASE_PATH . '/app/includes/profile_picture.php';
require_once BASE_PATH . '/app/includes/provider_patient_access.php';

// Auth guard — provider only
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'provider') {
    require_once BASE_PATH . '/app/includes/auth_guard.php';
    header('Location: ' . auth_signin_required_url());
    exit;
}

consultations_auto_expire($pdo, null, (int) $_SESSION['user_id']);
profile_picture_ensure_schema($pdo);
profile_picture_sync_session($pdo, (int) $_SESSION['user_id']);

require_once BASE_PATH . '/app/includes/theme_preferences.php';
require_once BASE_PATH . '/app/includes/provider_settings.php';

theme_preferences_sync_session($pdo, (int) $_SESSION['user_id'], 'provider');
require_once BASE_PATH . '/app/includes/provider_session_timeout.php';
require_once BASE_PATH . '/app/includes/provider_format.php';

provider_session_timeout_check();

$providerId = (int) ($_SESSION['user_id'] ?? 0);

$provider_settings_row = provider_settings_load($pdo, (int) $_SESSION['user_id']);
if (!empty($provider_settings_row['system'])) {
    provider_settings_apply_system_to_session($provider_settings_row['system']);
}

$provider = [
    'first_name'      => $_SESSION['first_name'] ?? 'Provider',
    'last_name'       => $_SESSION['last_name'] ?? '',
    'display_name'    => trim('Dr. ' . ($_SESSION['first_name'] ?? 'Provider') . ' ' . ($_SESSION['last_name'] ?? '')),
    'initials'        => profile_picture_initials($_SESSION['first_name'] ?? 'P', $_SESSION['last_name'] ?? ''),
    'profile_picture' => $_SESSION['profile_picture'] ?? null,
    'picture_url'     => profile_picture_public_url($_SESSION['profile_picture'] ?? null),
    'role'            => (string) ($provider_settings_row['profile']['specialty'] ?? 'General Medicine'),
    'facility'        => $provider_settings_row['profile']['facility'] ?? 'City Health Office',
];

/* ── Database-driven Provider Data ── */
// Fetch statistics from real tables
$stats = [
    'appointments' => 0,
    'pending'      => 0,
    'urgent'       => 0,
    'ongoing'      => 0,
    'completed'    => 0,
    'missed'       => 0
];

$hr = (int)date('H');
if ($hr < 12) $greeting = 'Good Morning';
elseif ($hr < 17) $greeting = 'Good Afternoon';
else $greeting = 'Good Evening';

try {
    // 1. Appointments (Today's booked slots)
    $s = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointment_slots
        WHERE provider_id = ? AND slot_date = CURDATE() AND status = 'booked'
    ");
    $s->execute([$providerId]);
    $stats['appointments'] = (int) $s->fetchColumn();

    // 2. Pending (In Queue/Waiting)
    $s = $pdo->prepare("
        SELECT COUNT(*)
        FROM triage_results tr
        WHERE tr.status = 'pending'
          AND (
            EXISTS (
                SELECT 1 FROM consultations c
                WHERE c.patient_id = tr.patient_id AND c.provider_id = ?
                ORDER BY c.id DESC LIMIT 1
            )
            OR EXISTS (
                SELECT 1 FROM appointment_slots s
                WHERE s.patient_id = tr.patient_id AND s.provider_id = ? AND s.status = 'booked'
                  AND s.slot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ORDER BY s.id DESC LIMIT 1
            )
          )
    ");
    $s->execute([$providerId, $providerId]);
    $stats['pending'] = (int) $s->fetchColumn();

    // 3. Urgent (Priority Level 1 or 2)
    $s = $pdo->prepare("
        SELECT COUNT(*)
        FROM triage_results tr
        WHERE (tr.level = '1' OR tr.level = '2' OR tr.level = 'Emergency')
          AND tr.status = 'pending'
          AND (
            EXISTS (
                SELECT 1 FROM consultations c
                WHERE c.patient_id = tr.patient_id AND c.provider_id = ?
                ORDER BY c.id DESC LIMIT 1
            )
            OR EXISTS (
                SELECT 1 FROM appointment_slots s
                WHERE s.patient_id = tr.patient_id AND s.provider_id = ? AND s.status = 'booked'
                  AND s.slot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ORDER BY s.id DESC LIMIT 1
            )
          )
    ");
    $s->execute([$providerId, $providerId]);
    $stats['urgent'] = (int) $s->fetchColumn();

    // 4. Ongoing (In Consultation)
    $s = $pdo->prepare("
        SELECT COUNT(*)
        FROM consultations
        WHERE provider_id = ? AND consult_date = CURDATE() AND status = 'in_consultation'
    ");
    $s->execute([$providerId]);
    $stats['ongoing'] = (int) $s->fetchColumn();

    // 5. Done this month
    $s = $pdo->prepare("
        SELECT COUNT(*)
        FROM consultations
        WHERE provider_id = ? AND status = 'completed'
          AND MONTH(consult_date) = MONTH(CURDATE())
          AND YEAR(consult_date) = YEAR(CURDATE())
    ");
    $s->execute([$providerId]);
    $stats['completed'] = (int) $s->fetchColumn();

    // 6. Missed (Check if status exists, else fallback to 0)
    $stats['missed'] = 0;
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*)
            FROM appointment_slots
            WHERE provider_id = ? AND slot_date = CURDATE() AND status = 'missed'
        ");
        $s->execute([$providerId]);
        $stats['missed'] = (int) $s->fetchColumn();
    } catch (Exception $e) { /* Status 'missed' likely not in ENUM */ }

    // Fetch today's schedule overview from appointment_slots
    $s_stmt = $pdo->prepare("
        SELECT s.start_time, s.end_time, s.status,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), '—') AS name,
               'Consultation' AS type
        FROM appointment_slots s
        LEFT JOIN users u ON u.id = s.patient_id
        WHERE s.provider_id = ? AND s.slot_date = CURDATE()
        ORDER BY s.start_time ASC
    ");
    $s_stmt->execute([$_SESSION['user_id']]);
    $schedule = [];
    while ($row = $s_stmt->fetch()) {
        $schedule[] = [
            'time'   => date('g:i A', strtotime($row['start_time'])),
            'name'   => $row['name'] ?? '—',
            'type'   => $row['type'],
            'status' => $row['status'] === 'booked' ? 'confirmed' : ($row['status'] === 'blocked' ? 'cancelled' : 'waiting')
        ];
    }

    // 5. Done this month (provider-scoped)
    $s = $pdo->prepare("
        SELECT COUNT(*)
        FROM consultations
        WHERE provider_id = ? AND status = 'completed'
          AND MONTH(consult_date) = MONTH(CURDATE())
          AND YEAR(consult_date) = YEAR(CURDATE())
    ");
    $s->execute([$providerId]);
    $stats['done_month'] = (int) $s->fetchColumn();

    // Fetch active queue — consultations assigned to this provider only.
    $q_stmt = $pdo->prepare("
        SELECT c.id, c.patient_id, c.consult_type AS complaint, c.status,
               c.consult_date, c.consult_time,
               s.slot_date, s.start_time AS slot_start,
               u.first_name, u.last_name,
               COALESCE(tr.urgency_label, 'Not triaged')         AS urgency_label,
               COALESCE(tr.chief_complaint, c.consult_type, '')  AS chief_complaint
        FROM consultations c
        JOIN users u ON c.patient_id = u.id
        LEFT JOIN appointment_slots s
            ON s.consultation_id = c.id AND s.status = 'booked'
        LEFT JOIN (
            SELECT patient_id, urgency_label, chief_complaint
            FROM triage_results
            WHERE id IN (
                SELECT MAX(id) FROM triage_results GROUP BY patient_id
            )
        ) tr ON tr.patient_id = c.patient_id
        WHERE c.provider_id = ?
          AND c.status IN ('pending', 'scheduled', 'in_consultation')
          AND c.consult_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY
            CASE c.status
                WHEN 'in_consultation' THEN 1
                WHEN 'pending'         THEN 2
                WHEN 'scheduled'       THEN 3
                ELSE 4
            END,
            c.consult_date ASC,
            c.consult_time ASC
    ");
    $q_stmt->execute([$providerId]);
    $queue = [];
    while ($row = $q_stmt->fetch()) {
        $queue[] = [
            'id'           => $row['id'],
            'patient_name' => $row['first_name'] . ' ' . $row['last_name'],
            'complaint'    => $row['chief_complaint'] ?: $row['complaint'],
            'urgency'      => $row['urgency_label'],
            'status'       => $row['status'] === 'in_consultation' ? 'In Consultation' : 'Waiting',
            'raw_status'   => (string) $row['status'],
            'date'         => $row['consult_date'],
            'time'         => $row['consult_time'],
            'slot_date'    => $row['slot_date'] ?? '',
            'slot_start'   => $row['slot_start'] ?? '',
        ];
    }

    $triage_cases = provider_triage_cases_load($pdo, $providerId);

} catch (Exception $e) {
    error_log("Data.php Error: " . $e->getMessage());
    // DB not ready — use safe empty defaults
    $stats        = ['appointments' => 0, 'pending' => 0, 'urgent' => 0, 'ongoing' => 0, 'completed' => 0, 'missed' => 0];
    $queue        = [];
    $triage_cases = [];
    $schedule     = [];
}

// ── Live Appointments (upcoming consultations for this provider) ─────────────
$appointments = [];
try {
    $a_stmt = $pdo->prepare("
        SELECT
            c.id,
            CONCAT(u.first_name, ' ', u.last_name)  AS name,
            CONCAT(UPPER(LEFT(u.first_name,1)), UPPER(LEFT(u.last_name,1))) AS initials,
            c.consult_type                           AS type,
            CONCAT(DATE_FORMAT(c.consult_date,'%b %e'), ' ', DATE_FORMAT(c.consult_time,'%l:%i %p')) AS time,
            c.status,
            DATE_FORMAT(c.consult_date,'%b %e')     AS date
        FROM consultations c
        JOIN users u ON u.id = c.patient_id
        WHERE c.provider_id = ?
          AND c.consult_date >= CURDATE()
          AND c.status NOT IN ('cancelled','completed')
        ORDER BY c.consult_date ASC, c.consult_time ASC
        LIMIT 10
    ");
    $a_stmt->execute([$_SESSION['user_id']]);
    $appointments = $a_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Appointments query error: " . $e->getMessage());
    $appointments = [];
}

// ── Live Notifications (from notifications table) ────────────────────────────
$notifications = [];
try {
    $n_stmt = $pdo->prepare("
        SELECT type, title, message AS msg, created_at, is_read,
               CASE
                   WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 60
                       THEN CONCAT(TIMESTAMPDIFF(MINUTE, created_at, NOW()), ' min ago')
                   WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) < 24
                       THEN CONCAT(TIMESTAMPDIFF(HOUR, created_at, NOW()), ' hr ago')
                   ELSE DATE_FORMAT(created_at, '%b %e')
               END AS time_label
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $n_stmt->execute([$_SESSION['user_id']]);
    $notifications = $n_stmt->fetchAll();
} catch (Exception $e) {
    $notifications = [];
}

// ── Live Activity (account logs + consultation events for this provider) ─────
require_once BASE_PATH . '/app/includes/provider_activity.php';
$activity = provider_load_recent_activity($pdo, $providerId, 8);

// ── Live Patient List (patients this provider has consulted or booked) ───────
$patients = [];
$providerId = (int) ($_SESSION['user_id'] ?? 0);
try {
    $p_stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.first_name,
            u.last_name,
            CONCAT(u.first_name, ' ', u.last_name)  AS name,
            CONCAT(UPPER(LEFT(u.first_name,1)), UPPER(LEFT(u.last_name,1))) AS initials,
            COALESCE(pr.age, '')                     AS age,
            COALESCE(pr.gender, '')                  AS sex,
            COALESCE(pr.contact_number, '')          AS contact,
            COALESCE(CONCAT_WS(', ',
                NULLIF(pr.barangay,''),
                NULLIF(pr.city_municipality,'')
            ), '')                                   AS address,
            COALESCE(pr.blood_type, '')              AS blood_type,
            COALESCE(pr.existing_conditions, '')     AS history,
            COALESCE(pr.allergies, '')               AS allergies,
            COALESCE(pr.current_medications, '')     AS medications,
            COALESCE(rel.last_consult, '')           AS last_consult,
            CASE WHEN u.is_active = 1 THEN 'Active' ELSE 'Inactive' END AS status
        FROM users u
        INNER JOIN (
            SELECT patient_id, MAX(consult_date) AS last_consult
            FROM consultations
            WHERE provider_id = ?
            GROUP BY patient_id
            UNION
            SELECT patient_id, MAX(slot_date) AS last_consult
            FROM appointment_slots
            WHERE provider_id = ? AND status = 'booked'
            GROUP BY patient_id
        ) rel ON rel.patient_id = u.id
        LEFT JOIN patient_registrations pr ON pr.user_id = u.id OR pr.email = u.email
        WHERE u.role = 'patient'
        ORDER BY rel.last_consult DESC, u.last_name ASC
    ");
    $p_stmt->execute([$providerId, $providerId]);
    $patients = $p_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Patients query error: " . $e->getMessage());
    $patients = [];
}

// ── Live Messages (recent conversations) ─────────────────────────────────────
$messages = [];
try {
    $m_stmt = $pdo->prepare("
        SELECT
            c.id                                              AS consultation_id,
            CONCAT(u.first_name, ' ', u.last_name)           AS from_name,
            CONCAT(UPPER(LEFT(u.first_name,1)), UPPER(LEFT(u.last_name,1))) AS initials,
            COALESCE(last_msg.message, c.consult_type)       AS preview,
            CASE
                WHEN last_msg.created_at IS NULL THEN DATE_FORMAT(c.consult_date,'%b %e')
                WHEN DATE(last_msg.created_at) = CURDATE() THEN DATE_FORMAT(last_msg.created_at,'%l:%i %p')
                WHEN DATEDIFF(NOW(), last_msg.created_at) = 1 THEN 'Yesterday'
                ELSE DATE_FORMAT(last_msg.created_at,'%b %e')
            END AS time,
            COALESCE(unread.cnt, 0) AS unread
        FROM consultations c
        JOIN users u ON u.id = c.patient_id
        LEFT JOIN (
            SELECT consultation_id, message, created_at
            FROM consultation_messages cm1
            WHERE cm1.id = (
                SELECT MAX(cm2.id) FROM consultation_messages cm2
                WHERE cm2.consultation_id = cm1.consultation_id
            )
        ) last_msg ON last_msg.consultation_id = c.id
        LEFT JOIN (
            SELECT consultation_id, COUNT(*) AS cnt
            FROM consultation_messages
            WHERE receiver_id = ? AND is_read = 0
            GROUP BY consultation_id
        ) unread ON unread.consultation_id = c.id
        WHERE c.provider_id = ?
        ORDER BY COALESCE(last_msg.created_at, c.created_at) DESC
        LIMIT 15
    ");
    $m_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $raw_messages = $m_stmt->fetchAll();
    foreach ($raw_messages as $row) {
        $messages[] = [
            'consultation_id' => $row['consultation_id'],
            'from'            => $row['from_name'],
            'initials'        => $row['initials'],
            'preview'         => $row['preview'],
            'time'            => $row['time'],
            'unread'          => (int)$row['unread'],
        ];
    }
} catch (Exception $e) {
    error_log("Messages query error: " . $e->getMessage());
    $messages = [];
}
