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

/** Allowed Analytics period lengths: Today, Week, Month, Year. */
function admin_chart_normalize_period_days(int $days): int
{
    return match ($days) {
        1, 7, 30, 365 => $days,
        default => 30,
    };
}

function admin_chart_period_label(int $days): string
{
    return match (admin_chart_normalize_period_days($days)) {
        1 => 'Today',
        7 => 'Week',
        30 => 'Month',
        365 => 'Year',
        default => 'Month',
    };
}

function admin_chart_period_range_label(int $days): string
{
    return match (admin_chart_normalize_period_days($days)) {
        1 => 'today',
        7 => 'this week',
        30 => 'this month',
        365 => 'this year',
        default => 'this month',
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
function admin_dashboard_chart_payload(PDO $pdo, int $days = 30): array
{
    $days = admin_chart_normalize_period_days($days);
    $consultations = admin_chart_consultations_daily($pdo, $days);
    $registrations = admin_chart_registrations_daily($pdo, $days);
    $triage        = admin_chart_triage_daily($pdo, $days);
    $roles         = admin_chart_user_roles($pdo);
    $status        = admin_chart_consult_status($pdo);

    return [
        'generated_at' => date('c'),
        'days'         => $days,
        'period_label' => admin_chart_period_label($days),
        'period_range_label' => admin_chart_period_range_label($days),
        'consultations' => [
            'series' => $consultations,
            'total'  => admin_chart_series_total($consultations),
            'peak'   => max(0, ...array_column($consultations, 'count')),
        ],
        'registrations' => [
            'series' => $registrations,
            'total'  => admin_chart_series_total($registrations),
            'peak'   => max(0, ...array_column($registrations, 'count')),
        ],
        'triage' => [
            'series' => $triage,
            'total'  => admin_chart_series_total($triage),
            'peak'   => max(0, ...array_column($triage, 'count')),
        ],
        'roles'  => $roles,
        'status' => $status,
    ];
}

/**
 * Live user counts by role for Admin + Super Admin User Distribution chart.
 * Always returns every tracked role (including BHW) so the chart never omits a category.
 * Counts every matching users-table row once — no barangay filter, no demo values.
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

    $counts = array_fill_keys($order, 0);
    try {
        $stmt = $pdo->query("
            SELECT role, COUNT(*) AS cnt
            FROM users
            WHERE role IN ('patient','provider','bhw','admin','superadmin')
            GROUP BY role
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $role = (string) ($row['role'] ?? '');
            if (array_key_exists($role, $counts)) {
                $counts[$role] = (int) $row['cnt'];
            }
        }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($order as $role) {
        $out[] = [
            'role'  => $role,
            'label' => $labels[$role],
            'count' => $counts[$role],
            'color' => $palette[$role],
        ];
    }

    return $out;
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
