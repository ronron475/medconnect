<?php
/**
 * API: Available appointment slots from provider schedule.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';

Api::startJson();

if (empty($_SESSION['user_id'])) {
    Api::error('Unauthorized.', 403);
}

$provider_id = (int) ($_GET['provider_id'] ?? 0);
$date        = trim((string) ($_GET['date'] ?? ''));

if ($provider_id <= 0) {
    Api::error('Provider ID is required.');
}

if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    Api::error('A valid date (YYYY-MM-DD) is required.');
}

$today = appointment_now()->format('Y-m-d');
if ($date !== $today) {
    Api::error('Appointments can only be booked for today.');
}

try {
    appointment_slots_sync_today($pdo, $provider_id);

    $stmt = $pdo->prepare("
        SELECT
            id,
            slot_date,
            start_time,
            end_time,
            status
        FROM appointment_slots
        WHERE provider_id = ?
          AND slot_date = ?
          AND status = 'available'
        ORDER BY start_time ASC
    ");
    $stmt->execute([$provider_id, $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $slots = array_map(static function (array $row): array {
        $slotDate  = (string) $row['slot_date'];
        $startTime = (string) $row['start_time'];
        $endTime   = (string) $row['end_time'];
        $bookable  = appointment_slot_is_bookable($slotDate, $startTime, $endTime);

        return [
            'id'         => (int) $row['id'],
            'slot_date'  => $slotDate,
            'start_time' => $startTime,
            'end_time'   => (string) $row['end_time'],
            'bookable'   => $bookable,
            'label'      => date('g:i A', strtotime($startTime))
                . ' – '
                . date('g:i A', strtotime((string) $row['end_time']))
                . ($bookable ? '' : ' (passed)'),
        ];
    }, $rows);

    Api::success([
        'date'       => $date,
        'today'      => $today,
        'today_only' => true,
        'slots'      => $slots,
    ]);
} catch (PDOException $e) {
    Api::error('Could not load slots: ' . $e->getMessage(), 500);
}
