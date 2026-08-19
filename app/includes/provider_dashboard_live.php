<?php
/**
 * Provider dashboard live payload (stats, chart, queue, activity).
 */

declare(strict_types=1);

require_once __DIR__ . '/provider_activity.php';
require_once __DIR__ . '/provider_triage_cases.php';

function provider_parse_dashboard_period(string $input): string
{
    $allowed = ['today', 'week', 'month', 'year'];
    $period = strtolower(trim($input));

    return in_array($period, $allowed, true) ? $period : 'week';
}

function provider_dashboard_period_label(string $period): string
{
    return match ($period) {
        'today' => 'Today',
        'month' => 'This month',
        'year'  => 'This year',
        default => 'This week',
    };
}

function provider_dashboard_total_label(string $period): string
{
    return match ($period) {
        'today' => 'today',
        'month' => 'this month',
        'year'  => 'this year',
        default => 'this week',
    };
}

function provider_count_consultations_on_date(PDO $pdo, int $providerId, string $date): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM consultations WHERE provider_id = ? AND consult_date = ?');
        $stmt->execute([$providerId, $date]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{
 *   period: string,
 *   period_label: string,
 *   total_label: string,
 *   series: list<array{label:string,date:string,count:int,is_today:bool}>,
 *   total: int
 * }
 */
function provider_dashboard_consultation_chart(PDO $pdo, int $providerId, string $period): array
{
    $period = provider_parse_dashboard_period($period);
    $today = date('Y-m-d');
    $series = [];

    switch ($period) {
        case 'today':
            $series[] = [
                'label'    => 'Today',
                'date'     => date('M j'),
                'count'    => provider_count_consultations_on_date($pdo, $providerId, $today),
                'is_today' => true,
            ];
            break;

        case 'month':
            $monthStart = date('Y-m-01');
            $monthCounts = [];
            try {
                $stmt = $pdo->prepare('
                    SELECT consult_date, COUNT(*) AS cnt
                    FROM consultations
                    WHERE provider_id = ? AND consult_date >= ? AND consult_date <= ?
                    GROUP BY consult_date
                ');
                $stmt->execute([$providerId, $monthStart, $today]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $monthCounts[$row['consult_date']] = (int) $row['cnt'];
                }
            } catch (Throwable $e) {}
            $cursor = $monthStart;
            $dayCount = (int) date('t');
            while ($cursor <= $today) {
                $series[] = [
                    'label'    => $dayCount > 14 ? date('M j', strtotime($cursor)) : date('D', strtotime($cursor)),
                    'date'     => date('M j', strtotime($cursor)),
                    'count'    => $monthCounts[$cursor] ?? 0,
                    'is_today' => $cursor === $today,
                ];
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            }
            break;

        case 'year':
            $year = (int) date('Y');
            $currentMonth = (int) date('n');
            for ($month = 1; $month <= $currentMonth; $month++) {
                $monthStart = sprintf('%04d-%02d-01', $year, $month);
                $monthEnd = date('Y-m-t', strtotime($monthStart));
                if ($monthEnd > $today) {
                    $monthEnd = $today;
                }
                $count = 0;
                try {
                    $stmt = $pdo->prepare('
                        SELECT COUNT(*)
                        FROM consultations
                        WHERE provider_id = ? AND consult_date >= ? AND consult_date <= ?
                    ');
                    $stmt->execute([$providerId, $monthStart, $monthEnd]);
                    $count = (int) $stmt->fetchColumn();
                } catch (Throwable $e) {
                    $count = 0;
                }
                $series[] = [
                    'label'    => date('M', strtotime($monthStart)),
                    'date'     => date('M Y', strtotime($monthStart)),
                    'count'    => $count,
                    'is_today' => $month === $currentMonth,
                ];
            }
            break;

        case 'week':
        default:
            $weekStart = date('Y-m-d', strtotime('-6 days'));
            $weekCounts = [];
            try {
                $stmt = $pdo->prepare('
                    SELECT consult_date, COUNT(*) AS cnt
                    FROM consultations
                    WHERE provider_id = ? AND consult_date >= ? AND consult_date <= ?
                    GROUP BY consult_date
                ');
                $stmt->execute([$providerId, $weekStart, $today]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $weekCounts[$row['consult_date']] = (int) $row['cnt'];
                }
            } catch (Throwable $e) {}
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $series[] = [
                    'label'    => date('D', strtotime($date)),
                    'date'     => date('M j', strtotime($date)),
                    'count'    => $weekCounts[$date] ?? 0,
                    'is_today' => ($i === 0),
                ];
            }
            break;
    }

    return [
        'period'       => $period,
        'period_label' => provider_dashboard_period_label($period),
        'total_label'  => provider_dashboard_total_label($period),
        'series'       => $series,
        'total'        => array_sum(array_column($series, 'count')),
    ];
}

/**
 * @return array{
 *   stats: array<string,int>,
 *   chart_period: string,
 *   chart_period_label: string,
 *   chart_total_label: string,
 *   week_chart: list<array{label:string,date:string,count:int,is_today:bool}>,
 *   week_total: int,
 *   queue: list<array<string,mixed>>,
 *   activity: list<array{msg:string,time:string,icon:string}>
 * }
 */
function provider_dashboard_live_payload(PDO $pdo, int $providerId, string $period = 'week'): array
{
    $stats = [
        'appointments' => 0,
        'pending'      => 0,
        'urgent'       => 0,
        'ongoing'      => 0,
        'completed'    => 0,
        'missed'       => 0,
        'slot_waiting' => 0,
    ];

    try {
        $s = $pdo->prepare("
            SELECT COUNT(*)
            FROM appointment_slots
            WHERE provider_id = ? AND slot_date = CURDATE() AND status = 'booked'
        ");
        $s->execute([$providerId]);
        $stats['appointments'] = (int) $s->fetchColumn();

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

        $s = $pdo->prepare("
            SELECT COUNT(*)
            FROM consultations
            WHERE provider_id = ? AND consult_date = CURDATE() AND status = 'in_consultation'
        ");
        $s->execute([$providerId]);
        $stats['ongoing'] = (int) $s->fetchColumn();

        $s = $pdo->prepare("
            SELECT COUNT(*)
            FROM consultations
            WHERE provider_id = ? AND status = 'completed'
              AND MONTH(consult_date) = MONTH(CURDATE())
              AND YEAR(consult_date) = YEAR(CURDATE())
        ");
        $s->execute([$providerId]);
        $stats['completed'] = (int) $s->fetchColumn();

        require_once __DIR__ . '/patient_slot_waitlist.php';
        $stats['slot_waiting'] = patient_slot_waitlist_count_for_provider($pdo, $providerId);
    } catch (Throwable $e) {
        // keep zeros
    }

    $chart = provider_dashboard_consultation_chart($pdo, $providerId, $period);
    $weekChart = $chart['series'];
    $weekTotal = $chart['total'];

    $queue = [];
    try {
        $qStmt = $pdo->prepare("
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
        $qStmt->execute([$providerId]);

        $helpersPath = dirname(__DIR__, 2) . '/resources/views/provider/partials/queue_helpers.php';
        if (is_file($helpersPath)) {
            require_once $helpersPath;
        }

        while ($row = $qStmt->fetch(PDO::FETCH_ASSOC)) {
            $item = [
                'id'           => (int) $row['id'],
                'patient_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'complaint'    => $row['chief_complaint'] ?: $row['complaint'],
                'urgency'      => (string) ($row['urgency_label'] ?? 'Not triaged'),
                'status'       => $row['status'] === 'in_consultation' ? 'In Consultation' : 'Waiting',
                'raw_status'   => (string) $row['status'],
                'date'         => (string) ($row['consult_date'] ?? ''),
                'time'         => (string) ($row['consult_time'] ?? ''),
                'slot_date'    => (string) ($row['slot_date'] ?? ''),
                'slot_start'   => (string) ($row['slot_start'] ?? ''),
            ];

            $access = ['allowed' => true, 'reason' => ''];
            if (function_exists('queue_session_access')) {
                $access = queue_session_access([
                    'status'       => $item['raw_status'],
                    'consult_date' => $item['date'],
                    'consult_time' => $item['time'],
                    'slot_date'    => $item['slot_date'],
                    'slot_start'   => $item['slot_start'],
                ]);
            }

            $item['session_allowed'] = !empty($access['allowed']);
            $item['session_reason']  = (string) ($access['reason'] ?? '');
            $queue[] = $item;
        }
    } catch (Throwable $e) {
        $queue = [];
    }

    $activity = [];
    try {
        $activity = provider_load_recent_activity($pdo, $providerId, 8);
    } catch (Throwable $e) {
        $activity = [];
    }

    return [
        'stats'              => $stats,
        'chart_period'       => $chart['period'],
        'chart_period_label' => $chart['period_label'],
        'chart_total_label'  => $chart['total_label'],
        'week_chart'         => $weekChart,
        'week_total'         => $weekTotal,
        'queue'              => $queue,
        'activity'           => $activity,
        'updated_at'         => date('c'),
    ];
}
