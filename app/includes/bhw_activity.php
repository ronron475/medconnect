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
        'bhw_triage_submitted'     => 'Legacy workflow',
        'bhw_emergency_referral'   => 'Legacy workflow',
        'bhw_referral_created'     => 'Referral',
        'bhw_records_viewed'       => 'Records',
        'bhw_document_uploaded'    => 'Records',
        'bhw_followup_reminder'    => 'Follow-Up',
        'bhw_followup_reminder_failed' => 'Follow-Up',
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
        'bhw_triage_submitted'     => 'Legacy triage activity',
        'bhw_emergency_referral'   => 'Emergency AI Referral',
        'bhw_referral_created'     => 'Created Referral',
        'bhw_records_viewed'       => 'Viewed Medical Records',
        'bhw_document_uploaded'    => 'Uploaded Medical Record',
        'bhw_followup_reminder'    => 'Sent Follow-Up Reminder',
        'bhw_followup_reminder_failed' => 'Follow-Up Reminder Failed',
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
 * A selected period overrides a custom range so the two cannot cancel each other out.
 * Custom dates apply only when Period is All time.
 *
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function bhw_activity_resolve_dates(array $filters): array
{
    $period = strtolower(trim((string) ($filters['period'] ?? '')));
    if (!in_array($period, ['today', 'week', 'month'], true)) {
        $period = '';
    }
    $from = bhw_activity_valid_date((string) ($filters['date_from'] ?? ''));
    $to = bhw_activity_valid_date((string) ($filters['date_to'] ?? ''));
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }
    if ($period !== '') {
        $from = '';
        $to = '';
    }
    $q = trim((string) ($filters['q'] ?? ''));
    $filters['period'] = $period;
    $filters['date_from'] = $from;
    $filters['date_to'] = $to;
    $filters['q'] = function_exists('mb_substr') ? mb_substr($q, 0, 80) : substr($q, 0, 80);

    return $filters;
}

function bhw_activity_valid_date(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}

function bhw_activity_like(string $value): string
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
}

/**
 * Search matches the activity placeholder: patient name, action, and module.
 *
 * @return array{sql:string,params:array<string,string>}
 */
function bhw_activity_search_clause(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return ['sql' => '', 'params' => []];
    }
    $like = bhw_activity_like($q);
    $params = [
        ':q1' => $like,
        ':q2' => $like,
        ':q3' => $like,
        ':q4' => $like,
        ':q5' => $like,
    ];
    $parts = [
        "al.description LIKE :q1 ESCAPE '!'",
        "al.action_type LIKE :q2 ESCAPE '!'",
        "JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.patient_name')) LIKE :q3 ESCAPE '!'",
        "JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.module')) LIKE :q4 ESCAPE '!'",
        "TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) LIKE :q5 ESCAPE '!'",
    ];
    $actions = [];
    foreach (bhw_activity_module_map() as $action => $module) {
        if (stripos(bhw_activity_label($action), $q) !== false || stripos((string) $module, $q) !== false || stripos((string) $action, $q) !== false) {
            $actions[] = (string) $action;
        }
    }
    foreach ($actions as $index => $action) {
        $key = ':qa' . $index;
        $params[$key] = $action;
        $parts[] = 'al.action_type = ' . $key;
    }

    return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
}

/**
 * @return array{rows: array, total: int, page: int, per_page: int}
 */
