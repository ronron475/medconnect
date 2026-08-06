<?php
/**
 * Provider consultation queue status (poll for schedule-based session unlock).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requireRole('provider');

require_once dirname(dirname(dirname(__DIR__))) . '/resources/views/provider/partials/queue_helpers.php';

$providerId = (int) ($_SESSION['user_id'] ?? 0);
if ($providerId <= 0) {
    Api::error('Authentication required.', 401);
}

header('Cache-Control: no-store');

try {
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.consult_date,
            c.consult_time,
            c.status,
            vs.room_token,
            s.slot_date,
            s.start_time AS slot_start,
            s.end_time AS slot_end
        FROM consultations c
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id AND vs.status = 'active'
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.provider_id = ?
          AND c.consult_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          AND c.status NOT IN ('cancelled', 'canceled')
        ORDER BY c.consult_date DESC, c.consult_time DESC
        LIMIT 50
    ");
    $stmt->execute([$providerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $stats = [
        'today'     => 0,
        'waiting'   => 0,
        'active'    => 0,
        'completed' => 0,
    ];
    $today = date('Y-m-d');

    foreach ($rows as $row) {
        $ctx = queue_session_context($row);
        $access = queue_session_access($row);
        $status = queue_normalize_status((string) ($row['status'] ?? 'pending'));
        $consultDate = queue_normalize_date($row['consult_date'] ?? null);

        if ($consultDate === $today) {
            $stats['today']++;
        }
        if (in_array($status, ['pending', 'scheduled'], true)) {
            $stats['waiting']++;
        }
        if ($status === 'in_consultation') {
            $stats['active']++;
        }
        if ($status === 'completed') {
            $stats['completed']++;
        }

        $items[] = [
            'id'               => (int) ($row['id'] ?? 0),
            'status'           => $status,
            'status_label'     => ucwords(str_replace('_', ' ', $status)),
            'session_allowed'  => (bool) $access['allowed'],
            'session_reason'   => (string) ($access['reason'] ?? ''),
            'scheduled_label'  => (string) ($access['scheduled_label'] ?? ''),
            'scheduled_start'  => $ctx['scheduled_start'] ? (int) $ctx['scheduled_start'] : null,
            'opens_at_label'   => (string) ($ctx['opens_at_label'] ?? ''),
            'room_token'       => (string) ($row['room_token'] ?? ''),
            'session_url'      => ASSET_BASE . '/views/provider/consultation_session.php?id=' . (int) ($row['id'] ?? 0),
            'live_room_url'    => !empty($row['room_token'])
                ? (ASSET_BASE . '/views/consultation/video_room.php?token=' . urlencode((string) $row['room_token']))
                : '',
        ];
    }

    Api::success([
        'items'      => $items,
        'stats'      => $stats,
        'server_now' => time(),
    ]);
} catch (Throwable $e) {
    error_log('queue_status.php: ' . $e->getMessage());
    Api::error('Could not load queue status.', 500);
}
