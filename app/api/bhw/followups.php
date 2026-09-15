<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_nav_inbox.php';

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$bhwId = (int) ($_SESSION['user_id'] ?? 0);

try {
    if ($action === 'list') {
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
        Api::success([
            'email' => $result['email'] ?? '',
        ], (string) ($result['message'] ?? 'Reminder email sent.'));
    } elseif ($action === 'log_visit') {
        bhw_api_require_patient_in_sector($pdo, $ctx, (int) ($_POST['patient_id'] ?? 0));
        $followupId = (int) ($_POST['followup_id'] ?? 0);
        $visitId = BhwWorkflows::logHomeVisit(
            $pdo,
            $ctx,
            (int) ($_POST['patient_id'] ?? 0),
            $followupId ?: null,
            trim($_POST['visit_date'] ?? date('Y-m-d')),
            trim($_POST['visit_type'] ?? 'follow_up'),
            trim($_POST['patient_status'] ?? 'stable'),
            trim($_POST['notes'] ?? '')
        );
        if ($bhwId > 0 && $followupId > 0) {
            bhw_nav_mark_followups_read($pdo, $bhwId, $followupId);
        }
        Api::success([
            'visit_id' => $visitId,
            'bhw_followups' => bhw_nav_followups_unread_count($pdo, $bhwId, $ctx),
        ], 'Home visit logged.');
    } elseif ($action === 'visits') {
        $patientId = (int) ($_GET['patient_id'] ?? 0);
        if ($patientId > 0) {
            bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
        }
        Api::success(['visits' => BhwWorkflows::listHomeVisits($pdo, $ctx, $patientId > 0 ? $patientId : null)]);
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    $msg = $e->getMessage();
    $denied = stripos($msg, 'barangay') !== false || stripos($msg, 'sector') !== false || strcasecmp($msg, 'ACCESS DENIED') === 0;
    Api::error($denied ? 'ACCESS DENIED' : $msg, $denied ? 403 : 400);
} catch (Throwable $e) {
    Api::error($e->getMessage(), 500);
}
