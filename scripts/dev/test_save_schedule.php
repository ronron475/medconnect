<?php
require_once dirname(__DIR__, 2) . '/config/db.php';

$provider = $pdo->query("SELECT id FROM users WHERE role = 'provider' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$provider) {
    echo "No provider found\n";
    exit(1);
}

$provider_id = (int) $provider['id'];
$day = 'Monday';
$start_time = '09:00';
$end_time = '17:00';
$duration = 30;
$is_active = 1;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO provider_schedules (provider_id, day_of_week, start_time, end_time, slot_duration, is_active)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            start_time = VALUES(start_time),
            end_time = VALUES(end_time),
            slot_duration = VALUES(slot_duration),
            is_active = VALUES(is_active)
    ");
    $stmt->execute([$provider_id, $day, $start_time, $end_time, $duration, $is_active]);

    $stmt = $pdo->prepare("
        DELETE FROM appointment_slots
        WHERE provider_id = ?
          AND DAYNAME(slot_date) = ?
          AND slot_date >= CURDATE()
          AND status = 'available'
    ");
    $stmt->execute([$provider_id, $day]);

    $start_ts = strtotime($start_time);
    $end_ts = strtotime($end_time);
    $interval = $duration * 60;
    $created = 0;

    for ($i = 0; $i < 28; $i++) {
        $date = date('Y-m-d', strtotime("+$i days"));
        if (date('l', strtotime($date)) === $day) {
            for ($current = $start_ts; $current < $end_ts; $current += $interval) {
                if ($current + $interval > $end_ts) {
                    break;
                }
                $s_time = date('H:i:s', $current);
                $e_time = date('H:i:s', $current + $interval);
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO appointment_slots (provider_id, slot_date, start_time, end_time, status)
                    VALUES (?, ?, ?, ?, 'available')
                ");
                $stmt->execute([$provider_id, $date, $s_time, $e_time]);
                $created += $stmt->rowCount();
            }
        }
    }

    $pdo->commit();
    echo "OK provider={$provider_id} slots_created={$created}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'ERR: ' . $e->getMessage() . "\n";
}
