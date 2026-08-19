<?php
/**
 * Admin / Super Admin queue monitoring live payload (today, Asia/Manila).
 */

declare(strict_types=1);

require_once __DIR__ . '/appointment_slots.php';

/**
 * @return array<string, mixed>
 */
function admin_queue_live_payload(PDO $pdo): array
{
    $today = appointment_now()->format('Y-m-d');
    $waiting = 0;
    $active = 0;
    $completed = 0;
    $rows = [];

    if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount() === 0) {
        return admin_queue_live_wrap($today, $waiting, $active, $completed, $rows);
    }

    $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasPriority = in_array('consult_priority', $consultCols, true);
    $hasCreated = in_array('created_at', $consultCols, true);
    $prioritySelect = $hasPriority ? 'c.consult_priority' : 'NULL AS consult_priority';
    $createdSelect = $hasCreated ? 'c.created_at' : 'NULL AS created_at';

    $hasTriage = $pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount() > 0;
    $triageSelect = $hasTriage ? 'tr.urgency_label, tr.level AS triage_level' : 'NULL AS urgency_label, NULL AS triage_level';
    $triageJoin = '';
    if ($hasTriage) {
        $triageJoin = "
        LEFT JOIN (
            SELECT t1.patient_id, t1.urgency_label, t1.level
            FROM triage_results t1
            INNER JOIN (
                SELECT patient_id, MAX(assessed_at) AS latest_at
                FROM triage_results
                GROUP BY patient_id
            ) t2 ON t2.patient_id = t1.patient_id AND t2.latest_at = t1.assessed_at
        ) tr ON tr.patient_id = c.patient_id
        ";
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM consultations
        WHERE status = 'scheduled' AND consult_date = ?
    ");
    $stmt->execute([$today]);
    $waiting = (int) $stmt->fetchColumn();

    $active = (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE status = 'in_consultation'")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM consultations
        WHERE status = 'completed' AND consult_date = ?
    ");
    $stmt->execute([$today]);
    $completed = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.id, c.status, c.consult_time, c.consult_date,
               {$prioritySelect}, {$createdSelect},
               {$triageSelect},
               CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
               CONCAT(pr.first_name, ' ', pr.last_name) AS provider_name
        FROM consultations c
        JOIN users p ON p.id = c.patient_id
        LEFT JOIN users pr ON pr.id = c.provider_id
        {$triageJoin}
        WHERE c.consult_date = ?
          AND c.status IN ('scheduled', 'in_consultation', 'completed')
        ORDER BY FIELD(c.status, 'in_consultation', 'scheduled', 'completed'), c.consult_time ASC
        LIMIT 100
    ");
    $stmt->execute([$today]);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $queuePosition = 0;
    foreach ($raw as $q) {
        $status = (string) ($q['status'] ?? '');
        $time = (string) ($q['consult_time'] ?? '');
        $label = $time;
        $ts = strtotime($time);
        if ($ts) {
            $label = date('g:i A', $ts);
        }

        $position = '—';
        if ($status === 'scheduled') {
            $queuePosition++;
            $position = (string) $queuePosition;
        }

        $rows[] = [
            'id' => (int) ($q['id'] ?? 0),
            'status' => $status,
            'time_label' => $label,
            'patient_name' => (string) ($q['patient_name'] ?? ''),
            'provider_name' => (string) ($q['provider_name'] ?? 'Unassigned'),
            'priority_label' => admin_queue_priority_label(
                isset($q['consult_priority']) ? (string) $q['consult_priority'] : null,
                isset($q['urgency_label']) ? (string) $q['urgency_label'] : null,
                isset($q['triage_level']) ? (string) $q['triage_level'] : null
            ),
            'queue_position' => $position,
            'waiting_label' => admin_queue_waiting_label(
                $status,
                (string) ($q['consult_date'] ?? $today),
                $time,
                isset($q['created_at']) ? (string) $q['created_at'] : null
            ),
        ];
    }

    return admin_queue_live_wrap($today, $waiting, $active, $completed, $rows);
}

function admin_queue_priority_label(?string $consultPriority, ?string $urgencyLabel, ?string $triageLevel = null): string
{
    $urgency = trim((string) $urgencyLabel);
    if ($urgency !== '') {
        return $urgency;
    }

    $level = strtolower(trim((string) $triageLevel));
    if (in_array($level, ['1', 'emergency'], true)) {
        return 'Emergency';
    }
    if (in_array($level, ['2', 'high', 'urgent'], true)) {
        return 'Urgent';
    }

    $priority = strtolower(trim((string) $consultPriority));
    return match ($priority) {
        'emergency' => 'Emergency',
        'urgent' => 'Urgent',
        'standard' => 'Standard',
        '' => '—',
        default => ucfirst($priority),
    };
}

function admin_queue_waiting_label(string $status, string $consultDate, string $consultTime, ?string $createdAt): string
{
    if ($status === 'completed') {
        return '—';
    }

    $now = appointment_now();
    $anchor = null;
    $created = trim((string) $createdAt);
    if ($created !== '') {
        try {
            $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Manila');
            $anchor = new DateTimeImmutable($created, $tz);
        } catch (Throwable $e) {
            $anchor = null;
        }
    }
    if ($anchor === null) {
        $time = appointment_slot_normalize_time($consultTime) ?: '00:00:00';
        $anchor = appointment_slot_start_datetime($consultDate, $time);
    }

    $seconds = $now->getTimestamp() - $anchor->getTimestamp();
    if ($seconds < 0) {
        return 'Starts in ' . admin_queue_minutes_phrase((int) ceil(abs($seconds) / 60));
    }

    return admin_queue_minutes_phrase((int) floor($seconds / 60));
}

function admin_queue_minutes_phrase(int $minutes): string
{
    $minutes = max(0, $minutes);
    if ($minutes < 1) {
        return 'Just now';
    }
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $hours = intdiv($minutes, 60);
    $remain = $minutes % 60;
    if ($remain === 0) {
        return $hours . 'h';
    }
    return $hours . 'h ' . $remain . 'm';
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function admin_queue_live_wrap(string $today, int $waiting, int $active, int $completed, array $rows): array
{
    $fpParts = [$today, (string) $waiting, (string) $active, (string) $completed, (string) count($rows)];
    foreach ($rows as $row) {
        $fpParts[] = implode(':', [
            (string) ($row['id'] ?? 0),
            (string) ($row['status'] ?? ''),
            (string) ($row['time_label'] ?? ''),
            (string) ($row['queue_position'] ?? ''),
            (string) ($row['waiting_label'] ?? ''),
            (string) ($row['priority_label'] ?? ''),
            (string) ($row['provider_name'] ?? ''),
        ]);
    }

    return [
        'today' => $today,
        'waiting' => $waiting,
        'active' => $active,
        'completed' => $completed,
        'rows' => $rows,
        'fingerprint' => hash('sha256', implode('|', $fpParts)),
    ];
}