function bhw_activity_sql_literal(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function bhw_activity_display_module(array $meta, string $action): string
{
    $stored = trim((string) ($meta['module'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    $map = bhw_activity_module_map();

    return $map[$action] ?? 'BHW Portal';
}

function bhw_activity_displayed_module_sql(): string
{
    $cases = '';
    foreach (bhw_activity_module_map() as $action => $module) {
        $cases .= ' WHEN ' . bhw_activity_sql_literal((string) $action) . ' THEN ' . bhw_activity_sql_literal((string) $module);
    }

    return "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.module'))), ''), CASE al.action_type{$cases} ELSE 'BHW Portal' END)";
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function bhw_activity_format_row(array $row, int $bhwId): array
{
    $meta = [];
    if (!empty($row['meta'])) {
        $decoded = json_decode((string) $row['meta'], true);
        $meta = is_array($decoded) ? $decoded : [];
    }
    $ua = bhw_activity_parse_ua($row['user_agent'] ?? '');
    $action = (string) ($row['action_type'] ?? '');
    $patientName = '';
    if (!empty($meta['patient_name'])) {
        $patientName = (string) $meta['patient_name'];
    } elseif ((int) ($row['patient_id'] ?? 0) !== $bhwId && trim((string) ($row['subject_name'] ?? '')) !== '') {
        $patientName = trim((string) $row['subject_name']);
    }

    return [
        'id'           => (int) ($row['id'] ?? 0),
        'date'         => date('M j, Y', strtotime((string) ($row['created_at'] ?? 'now'))),
        'time'         => date('g:i A', strtotime((string) ($row['created_at'] ?? 'now'))),
        'action'       => bhw_activity_label($action),
        'action_type'  => $action,
        'patient_name' => $patientName !== '' ? $patientName : '—',
        'module'       => bhw_activity_display_module($meta, $action),
        'ip_address'   => $row['ip_address'] ?? '—',
        'device'       => $ua['device'],
        'browser'      => $ua['browser'],
        'os'           => $ua['os'],
        'status'       => $meta['status'] ?? 'success',
        'description'  => $row['description'] ?? '',
        'meta'         => $meta,
        'created_at'   => $row['created_at'] ?? '',
    ];
}

/**
 * @param array<string, mixed> $filters
 * @return array{0:string,1:array<string,mixed>}
 */
function bhw_activity_query_parts(int $bhwId, array $filters): array
{
    $filters = bhw_activity_resolve_dates($filters);
    $where = [
        "(JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.bhw_id')) = :bhw_id OR (al.patient_id = :bhw_id2 AND al.action_type IN ('login_success','logout','password_changed')))",
    ];
    $params = [':bhw_id' => (string) $bhwId, ':bhw_id2' => $bhwId];

    $search = bhw_activity_search_clause($filters['q'] ?? '');
    if ($search['sql'] !== '') {
        $where[] = $search['sql'];
        foreach ($search['params'] as $key => $value) {
            $params[$key] = $value;
        }
    }

    $module = trim((string) ($filters['module'] ?? ''));
    if ($module !== '') {
        if (!in_array($module, array_values(array_unique(bhw_activity_module_map())), true)) {
            $where[] = '1 = 0';
        } else {
            $where[] = bhw_activity_displayed_module_sql() . ' = :module';
            $params[':module'] = $module;
        }
    }

    if (!empty($filters['action'])) {
        $where[] = 'al.action_type = :action';
        $params[':action'] = $filters['action'];
    }

    $period = (string) ($filters['period'] ?? '');
    if ($period === 'today') {
        $where[] = 'DATE(al.created_at) = CURDATE()';
    } elseif ($period === 'week') {
        $where[] = 'al.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
    } elseif ($period === 'month') {
        $where[] = 'al.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
    } else {
        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
    }

    $params[':bhw_join'] = $bhwId;

    return [implode(' AND ', $where), $params];
}

/**
 * @return array{rows: array, total: int, page: int, per_page: int, unavailable?: bool}
 */
function bhw_activity_list(PDO $pdo, int $bhwId, array $filters = []): array
{
    $exporting = !empty($filters['export']);
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(50, max(10, (int) ($filters['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    [$whereSql, $params] = bhw_activity_query_parts($bhwId, $filters);
    $fromSql = 'FROM patient_audit_logs al
        LEFT JOIN users u ON u.id = al.patient_id AND al.patient_id != :bhw_join';
    $limitSql = $exporting ? '' : ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) {$fromSql} WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT al.*,
                   CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS subject_name
            {$fromSql}
            WHERE {$whereSql}
            ORDER BY al.created_at DESC, al.id DESC
            {$limitSql}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('bhw_activity_list: ' . $e->getMessage());

        return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'unavailable' => true];
    }

    $formatted = [];
    foreach ($rows as $row) {
        $formatted[] = bhw_activity_format_row($row, $bhwId);
    }

    if ($exporting) {
        $page = 1;
        $perPage = max(1, count($formatted));
    }

    return ['rows' => $formatted, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

function bhw_activity_send_csv(PDO $pdo, int $bhwId, array $query): void
{
    $role = (string) ($_SESSION['user_role'] ?? '');
    if ($role !== 'bhw' || $bhwId <= 0) {
        Api::error('Forbidden.', 403);
    }
    $filters = [
        'q' => $query['q'] ?? '',
        'module' => $query['module'] ?? '',
        'period' => $query['period'] ?? '',
        'date_from' => $query['date_from'] ?? '',
        'date_to' => $query['date_to'] ?? '',
    ];
    [$whereSql, $params] = bhw_activity_query_parts($bhwId, $filters);
    $fromSql = 'FROM patient_audit_logs al
        LEFT JOIN users u ON u.id = al.patient_id AND al.patient_id != :bhw_join';
    $batchSize = 200;
    $cursorAt = null;
    $cursorId = null;
    $tmp = fopen('php://temp', 'w+');
    if ($tmp === false) {
        Api::error('Activity log is unavailable.', 500);
    }
    require_once __DIR__ . '/csv_export.php';
    csv_export_row($tmp, ['Date', 'Time', 'Action', 'Patient', 'Module', 'IP Address', 'Device', 'Status']);
    do {
        $batchParams = $params;
        $cursorSql = '';
        if ($cursorAt !== null) {
            $cursorSql = ' AND (al.created_at < :cursor_at OR (al.created_at = :cursor_at AND al.id < :cursor_id))';
            $batchParams[':cursor_at'] = $cursorAt;
            $batchParams[':cursor_id'] = $cursorId;
        }
        $sql = "
            SELECT al.*,
                   CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS subject_name
            {$fromSql}
            WHERE {$whereSql}{$cursorSql}
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT {$batchSize}
        ";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($batchParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('bhw_activity_export: ' . $e->getMessage());
            fclose($tmp);
            Api::error('Activity log is unavailable.', 500);
        }
        foreach ($rows as $row) {
            $formatted = bhw_activity_format_row($row, $bhwId);
            csv_export_row($tmp, [
                $formatted['date'] ?? '',
                $formatted['time'] ?? '',
                $formatted['action'] ?? '',
                $formatted['patient_name'] ?? '',
                $formatted['module'] ?? '',
                $formatted['ip_address'] ?? '',
                $formatted['device'] ?? '',
                $formatted['status'] ?? '',
            ]);
            $cursorAt = $row['created_at'];
            $cursorId = (int) $row['id'];
        }
    } while (count($rows) === $batchSize);

    rewind($tmp);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $out = csv_export_begin('bhw-activity-log.csv');
    stream_copy_to_stream($tmp, $out);
    fclose($tmp);
    fclose($out);
    exit;
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
