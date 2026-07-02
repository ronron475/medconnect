<?php
/**
 * API: Dates with available appointment slots for a provider.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';

Api::startJson();

if (empty($_SESSION['user_id'])) {
    Api::error('Unauthorized.', 403);
}

$provider_id = (int) ($_GET['provider_id'] ?? 0);
if ($provider_id <= 0) {
    Api::error('Provider ID is required.');
}

try {
    $today = date('Y-m-d');
    appointment_slots_sync_today($pdo, $provider_id);

    if (!appointment_provider_has_today_schedule($pdo, $provider_id)) {
        Api::success([
            'dates'      => [],
            'today'      => $today,
            'today_only' => true,
            'message'    => 'This provider is not available today.',
        ]);
    }

    $bookable = appointment_slots_bookable_sql();
    $stmt = $pdo->prepare("
        SELECT
            slot_date,
            COUNT(*) AS available_count
        FROM appointment_slots
        WHERE provider_id = ?
          AND slot_date = CURDATE()
          AND status = 'available'
          AND {$bookable}
        GROUP BY slot_date
        ORDER BY slot_date ASC
    ");
    $stmt->execute([$provider_id]);
    $dates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Api::success([
        'dates'       => $dates,
        'today'       => $today,
        'today_only'  => true,
    ]);
} catch (PDOException $e) {
    Api::error('Could not load available dates: ' . $e->getMessage(), 500);
}
