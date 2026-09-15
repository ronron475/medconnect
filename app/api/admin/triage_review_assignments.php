<?php
/**
 * Admin/SuperAdmin API: AI Review Assignments inbox (monitoring + read/unread only).
 * Provider assignment is read-only — never accept provider changes from this endpoint.
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_provider_assignment.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/NotificationManager.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';

triage_assessment_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$relatedTable = NotificationManager::RELATED_AI_REVIEW_ASSIGNMENTS;

/**
 * @return string SQL fragment for recommendation_status filter
 */
function triage_review_assignments_status_where(string $status): string
{
    $where = "tr.recommendation_status IN ('pending_approval', 'approved')";
    if ($status === 'pending') {
        $where = "tr.recommendation_status = 'pending_approval'";
    } elseif ($status === 'approved') {
        $where = "tr.recommendation_status = 'approved'";
    }
    return $where;
}

function triage_review_assignments_format_provider(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    return triage_provider_format_doctor_name($name);
}

if ($method === 'GET') {
    $status = trim($_GET['status'] ?? 'active');
    $where = triage_review_assignments_status_where($status);

    try {
        if ($userId > 0) {
            NotificationEvents::ensureAiReviewInboxForUser($pdo, $userId);
        }

        $stmt = $pdo->query("
            SELECT
                tr.id,
                tr.patient_id,
                tr.chief_complaint,
                tr.recommendation_status,
                tr.triage_level,
                tr.urgency_label,
                tr.assigned_provider_id,
                tr.assigned_at,
                tr.assessed_at,
                tr.recommendation_approved_at,
                CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name,
                CONCAT(rv.first_name, ' ', rv.last_name) AS reviewer_name
            FROM triage_results tr
            JOIN users pt ON pt.id = tr.patient_id
            LEFT JOIN users rv ON rv.id = tr.assigned_provider_id
            WHERE {$where}
              AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
            ORDER BY
              CASE WHEN tr.recommendation_status = 'pending_approval' THEN 0 ELSE 1 END,
              tr.assessed_at DESC
            LIMIT 250
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $unreadIds = $userId > 0
            ? NotificationManager::listRelatedUnreadIds($pdo, $userId, $relatedTable)
            : [];
        $unreadSet = array_fill_keys($unreadIds, true);

        foreach ($rows as &$row) {
            $tid = (int) ($row['id'] ?? 0);
            $providerId = (int) ($row['assigned_provider_id'] ?? 0);
            $providerName = '';
            if ($providerId > 0) {
                $providerName = triage_provider_display_name($pdo, $providerId);
                if ($providerName === '') {
                    $providerName = triage_review_assignments_format_provider($row['reviewer_name'] ?? '');
                }
            }
            $row['assigned_provider_id'] = $providerId;
            $row['reviewer_name'] = $providerName;
            $row['assigned_provider_name'] = $providerName;
            $row['is_unread'] = $tid > 0 && isset($unreadSet[$tid]);
        }
        unset($row);

        $unreadCount = $userId > 0
            ? NotificationManager::countUnreadRelated($pdo, $userId, $relatedTable)
            : 0;

        echo json_encode([
            'success' => true,
            'rows' => $rows,
            'unread_count' => $unreadCount,
            'timestamp' => date('c'),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Could not load AI review assignments.']);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$action = trim((string) ($_POST['action'] ?? ''));
$triageId = (int) ($_POST['triage_id'] ?? 0);

// Explicitly reject any provider-assignment attempts from this inbox endpoint.
if ($action === 'reassign' || isset($_POST['provider_id'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Provider assignment cannot be changed from AI Review Assignments.',
    ]);
    exit;
}

if (!in_array($action, ['mark_read', 'mark_unread'], true) || $triageId <= 0 || $userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

try {
    $chk = $pdo->prepare("
        SELECT tr.id, CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name
        FROM triage_results tr
        JOIN users pt ON pt.id = tr.patient_id
        WHERE tr.id = ?
          AND tr.recommendation_status IN ('pending_approval', 'approved')
        LIMIT 1
    ");
    $chk->execute([$triageId]);
    $case = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'AI review case not found.']);
        exit;
    }

    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $roleStmt->execute([$userId]);
    $role = strtolower(trim((string) ($roleStmt->fetchColumn() ?: '')));
    $actionUrl = $role === 'superadmin'
        ? '/views/superadmin/ai_review_assignments.php'
        : '/views/admin/ai_review_assignments.php';
    $patientName = trim((string) ($case['patient_name'] ?? 'A patient'));

    if ($action === 'mark_read') {
        $updated = NotificationManager::markRelatedRead($pdo, $userId, $relatedTable, $triageId);
        if ($updated <= 0 && !NotificationManager::isRelatedUnread($pdo, $userId, $relatedTable, $triageId)) {
            // Ensure a read inbox row exists so ensureAiReviewInboxForUser won't recreate it as unread.
            $exists = $pdo->prepare("
                SELECT id FROM notifications
                WHERE user_id = ? AND related_table = ? AND related_id = ? AND status = 'active'
                LIMIT 1
            ");
            $exists->execute([$userId, $relatedTable, $triageId]);
            if (!(int) ($exists->fetchColumn() ?: 0)) {
                $newId = NotificationManager::create($pdo, $userId, [
                    'receiver_role' => $role,
                    'type'          => NotificationManager::TYPE_MEDICAL,
                    'title'         => 'AI Review Assignment',
                    'message'       => "{$patientName} has an AI review case in the assignments inbox.",
                    'priority'      => 'high',
                    'related_table' => $relatedTable,
                    'related_id'    => $triageId,
                    'action_url'    => $actionUrl,
                    'icon'          => 'clipboard',
                ]);
                if ($newId) {
                    NotificationManager::markRelatedRead($pdo, $userId, $relatedTable, $triageId);
                }
            }
        }
        $isUnread = false;
        $message = 'Marked as read.';
    } else {
        NotificationManager::markRelatedUnread($pdo, $userId, $relatedTable, $triageId, [
            'receiver_role' => $role,
            'title'         => 'AI Review Assignment',
            'message'       => "{$patientName} has an AI review case in the assignments inbox.",
            'action_url'    => $actionUrl,
            'type'          => NotificationManager::TYPE_MEDICAL,
        ]);
        $isUnread = true;
        $message = 'Marked as unread.';
    }

    $unreadCount = NotificationManager::countUnreadRelated($pdo, $userId, $relatedTable);

    echo json_encode([
        'success' => true,
        'triage_id' => $triageId,
        'is_unread' => $isUnread,
        'unread_count' => $unreadCount,
        'message' => $message,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update read state.']);
}
