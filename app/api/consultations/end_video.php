<?php
/**
 * End / leave video room.
 *
 * Patient leave  → rejoinable; consultation stays active (NOT completed).
 * Provider end   → ends video_sessions, auto-completes consultation (SOAP stays separate).
 * Idempotent for provider retries on the same room token.
 */
ob_start();
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_video_lifecycle.php';

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

    $role = (string) ($_SESSION['user_role'] ?? '');
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    // Patient (or other non-provider) leave: keep room + consultation active for rejoin.
    if ($role !== 'provider') {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Left the call. The session remains active so you can rejoin from your dashboard.',
            'rejoinable' => true,
            'session_ended' => false,
            'consultation_completed' => false,
        ]);
        exit;
    }

    $result = consultation_provider_end_video_session($pdo, $token, $uid);
    if (!$result['success']) {
        $status = ($result['message'] === 'Unauthorized.') ? 403 : 404;
        ob_end_clean();
        http_response_code($status);
        echo json_encode([
            'success' => false,
            'message' => $result['message'],
        ]);
        exit;
    }

    $started = $result['started_at'] ?? null;
    $ended = $result['ended_at'] ?? null;
    $durationSeconds = null;
    if ($started && $ended) {
        $startTs = strtotime((string) $started);
        $endTs = strtotime((string) $ended);
        if ($startTs && $endTs && $endTs >= $startTs) {
            $durationSeconds = (int) ($endTs - $startTs);
        }
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'consultation_id' => (int) $result['consultation_id'],
        'patient_id' => (int) $result['patient_id'],
        'video_status' => (string) $result['video_status'],
        'consultation_status' => (string) $result['consultation_status'],
        'started_at' => $started,
        'ended_at' => $ended,
        'duration_seconds' => $durationSeconds,
        'session_ended' => true,
        'consultation_completed' => ((string) $result['consultation_status'] === 'completed'),
        'newly_ended' => !empty($result['newly_ended']),
        'newly_completed' => !empty($result['newly_completed']),
        'rejoinable' => false,
    ]);
} catch (Throwable $e) {
    error_log('end_video.php: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not end video session.']);
}
