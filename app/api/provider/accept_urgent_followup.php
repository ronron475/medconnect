<?php
/**
 * API: Provider accepts an urgent follow-up case (optionally starts video).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requireRole('provider');
Api::requirePost();
Api::requireCsrf();

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/urgent_followup_workflow.php';

$providerId = (int) $_SESSION['user_id'];
$caseId = (int) ($_POST['case_id'] ?? 0);
$startVideo = !empty($_POST['start_video']) && (string) $_POST['start_video'] !== '0';

if ($caseId <= 0) {
    Api::error('Case ID is required.');
}

try {
    $pdo->beginTransaction();
    $result = urgent_followup_accept($pdo, $providerId, $caseId, $startVideo);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('accept_urgent_followup: ' . $e->getMessage());
    Api::error($e->getMessage());
}

$msg = $startVideo
    ? 'Urgent follow-up accepted. Video consultation started.'
    : 'Urgent follow-up accepted.';

Api::success($result, $msg);
