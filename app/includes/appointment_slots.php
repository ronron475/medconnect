<?php
/**
 * Appointment slot generation and patient booking rules.
 */

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
    $stmt = $pdo->prepare("
        DELETE FROM appointment_slots
        WHERE provider_id = ?
          AND DAYNAME(slot_date) = ?
          AND slot_date >= CURDATE()
          AND status = 'available'
    ");
    $stmt->execute([$provider_id, $day]);
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
