<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_nav_inbox.php';

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$bhwId = (int) ($_SESSION['user_id'] ?? 0);

try {
    if ($action === 'queue') {
        Api::success([
            'queue' => BhwWorkflows::listAppointmentFollowupQueue($pdo, $ctx),
        ]);
    } elseif ($action === 'list') {
        $status = $_GET['status'] ?? null;
        if ($status === '') {
            $status = null;
        }
        $followups = BhwWorkflows::listFollowups($pdo, $ctx, $status);
        if ($bhwId > 0) {
            bhw_nav_mark_followups_read($pdo, $bhwId);
        }
        Api::success([
            'followups' => $followups,
            'bhw_followups' => bhw_nav_followups_unread_count($pdo, $bhwId, $ctx),
        ]);
    } elseif ($action === 'get') {
        $followupId = (int) ($_GET['followup_id'] ?? $_POST['followup_id'] ?? 0);
        $payload = BhwWorkflows::getFollowup($pdo, $ctx, $followupId);
        if ($bhwId > 0) {
            bhw_nav_mark_followups_read($pdo, $bhwId, $followupId);
        }
        Api::success($payload);
    } elseif ($action === 'remind') {
        $result = BhwWorkflows::sendFollowupReminder($pdo, $ctx, (int) ($_POST['followup_id'] ?? 0));
        $payload = [
            'email' => (string) ($result['email'] ?? ''),
            'email_sent' => !empty($result['email_sent']),
            'sms_status' => (string) ($result['sms_status'] ?? ''),
        ];
        $message = (string) ($result['message'] ?? 'Reminder was not sent.');
        if (!empty($result['success'])) {
            Api::success($payload, $message);
        } else {
            Api::error($message, 400, $payload);
        }
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    $msg = $e->getMessage();
    $denied = stripos($msg, 'barangay') !== false || stripos($msg, 'sector') !== false || strcasecmp($msg, 'ACCESS DENIED') === 0;
    Api::error($denied ? 'ACCESS DENIED' : $msg, $denied ? 403 : 400);
} catch (Throwable $e) {
    error_log('bhw_followups: ' . $e->getMessage());
    Api::error('Operation failed. Please try again.', 500);
}
