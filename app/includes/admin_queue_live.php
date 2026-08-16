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
        SELECT c.id, c.status, c.consult_time,
               CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
               CONCAT(pr.first_name, ' ', pr.last_name) AS provider_name
        FROM consultations c
        JOIN users p ON p.id = c.patient_id
        LEFT JOIN users pr ON pr.id = c.provider_id
        WHERE c.consult_date = ?
          AND c.status IN ('scheduled', 'in_consultation', 'completed')
        ORDER BY FIELD(c.status, 'in_consultation', 'scheduled', 'completed'), c.consult_time ASC
        LIMIT 100
    ");
    $stmt->execute([$today]);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($raw as $q) {
        $time = (string) ($q['consult_time'] ?? '');
        $label = $time;
        $ts = strtotime($time);
        if ($ts) {
            $label = date('g:i A', $ts);
        }
        $rows[] = [
            'id' => (int) ($q['id'] ?? 0),
            'status' => (string) ($q['status'] ?? ''),
            'time_label' => $label,
            'patient_name' => (string) ($q['patient_name'] ?? ''),
            'provider_name' => (string) ($q['provider_name'] ?? 'Unassigned'),
        ];
    }

    return admin_queue_live_wrap($today, $waiting, $active, $completed, $rows);
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
