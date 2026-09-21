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

/** Allowed Analytics period lengths: Today, Week, Month, 6 Months, Year. */
function admin_chart_normalize_period_days(int $days): int
{
    return match ($days) {
        1, 7, 30, 180, 365 => $days,
        default => 180,
    };
}

function admin_chart_period_label(int $days): string
{
    return match (admin_chart_normalize_period_days($days)) {
        1 => 'Today',
        7 => 'Week',
        30 => 'Month',
        180 => 'Last 6 Months',
        365 => 'Year',
        default => 'Last 6 Months',
    };
}

function admin_chart_period_range_label(int $days): string
{
    return match (admin_chart_normalize_period_days($days)) {
        1 => 'today',
        7 => 'this week',
        30 => 'this month',
        180 => 'the last 6 months',
        365 => 'this year',
        default => 'the last 6 months',
    };
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

/** @return list<array{date:string,label:string,count:int,is_today:bool}> */
function admin_chart_consultations_daily(PDO $pdo, int $days = 30): array
{
    $series = admin_chart_last_n_days($days);
    if (!admin_chart_table_exists($pdo, 'consultations')) {
        return $series;
    }

    $start = $series[0]['date'];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
        $hasCreated = in_array('created_at', $cols, true);
        $dateExpr = $hasCreated
            ? 'DATE(COALESCE(consult_date, created_at))'
            : 'DATE(consult_date)';
        $stmt = $pdo->prepare("
            SELECT {$dateExpr} AS d, COUNT(*) AS cnt
            FROM consultations
            WHERE {$dateExpr} >= ?
            GROUP BY {$dateExpr}
            ORDER BY d ASC
        ");
        $stmt->execute([$start]);
        $series = admin_chart_merge_daily_counts($series, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {}

    return $series;
}

/** @return list<array{date:string,label:string,count:int,is_today:bool}> */
function admin_chart_registrations_daily(PDO $pdo, int $days = 7): array
{
    $days = admin_chart_normalize_period_days($days);
    if ($days >= 180) {
        return admin_chart_registrations_monthly($pdo, $days === 365 ? 12 : 6);
    }

    $series = admin_chart_last_n_days($days);
    $start = $series[0]['date'] . ' 00:00:00';

    try {
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) AS d, COUNT(*) AS cnt
            FROM users
            WHERE created_at >= ?
            GROUP BY DATE(created_at)
        ");
        $stmt->execute([$start]);
        $series = admin_chart_merge_daily_counts($series, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {}

    return $series;
}

/**
 * Monthly registration totals for trend cards (e.g. Last 6 Months).
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
    try {
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
            FROM users
            WHERE created_at >= ?
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

/** @return array<string, mixed> */
function admin_dashboard_chart_payload(PDO $pdo, int $days = 180): array
{
    $days = admin_chart_normalize_period_days($days);
    $consultations = admin_chart_consultations_daily($pdo, $days === 180 ? 30 : $days);
    $registrations = admin_chart_registrations_daily($pdo, $days);
    $triage        = admin_chart_triage_daily($pdo, $days === 180 ? 30 : $days);
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
        'doctors' => (int) ($roleMap['provider'] ?? 0),
        'bhw' => (int) ($roleMap['bhw'] ?? 0),
        'administrators' => $adminTotal,
    ];

    // Distribution card matches the 4-role overview (Administrators = admin + superadmin).
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
        'days'         => $days,
        'period_label' => admin_chart_period_label($days),
        'period_range_label' => admin_chart_period_range_label($days),
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
        'triage' => [
            'series' => $triage,
            'total'  => admin_chart_series_total($triage),
            'peak'   => $triagePeak !== [] ? max(0, ...$triagePeak) : 0,
        ],
        'roles'  => $roles,
        'distribution' => $distribution,
        'overview' => $overview,
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
        // (SQL `<> 'archived'` alone would drop NULL rows and under-count BHW).
        $where = [
            "LOWER(TRIM(COALESCE(role, ''))) IN ('patient','provider','bhw','admin','superadmin')",
        ];
        if ($hasArchivedAt) {
            $where[] = 'archived_at IS NULL';
        }
        if ($hasAccountStatus) {
            $where[] = "(account_status IS NULL OR TRIM(account_status) = '' OR LOWER(TRIM(account_status)) <> 'archived')";
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
