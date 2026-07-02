<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/security.php';

function superadmin_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function superadmin_count(PDO $pdo, string $sql): int
{
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array<string, mixed> */
function superadmin_dashboard_stats(PDO $pdo): array
{
    superadmin_ensure_schema($pdo);

    $stats = [
        'total_patients'      => superadmin_count($pdo, "SELECT COUNT(*) FROM users WHERE role='patient'"),
        'total_providers'     => superadmin_count($pdo, "SELECT COUNT(*) FROM users WHERE role='provider'"),
        'total_bhw'           => superadmin_count($pdo, "SELECT COUNT(*) FROM users WHERE role='bhw'"),
        'total_admins'        => superadmin_count($pdo, "SELECT COUNT(*) FROM users WHERE role='admin'"),
        'total_superadmins'   => superadmin_count($pdo, "SELECT COUNT(*) FROM users WHERE role='superadmin'"),
        'total_consultations' => superadmin_table_exists($pdo, 'consultations')
            ? superadmin_count($pdo, 'SELECT COUNT(*) FROM consultations') : 0,
        'total_referrals'     => superadmin_table_exists($pdo, 'digital_referrals')
            ? superadmin_count($pdo, 'SELECT COUNT(*) FROM digital_referrals') : 0,
        'total_barangays'     => superadmin_table_exists($pdo, 'barangays')
            ? superadmin_count($pdo, "SELECT COUNT(*) FROM barangays WHERE archived_at IS NULL") : 0,
        'total_facilities'    => superadmin_table_exists($pdo, 'facilities')
            ? superadmin_count($pdo, "SELECT COUNT(*) FROM facilities WHERE status='active'") : 0,
        'emergency_cases'     => superadmin_table_exists($pdo, 'triage_results')
            ? superadmin_count($pdo, "SELECT COUNT(*) FROM triage_results WHERE level IN ('1','2') OR urgency_label LIKE '%Urgent%' OR urgency_label LIKE '%Emergency%'") : 0,
        'ai_triage_total'     => superadmin_table_exists($pdo, 'triage_results')
            ? superadmin_count($pdo, 'SELECT COUNT(*) FROM triage_results') : 0,
        'pending_notifications' => superadmin_table_exists($pdo, 'notifications')
            ? superadmin_count($pdo, "SELECT COUNT(*) FROM notifications WHERE is_read = 0") : 0,
        'active_sessions'     => 0,
        'database_size_mb'    => 0.0,
        'storage_used_mb'     => 0.0,
        'storage_total_mb'    => 0.0,
        'system_health'       => 'healthy',
    ];

    try {
        $stats['active_sessions'] = (int) $pdo->query("SELECT COUNT(*) FROM active_sessions WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();
    } catch (Throwable $e) {
        $stats['active_sessions'] = superadmin_table_exists($pdo, 'consultations')
            ? superadmin_count($pdo, "SELECT COUNT(*) FROM consultations WHERE status='in_consultation'") : 0;
    }

    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName) {
            $stmt = $pdo->prepare('
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
                FROM information_schema.tables WHERE table_schema = ?
            ');
            $stmt->execute([$dbName]);
            $stats['database_size_mb'] = (float) ($stmt->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {}

    $storagePath = BASE_PATH . '/storage';
    if (is_dir($storagePath) && function_exists('disk_free_space') && function_exists('disk_total_space')) {
        $total = disk_total_space($storagePath);
        $free = disk_free_space($storagePath);
        if ($total > 0) {
            $stats['storage_total_mb'] = round($total / 1048576, 1);
            $stats['storage_used_mb'] = round(($total - $free) / 1048576, 1);
            if (($total - $free) / $total >= 0.9) {
                $stats['system_health'] = 'critical';
            } elseif (($total - $free) / $total >= 0.8) {
                $stats['system_health'] = 'warning';
            }
        }
    }

    return $stats;
}

function superadmin_recent_activities(PDO $pdo, int $limit = 10): array
{
    superadmin_ensure_schema($pdo);
    $rows = [];

    try {
        $stmt = $pdo->prepare('
            SELECT sl.*, u.first_name, u.last_name
            FROM security_logs sl
            LEFT JOIN users u ON u.id = sl.user_id
            ORDER BY sl.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare('
                SELECT al.*, u.first_name, u.last_name, u.role
                FROM patient_audit_logs al
                JOIN users u ON u.id = al.patient_id
                ORDER BY al.created_at DESC
                LIMIT ?
            ');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'action' => $r['action_type'],
                    'module' => 'audit',
                    'status' => 'info',
                    'description' => $r['description'],
                    'first_name' => $r['first_name'],
                    'last_name' => $r['last_name'],
                    'role' => $r['role'],
                    'created_at' => $r['created_at'],
                ];
            }
        } catch (Throwable $e2) {}
    }

    return $rows;
}

function superadmin_recent_logins(PDO $pdo, int $limit = 10): array
{
    login_security_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare('
            SELECT e.*, u.first_name, u.last_name, u.email
            FROM user_login_events e
            JOIN users u ON u.id = e.user_id
            ORDER BY e.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function superadmin_list_admins(PDO $pdo): array
{
    superadmin_ensure_schema($pdo);
    $stmt = $pdo->query("
        SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_active, u.created_at,
               sa.permissions, sa.created_at AS super_profile_at
        FROM users u
        LEFT JOIN super_admins sa ON sa.user_id = u.id
        WHERE u.role IN ('admin', 'superadmin')
        ORDER BY FIELD(u.role, 'superadmin', 'admin'), u.created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function superadmin_system_health(PDO $pdo): array
{
    require_once __DIR__ . '/../system_health_monitor.php';
    $snapshot = system_health_snapshot($pdo);
    $map = [];
    foreach ($snapshot['services'] ?? [] as $svc) {
        $key = (string) ($svc['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $entry = [
            'status' => (string) ($svc['status'] ?? 'unknown'),
            'label'  => (string) ($svc['label'] ?? ''),
        ];
        if (isset($svc['latency_ms'])) {
            $entry['latency_ms'] = $svc['latency_ms'];
        }
        if (isset($svc['used_mb'])) {
            $entry['used_mb'] = $svc['used_mb'];
            $entry['free_mb'] = $svc['free_mb'] ?? 0;
        }
        if (isset($svc['detail'])) {
            $entry['detail'] = $svc['detail'];
        }
        $map[$key] = $entry;
    }
    return $map;
}
