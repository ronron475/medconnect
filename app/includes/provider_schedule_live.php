<?php
/**
 * Provider Schedule & Availability — live today's-slot payload.
 */

declare(strict_types=1);

require_once __DIR__ . '/appointment_slots.php';
require_once __DIR__ . '/provider_schedule_sessions.php';

/**
 * @return list<array<string, mixed>>
 */
function provider_schedule_fetch_today_slot_rows(PDO $pdo, int $providerId, string $todayYmd): array
{
    $stmt = $pdo->prepare("
        SELECT s.id, s.start_time, s.end_time, s.status, s.consultation_id,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), '') AS patient_name,
               COALESCE(c.reschedule_status, 'none') AS reschedule_status,
               c.status AS consultation_status,
               r.reason AS reschedule_reason,
               r.old_time AS reschedule_old_time,
               r.new_time AS reschedule_new_time
        FROM appointment_slots s
        LEFT JOIN users u ON u.id = s.patient_id
        LEFT JOIN consultations c ON c.id = s.consultation_id
        LEFT JOIN appointment_reschedule_log r
            ON r.consultation_id = c.id
           AND r.status = 'pending_patient'
           AND r.id = (
               SELECT MAX(r2.id)
               FROM appointment_reschedule_log r2
               WHERE r2.consultation_id = c.id
                 AND r2.status = 'pending_patient'
           )
        WHERE s.provider_id = ? AND s.slot_date = ?
        ORDER BY s.start_time ASC
    ");
    $stmt->execute([$providerId, $todayYmd]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{available:int,booked:int,passed:int,completed:int,cancelled:int}
 */
function provider_schedule_count_today_slots(array $rows, string $nowHis): array
{
    $counts = ['available' => 0, 'booked' => 0, 'passed' => 0, 'completed' => 0, 'cancelled' => 0];
    foreach ($rows as $sl) {
        $st = strtolower((string) ($sl['status'] ?? ''));
        if (in_array($st, ['booked', 'blocked'], true)) {
            $counts['booked']++;
            continue;
        }
        if ($st === 'completed') {
            $counts['completed']++;
            continue;
        }
        if ($st === 'cancelled') {
            $counts['cancelled']++;
            continue;
        }
        if (in_array($st, ['expired'], true) || substr((string) ($sl['start_time'] ?? ''), 0, 8) <= $nowHis) {
            $counts['passed']++;
        } else {
            $counts['available']++;
        }
    }

    return $counts;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function provider_schedule_format_live_slot(array $row, string $todayYmd, string $nowHis): array
{
    $status = strtolower((string) ($row['status'] ?? 'available'));
    $isBooked = $status === 'booked' || $status === 'blocked';
    $isPast = $status === 'expired'
        || ($status === 'available' && substr((string) ($row['start_time'] ?? ''), 0, 8) <= $nowHis);
    $displayStatus = appointment_slot_display_status($status, $isPast);
    $cardClass = match (true) {
        $isBooked => 'is-booked',
        $status === 'completed' => 'is-completed',
        $status === 'cancelled' => 'is-cancelled',
        $isPast, $status === 'expired' => 'is-past',
        default => 'is-available',
    };
    $consultationId = (int) ($row['consultation_id'] ?? 0);
    $pendingReschedule = (string) ($row['reschedule_status'] ?? '') === 'pending_patient';
    $startTime = (string) ($row['start_time'] ?? '');
    $newTime = (string) ($row['reschedule_new_time'] ?? '');
    $oldTime = (string) ($row['reschedule_old_time'] ?? '');

    return [
        'id' => (int) ($row['id'] ?? 0),
        'start_time' => $startTime,
        'end_time' => (string) ($row['end_time'] ?? ''),
        'label' => $startTime !== '' ? date('g:i A', strtotime($startTime)) : '',
        'status' => $status,
        'display_status' => $displayStatus,
        'card_class' => $cardClass,
        'is_booked' => $isBooked,
        'is_past' => $isPast,
        'patient_name' => trim((string) ($row['patient_name'] ?? '')),
        'consultation_id' => $consultationId,
        'pending_reschedule' => $pendingReschedule,
        'reschedule_reason' => (string) ($row['reschedule_reason'] ?? ''),
        'reschedule_old_label' => $oldTime !== '' ? date('g:i A', strtotime($oldTime)) : '',
        'reschedule_new_label' => $newTime !== '' ? date('g:i A', strtotime($newTime)) : '',
        'can_remove' => $status === 'available' && !$isPast,
        'can_reschedule' => $isBooked && $consultationId > 0 && !$pendingReschedule,
        'slot_date' => $todayYmd,
    ];
}

/**
 * @return array<string, mixed>
 */
function provider_schedule_live_payload(PDO $pdo, int $providerId, bool $expirePassed = true): array
{
    appointment_schedule_ensure_schema($pdo);
    provider_schedule_ensure_schema($pdo);
    if ($expirePassed) {
        appointment_slots_expire_passed($pdo, $providerId);
    }

    $now = appointment_now();
    $todayName = $now->format('l');
    $todayYmd = $now->format('Y-m-d');
    $todayHis = $now->format('H:i:s');
    $todayLabel = $now->format('M j, Y');

    $grouped = provider_schedule_load_grouped($pdo, $providerId);
    $todaySessions = $grouped[$todayName] ?? [];
    $isActive = provider_schedule_day_is_active($todaySessions);
    $rows = provider_schedule_fetch_today_slot_rows($pdo, $providerId, $todayYmd);
    $counts = provider_schedule_count_today_slots($rows, $todayHis);

    $slots = [];
    foreach ($rows as $row) {
        $slots[] = provider_schedule_format_live_slot($row, $todayYmd, $todayHis);
    }

    $fingerprintParts = [$isActive ? '1' : '0', (string) count($slots)];
    foreach ($slots as $slot) {
        $fingerprintParts[] = implode('|', [
            (string) $slot['id'],
            (string) $slot['status'],
            (string) $slot['display_status'],
            (string) $slot['patient_name'],
            $slot['pending_reschedule'] ? '1' : '0',
            (string) $slot['consultation_id'],
        ]);
    }

    return [
        'today' => $todayName,
        'today_ymd' => $todayYmd,
        'today_label' => $todayLabel,
        'is_active' => $isActive,
        'session_count' => count($todaySessions),
        'slot_count' => count($rows),
        'counts' => $counts,
        'slots' => $slots,
        'rows' => $rows,
        'fingerprint' => hash('sha256', implode("\n", $fingerprintParts)),
    ];
}
