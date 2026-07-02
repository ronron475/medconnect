<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/appointment_slots.php';

$providerId = (int) ($argv[1] ?? 3);
echo 'Today: ' . date('Y-m-d l') . PHP_EOL;
echo 'Slots created: ' . appointment_slots_sync_provider($pdo, $providerId) . PHP_EOL;

$bookable = appointment_slots_bookable_sql();
$stmt = $pdo->prepare("
    SELECT slot_date, COUNT(*) AS available_count
    FROM appointment_slots
    WHERE provider_id = ?
      AND status = 'available'
      AND slot_date = CURDATE()
      AND {$bookable}
    GROUP BY slot_date
    ORDER BY slot_date ASC
    LIMIT 8
");
$stmt->execute([$providerId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['slot_date'] . ' => ' . $row['available_count'] . PHP_EOL;
}
