<?php
/**
 * Appointment slot generation and patient booking rules.
 */

require_once __DIR__ . '/appointment_schedule_schema.php';

function appointment_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

function appointment_slot_start_datetime(string $slotDate, string $startTime): DateTimeImmutable
{
    $time = substr($startTime, 0, 8);

    return DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $slotDate . ' ' . $time,
        new DateTimeZone(APP_TIMEZONE)
    ) ?: appointment_now();
}

/**
 * Slot statuses that may be edited or removed by the provider.
 */
function appointment_slot_editable_statuses(): array
{
    return ['available'];
}

function appointment_slot_is_editable(string $status): bool
{
    return in_array(strtolower($status), appointment_slot_editable_statuses(), true);
}

function appointment_slot_display_status(string $status, bool $isPast = false): string
{
    $status = strtolower($status);
    if ($status === 'available' && $isPast) {
        return 'EXPIRED';
    }

    return strtoupper($status);
}

/**
 * Mark past unbooked slots as expired (today only).
 */
function appointment_slots_expire_passed(PDO $pdo, ?int $providerId = null): int
{
    appointment_schedule_ensure_schema($pdo);

    $sql = "
        UPDATE appointment_slots
        SET status = 'expired'
        WHERE status = 'available'
          AND slot_date = CURDATE()
          AND start_time <= CURTIME()
    ";
    $params = [];
    if ($providerId !== null && $providerId > 0) {
        $sql .= ' AND provider_id = ?';
        $params[] = $providerId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->rowCount();
}

/**
 * Mark booked slots linked to a consultation with a terminal slot status.
 */
function appointment_slot_set_consultation_status(PDO $pdo, int $consultationId, string $slotStatus): int
{
    if ($consultationId <= 0) {
        return 0;
    }

    appointment_schedule_ensure_schema($pdo);
    $slotStatus = strtolower($slotStatus);
    if (!in_array($slotStatus, ['completed', 'cancelled', 'available'], true)) {
        return 0;
    }

    if ($slotStatus === 'available') {
        $stmt = $pdo->prepare("
            UPDATE appointment_slots
            SET status = 'available',
                patient_id = NULL,
                consultation_id = NULL
            WHERE consultation_id = ?
              AND status IN ('booked', 'blocked')
        ");
        $stmt->execute([$consultationId]);

        return (int) $stmt->rowCount();
    }

    $stmt = $pdo->prepare("
        UPDATE appointment_slots
        SET status = ?,
            patient_id = NULL
        WHERE consultation_id = ?
          AND status IN ('booked', 'blocked')
    ");
    $stmt->execute([$slotStatus, $consultationId]);

    return (int) $stmt->rowCount();
}

/**
 * Ensure future bookable slots exist for all active schedule days (next N days).
 */
function appointment_slots_sync_provider(PDO $pdo, int $provider_id, int $daysAhead = 28, ?string $onlyDay = null): int
{
    $sql = "
        SELECT day_of_week, start_time, end_time, slot_duration
        FROM provider_schedules
        WHERE provider_id = ? AND is_active = 1
    ";
    $params = [$provider_id];
    if ($onlyDay !== null) {
        $sql .= ' AND day_of_week = ?';
        $params[] = $onlyDay;
    }
    $sql .= ' ORDER BY day_of_week, sort_order ASC, start_time ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$schedules) {
        return 0;
    }

    $insert = $pdo->prepare("
        INSERT IGNORE INTO appointment_slots (provider_id, slot_date, start_time, end_time, status)
        VALUES (?, ?, ?, ?, 'available')
    ");

    $created = 0;
    foreach ($schedules as $schedule) {
        $day = (string) $schedule['day_of_week'];
        $start_ts = strtotime(substr((string) $schedule['start_time'], 0, 8));
        $end_ts = strtotime(substr((string) $schedule['end_time'], 0, 8));
        if ($start_ts === false || $end_ts === false || $end_ts <= $start_ts) {
            continue;
        }

        $interval = max(1, (int) $schedule['slot_duration']) * 60;

        for ($i = 0; $i <= $daysAhead; $i++) {
            $dayDate = appointment_now()->modify('+' . $i . ' days');
            if ($dayDate->format('l') !== $day) {
                continue;
            }
            $date = $dayDate->format('Y-m-d');

            for ($current = $start_ts; $current < $end_ts; $current += $interval) {
                if ($current + $interval > $end_ts) {
                    break;
                }

                $s_time = date('H:i:s', $current);
                $e_time = date('H:i:s', $current + $interval);
                $insert->execute([$provider_id, $date, $s_time, $e_time]);
                $created += $insert->rowCount();
            }
        }
    }

    return $created;
}

/**
 * Remove unbooked future slots for one weekday (before regenerating).
 */
function appointment_slots_clear_day(PDO $pdo, int $provider_id, string $day): void
{
    appointment_schedule_ensure_schema($pdo);

    // Use WEEKDAY (0=Mon … 6=Sun) instead of DAYNAME() to avoid collation mismatches on Hostinger.
    $weekdayMap = [
        'Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3,
        'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6,
    ];
    $weekday = $weekdayMap[$day] ?? null;
    if ($weekday === null) {
        return;
    }

    // Only remove unprotected slots. Booked/completed slots stay regardless of schedule edits.
    $stmt = $pdo->prepare("
        DELETE s FROM appointment_slots s
        LEFT JOIN consultations c ON c.id = s.consultation_id
        WHERE s.provider_id = ?
          AND WEEKDAY(s.slot_date) = ?
          AND s.slot_date >= CURDATE()
          AND (
            s.status IN ('available', 'expired')
            OR (
                s.status = 'cancelled'
                AND (s.consultation_id IS NULL OR c.status IN ('cancelled', 'completed'))
            )
          )
    ");
    $stmt->execute([$provider_id, $weekday]);
}

/**
 * Remove a single available slot (provider action).
 *
 * @return array{ok:bool,message:string}
 */
function appointment_slot_remove_available(PDO $pdo, int $providerId, int $slotId): array
{
    appointment_schedule_ensure_schema($pdo);

    if ($providerId <= 0 || $slotId <= 0) {
        return ['ok' => false, 'message' => 'Invalid slot.'];
    }

    $stmt = $pdo->prepare("
        SELECT id, provider_id, slot_date, start_time, status
        FROM appointment_slots
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) {
        return ['ok' => false, 'message' => 'Slot not found.'];
    }
    if ((int) $slot['provider_id'] !== $providerId) {
        return ['ok' => false, 'message' => 'This slot does not belong to your schedule.'];
    }
    if (!appointment_slot_is_editable((string) ($slot['status'] ?? ''))) {
        return ['ok' => false, 'message' => 'Only available slots can be removed. Booked appointments are protected.'];
    }
    if (!appointment_slot_is_today((string) $slot['slot_date'])) {
        return ['ok' => false, 'message' => 'Today\'s schedule is locked. Only today\'s availability can be edited.'];
    }

    $del = $pdo->prepare("
        DELETE FROM appointment_slots
        WHERE id = ?
          AND provider_id = ?
          AND status = 'available'
    ");
    $del->execute([$slotId, $providerId]);
    if ($del->rowCount() < 1) {
        return ['ok' => false, 'message' => 'Could not remove slot. It may have just been booked.'];
    }

    return ['ok' => true, 'message' => 'Time slot removed.'];
}

/**
 * SQL fragment: same-day slot with a future start time (patients book today only).
 */
function appointment_slots_bookable_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';

    return '('
        . $prefix . 'slot_date = CURDATE()'
        . ' AND ' . $prefix . 'start_time > CURTIME()'
        . ')';
}

function appointment_slot_is_today(string $slotDate): bool
{
    return $slotDate === appointment_now()->format('Y-m-d');
}

function appointment_slot_is_bookable(string $slotDate, string $startTime, ?string $endTime = null): bool
{
    if (!appointment_slot_is_today($slotDate)) {
        return false;
    }

    $slotStart = appointment_slot_start_datetime($slotDate, $startTime);
    if ($slotStart <= appointment_now()) {
        return false;
    }

    if ($endTime !== null && $endTime !== '') {
        $slotEnd = appointment_slot_start_datetime($slotDate, $endTime);
        if ($slotEnd <= appointment_now()) {
            return false;
        }
    }

    return true;
}

/**
 * Generate appointment slots for today only when the provider has today active.
 */
function appointment_slots_sync_today(PDO $pdo, int $provider_id): int
{
    appointment_schedule_ensure_schema($pdo);
    appointment_slots_expire_passed($pdo, $provider_id);

    $todayDay = appointment_now()->format('l');
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM provider_schedules
        WHERE provider_id = ?
          AND day_of_week = ?
          AND is_active = 1
    ");
    $stmt->execute([$provider_id, $todayDay]);
    if ((int) $stmt->fetchColumn() === 0) {
        return 0;
    }

    return appointment_slots_sync_provider($pdo, $provider_id, 0, $todayDay);
}

function appointment_provider_has_today_schedule(PDO $pdo, int $provider_id): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM provider_schedules
        WHERE provider_id = ?
          AND day_of_week = ?
          AND is_active = 1
    ");
    $stmt->execute([$provider_id, appointment_now()->format('l')]);

    return (int) $stmt->fetchColumn() > 0;
}
