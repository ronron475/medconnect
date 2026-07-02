<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        Api::success(['referrals' => BhwWorkflows::listReferrals($pdo, $ctx)]);
    } elseif ($action === 'create') {
        $id = BhwWorkflows::createReferral(
            $pdo, $ctx,
            (int) ($_POST['patient_id'] ?? 0),
            trim($_POST['referral_type'] ?? 'Other'),
            trim($_POST['reason'] ?? ''),
            (int) ($_POST['facility_id'] ?? 0),
            trim($_POST['facility_name'] ?? '')
        );
        Api::success(['referral_id' => $id], 'Referral created.');
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    Api::error($e->getMessage());
}
