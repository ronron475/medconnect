<?php
/**
 * Digital referrals — list + mark-read (Admin + Super Admin).
 * Monitoring/viewing only: clinical status updates are not allowed.
 * Read/unread uses per-user notifications (related_table = digital_referrals).
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/NotificationManager.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$relatedTable = 'digital_referrals';

/**
 * Ensure one per-user referral inbox notification exists, then mark it read.
 * Does not change clinical referral data.
 */
function referrals_mark_read_for_user(PDO $pdo, int $userId, int $referralId): int
{
    NotificationManager::ensureSchema($pdo);

    $updated = NotificationManager::markRelatedRead($pdo, $userId, 'digital_referrals', $referralId);
    if ($updated > 0) {
        return NotificationManager::countUnreadRelated($pdo, $userId, 'digital_referrals');
    }

    // No unread row to flip — create a read inbox row so this view stays read for this user.
    $existsNotif = $pdo->prepare("
        SELECT id
        FROM notifications
        WHERE user_id = ?
          AND related_table = 'digital_referrals'
          AND related_id = ?
          AND status = 'active'
        LIMIT 1
    ");
    $existsNotif->execute([$userId, $referralId]);
    if ((int) ($existsNotif->fetchColumn() ?: 0) > 0) {
        return NotificationManager::countUnreadRelated($pdo, $userId, 'digital_referrals');
    }

    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $roleStmt->execute([$userId]);
    $role = strtolower(trim((string) ($roleStmt->fetchColumn() ?: '')));
    $actionUrl = $role === 'superadmin'
        ? '/views/superadmin/facility_management.php?tab=referral'
        : '/views/admin/facility_management.php?tab=referral';

    $newId = NotificationManager::create($pdo, $userId, [
        'receiver_role' => $role,
        'type'          => NotificationManager::TYPE_REFERRAL,
        'title'         => 'Referral Issued',
        'message'       => 'A doctor has issued a patient referral. Open Referral Center to monitor the record.',
        'related_table' => 'digital_referrals',
        'related_id'    => $referralId,
        'action_url'    => $actionUrl,
        'icon'          => 'clipboard',
    ]);
    if ($newId) {
        NotificationManager::markRelatedRead($pdo, $userId, 'digital_referrals', $referralId);
    }

    return NotificationManager::countUnreadRelated($pdo, $userId, 'digital_referrals');
}

if ($method === 'GET') {
    $status = trim($_GET['status'] ?? 'all');
    $where = '1=1';
    $params = [];
    if ($status !== '' && $status !== 'all') {
        $where = 'dr.status = ?';
        $params[] = $status;
    }

    try {
        if (!$pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
            echo json_encode([
                'success' => true,
                'rows' => [],
                'unread_count' => 0,
                'total_count' => 0,
            ]);
            exit;
        }

        $cols = $pdo->query('SHOW COLUMNS FROM digital_referrals')->fetchAll(PDO::FETCH_COLUMN);
        $hasFacilityName = in_array('facility_name', $cols, true);
        $hasDestination = in_array('destination_facility', $cols, true);
        if ($hasFacilityName && $hasDestination) {
            $destExpr = 'COALESCE(NULLIF(TRIM(dr.facility_name), ""), NULLIF(TRIM(dr.destination_facility), ""), "")';
        } elseif ($hasFacilityName) {
            $destExpr = 'COALESCE(dr.facility_name, "")';
        } elseif ($hasDestination) {
            $destExpr = 'COALESCE(dr.destination_facility, "")';
        } else {
            $destExpr = '""';
        }
        $stmt = $pdo->prepare("
            SELECT dr.id, dr.referral_type, dr.reason, dr.status, dr.created_at,
                   {$destExpr} AS facility_name,
                   TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS patient_name,
                   TRIM(CONCAT(COALESCE(pr.first_name, ''), ' ', COALESCE(pr.last_name, ''))) AS provider_name
            FROM digital_referrals dr
            LEFT JOIN users p ON p.id = dr.patient_id
            LEFT JOIN users pr ON pr.id = dr.provider_id
            WHERE {$where}
            ORDER BY dr.created_at DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $unreadIds = $userId > 0
            ? NotificationManager::listRelatedUnreadIds($pdo, $userId, $relatedTable)
            : [];
        $unreadSet = array_fill_keys($unreadIds, true);
        foreach ($rows as &$row) {
            $rid = (int) ($row['id'] ?? 0);
            $row['is_unread'] = $rid > 0 && isset($unreadSet[$rid]);
        }
        unset($row);

        $totalCount = (int) $pdo->query('SELECT COUNT(*) FROM digital_referrals')->fetchColumn();
        $unreadCount = NotificationManager::countUnreadRelated($pdo, $userId, $relatedTable);

        echo json_encode([
            'success' => true,
            'rows' => $rows,
            'unread_count' => $unreadCount,
            'total_count' => $totalCount,
            'timestamp' => date('c'),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Could not load referrals.']);
    }
    exit;
}

if ($method === 'POST') {
    auth_csrf_require();

    $action = trim((string) ($_POST['action'] ?? ''));
    $referralId = (int) ($_POST['referral_id'] ?? 0);

    // Viewing = reading. No clinical mutations from this monitoring page.
    if ($action === 'mark_read' && $referralId > 0 && $userId > 0) {
        try {
            if (!$pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
                echo json_encode(['success' => false, 'message' => 'Referral not found.']);
                exit;
            }
            $exists = $pdo->prepare('SELECT id FROM digital_referrals WHERE id = ? LIMIT 1');
            $exists->execute([$referralId]);
            if (!(int) $exists->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Referral not found.']);
                exit;
            }

            $unreadCount = referrals_mark_read_for_user($pdo, $userId, $referralId);

            echo json_encode([
                'success' => true,
                'referral_id' => $referralId,
                'is_unread' => false,
                'unread_count' => $unreadCount,
                'message' => 'Referral marked as read.',
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not mark referral as read.']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

http_response_code(403);
echo json_encode([
    'success' => false,
    'message' => 'Referral status is read-only for Admin and SuperAdmin. Monitoring only.',
]);
