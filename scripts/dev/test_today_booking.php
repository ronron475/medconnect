<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/appointment_slots.php';

$providerId = (int) ($argv[1] ?? 3);
$today = date('Y-m-d');
echo 'Today: ' . $today . ' (' . date('l') . ')' . PHP_EOL;
echo 'Provider has today schedule: ' . (appointment_provider_has_today_schedule($pdo, $providerId) ? 'yes' : 'no') . PHP_EOL;
echo 'Slots synced: ' . appointment_slots_sync_today($pdo, $providerId) . PHP_EOL;

$stmt = $pdo->prepare("
    SELECT start_time, status
    FROM appointment_slots
    WHERE provider_id = ? AND slot_date = CURDATE()
    ORDER BY start_time
");
$stmt->execute([$providerId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo 'Today slots: ' . count($rows) . PHP_EOL;
foreach (array_slice($rows, 0, 5) as $row) {
    echo '  ' . $row['start_time'] . ' ' . $row['status'] . PHP_EOL;
}
