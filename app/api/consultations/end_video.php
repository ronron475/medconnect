<?php
ob_start();
session_start();

if (empty($_SESSION['user_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';

$token = $_POST['token'] ?? '';

if (!$token) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Token required.']);
    exit;
}

try {
    if (($_SESSION['user_role'] ?? '') !== 'provider') {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Left the call. The session remains active so you can rejoin from your dashboard.',
            'rejoinable' => true,
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE video_sessions
        SET status = 'ended', ended_at = NOW()
        WHERE room_token = ?
          AND status = 'active'
    ");
    $stmt->execute([$token]);

    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
