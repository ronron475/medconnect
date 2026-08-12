<?php
ob_start();
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!auth_csrf_validate($csrf)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$token = trim((string) ($_POST['token'] ?? ''));
if ($token === '') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Token required.']);
    exit;
}

try {
    clinical_tables_ensure($pdo);

    if (($_SESSION['user_role'] ?? '') !== 'provider') {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Left the call. The session remains active so you can rejoin from your dashboard.',
            'rejoinable' => true,
        ]);
        exit;
    }

    $own = $pdo->prepare("
        SELECT vs.id, c.id AS consultation_id, c.provider_id
        FROM video_sessions vs
        JOIN consultations c ON c.id = vs.consultation_id
        WHERE vs.room_token = ?
          AND vs.status = 'active'
        LIMIT 1
    ");
    $own->execute([$token]);
    $sessionRow = $own->fetch(PDO::FETCH_ASSOC);
    if (!$sessionRow || (int) ($sessionRow['provider_id'] ?? 0) !== (int) $_SESSION['user_id']) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Video session not found.']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE video_sessions
        SET status = 'ended', ended_at = NOW()
        WHERE id = ?
          AND status = 'active'
    ");
    $stmt->execute([(int) $sessionRow['id']]);

    ob_end_clean();
    echo json_encode(['success' => true, 'consultation_id' => (int) $sessionRow['consultation_id']]);
} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not end video session.']);
}
