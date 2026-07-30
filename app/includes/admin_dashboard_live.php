<?php
/**
 * Admin / Super Admin dashboard live payloads (metrics + tables).
 * Charts already refresh via dashboard_charts.php.
 */

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function admin_dashboard_live_payload(PDO $pdo, int $adminId): array
{
    require_once dirname(__DIR__) . '/doctor_application_schema.php';
    require_once dirname(__DIR__) . '/bhw_application_schema.php';
    doctor_application_ensure_schema($pdo);
    bhw_application_ensure_schema($pdo);

    $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalPatients = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='patient'")->fetchColumn();
    $totalProviders = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='provider'")->fetchColumn();
    $totalBhw = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='bhw'")->fetchColumn();

    $hasConsults = $pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount() > 0;
    $consultsToday = $hasConsults
        ? (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE consult_date = CURDATE()")->fetchColumn()
        : 0;
    $activeSessions = $hasConsults
        ? (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE status='in_consultation'")->fetchColumn()
        : 0;

    $urgentTriage = $pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()
        ? (int) $pdo->query("SELECT COUNT(*) FROM triage_results WHERE level IN ('1','2') OR urgency_label LIKE '%Urgent%'")->fetchColumn()
        : 0;

    $pendingDoctorApps = (int) $pdo->query("SELECT COUNT(*) FROM doctor_applications WHERE status='pending_approval'")->fetchColumn();
    $pendingBhwApps = (int) $pdo->query("SELECT COUNT(*) FROM bhw_applications WHERE status='pending_approval'")->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM doctor_applications WHERE created_by = ? AND status IN ('draft','requires_documents','rejected')");
    $stmt->execute([$adminId]);
    $myDraftDoctor = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bhw_applications WHERE created_by = ? AND status IN ('draft','requires_documents','rejected')");
    $stmt->execute([$adminId]);
    $myDraftBhw = (int) $stmt->fetchColumn();

    $recentUsers = $pdo->query(
        "SELECT id, first_name, last_name, email, role, is_active, created_at
         FROM users ORDER BY created_at DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $roleLabels = [
        'patient'    => 'Patient',
        'provider'   => 'Doctor',
        'bhw'        => 'BHW',
        'admin'      => 'Admin',
        'superadmin' => 'Super Admin',
    ];

    $recent = [];
    foreach ($recentUsers as $u) {
        $role = (string) ($u['role'] ?? '');
        $recent[] = [
            'id'         => (int) ($u['id'] ?? 0),
            'name'       => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
            'email'      => (string) ($u['email'] ?? ''),
            'initials'   => strtoupper(substr((string) ($u['first_name'] ?? 'U'), 0, 1) . substr((string) ($u['last_name'] ?? ''), 0, 1)),
            'role'       => $role,
            'role_label' => $roleLabels[$role] ?? ucfirst($role),
            'is_active'  => !empty($u['is_active']),
            'joined'     => !empty($u['created_at']) ? date('M j, Y', strtotime((string) $u['created_at'])) : '—',
        ];
    }

    return [
        'scope' => 'admin',
        'metrics' => [
            'patients'        => $totalPatients,
            'total_users'     => $totalUsers,
            'providers'       => $totalProviders,
            'bhw'             => $totalBhw,
            'consults_today'  => $consultsToday,
            'active_sessions' => $activeSessions,
            'urgent_triage'   => $urgentTriage,
        ],
        'queue' => [
            'pending_doctor' => $pendingDoctorApps,
            'pending_bhw'    => $pendingBhwApps,
            'draft_doctor'   => $myDraftDoctor,
            'draft_bhw'      => $myDraftBhw,
        ],
        'recent_users' => $recent,
        'updated_at'   => date('c'),
    ];
}

/**
 * @return array<string, mixed>
 */
function superadmin_dashboard_live_payload(PDO $pdo): array
{
    require_once __DIR__ . '/superadmin/service.php';
    require_once dirname(__DIR__) . '/doctor_application_schema.php';
    require_once dirname(__DIR__) . '/bhw_application_schema.php';
    doctor_application_ensure_schema($pdo);
    bhw_application_ensure_schema($pdo);

    $stats = superadmin_dashboard_stats($pdo);
    $security = superadmin_get_security_summary($pdo);
    $recentActivities = superadmin_recent_activities($pdo, 8);
    $recentLogins = superadmin_recent_logins($pdo, 6);
    $health = superadmin_system_health($pdo);

    $pendingDoctor = (int) $pdo->query("SELECT COUNT(*) FROM doctor_applications WHERE status='pending_approval'")->fetchColumn();
    $pendingBhw = (int) $pdo->query("SELECT COUNT(*) FROM bhw_applications WHERE status='pending_approval'")->fetchColumn();

    $activities = [];
    foreach ($recentActivities as $a) {
        $activities[] = [
            'user'   => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'System',
            'action' => (string) ($a['action'] ?? $a['action_type'] ?? ''),
            'module' => (string) ($a['module'] ?? 'system'),
            'time'   => !empty($a['created_at']) ? date('M j, g:i A', strtotime((string) $a['created_at'])) : '—',
        ];
    }

    $logins = [];
    foreach ($recentLogins as $l) {
        $logins[] = [
            'user' => trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')),
            'role' => strtoupper((string) ($l['role'] ?? '')),
            'ip'   => (string) ($l['ip_address'] ?? '—'),
            'time' => !empty($l['created_at']) ? date('M j, g:i A', strtotime((string) $l['created_at'])) : '—',
        ];
    }

    $healthRows = [];
    foreach ($health as $key => $svc) {
        if ($key === 'storage' || !is_array($svc)) {
            continue;
        }
        $st = (string) ($svc['status'] ?? 'unknown');
        $pill = in_array($st, ['healthy', 'online'], true)
            ? 'healthy'
            : ($st === 'disabled' ? 'warning' : ($st === 'critical' ? 'critical' : 'warning'));
        $healthRows[] = [
            'key'    => (string) $key,
            'label'  => (string) ($svc['label'] ?? $key),
            'status' => $st,
            'pill'   => $pill,
        ];
    }

    return [
        'scope' => 'superadmin',
        'metrics' => [
            'patients'         => (int) ($stats['total_patients'] ?? 0),
            'providers'        => (int) ($stats['total_providers'] ?? 0),
            'consultations'    => (int) ($stats['total_consultations'] ?? 0),
            'emergency_cases'  => (int) ($stats['emergency_cases'] ?? 0),
            'barangays'        => (int) ($stats['total_barangays'] ?? 0),
            'facilities'       => (int) ($stats['total_facilities'] ?? 0),
            'failed24h'        => (int) ($security['failed24h'] ?? 0),
            'active_sessions'  => (int) ($security['activeSessions'] ?? 0),
            'system_health'    => (string) ($stats['system_health'] ?? 'healthy'),
        ],
        'approvals' => [
            'doctor' => $pendingDoctor,
            'bhw'    => $pendingBhw,
            'total'  => $pendingDoctor + $pendingBhw,
        ],
        'activities' => $activities,
        'logins'     => $logins,
        'health'     => $healthRows,
        'updated_at' => date('c'),
    ];
}
