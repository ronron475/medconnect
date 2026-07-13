<?php
/**
 * Provider schedule sessions — schema, validation, persistence.
 * One weekday may have multiple independent availability sessions.
 */

function provider_schedule_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $cols = $pdo->query('SHOW COLUMNS FROM provider_schedules')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sort_order', $cols, true)) {
        try {
            $pdo->exec('ALTER TABLE provider_schedules ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER day_of_week');
        } catch (PDOException $e) {
            error_log('provider_schedule sort_order: ' . $e->getMessage());
        }
    }

    $indexes = $pdo->query('SHOW INDEX FROM provider_schedules')->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_unique(array_column($indexes, 'Key_name'));
    if (in_array('uq_provider_day', $indexNames, true)) {
        try {
            $pdo->exec('ALTER TABLE provider_schedules DROP INDEX uq_provider_day');
        } catch (PDOException $e) {
            error_log('provider_schedule drop uq_provider_day: ' . $e->getMessage());
        }
    }
    if (!in_array('idx_provider_day_sort', $indexNames, true)) {
        try {
            $pdo->exec('ALTER TABLE provider_schedules ADD INDEX idx_provider_day_sort (provider_id, day_of_week, sort_order)');
        } catch (PDOException $e) {
            error_log('provider_schedule idx: ' . $e->getMessage());
        }
    }

    $ready = true;
}

function provider_schedule_valid_days(): array
{
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

function provider_schedule_normalize_time(string $time): string
{
    $time = trim($time);
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        return '';
    }
    if (strlen($time) === 5) {
        $time .= ':00';
    }

    return $time;
}

function provider_schedule_time_to_minutes(string $time): int
{
    $norm = provider_schedule_normalize_time($time);
    if ($norm === '') {
        return -1;
    }
    $parts = explode(':', $norm);

    return ((int) $parts[0]) * 60 + (int) $parts[1];
}

function provider_schedule_duration_allowed(int $duration): int
{
    return in_array($duration, [15, 30, 45, 60], true) ? $duration : 30;
}

/**
 * @param array<int, array{id?: int|null, start_time?: string, end_time?: string, duration?: int, slot_duration?: int}> $sessions
 * @return array{valid: bool, errors: string[], sessions: array<int, array{id: ?int, start_time: string, end_time: string, slot_duration: int}>}
 */
function provider_schedule_validate_sessions(array $sessions, bool $requireAtLeastOne = true): array
{
    $errors = [];
    $normalized = [];
    $seen = [];

    if ($requireAtLeastOne && count($sessions) === 0) {
        return [
            'valid'    => false,
            'errors'   => ['Add at least one availability session.'],
            'sessions' => [],
        ];
    }

    foreach ($sessions as $idx => $session) {
        $label = 'Session ' . ($idx + 1);
        $start = provider_schedule_normalize_time((string) ($session['start_time'] ?? ''));
        $end   = provider_schedule_normalize_time((string) ($session['end_time'] ?? ''));
        $dur   = provider_schedule_duration_allowed((int) ($session['duration'] ?? $session['slot_duration'] ?? 30));

        if ($start === '' || $end === '') {
            $errors[] = "{$label}: start and end times are required.";
            continue;
        }

        $startMin = provider_schedule_time_to_minutes($start);
        $endMin   = provider_schedule_time_to_minutes($end);
        if ($endMin <= $startMin) {
            $errors[] = "{$label}: end time must be later than start time.";
            continue;
        }

        if (($endMin - $startMin) < $dur) {
            $errors[] = "{$label}: session must be at least as long as the slot length ({$dur} min).";
            continue;
        }

        $key = $start . '|' . $end . '|' . $dur;
        if (isset($seen[$key])) {
            $errors[] = "{$label}: duplicate time range (same as Session {$seen[$key]}).";
            continue;
        }
        $seen[$key] = $idx + 1;

        $id = isset($session['id']) && $session['id'] !== '' && $session['id'] !== null
            ? (int) $session['id']
            : null;

        $normalized[] = [
            'id'            => $id > 0 ? $id : null,
            'start_time'    => $start,
            'end_time'      => $end,
            'slot_duration' => $dur,
            'start_min'     => $startMin,
            'end_min'       => $endMin,
        ];
    }

    usort($normalized, static fn ($a, $b) => $a['start_min'] <=> $b['start_min']);

    for ($i = 1, $n = count($normalized); $i < $n; $i++) {
        $prev = $normalized[$i - 1];
        $curr = $normalized[$i];
        if ($curr['start_min'] < $prev['end_min']) {
            $errors[] = 'Sessions overlap: '
                . date('g:i A', strtotime($curr['start_time']))
                . ' conflicts with a previous session ending at '
                . date('g:i A', strtotime($prev['end_time']))
                . '.';
            break;
        }
    }

    $clean = array_map(static function (array $s) {
        return [
            'id'            => $s['id'],
            'start_time'    => $s['start_time'],
            'end_time'      => $s['end_time'],
            'slot_duration' => $s['slot_duration'],
        ];
    }, $normalized);

    return [
        'valid'    => empty($errors),
        'errors'   => $errors,
        'sessions' => $clean,
    ];
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function provider_schedule_load_grouped(PDO $pdo, int $providerId): array
{
    provider_schedule_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, day_of_week, start_time, end_time, slot_duration, is_active, sort_order
        FROM provider_schedules
        WHERE provider_id = ?
        ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                 sort_order ASC, start_time ASC
    ");
    $stmt->execute([$providerId]);

    $grouped = [];
    foreach (provider_schedule_valid_days() as $day) {
        $grouped[$day] = [];
    }

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = (string) $row['day_of_week'];
        if (!isset($grouped[$day])) {
            $grouped[$day] = [];
        }
        $grouped[$day][] = $row;
    }

    return $grouped;
}

