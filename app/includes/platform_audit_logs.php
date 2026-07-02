<?php
/**
 * Platform audit log queries for Admin & Super Admin audit pages.
 */
declare(strict_types=1);

/**
 * @return array{logs: list<array<string, mixed>>, total: int, stats: array<string, int>, action_types: list<string>}
 */
function platform_audit_fetch(PDO $pdo, array $filters = []): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    $action = trim((string) ($filters['action'] ?? ''));
    $role = trim((string) ($filters['role'] ?? ''));
    $limit = max(10, min(500, (int) ($filters['limit'] ?? 100)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));

    $where = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR al.description LIKE ? OR al.action_type LIKE ? OR al.ip_address LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    if ($action !== '' && $action !== 'all') {
        $where[] = 'al.action_type = ?';
        $params[] = $action;
    }

    if ($role !== '' && $role !== 'all') {
        $where[] = 'u.role = ?';
        $params[] = $role;
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*)
        FROM patient_audit_logs al
        JOIN users u ON u.id = al.patient_id
        WHERE {$whereSql}
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT al.id, al.patient_id, al.action_type, al.description, al.meta,
               al.ip_address, al.user_agent, al.created_at,
               u.first_name, u.last_name, u.email, u.role
        FROM patient_audit_logs al
        JOIN users u ON u.id = al.patient_id
        WHERE {$whereSql}
        ORDER BY al.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $logs = array_map(static function (array $row): array {
        $row['display_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $row['initials'] = platform_audit_initials($row['first_name'] ?? '', $row['last_name'] ?? '');
        $row['meta_pretty'] = platform_audit_format_meta($row['meta'] ?? null);
        $row['action_label'] = platform_audit_action_label((string) ($row['action_type'] ?? ''));
        $row['action_tone'] = platform_audit_action_tone((string) ($row['action_type'] ?? ''));
        return $row;
    }, $rows);

    return [
        'logs'          => $logs,
        'total'         => $total,
        'stats'         => platform_audit_stats($pdo),
        'action_types'  => platform_audit_action_types($pdo),
    ];
}

/**
 * @return array{today: int, week: int, total: int, unique_users_today: int}
 */
function platform_audit_stats(PDO $pdo): array
{
    try {
        $today = (int) $pdo->query("
            SELECT COUNT(*) FROM patient_audit_logs WHERE DATE(created_at) = CURDATE()
        ")->fetchColumn();

        $week = (int) $pdo->query("
            SELECT COUNT(*) FROM patient_audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ")->fetchColumn();

        $total = (int) $pdo->query('SELECT COUNT(*) FROM patient_audit_logs')->fetchColumn();

        $uniqueToday = (int) $pdo->query("
            SELECT COUNT(DISTINCT patient_id) FROM patient_audit_logs WHERE DATE(created_at) = CURDATE()
        ")->fetchColumn();

        return [
            'today'             => $today,
            'week'              => $week,
            'total'             => $total,
            'unique_users_today' => $uniqueToday,
        ];
    } catch (Throwable $e) {
        return ['today' => 0, 'week' => 0, 'total' => 0, 'unique_users_today' => 0];
    }
}

/**
 * @return list<string>
 */
function platform_audit_action_types(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT DISTINCT action_type FROM patient_audit_logs ORDER BY action_type ASC');
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'action_type');
    } catch (Throwable $e) {
        return [];
    }
}

function platform_audit_initials(string $first, string $last): string
{
    $f = strtoupper(substr(trim($first), 0, 1));
    $l = strtoupper(substr(trim($last), 0, 1));
    return ($f . $l) ?: '?';
}

function platform_audit_action_label(string $action): string
{
    return ucwords(str_replace('_', ' ', $action));
}

function platform_audit_action_tone(string $action): string
{
    if (preg_match('/login|logout|otp|password|register|setup/i', $action)) {
        return 'auth';
    }
    if (preg_match('/account_status|restored|restricted|archive/i', $action)) {
        return 'account';
    }
    if (preg_match('/announcement|report|export/i', $action)) {
        return 'admin';
    }
    if (preg_match('/message|delete/i', $action)) {
        return 'message';
    }
    if (preg_match('/profile|contact|medical|residency|doc/i', $action)) {
        return 'profile';
    }
    return 'default';
}

function platform_audit_format_meta(?string $meta): ?string
{
    if ($meta === null || $meta === '') {
        return null;
    }
    $decoded = json_decode($meta, true);
    if (!is_array($decoded)) {
        return trim($meta) !== '' ? $meta : null;
    }
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function platform_audit_role_label(string $role): string
{
    return match ($role) {
        'superadmin' => 'Super Admin',
        'admin'      => 'Administrator',
        'provider'   => 'Doctor',
        'bhw'        => 'BHW',
        'patient'    => 'Patient',
        default      => ucfirst($role),
    };
}
