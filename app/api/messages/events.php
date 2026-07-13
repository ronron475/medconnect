<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/rate_limiter.php';

messages_api_require_auth($pdo);

$consultationId = (int)($_GET['consultation_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];
$sinceId = (int)($_GET['since_id'] ?? 0);

if (!$consultationId) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Consultation ID required.']);
    exit;
}

try {
    $rl = mc_rate_limiter_allow('messages_events', 90, 30, $userId);
    if (!$rl['allowed']) {
        ob_end_clean();
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many requests.']);
        exit;
    }

    consultation_messages_ensure_schema($pdo);

    $access = message_assert_participant($pdo, $consultationId, $userId);
    if (!$access['success']) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $access['message']]);
        exit;
    }

    $sql = '
        SELECT id, consultation_id, message_id, event_type, actor_user_id, payload, created_at
        FROM message_chat_events
        WHERE consultation_id = ?
    ';
    $params = [$consultationId];
    if ($sinceId > 0) {
        $sql .= ' AND id > ?';
        $params[] = $sinceId;
    }
    $sql .= ' ORDER BY id ASC LIMIT 100';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $events = [];
    $lastId = $sinceId;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventId = (int)$row['id'];
        $lastId = max($lastId, $eventId);
        $payload = $row['payload'] ? json_decode($row['payload'], true) : null;

        $event = [
            'id' => $eventId,
            'consultation_id' => (int)$row['consultation_id'],
            'message_id' => (int)$row['message_id'],
            'event_type' => $row['event_type'],
            'actor_user_id' => (int)$row['actor_user_id'],
            'created_at' => $row['created_at'],
            'payload' => is_array($payload) ? $payload : null,
        ];

        if ($row['event_type'] === 'deleted_for_everyone') {
            $fetched = message_fetch_by_id($pdo, (int)$row['message_id']);
            if ($fetched['success']) {
                $formatted = message_format_for_viewer($fetched['row'], $userId);
                if ($formatted !== null) {
                    $event['message'] = $formatted;
                }
            }
        } elseif ($row['event_type'] === 'deleted_for_me') {
            $hiddenFor = (int)($payload['hidden_for_user_id'] ?? 0);
            if ($hiddenFor === $userId) {
                $event['hidden'] = true;
            }
        }

        $events[] = $event;
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'events' => $events,
        'last_event_id' => $lastId,
    ]);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load message events.']);
}
