<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        Api::success(['referrals' => BhwWorkflows::listReferrals($pdo, $ctx)]);
    } elseif ($action === 'followups') {
        $referralId = (int) ($_GET['referral_id'] ?? $_POST['referral_id'] ?? 0);
        Api::success([
            'followups' => BhwWorkflows::listCommunityFollowups($pdo, $ctx, $referralId),
        ]);
    } elseif ($action === 'record_followup') {
        $id = BhwWorkflows::recordCommunityFollowup(
            $pdo,
            $ctx,
            (int) ($_POST['referral_id'] ?? 0),
            !empty($_POST['patient_contacted']) && (string) $_POST['patient_contacted'] !== '0',
            !empty($_POST['acted_on_referral']) && (string) $_POST['acted_on_referral'] !== '0',
            trim((string) ($_POST['notes'] ?? ''))
        );
        Api::success(['followup_id' => $id], 'Follow-up recorded. Clinical referral was not changed.');
    } elseif ($action === 'create' || $action === 'update_status') {
        Api::error('BHW cannot create or change clinical referrals. Use record_followup instead.', 403);
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    $msg = $e->getMessage();
    $denied = stripos($msg, 'barangay') !== false || strcasecmp($msg, 'ACCESS DENIED') === 0;
    Api::error($denied ? 'ACCESS DENIED' : $msg, $denied ? 403 : 400);
} catch (Throwable $e) {
    Api::error($e->getMessage(), 500);
}
