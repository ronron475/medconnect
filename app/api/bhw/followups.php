<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $status = $_GET['status'] ?? null;
        Api::success(['followups' => BhwWorkflows::listFollowups($pdo, $ctx, $status)]);
    } elseif ($action === 'remind') {
        BhwWorkflows::sendFollowupReminder($pdo, $ctx, (int) ($_POST['followup_id'] ?? 0));
        Api::success([], 'Reminder sent.');
    } elseif ($action === 'log_visit') {
        $visitId = BhwWorkflows::logHomeVisit(
            $pdo,
            $ctx,
            (int) ($_POST['patient_id'] ?? 0),
            (int) ($_POST['followup_id'] ?? 0) ?: null,
            trim($_POST['visit_date'] ?? date('Y-m-d')),
            trim($_POST['visit_type'] ?? 'follow_up'),
            trim($_POST['patient_status'] ?? 'stable'),
            trim($_POST['notes'] ?? '')
        );
        Api::success(['visit_id' => $visitId], 'Home visit logged.');
    } elseif ($action === 'visits') {
        $patientId = (int) ($_GET['patient_id'] ?? 0);
        Api::success(['visits' => BhwWorkflows::listHomeVisits($pdo, $ctx, $patientId > 0 ? $patientId : null)]);
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    Api::error($e->getMessage());
} catch (Throwable $e) {
    Api::error($e->getMessage(), 500);
}
