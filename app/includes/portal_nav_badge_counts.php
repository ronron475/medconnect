<?php
declare(strict_types=1);

/**
 * Sidebar nav badge counts — shared across patient, provider, BHW, admin, superadmin.
 */

require_once __DIR__ . '/message_deletion.php';

/**
 * @return array<string, int>
 */
function portal_nav_badge_counts(PDO $pdo, string $role, int $userId): array
{
    $role = strtolower(trim($role));
    $counts = ['messages' => 0];

    if ($userId > 0) {
        try {
            consultation_messages_ensure_schema($pdo);
            $counts['messages'] = max(0, message_unread_count($pdo, $userId));
        } catch (Throwable $e) {
            $counts['messages'] = 0;
        }
    }

    return match ($role) {
        'provider' => array_merge($counts, portal_nav_provider_counts($pdo, $userId)),
        'patient'  => array_merge($counts, portal_nav_patient_counts($pdo, $userId)),
        'bhw'      => array_merge($counts, portal_nav_bhw_counts($pdo, $userId)),
        'admin', 'superadmin' => portal_nav_admin_counts($pdo, $role),
        default    => $counts,
    };
}

/**
 * @return array<string, int>
 */
function portal_nav_provider_counts(PDO $pdo, int $providerId): array
{
    require_once __DIR__ . '/provider_nav_counts.php';
    $nav = provider_nav_counts($pdo, $providerId);
    return [
        'queue'  => (int) ($nav['queue'] ?? 0),
        'triage' => (int) ($nav['triage'] ?? 0),
        'referrals'  => (int) ($nav['referrals'] ?? 0),
        'followups'  => (int) ($nav['followups'] ?? 0),
    ];
}

/**
 * @return array<string, int>
 */
function portal_nav_patient_counts(PDO $pdo, int $patientId): array
{
    $upcoming = 0;
    $triagePending = 0;
    if ($patientId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM consultations
                WHERE patient_id = ?
                  AND consult_date >= CURDATE()
                  AND status IN ('pending', 'scheduled', 'in_consultation', 'waiting')
            ");
            $stmt->execute([$patientId]);
            $upcoming = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $upcoming = 0;
        }
        try {
            if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM triage_results
                    WHERE patient_id = ?
                      AND status = 'pending'
                      AND assessed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ");
                $stmt->execute([$patientId]);
                $triagePending = (int) $stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $triagePending = 0;
        }
    }
    return [
        'consultations'   => max(0, $upcoming),
        'patient_triage'  => max(0, $triagePending),
    ];
}

/**
 * @return array<string, int>
 */
function portal_nav_bhw_counts(PDO $pdo, int $bhwId): array
{
    require_once __DIR__ . '/bhw_workflows.php';
    require_once VIEWS_PATH . '/bhw/partials/bhw_context.php';

    $counts = [
        'bhw_triage'            => 0,
        'bhw_consultations'     => 0,
        'bhw_referrals'         => 0,
        'bhw_records'           => 0,
        'bhw_followups'         => 0,
        'bhw_patients_pending'  => 0,
    ];

    if ($bhwId <= 0) {
        return $counts;
    }

    try {
        $ctx = bhw_resolve_context($pdo);
        if (empty($ctx['allowed'])) {
            return $counts;
        }

        $metrics = BhwWorkflows::getDashboardMetrics($pdo, $ctx, ['days' => 7]);
        $counts['bhw_triage'] = max(
            0,
            (int) ($metrics['waiting_ai_triage'] ?? 0) + (int) ($metrics['emergency_cases'] ?? 0)
        );
        $counts['bhw_consultations'] = max(0, (int) ($metrics['upcoming_consultations'] ?? 0));
        $counts['bhw_referrals'] = max(0, (int) ($metrics['referrals'] ?? 0));
        $counts['bhw_records'] = max(0, (int) ($metrics['pending_registrations'] ?? 0));
        $counts['bhw_followups'] = max(0, (int) ($metrics['followups'] ?? 0));
        $counts['bhw_patients_pending'] = max(0, (int) ($metrics['pending_registrations'] ?? 0));
    } catch (Throwable $e) {
        // keep zeros
    }

    return $counts;
}

/**
 * @return array<string, int>
 */
