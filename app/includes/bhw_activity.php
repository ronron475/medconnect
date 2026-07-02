<?php
/**
 * BHW My Activity Log — query helpers (own actions only).
 */
require_once __DIR__ . '/bhw_scope.php';

function bhw_activity_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $migration = dirname(__DIR__, 2) . '/database/migrations/2026_06_29_bhw_reports_activity.sql';
        if (is_file($migration)) {
            $sql = file_get_contents($migration);
            if ($sql !== false) {
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '' && stripos($stmt, 'CREATE TABLE') !== false) {
                        $pdo->exec($stmt);
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // non-fatal
    }
    $done = true;
}

/** Human-readable module labels for BHW activity types. */
function bhw_activity_module_map(): array
{
    return [
        'bhw_patient_registered'   => 'Patient Management',
        'bhw_patient_updated'      => 'Patient Management',
        'bhw_patient_viewed'       => 'Patient Management',
        'bhw_triage_submitted'     => 'Triage & Booking',
        'bhw_emergency_referral'   => 'Triage & Booking',
        'bhw_referral_created'     => 'Referral',
        'bhw_records_viewed'       => 'Records',
        'bhw_document_uploaded'    => 'Records',
        'bhw_followup_reminder'    => 'Follow-Up',
        'bhw_home_visit_logged'    => 'Follow-Up',
        'bhw_profile_updated'      => 'Settings',
        'bhw_consultations_viewed' => 'Consultations',
        'bhw_report_exported'      => 'Reports',
        'bhw_report_viewed'        => 'Reports',
        'bhw_login'                => 'Authentication',
        'bhw_logout'               => 'Authentication',
        'login_success'            => 'Authentication',
        'logout'                   => 'Authentication',
        'password_changed'         => 'Authentication',
    ];
}

function bhw_activity_label(string $actionType): string
{
    $labels = [
        'bhw_patient_registered'   => 'Registered Patient',
        'bhw_patient_updated'      => 'Updated Patient Contact',
        'bhw_patient_viewed'       => 'Viewed Patient Profile',
        'bhw_triage_submitted'     => 'Performed AI Triage',
        'bhw_emergency_referral'   => 'Emergency AI Referral',
        'bhw_referral_created'     => 'Created Referral',
        'bhw_records_viewed'       => 'Viewed Medical Records',
        'bhw_document_uploaded'    => 'Uploaded Medical Record',
        'bhw_followup_reminder'    => 'Sent Follow-Up Reminder',
        'bhw_home_visit_logged'    => 'Logged Home Visit',
        'bhw_profile_updated'      => 'Updated Profile',
        'bhw_consultations_viewed' => 'Viewed Consultations',
        'bhw_report_exported'      => 'Downloaded Report',
        'bhw_report_viewed'        => 'Viewed Report',
        'bhw_login'                => 'Logged In',
        'bhw_logout'               => 'Logged Out',
        'login_success'            => 'Logged In',
        'logout'                   => 'Logged Out',
        'password_changed'         => 'Changed Password',
    ];
    return $labels[$actionType] ?? ucwords(str_replace(['bhw_', '_'], ['', ' '], $actionType));
}

function bhw_activity_parse_ua(?string $ua): array
{
    $ua = (string) $ua;
    $browser = 'Unknown';
    $os = 'Unknown';
    $device = 'Desktop';

    if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) {
        $device = preg_match('/iPad|Tablet/i', $ua) ? 'Tablet' : 'Mobile';
    }

    if (preg_match('/Windows NT/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/iPhone|iPad/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    if (preg_match('/Edg\//i', $ua)) {
        $browser = 'Microsoft Edge';
    } elseif (preg_match('/Chrome\//i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox\//i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome/i', $ua)) {
        $browser = 'Safari';
    }

    return ['browser' => $browser, 'os' => $os, 'device' => $device];
}

function bhw_activity_log(PDO $pdo, string $action, string $description, array $meta = [], int $subjectPatientId = 0, string $status = 'success'): void
{
    $bhwId = (int) ($_SESSION['user_id'] ?? 0);
    bhw_audit($pdo, $subjectPatientId > 0 ? $subjectPatientId : $bhwId, $action, $description, array_merge($meta, [
        'module' => bhw_activity_module_map()[$action] ?? 'BHW Portal',
        'status' => $status,
    ]));
}

/**
 * @return array{rows: array, total: int, page: int, per_page: int}
 */