function provider_schedule_day_is_active(array $sessions): bool
{
    foreach ($sessions as $session) {
        if ((int) ($session['is_active'] ?? 0) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array{id: ?int, start_time: string, end_time: string, slot_duration: int}> $sessions
 */
function provider_schedule_save_day(
    PDO $pdo,
    int $providerId,
    string $day,
    array $sessions,
    bool $dayActive
): array {
    provider_schedule_ensure_schema($pdo);

    $keepIds = array_values(array_filter(array_map(
        static fn (array $s) => $s['id'] ?? null,
        $sessions
    )));

    if ($keepIds) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $del = $pdo->prepare("
            DELETE FROM provider_schedules
            WHERE provider_id = ? AND day_of_week = ? AND id NOT IN ({$placeholders})
        ");
        $del->execute(array_merge([$providerId, $day], $keepIds));
    } else {
        $del = $pdo->prepare('DELETE FROM provider_schedules WHERE provider_id = ? AND day_of_week = ?');
        $del->execute([$providerId, $day]);
    }

    $isActive = $dayActive ? 1 : 0;
    $upsert = $pdo->prepare('
        INSERT INTO provider_schedules
            (provider_id, day_of_week, sort_order, start_time, end_time, slot_duration, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $update = $pdo->prepare('
        UPDATE provider_schedules
        SET sort_order = ?, start_time = ?, end_time = ?, slot_duration = ?, is_active = ?
        WHERE id = ? AND provider_id = ? AND day_of_week = ?
    ');

    $savedIds = [];
    foreach ($sessions as $sort => $session) {
        if (!empty($session['id'])) {
            $update->execute([
                $sort,
                $session['start_time'],
                $session['end_time'],
                $session['slot_duration'],
                $isActive,
                (int) $session['id'],
                $providerId,
                $day,
            ]);
            $savedIds[] = (int) $session['id'];
        } else {
            $upsert->execute([
                $providerId,
                $day,
                $sort,
                $session['start_time'],
                $session['end_time'],
                $session['slot_duration'],
                $isActive,
            ]);
            $savedIds[] = (int) $pdo->lastInsertId();
        }
    }

    return $savedIds;
}

function provider_schedule_session_summary(array $sessions): array
{
    if (!$sessions) {
        return ['start' => '', 'end' => '', 'count' => 0];
    }

    $starts = array_map(static fn ($s) => provider_schedule_normalize_time((string) $s['start_time']), $sessions);
    $ends   = array_map(static fn ($s) => provider_schedule_normalize_time((string) $s['end_time']), $sessions);
    sort($starts);
    usort($ends, static fn ($a, $b) => provider_schedule_time_to_minutes($a) <=> provider_schedule_time_to_minutes($b));

    return [
        'start' => $starts[0] ?? '',
        'end'   => end($ends) ?: '',
        'count' => count($sessions),
    ];
}