function portal_nav_admin_counts(PDO $pdo, string $role): array
{
    require_once __DIR__ . '/doctor_application_schema.php';
    require_once __DIR__ . '/bhw_application_schema.php';
    doctor_application_ensure_schema($pdo);
    bhw_application_ensure_schema($pdo);

    $pendingDoctors = 0;
    $pendingBhw = 0;
    $activeConsults = 0;
    $queuePending = 0;
    $notifications = 0;
    $aiReviewPending = 0;
    $announcementDrafts = 0;
    $pendingReferrals = 0;

    try {
        $pendingDoctors = (int) $pdo->query("SELECT COUNT(*) FROM doctor_applications WHERE status='pending_approval'")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $pendingBhw = (int) $pdo->query("SELECT COUNT(*) FROM bhw_applications WHERE status='pending_approval'")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            $activeConsults = (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE status='in_consultation'")->fetchColumn();
            $queuePending = (int) $pdo->query("
                SELECT COUNT(*) FROM consultations
                WHERE consult_date = CURDATE()
                  AND status IN ('pending', 'scheduled', 'waiting', 'in_consultation')
            ")->fetchColumn();
        }
    } catch (Throwable $e) {
    }
    try {
        if ($pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
            $pendingReferrals = (int) $pdo->query("SELECT COUNT(*) FROM digital_referrals WHERE status = 'pending'")->fetchColumn();
        }
    } catch (Throwable $e) {
    }
    try {
        require_once __DIR__ . '/triage_assessment_schema.php';
        triage_assessment_ensure_schema($pdo);
        if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
            $aiReviewPending = (int) $pdo->query("
                SELECT COUNT(*)
                FROM triage_results
                WHERE recommendation_status = 'pending_approval'
                  AND assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND TRIM(COALESCE(chief_complaint, '')) <> ''
            ")->fetchColumn();
        }
    } catch (Throwable $e) {
    }
    try {
        if ($pdo->query("SHOW TABLES LIKE 'announcements'")->rowCount()) {
            $announcementDrafts = (int) $pdo->query("
                SELECT COUNT(*) FROM announcements
                WHERE status = 'draft' AND deleted_at IS NULL
            ")->fetchColumn();
        }
    } catch (Throwable $e) {
    }
    if (!empty($_SESSION['user_id']) && in_array($role, ['admin', 'superadmin'], true)) {
        try {
            require_once __DIR__ . '/../core/NotificationManager.php';
            $notifications = max(0, NotificationManager::getUnreadCount($pdo, (int) $_SESSION['user_id']));
        } catch (Throwable $e) {
        }
    }

    return [
        'pending_doctor_apps'  => max(0, $pendingDoctors),
        'pending_bhw_apps'     => max(0, $pendingBhw),
        'active_consultations' => max(0, $activeConsults),
        'queue_pending'        => max(0, $queuePending),
        'notifications'        => max(0, $notifications),
        'ai_review_pending'    => max(0, $aiReviewPending),
        'announcement_drafts'  => max(0, $announcementDrafts),
        'pending_referrals'    => max(0, $pendingReferrals),
    ];
}

/**
 * Resolve badge count for a nav item.
 */
function portal_nav_badge_count_for_item(string $role, string $file, ?string $itemQuery, array $counts): int
{
    $key = portal_nav_badge_key_for_item($role, $file, $itemQuery);
    if ($key === null) {
        return 0;
    }
    return max(0, (int) ($counts[$key] ?? 0));
}

function portal_nav_badge_key_for_item(string $role, string $file, ?string $itemQuery = null): ?string
{
    $role = strtolower(trim($role));
    $file = str_replace('\\', '/', trim($file));

    if ($role === 'patient') {
        return match ($file) {
            'messages.php'      => 'messages',
            'consultations.php' => 'consultations',
            'triage.php'        => 'patient_triage',
            default             => null,
        };
    }

    if ($role === 'provider') {
        return match ($file) {
            'queue.php'               => 'queue',
            'triage.php'              => 'triage',
            'messages.php'            => 'messages',
            'referrals.php'           => 'referrals',
            'followup_management.php' => 'followups',
            default                   => null,
        };
    }

    if ($role === 'bhw') {
        return match ($file) {
            'triage/submit.php'        => 'bhw_triage',
            'consultations/index.php'  => 'bhw_consultations',
            'referral/status.php'      => 'bhw_referrals',
            'records/index.php'        => 'bhw_records',
            'followup/track.php'       => 'bhw_followups',
            'patients/list.php'        => 'bhw_patients_pending',
            default                    => null,
        };
    }

    if ($role === 'admin' || $role === 'superadmin') {
        if ($file === 'facility_management.php' && $itemQuery === 'tab=referral') {
            return 'pending_referrals';
        }
        $map = [
            'doctor_applications.php'       => 'pending_doctor_apps',
            'doctor_approvals.php'          => 'pending_doctor_apps',
            'bhw_applications.php'          => 'pending_bhw_apps',
            'bhw_approvals.php'             => 'pending_bhw_apps',
            'live_consultation_monitor.php' => 'active_consultations',
            'queue_monitoring.php'          => 'queue_pending',
            'notification_center.php'       => 'notifications',
            'ai_review_assignments.php'     => 'ai_review_pending',
            'announcements.php'             => 'announcement_drafts',
        ];
        return $map[$file] ?? null;
    }

    return null;
}

function portal_nav_badge_data_attr(?string $badgeKey): string
{
    if ($badgeKey === null || $badgeKey === '') {
        return '';
    }
    if ($badgeKey === 'messages') {
        return 'data-nav-messages-badge data-nav-badge="messages"';
    }
    if ($badgeKey === 'queue') {
        return 'data-nav-queue-badge data-nav-badge="queue"';
    }
    if ($badgeKey === 'triage') {
        return 'data-nav-triage-badge data-nav-badge="triage"';
    }
    return 'data-nav-badge="' . htmlspecialchars($badgeKey, ENT_QUOTES, 'UTF-8') . '"';
}

function portal_nav_badge_nav_link_attr(?string $badgeKey): string
{
    if ($badgeKey === 'messages') {
        return ' data-nav-messages';
    }
    if ($badgeKey === 'queue') {
        return ' data-nav-queue';
    }
    if ($badgeKey === 'triage') {
        return ' data-nav-triage';
    }
    return '';
}

function portal_nav_badge_format(int $count): string
{
    if ($count <= 0) {
        return '';
    }
    return $count > 99 ? '99+' : (string) $count;
}
