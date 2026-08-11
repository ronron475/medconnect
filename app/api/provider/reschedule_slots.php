<?php
/**
 * API: List available slots for provider reschedule picker (today, own schedule).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$providerId = (int) $_SESSION['user_id'];
$excludeSlotId = (int) ($_GET['exclude_slot_id'] ?? 0);

try {
    appointment_slots_sync_today($pdo, $providerId);

    $sql = "
        SELECT id, slot_date, start_time, end_time, status
        FROM appointment_slots
        WHERE provider_id = ?
          AND slot_date = CURDATE()
          AND status = 'available'
    ";
    $params = [$providerId];
    if ($excludeSlotId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeSlotId;
    }
    $sql .= ' ORDER BY start_time ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $slots = [];
    foreach ($rows as $row) {
        $slotDate = (string) $row['slot_date'];
        $startTime = (string) $row['start_time'];
        $endTime = (string) $row['end_time'];
        if (!appointment_slot_is_bookable($slotDate, $startTime, $endTime)) {
            continue;
        }
        $slots[] = [
            'id' => (int) $row['id'],
            'label' => date('g:i A', strtotime($startTime))
                . ' – '
                . date('g:i A', strtotime($endTime)),
        ];
    }

    echo json_encode(['success' => true, 'slots' => $slots]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load available slots.']);
}
