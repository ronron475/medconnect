<?php
declare(strict_types=1);

/**
 * Dashboard chart data for Admin & Super Admin portals.
 */

function admin_chart_table_exists(PDO $pdo, string $table): bool
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

function admin_chart_normalize_date(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }
    $ts = strtotime((string) $value);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Allowed registration-trend periods: 1 Day, 1 Week, 1 Month, 1 Year. */
function admin_chart_normalize_period_days(int $days): int
{
    return match ($days) {
        1, 7, 30, 365 => $days,
        // Legacy clients that still request 180 → treat as 1 Year
        180 => 365,
        default => 30,
    };
}

function admin_chart_period_label(int $days): string
{
    return match (admin_chart_normalize_period_days($days)) {
        1 => '1 Day',
        7 => '1 Week',
        30 => '1 Month',
        365 => '1 Year',
        default => '1 Month',
    };
}

function admin_chart_period_range_label(int $days): string
{
    return match (admin_chart_normalize_period_days($days)) {
        1 => 'the last day',
        7 => 'the last week',
        30 => 'the last month',
        365 => 'the last year',
        default => 'the last month',
    };
}

/** Validate YYYY-MM-DD; returns '' when invalid. */
function admin_chart_valid_ymd(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

/**
 * Resolve preset days or an inclusive custom From–To range.
 *
 * @return array{
 *   mode:string,
 *   days:?int,
 *   from:string,
 *   to:string,
 *   bucket:string,
 *   period_label:string,
 *   period_range_label:string
 * }
 */
function admin_chart_resolve_period(int $days = 30, string $from = '', string $to = ''): array
{
    $fromYmd = admin_chart_valid_ymd($from);
    $toYmd = admin_chart_valid_ymd($to);

    if ($fromYmd !== '' && $toYmd !== '') {
        if ($fromYmd > $toYmd) {
            [$fromYmd, $toYmd] = [$toYmd, $fromYmd];
        }

        $start = new DateTimeImmutable($fromYmd . ' 00:00:00');
        $end = new DateTimeImmutable($toYmd . ' 00:00:00');
        $spanDays = (int) $start->diff($end)->days + 1;
        // Cap custom ranges at 2 years for readable charts / query cost.
        if ($spanDays > 731) {
            $start = $end->modify('-730 days');
            $fromYmd = $start->format('Y-m-d');
            $spanDays = 731;
        }

        return [
            'mode' => 'custom',
            'days' => null,
            'from' => $fromYmd,
            'to' => $toYmd,
            'bucket' => $spanDays > 62 ? 'month' : 'day',
            'period_label' => 'Custom',
            'period_range_label' => $fromYmd . ' to ' . $toYmd,
        ];
    }

    $days = admin_chart_normalize_period_days($days);
    $toYmd = date('Y-m-d');
    if ($days >= 365) {
        $fromYmd = date('Y-m-01', strtotime('-11 months'));
        $bucket = 'month';
    } else {
        $fromYmd = $days === 1
            ? $toYmd
            : date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $bucket = 'day';
    }

    return [
        'mode' => 'preset',
        'days' => $days,
        'from' => $fromYmd,
        'to' => $toYmd,
        'bucket' => $bucket,
        'period_label' => admin_chart_period_label($days),
        'period_range_label' => admin_chart_period_range_label($days),
    ];
}

/**
 * Build chart axis points for an inclusive date range.
 *
 * @return list<array{date:string,label:string,is_today:bool,bucket_key:string}>
 */
function admin_chart_build_axis(string $from, string $to, string $bucket): array
{
    $from = admin_chart_valid_ymd($from);
    $to = admin_chart_valid_ymd($to);
    if ($from === '' || $to === '') {
        return [];
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $today = date('Y-m-d');
    $series = [];

    if ($bucket === 'month') {
        $cursor = new DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new DateTimeImmutable(substr($to, 0, 7) . '-01');
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'date' => $key . '-01',
                'label' => $cursor->format('M Y'),
                'is_today' => $key === substr($today, 0, 7),
                'bucket_key' => $key,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $series;
    }

    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    $spanDays = (int) $start->diff($end)->days + 1;
    $cursor = $start;
    while ($cursor <= $end) {
        $ymd = $cursor->format('Y-m-d');
        if ($spanDays === 1) {
            $label = $ymd === $today ? 'Today' : $cursor->format('M j');
        } elseif ($spanDays <= 7) {
            $label = $cursor->format('D');
        } elseif ($spanDays <= 31) {
            $label = $cursor->format('M j');
        } else {
            $day = (int) $cursor->format('j');
            $label = ($day === 1 || $ymd === $from || $ymd === $to)
                ? $cursor->format('M j')
                : '';
        }
        $series[] = [
            'date' => $ymd,
            'label' => $label,
            'is_today' => $ymd === $today,
            'bucket_key' => $ymd,
        ];
        $cursor = $cursor->modify('+1 day');
    }

    return $series;
}

/**
 * Tracked dashboard roles (Administrators merges admin + superadmin).
 *
 * @return list<array{role:string,label:string,color:string,db_roles:list<string>}>
 */
function admin_chart_tracked_roles(): array
{
    return [
        ['role' => 'patient', 'label' => 'Patients', 'color' => '#0d9488', 'db_roles' => ['patient']],
        ['role' => 'provider', 'label' => 'Doctors', 'color' => '#2563eb', 'db_roles' => ['provider']],
        ['role' => 'bhw', 'label' => 'BHW', 'color' => '#0284c7', 'db_roles' => ['bhw']],
        ['role' => 'admin', 'label' => 'Administrators', 'color' => '#f59e0b', 'db_roles' => ['admin', 'superadmin']],
    ];
}

/** @return list<array{date:string,label:string,count:int,is_today:bool}> */
function admin_chart_last_n_days(int $days = 7): array
{
    $series = [];
    $days = admin_chart_normalize_period_days($days);
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts = strtotime("-{$i} days");
        if ($days === 1) {
            $label = 'Today';
        } elseif ($days <= 7) {
            $label = date('D', $ts);
        } elseif ($days <= 30) {
            $label = date('M j', $ts);
        } else {
            // Year: month ticks keep the axis readable
            $label = ((int) date('j', $ts) === 1 || $i === $days - 1 || $i === 0)
                ? date('M', $ts)
                : '';
        }
        $series[] = [
            'date'     => date('Y-m-d', $ts),
            'label'    => $label,
            'count'    => 0,
            'is_today' => $i === 0,
        ];
    }
    return $series;
}

function admin_chart_merge_daily_counts(array $series, array $rows, string $dateKey = 'd'): array
{
    $map = [];
    foreach ($rows as $row) {
        $d = admin_chart_normalize_date($row[$dateKey] ?? null);
        if ($d !== null) {
            $map[$d] = (int) ($row['cnt'] ?? $row['count'] ?? 0);
        }
    }
    foreach ($series as &$point) {
        $point['count'] = $map[$point['date']] ?? 0;
    }
    unset($point);
    return $series;
}

/**
 * A consultation counts only after the patient was actually seen.
 * Booked, waiting, and cancelled rows stay out of the trend.
 */
function admin_chart_consulted_where_sql(bool $hasCompletedAt): string
{
    $happened = "LOWER(TRIM(COALESCE(c.status,''))) IN ('completed','in_consultation','ended','closed')";
    if ($hasCompletedAt) {
        $happened = "({$happened} OR c.completed_at IS NOT NULL)";
    }

    return $happened
        . " AND LOWER(TRIM(COALESCE(c.status,''))) NOT IN ('cancelled','canceled')"
        . ' AND c.patient_id IS NOT NULL AND c.patient_id > 0';
}

/**
 * @return array{date_expr:string,where_sql:string,join_sql:string}|null
 */
function admin_chart_consulted_query_parts(PDO $pdo): ?array
{
    if (!admin_chart_table_exists($pdo, 'consultations')) {
        return null;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return null;
    }

    $dateParts = [];
    $hasCompletedAt = in_array('completed_at', $cols, true);
    if ($hasCompletedAt) {
        $dateParts[] = 'c.completed_at';
    }
    if (in_array('consult_date', $cols, true)) {
        $dateParts[] = 'c.consult_date';
    }
    if (in_array('created_at', $cols, true)) {
        $dateParts[] = 'c.created_at';
    }
    if ($dateParts === []) {
        return null;
    }

    $join = '';
    if (admin_chart_table_exists($pdo, 'users')) {
        $alive = admin_chart_users_not_soft_deleted_sql($pdo, 'u');
        $join = "INNER JOIN users u ON u.id = c.patient_id
            AND LOWER(TRIM(COALESCE(u.role,''))) = 'patient'
            AND {$alive}";
    }

    return [
        'date_expr' => 'COALESCE(' . implode(', ', $dateParts) . ')',
        'where_sql' => admin_chart_consulted_where_sql($hasCompletedAt),
        'join_sql'  => $join,
    ];
}

/** @return list<array{date:string,label:string,count:int,is_today:bool}> */
function admin_chart_consultations_daily(PDO $pdo, int $days = 30): array
{
    $days = admin_chart_normalize_period_days($days);
    if ($days >= 365) {
        return admin_chart_consultations_monthly($pdo, 12);
    }

    $series = admin_chart_last_n_days($days);
    $parts = admin_chart_consulted_query_parts($pdo);
    if ($parts === null) {
        return $series;
    }

    $start = $series[0]['date'] . ' 00:00:00';
    $dateExpr = 'DATE(' . $parts['date_expr'] . ')';
    try {
        $stmt = $pdo->prepare("
            SELECT {$dateExpr} AS d, COUNT(DISTINCT c.patient_id) AS cnt
            FROM consultations c
            {$parts['join_sql']}
            WHERE {$dateExpr} >= ?
              AND {$parts['where_sql']}
            GROUP BY {$dateExpr}
            ORDER BY d ASC
        ");
        $stmt->execute([$start]);
        $series = admin_chart_merge_daily_counts($series, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {}

    return $series;
}

/**
 * Monthly count of distinct patients who consulted (year trend).
 *
 * @return list<array{date:string,label:string,count:int,is_today:bool}>
 */
function admin_chart_consultations_monthly(PDO $pdo, int $months = 12): array
{
    $months = max(1, min(24, $months));
    $series = [];
    $counts = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $ts = strtotime(date('Y-m-01') . " -{$i} months");
        $key = date('Y-m', $ts);
        $series[] = [
            'date'     => $key . '-01',
            'label'    => date('M Y', $ts),
            'count'    => 0,
            'is_today' => $i === 0,
        ];
        $counts[$key] = 0;
    }

    $parts = admin_chart_consulted_query_parts($pdo);
    if ($parts === null) {
        return $series;
    }

    $start = $series[0]['date'] . ' 00:00:00';
    $monthExpr = "DATE_FORMAT({$parts['date_expr']}, '%Y-%m')";
    try {
        $stmt = $pdo->prepare("
            SELECT {$monthExpr} AS ym, COUNT(DISTINCT c.patient_id) AS cnt
            FROM consultations c
            {$parts['join_sql']}
            WHERE {$parts['date_expr']} >= ?
              AND {$parts['where_sql']}
            GROUP BY {$monthExpr}
        ");
        $stmt->execute([$start]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ym = (string) ($row['ym'] ?? '');
            if (array_key_exists($ym, $counts)) {
                $counts[$ym] = (int) ($row['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {}

    foreach ($series as &$point) {
        $ym = substr((string) $point['date'], 0, 7);
        $point['count'] = (int) ($counts[$ym] ?? 0);
    }
    unset($point);

    return $series;
}

/**
 * SQL predicate: user row is not archived (NULL/blank account_status still counts).
 * Optional table alias, e.g. 'u' → "u.account_status ...".
 */
function admin_chart_users_not_archived_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "({$prefix}account_status IS NULL OR TRIM({$prefix}account_status) = '' OR LOWER(TRIM({$prefix}account_status)) <> 'archived')";
}

/**
 * Optional archived_at IS NULL when the column exists.
 */
function admin_chart_users_not_soft_deleted_sql(PDO $pdo, string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $parts = [admin_chart_users_not_archived_sql($alias)];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('archived_at', $cols, true)) {
            $parts[] = "{$prefix}archived_at IS NULL";
        }
    } catch (Throwable $e) {
        // keep account_status predicate only
    }
    return implode(' AND ', $parts);
}

/** @return list<array{date:string,label:string,count:int,is_today:bool}> */
function admin_chart_registrations_daily(PDO $pdo, int $days = 7): array
{
    $days = admin_chart_normalize_period_days($days);
    // Year view: monthly buckets keep the axis readable while staying live from DB.
    if ($days >= 365) {
        return admin_chart_registrations_monthly($pdo, 12);
    }

    $series = admin_chart_last_n_days($days);
    $start = $series[0]['date'] . ' 00:00:00';
    $alive = admin_chart_users_not_soft_deleted_sql($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) AS d, COUNT(*) AS cnt
            FROM users
            WHERE created_at >= ?
              AND {$alive}
            GROUP BY DATE(created_at)
        ");
        $stmt->execute([$start]);
        $series = admin_chart_merge_daily_counts($series, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {}

    return $series;
}

/**
 * Monthly registration totals for year trend cards.
 *
 * @return list<array{date:string,label:string,count:int,is_today:bool}>
 */
function admin_chart_registrations_monthly(PDO $pdo, int $months = 6): array
{
    $months = max(1, min(24, $months));
    $series = [];
    $counts = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $ts = strtotime(date('Y-m-01') . " -{$i} months");
        $key = date('Y-m', $ts);
        $series[] = [
            'date'     => $key . '-01',
            'label'    => date('M Y', $ts),
            'count'    => 0,
            'is_today' => $i === 0,
        ];
        $counts[$key] = 0;
    }

    $start = $series[0]['date'] . ' 00:00:00';
    $alive = admin_chart_users_not_soft_deleted_sql($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
            FROM users
            WHERE created_at >= ?
              AND {$alive}
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ");
        $stmt->execute([$start]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ym = (string) ($row['ym'] ?? '');
            if (array_key_exists($ym, $counts)) {
                $counts[$ym] = (int) ($row['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {}

    foreach ($series as &$point) {
        $ym = substr((string) $point['date'], 0, 7);
        $point['count'] = (int) ($counts[$ym] ?? 0);
    }
    unset($point);

    return $series;
}

/**
 * Live new-user registrations by role for the selected period (parameterized bounds).
 *
 * @param array{from:string,to:string,bucket:string} $period
 * @return array{
 *   series:list<array{date:string,label:string,is_today:bool,counts:array<string,int>,total:int}>,
 *   roles:list<array{role:string,label:string,color:string,total:int}>,
 *   totals:array<string,int>,
 *   total:int,
 *   peak:int
 * }
 */
function admin_chart_registrations_by_role(PDO $pdo, array $period): array
{
    $tracked = admin_chart_tracked_roles();
    $roleKeys = array_column($tracked, 'role');
    $axis = admin_chart_build_axis(
        (string) ($period['from'] ?? ''),
        (string) ($period['to'] ?? ''),
        (string) ($period['bucket'] ?? 'day')
    );

    $series = [];
    $matrix = [];
    foreach ($axis as $point) {
        $key = (string) $point['bucket_key'];
        $zeros = array_fill_keys($roleKeys, 0);
        $matrix[$key] = $zeros;
        $series[] = [
            'date' => (string) $point['date'],
            'label' => (string) $point['label'],
            'is_today' => (bool) $point['is_today'],
            'counts' => $zeros,
            'total' => 0,
        ];
    }

    $totals = array_fill_keys($roleKeys, 0);
    $peak = 0;

    if ($series === []) {
        return [
            'series' => [],
            'roles' => array_map(static function (array $r) use ($totals): array {
                return [
                    'role' => $r['role'],
                    'label' => $r['label'],
                    'color' => $r['color'],
                    'total' => (int) ($totals[$r['role']] ?? 0),
                ];
            }, $tracked),
            'totals' => $totals,
            'total' => 0,
            'peak' => 0,
        ];
    }

    $from = (string) $period['from'];
    $to = (string) $period['to'];
    $startBound = $from . ' 00:00:00';
    $endExclusive = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    $alive = admin_chart_users_not_soft_deleted_sql($pdo);
    $bucket = ((string) ($period['bucket'] ?? 'day')) === 'month' ? 'month' : 'day';
    $bucketExpr = $bucket === 'month'
        ? "DATE_FORMAT(created_at, '%Y-%m')"
        : 'DATE(created_at)';
    $roleExpr = "CASE
            WHEN LOWER(TRIM(COALESCE(role,''))) IN ('admin','superadmin') THEN 'admin'
            ELSE LOWER(TRIM(COALESCE(role,'')))
        END";

    try {
        $stmt = $pdo->prepare("
            SELECT {$bucketExpr} AS bucket_key, {$roleExpr} AS role_key, COUNT(*) AS cnt
            FROM users
            WHERE created_at >= ?
              AND created_at < ?
              AND {$alive}
              AND LOWER(TRIM(COALESCE(role,''))) IN ('patient','provider','bhw','admin','superadmin')
            GROUP BY bucket_key, role_key
        ");
        $stmt->execute([$startBound, $endExclusive]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bucketKey = (string) ($row['bucket_key'] ?? '');
            $roleKey = (string) ($row['role_key'] ?? '');
            if (!isset($matrix[$bucketKey]) || !array_key_exists($roleKey, $matrix[$bucketKey])) {
                continue;
            }
            $cnt = (int) ($row['cnt'] ?? 0);
            $matrix[$bucketKey][$roleKey] = $cnt;
            $totals[$roleKey] = (int) ($totals[$roleKey] ?? 0) + $cnt;
        }
    } catch (Throwable $e) {
        // Keep zero series rather than failing the dashboard.
    }

    foreach ($series as &$point) {
        $key = $bucket === 'month'
            ? substr((string) $point['date'], 0, 7)
            : (string) $point['date'];
        $counts = $matrix[$key] ?? array_fill_keys($roleKeys, 0);
        $point['counts'] = $counts;
        $pointTotal = (int) array_sum($counts);
        $point['total'] = $pointTotal;
        $rolePeak = $counts !== [] ? max(0, ...array_values($counts)) : 0;
        $peak = max($peak, $pointTotal, $rolePeak);
    }
    unset($point);

    $rolesOut = [];
    foreach ($tracked as $r) {
        $rolesOut[] = [
            'role' => $r['role'],
            'label' => $r['label'],
            'color' => $r['color'],
            'total' => (int) ($totals[$r['role']] ?? 0),
        ];
    }

    return [
        'series' => $series,
        'roles' => $rolesOut,
        'totals' => $totals,
        'total' => array_sum($totals),
        'peak' => $peak,
    ];
}

/**
 * Aggregate system services into Online / Maintenance / Issues for the status card.
 *
 * @return array{operational_pct:int,buckets:list<array{key:string,label:string,count:int,pct:int,color:string}>}
 */
function admin_chart_system_status_summary(PDO $pdo): array
{
    require_once __DIR__ . '/system_health_monitor.php';
    $snapshot = system_health_snapshot($pdo);
    $services = is_array($snapshot['services'] ?? null) ? $snapshot['services'] : [];

    $buckets = [
        'online' => ['key' => 'online', 'label' => 'Online', 'count' => 0, 'color' => '#16a34a'],
        'maintenance' => ['key' => 'maintenance', 'label' => 'Maintenance', 'count' => 0, 'color' => '#94a3b8'],
        'issues' => ['key' => 'issues', 'label' => 'Issues', 'count' => 0, 'color' => '#ef4444'],
    ];

    foreach ($services as $svc) {
        $status = strtolower(trim((string) ($svc['status'] ?? '')));
        if (in_array($status, ['online', 'healthy'], true)) {
            $buckets['online']['count']++;
        } elseif (in_array($status, ['warning', 'disabled', 'unknown'], true)) {
            $buckets['maintenance']['count']++;
        } else {
            $buckets['issues']['count']++;
        }
    }

    $total = max(1, array_sum(array_column(array_values($buckets), 'count')));
    $out = [];
    foreach ($buckets as $b) {
        $b['pct'] = (int) round(($b['count'] / $total) * 100);
        $out[] = $b;
    }

    $operationalPct = (int) round(($buckets['online']['count'] / $total) * 100);

    return [
        'operational_pct' => $operationalPct,
        'buckets' => $out,
        'overall_status' => (string) ($snapshot['overall_status'] ?? 'healthy'),
    ];
}

/** @return list<array{date:string,label:string,count:int,is_today:bool}> */
function admin_chart_triage_daily(PDO $pdo, int $days = 30): array
{
    $series = admin_chart_last_n_days($days);
    if (!admin_chart_table_exists($pdo, 'triage_results')) {
        return $series;
    }

    $start = $series[0]['date'] . ' 00:00:00';
    $dateCol = 'assessed_at';
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM triage_results')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('assessed_at', $cols, true) && in_array('created_at', $cols, true)) {
            $dateCol = 'created_at';
        }
        $stmt = $pdo->prepare("
            SELECT DATE({$dateCol}) AS d, COUNT(*) AS cnt
            FROM triage_results
            WHERE {$dateCol} >= ?
            GROUP BY DATE({$dateCol})
        ");
        $stmt->execute([$start]);
        $series = admin_chart_merge_daily_counts($series, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {}

    return $series;
}

/**
 * @return array<string, mixed>
 */
function admin_dashboard_chart_payload(PDO $pdo, int $days = 30, string $from = '', string $to = ''): array
{
    $period = admin_chart_resolve_period($days, $from, $to);
    $presetDays = $period['days'] ?? admin_chart_normalize_period_days($days);
    if ($presetDays === null) {
        // Custom range: keep companion series on a safe daily window for legacy consumers.
        $span = (int) (new DateTimeImmutable($period['from']))
            ->diff(new DateTimeImmutable($period['to']))
            ->days + 1;
        $presetDays = $span <= 1 ? 1 : ($span <= 7 ? 7 : ($span <= 31 ? 30 : 365));
        $presetDays = admin_chart_normalize_period_days($presetDays);
    }

    $companionDays = $presetDays > 30 ? 30 : $presetDays;
    $consultations = admin_chart_consultations_daily($pdo, $presetDays);
    $registrations = admin_chart_registrations_daily($pdo, $presetDays);
    $registrationsByRole = admin_chart_registrations_by_role($pdo, $period);
    $triage        = admin_chart_triage_daily($pdo, $companionDays);
    $roles         = admin_chart_user_roles($pdo);
    $status        = admin_chart_consult_status($pdo);
    $systemStatus  = admin_chart_system_status_summary($pdo);

    $roleMap = [];
    foreach ($roles as $r) {
        $roleMap[(string) ($r['role'] ?? '')] = (int) ($r['count'] ?? 0);
    }
    $adminTotal = (int) ($roleMap['admin'] ?? 0) + (int) ($roleMap['superadmin'] ?? 0);
    $overview = [
        'total_users' => array_sum($roleMap),
        'patients' => (int) ($roleMap['patient'] ?? 0),
        'doctors' => (int) ($roleMap['provider'] ?? 0),
        'bhw' => (int) ($roleMap['bhw'] ?? 0),
        'administrators' => $adminTotal,
    ];

    // New accounts in the selected period (updates when filters change).
    $periodTotals = $registrationsByRole['totals'] ?? [];
    $periodOverview = [
        'total_users' => (int) ($registrationsByRole['total'] ?? 0),
        'patients' => (int) ($periodTotals['patient'] ?? 0),
        'doctors' => (int) ($periodTotals['provider'] ?? 0),
        'bhw' => (int) ($periodTotals['bhw'] ?? 0),
        'administrators' => (int) ($periodTotals['admin'] ?? 0),
    ];

    // Distribution card matches overview roles (Administrators = admin + superadmin).
    $distribution = [];
    foreach ($roles as $r) {
        $role = (string) ($r['role'] ?? '');
        if ($role === 'superadmin') {
            continue;
        }
        if ($role === 'admin') {
            $distribution[] = [
                'role' => 'admin',
                'label' => 'Administrators',
                'count' => $adminTotal,
                'color' => '#f59e0b',
            ];
            continue;
        }
        $distribution[] = $r;
    }

    $consultPeak = array_column($consultations, 'count');
    $regPeak = array_column($registrations, 'count');
    $triagePeak = array_column($triage, 'count');

    return [
        'generated_at' => date('c'),
        'days'         => $period['days'],
        'mode'         => $period['mode'],
        'from'         => $period['from'],
        'to'           => $period['to'],
        'bucket'       => $period['bucket'],
        'period_label' => $period['period_label'],
        'period_range_label' => $period['period_range_label'],
        'consultations' => [
            'series' => $consultations,
            'total'  => admin_chart_series_total($consultations),
            'peak'   => $consultPeak !== [] ? max(0, ...$consultPeak) : 0,
        ],
        'registrations' => [
            'series' => $registrations,
            'total'  => admin_chart_series_total($registrations),
            'peak'   => $regPeak !== [] ? max(0, ...$regPeak) : 0,
        ],
        'registrations_by_role' => $registrationsByRole,
        'triage' => [
            'series' => $triage,
            'total'  => admin_chart_series_total($triage),
            'peak'   => $triagePeak !== [] ? max(0, ...$triagePeak) : 0,
        ],
        'roles'  => $roles,
        'distribution' => $distribution,
        'overview' => $overview,
        'period_overview' => $periodOverview,
        'status' => $status,
        'system_status' => $systemStatus,
    ];
}

/**
 * Live user counts by role for Admin + Super Admin User Distribution chart.
 * Always returns every tracked role (including BHW) so the chart never omits a category.
 *
 * Source of truth: users.id + users.role (lowercase role key 'bhw').
 * Counts every matching account once across ALL barangays — no barangay/admin/session filter,
 * no LIMIT 1, no JOINs that could drop or duplicate rows.
 *
 * @return list<array{role:string,label:string,count:int,color:string}>
 */
function admin_chart_user_roles(PDO $pdo): array
{
    $order = ['patient', 'provider', 'bhw', 'admin', 'superadmin'];
    $palette = [
        'patient'    => '#0d9488',
        'provider'   => '#2563eb',
        'bhw'        => '#0284c7',
        'admin'      => '#0f766e',
        'superadmin' => '#b45309',
    ];
    $labels = [
        'patient'    => 'Patients',
        'provider'   => 'Doctors',
        'bhw'        => 'BHW',
        'admin'      => 'Administrators',
        'superadmin' => 'Super Admins',
    ];

    $counts = admin_chart_role_counts_map($pdo);

    $out = [];
    foreach ($order as $role) {
        $out[] = [
            'role'  => $role,
            'label' => $labels[$role],
            'count' => (int) ($counts[$role] ?? 0),
            'color' => $palette[$role],
        ];
    }

    return $out;
}

/**
 * Distinct user counts keyed by canonical role.
 * BHW uses role value 'bhw' in the users table (not applications / invites).
 *
 * @return array<string, int>
 */
function admin_chart_role_counts_map(PDO $pdo): array
{
    $order = ['patient', 'provider', 'bhw', 'admin', 'superadmin'];
    $counts = array_fill_keys($order, 0);

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $hasArchivedAt = in_array('archived_at', $cols, true);
        $hasAccountStatus = in_array('account_status', $cols, true);

        // Source: users accounts only. No barangay / session / LIMIT / JOIN filters.
        // Exclude only explicitly archived accounts. NULL/blank account_status must still count
        // (SQL `<> 'archived'` alone would drop NULL rows and under-count patients/BHW).
        $where = [
            "LOWER(TRIM(COALESCE(role, ''))) IN ('patient','provider','bhw','admin','superadmin')",
        ];
        if ($hasArchivedAt) {
            $where[] = 'archived_at IS NULL';
        }
        if ($hasAccountStatus) {
            $where[] = admin_chart_users_not_archived_sql('');
        }

        $sql = '
            SELECT LOWER(TRIM(role)) AS role_key, COUNT(DISTINCT id) AS cnt
            FROM users
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY LOWER(TRIM(role))
        ';
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $role = (string) ($row['role_key'] ?? '');
            if (array_key_exists($role, $counts)) {
                $counts[$role] = (int) ($row['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // Fall through with zeros rather than a wrong singleton count.
    }

    return $counts;
}

/** @return list<array{label:string,count:int,color:string}> */
function admin_chart_consult_status(PDO $pdo): array
{
    $colors = [
        'completed'        => '#16a34a',
        'in_consultation'  => '#2563eb',
        'scheduled'        => '#0d9488',
        'waiting'          => '#d97706',
        'cancelled'        => '#94a3b8',
    ];

    if (!admin_chart_table_exists($pdo, 'consultations')) {
        return [];
    }

    $out = [];
    try {
        $stmt = $pdo->query("
            SELECT status, COUNT(*) AS cnt
            FROM consultations
            GROUP BY status
            ORDER BY cnt DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $status = (string) $row['status'];
            $out[] = [
                'label' => ucwords(str_replace('_', ' ', $status)),
                'count' => (int) $row['cnt'],
                'color' => $colors[$status] ?? '#64748b',
            ];
        }
    } catch (Throwable $e) {}

    return $out;
}

/**
 * Build SVG polyline points for a line chart (viewBox 0 0 100 40).
 *
 * @param list<array{count:int}> $series
 * @return array{points:string,coords:list<array{x:float,y:float,val:int}>}
 */
function admin_chart_line_points(array $series): array
{
    $n = count($series);
    if ($n === 0) {
        return ['points' => '', 'coords' => []];
    }

    $max = max(1, ...array_column($series, 'count'));
    $coords = [];
    foreach ($series as $i => $point) {
        $x = $n === 1 ? 50 : ($i / ($n - 1)) * 100;
        $y = 38 - (($point['count'] / $max) * 34);
        $coords[] = ['x' => $x, 'y' => $y, 'val' => (int) $point['count']];
    }

    $points = implode(' ', array_map(fn($c) => round($c['x'], 2) . ',' . round($c['y'], 2), $coords));

    return ['points' => $points, 'coords' => $coords];
}

function admin_chart_series_total(array $series): int
{
    return array_sum(array_column($series, 'count'));
}

function admin_chart_series_max(array $series): int
{
    $counts = array_column($series, 'count');
    return max(1, ...($counts ?: [1]));
}
