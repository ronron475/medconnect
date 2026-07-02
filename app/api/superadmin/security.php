<?php
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/login_security.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/superadmin/security.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/superadmin/service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? 'summary';

if ($method === 'GET') {
    if ($action === 'summary') {
        echo json_encode(['success' => true, 'data' => superadmin_get_security_summary($pdo)]);
        exit;
    }
    if ($action === 'failed_logins') {
        $rows = $pdo->query('SELECT * FROM failed_logins ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }
    if ($action === 'blocked_ips') {
        $rows = $pdo->query('SELECT * FROM blocked_ips ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }
    if ($action === 'sessions') {
        $rows = $pdo->query('
            SELECT s.id, s.session_id, s.user_id, s.role, s.ip_address, s.browser, s.device, s.last_activity,
                   u.email, u.first_name, u.last_name
            FROM active_sessions s
            JOIN users u ON u.id = s.user_id
            ORDER BY s.last_activity DESC LIMIT 200
        ')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }
    if ($action === 'logs') {
        $rows = $pdo->query('
            SELECT sl.*, u.first_name, u.last_name
            FROM security_logs sl
            LEFT JOIN users u ON u.id = sl.user_id
            ORDER BY sl.created_at DESC LIMIT 300
        ')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }
    if ($action === 'login_events') {
        login_security_ensure_schema($pdo);
        $rows = $pdo->query('
            SELECT e.*, u.first_name, u.last_name, u.email
            FROM user_login_events e
            LEFT JOIN users u ON u.id = e.user_id
            ORDER BY e.created_at DESC LIMIT 200
        ')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }
}

if ($method === 'POST') {
    $ip = trim($_POST['ip'] ?? '');
    if ($action === 'block_ip' && $ip !== '') {
        superadmin_block_ip($pdo, $ip, trim($_POST['reason'] ?? 'Manual block'), !empty($_POST['permanent']));
        echo json_encode(['success' => true, 'message' => 'IP blocked.']);
        exit;
    }
    if ($action === 'unblock_ip' && $ip !== '') {
        superadmin_unblock_ip($pdo, $ip);
        echo json_encode(['success' => true, 'message' => 'IP unblocked.']);
        exit;
    }
    if ($action === 'terminate_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Session ID required.']);
            exit;
        }
        $ok = superadmin_terminate_session($pdo, $sessionId, (int) ($_SESSION['user_id'] ?? 0));
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? 'Session terminated.' : 'Session not found.',
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