function bhw_activity_list(PDO $pdo, int $bhwId, array $filters = []): array
{
    bhw_activity_ensure_schema($pdo);

    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(50, max(10, (int) ($filters['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $where = [
        "(JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.bhw_id')) = :bhw_id OR (al.patient_id = :bhw_id2 AND al.action_type IN ('login_success','logout','password_changed')))",
    ];
    $params = [':bhw_id' => (string) $bhwId, ':bhw_id2' => $bhwId];

    if (!empty($filters['q'])) {
        $where[] = '(al.description LIKE :q OR al.action_type LIKE :q2)';
        $params[':q'] = '%' . $filters['q'] . '%';
        $params[':q2'] = '%' . $filters['q'] . '%';
    }

    if (!empty($filters['module'])) {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.module')) = :module";
        $params[':module'] = $filters['module'];
    }

    if (!empty($filters['action'])) {
        $where[] = 'al.action_type = :action';
        $params[':action'] = $filters['action'];
    }

    $period = $filters['period'] ?? '';
    if ($period === 'today') {
        $where[] = 'DATE(al.created_at) = CURDATE()';
    } elseif ($period === 'week') {
        $where[] = 'al.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
    } elseif ($period === 'month') {
        $where[] = 'al.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
    }

    if (!empty($filters['date_from'])) {
        $where[] = 'al.created_at >= :date_from';
        $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'al.created_at <= :date_to';
        $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    $whereSql = implode(' AND ', $where);

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM patient_audit_logs al WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT al.*,
                   CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS subject_name
            FROM patient_audit_logs al
            LEFT JOIN users u ON u.id = al.patient_id AND al.patient_id != :bhw_join
            WHERE {$whereSql}
            ORDER BY al.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        $params[':bhw_join'] = $bhwId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
    }

    $formatted = [];
    foreach ($rows as $row) {
        $meta = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode((string) $row['meta'], true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        $ua = bhw_activity_parse_ua($row['user_agent'] ?? '');
        $action = (string) $row['action_type'];
        $patientName = '';
        if (!empty($meta['patient_name'])) {
            $patientName = (string) $meta['patient_name'];
        } elseif ((int) $row['patient_id'] !== $bhwId && trim($row['subject_name'] ?? '') !== '') {
            $patientName = trim($row['subject_name']);
        }

        $formatted[] = [
            'id'           => (int) $row['id'],
            'date'         => date('M j, Y', strtotime($row['created_at'])),
            'time'         => date('h:i A', strtotime($row['created_at'])),
            'action'       => bhw_activity_label($action),
            'action_type'  => $action,
            'patient_name' => $patientName ?: '—',
            'module'       => $meta['module'] ?? (bhw_activity_module_map()[$action] ?? 'BHW Portal'),
            'ip_address'   => $row['ip_address'] ?? '—',
            'device'       => $ua['device'],
            'browser'      => $ua['browser'],
            'os'           => $ua['os'],
            'status'       => $meta['status'] ?? 'success',
            'description'  => $row['description'],
            'meta'         => $meta,
            'created_at'   => $row['created_at'],
        ];
    }

    return ['rows' => $formatted, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

function bhw_activity_get(PDO $pdo, int $bhwId, int $logId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM patient_audit_logs WHERE id = ? LIMIT 1');
        $stmt->execute([$logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $meta = json_decode((string) ($row['meta'] ?? '{}'), true) ?: [];
        $rowBhwId = (int) ($meta['bhw_id'] ?? 0);
        if ($rowBhwId !== $bhwId && !((int) $row['patient_id'] === $bhwId && in_array($row['action_type'], ['login_success', 'logout', 'password_changed'], true))) {
            return null;
        }
        $ua = bhw_activity_parse_ua($row['user_agent'] ?? '');
        $action = (string) $row['action_type'];
        return [
            'id'           => (int) $row['id'],
            'action'       => bhw_activity_label($action),
            'action_type'  => $action,
            'description'  => $row['description'],
            'module'       => $meta['module'] ?? (bhw_activity_module_map()[$action] ?? 'BHW Portal'),
            'patient_name' => $meta['patient_name'] ?? null,
            'timestamp'    => $row['created_at'],
            'ip_address'   => $row['ip_address'] ?? '—',
            'browser'      => $ua['browser'],
            'os'           => $ua['os'],
            'device'       => $ua['device'],
            'status'       => $meta['status'] ?? 'success',
            'meta'         => $meta,
        ];
    } catch (PDOException $e) {
        return null;
    }
}
