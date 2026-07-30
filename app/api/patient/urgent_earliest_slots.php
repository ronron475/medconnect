<?php
/**
 * Patient API: earliest bookable slot today per active provider (urgent booking helper).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_provider_assignment.php';

Api::startJson();
Api::requirePatientReady($pdo);

$patientId = (int) $_SESSION['user_id'];

try {
    $ctx = triage_patient_review_booking_context($pdo, $patientId);
    $locked = !empty($ctx['locked']) && (int) ($ctx['provider_id'] ?? 0) > 0;
    $lockedProviderId = $locked ? (int) $ctx['provider_id'] : 0;

    $providers = $pdo->query("
        SELECT id
        FROM users
        WHERE role = 'provider' AND is_active = 1
        ORDER BY first_name ASC, last_name ASC, id ASC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $bookable = appointment_slots_bookable_sql('s');
    $earliestStmt = $pdo->prepare("
        SELECT s.id, s.slot_date, s.start_time, s.end_time
        FROM appointment_slots s
        WHERE s.provider_id = ?
          AND s.status = 'available'
          AND {$bookable}
        ORDER BY s.start_time ASC
        LIMIT 1
    ");

    $options = [];
    foreach ($providers as $rawId) {
        $providerId = (int) $rawId;
        if ($providerId <= 0) {
            continue;
        }
        if ($lockedProviderId > 0 && $providerId !== $lockedProviderId) {
            continue;
        }

        appointment_slots_sync_today($pdo, $providerId);
        $earliestStmt->execute([$providerId]);
        $slot = $earliestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$slot) {
            continue;
        }

        $start = (string) ($slot['start_time'] ?? '');
        $end = (string) ($slot['end_time'] ?? '');
        $slotDate = (string) ($slot['slot_date'] ?? '');
        if (!appointment_slot_is_bookable($slotDate, $start, $end)) {
            continue;
        }

        $options[] = [
            'provider_id'   => $providerId,
            'provider_name' => triage_provider_display_name($pdo, $providerId),
            'slot_id'       => (int) ($slot['id'] ?? 0),
            'slot_date'     => $slotDate,
            'start_time'    => $start,
            'end_time'      => $end,
            'time_label'    => date('g:i A', strtotime($start)),
            'range_label'   => date('g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end)),
        ];
    }

    usort($options, static function (array $a, array $b): int {
        return strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));
    });

    Api::success([
        'today' => appointment_now()->format('Y-m-d'),
        'locked' => $locked,
        'locked_provider_id' => $lockedProviderId,
        'options' => $options,
        'count' => count($options),
    ]);
} catch (PDOException $e) {
    Api::error('Could not load earliest slots: ' . $e->getMessage(), 500);
}
