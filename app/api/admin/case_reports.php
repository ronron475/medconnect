<?php
/**
 * API: Admin / Superadmin case report review.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/case_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';

portal_api_require_admin_portal();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(trim((string) ($_REQUEST['action'] ?? 'list')));
$adminId = (int) ($_SESSION['user_id'] ?? 0);
$isSuperadmin = portal_is_superadmin();

try {
    if ($method === 'GET') {
        if ($action === 'detail') {
            $reportId = (int) ($_GET['id'] ?? 0);
            $detail = case_report_admin_detail($pdo, $reportId);
            if (!$detail) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Report not found.']);
                exit;
            }
            require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/complaint_evidence.php';
            if ((string) ($detail['source_type'] ?? '') !== case_report_source_video() && !empty($detail['triage_id'])) {
                $detail['supporting_evidence'] = complaint_evidence_provider_case_meta(
                    $pdo,
                    (int) ($detail['triage_id'] ?? 0),
                    (int) ($detail['patient_id'] ?? 0),
                    (string) ($detail['assessed_at'] ?? '')
                );
            } else {
                $detail['supporting_evidence'] = ['has_file' => false];
            }
            echo json_encode(['success' => true, 'report' => $detail]);
            exit;
        }

        $filter = trim((string) ($_GET['status'] ?? 'all'));
        $reports = case_reports_admin_list($pdo, $filter === 'all' ? null : $filter);
        echo json_encode([
            'success' => true,
            'reports' => $reports,
            'pending_count' => case_reports_pending_count($pdo),
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    auth_csrf_require();

    $reportId = (int) ($_POST['report_id'] ?? $_POST['id'] ?? 0);
    if ($reportId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Report ID is required.']);
        exit;
    }

    if (in_array($action, ['dismiss', 'confirm', 'escalate', 'under_review'], true)) {
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        $result = case_report_admin_review($pdo, $reportId, $adminId, $action, $note, $isSuperadmin);
        if (!$result['success']) {
            http_response_code(400);
        }
        echo json_encode($result);
        exit;
    }

    if ($action === 'restrict_patient' || $action === 'suspend_patient' || $action === 'restore_patient') {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($patientId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Patient ID is required.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$patientId]);
        $targetRole = (string) ($stmt->fetchColumn() ?: '');
        if ($targetRole !== 'patient') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Account restrictions apply to patient accounts only.']);
            exit;
        }

        if (!portal_can_manage_user($pdo, $patientId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You cannot manage this account.']);
            exit;
        }

        $statusAction = match ($action) {
            'restrict_patient' => 'restrict',
            'suspend_patient'  => 'suspend',
            'restore_patient'  => null,
            default            => '',
        };

        if ($action === 'restore_patient') {
            $acctStmt = $pdo->prepare('SELECT account_status FROM users WHERE id = ? LIMIT 1');
            $acctStmt->execute([$patientId]);
            $acctStatus = AccountStatus::normalize((string) ($acctStmt->fetchColumn() ?: ''));
            if ($acctStatus === AccountStatus::SUSPENDED) {
                $statusAction = 'reactivate';
            } elseif ($acctStatus === AccountStatus::RESTRICTED) {
                $statusAction = 'lift_restriction';
            } else {
                echo json_encode(['success' => false, 'message' => 'This account is not restricted or suspended.']);
                exit;
            }
        }

        if ($statusAction === 'suspend' && !$isSuperadmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only the Super Administrator can suspend patient accounts.']);
            exit;
        }

        if (in_array($statusAction, ['lift_restriction', 'reactivate'], true) && !$isSuperadmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only the Super Administrator can restore restricted or suspended accounts.']);
            exit;
        }

        if ($statusAction === '' || $statusAction === null) {
            echo json_encode(['success' => false, 'message' => 'Invalid account action.']);
            exit;
        }

        $result = user_account_status_change($pdo, $patientId, $statusAction, $reason, $adminId);
        if (!$result['success']) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        $auditType = match ($statusAction) {
            'restrict'         => AuditAction::PATIENT_RESTRICTED,
            'suspend'          => AuditAction::PATIENT_SUSPENDED,
            'lift_restriction', 'reactivate' => AuditAction::PATIENT_RESTORED,
            default            => AuditAction::ACCOUNT_STATUS_CHANGED,
        };

        audit_log($pdo, [
            'patient_id'  => $patientId,
            'action_type' => $auditType,
            'description' => 'Account status changed via case report review.',
            'meta'        => [
                'report_id' => $reportId,
                'action'    => $statusAction,
                'reason'    => $reason,
                'by'        => $adminId,
            ],
        ]);

        if ($statusAction === 'restrict') {
            NotificationEvents::patientAccountRestricted($pdo, $patientId, $adminId);
        } elseif ($statusAction === 'suspend') {
            NotificationEvents::patientAccountSuspended($pdo, $patientId, $adminId);
        } elseif ($statusAction === 'lift_restriction' || $statusAction === 'reactivate') {
            NotificationEvents::patientAccountRestored($pdo, $patientId, $adminId);
        }

        echo json_encode($result);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('admin/case_reports: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Request failed. Please try again.']);
}
