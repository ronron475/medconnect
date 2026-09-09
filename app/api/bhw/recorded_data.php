<?php
/**
 * BHW API: record vitals / observations for a consultation OR as pending pre-consultation data.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_recorded_data.php';

$ctx = bhw_api_bootstrap($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list_open';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$bhwId = (int) ($_SESSION['user_id'] ?? 0);

try {
    switch ($action) {
        case 'list_open':
            $patientId = (int) ($_GET['patient_id'] ?? 0);
            bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
            $open = consultation_recorded_data_open_consults_for_patient($pdo, $patientId);
            $latestByConsult = [];
            foreach ($open as $c) {
                $cid = (int) ($c['id'] ?? 0);
                $latest = consultation_recorded_data_latest($pdo, $cid, $patientId);
                if ($latest) {
                    $latestByConsult[$cid] = consultation_recorded_data_for_doctor($pdo, $cid, $patientId);
                }
            }
            Api::success([
                'consultations' => $open,
                'latest_by_consultation' => $latestByConsult,
                'pending' => consultation_recorded_data_pending_for_bhw($pdo, $patientId),
                'can_save_without_consultation' => true,
            ]);
            break;

        case 'get':
            $patientId = (int) ($_GET['patient_id'] ?? 0);
            $consultationId = (int) ($_GET['consultation_id'] ?? 0);
            bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
            if ($consultationId > 0) {
                if (!consultation_recorded_data_assert_patient_consultation($pdo, $patientId, $consultationId)) {
                    Api::error('Consultation does not belong to this patient.', 403);
                }
                Api::success([
                    'recorded' => consultation_recorded_data_for_doctor($pdo, $consultationId, $patientId),
                    'history'  => consultation_recorded_data_history($pdo, $consultationId, $patientId),
                    'pending'  => consultation_recorded_data_pending_for_bhw($pdo, $patientId),
                ]);
            }
            Api::success([
                'recorded' => consultation_recorded_data_pending_for_bhw($pdo, $patientId),
                'history'  => consultation_recorded_data_pending_history($pdo, $patientId),
                'pending'  => consultation_recorded_data_pending_for_bhw($pdo, $patientId),
            ]);
            break;

        case 'save':
            if ($method !== 'POST') {
                Api::error('Method not allowed.', 405);
            }
            $patientId = (int) ($_POST['patient_id'] ?? 0);
            $consultationId = (int) ($_POST['consultation_id'] ?? 0);
            bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
            if ($bhwId <= 0) {
                Api::error('BHW session required.', 401);
            }

            // If an open consult exists and BHW selected one, attach directly.
            // If no consultation_id, save as PENDING pre-consultation data.
            if ($consultationId > 0) {
                if (!consultation_recorded_data_assert_patient_consultation($pdo, $patientId, $consultationId)) {
                    Api::error('Consultation does not belong to this patient.', 403);
                }
            }

            $result = consultation_recorded_data_save(
                $pdo,
                $patientId,
                $consultationId,
                $bhwId,
                'bhw',
                $_POST,
                !empty($_POST['triage_result_id']) ? (int) $_POST['triage_result_id'] : null,
                $consultationId > 0
            );
            if (!$result['success']) {
                Api::error($result['message'] ?? 'Could not save recorded data.', 400);
            }

            $mode = (string) ($result['mode'] ?? ($consultationId > 0 ? 'consultation' : 'pre_consultation'));
            bhw_audit($pdo, $patientId, 'bhw_consultation_recorded_data', $mode === 'pre_consultation'
                ? 'BHW saved pending pre-consultation recorded data.'
                : 'BHW saved patient recorded data for consultation.', [
                'consultation_id' => $consultationId > 0 ? $consultationId : null,
                'recorded_data_id' => $result['id'] ?? null,
                'mode' => $mode,
                'status' => $result['status'] ?? null,
            ]);

            $recorded = $consultationId > 0
                ? consultation_recorded_data_for_doctor($pdo, $consultationId, $patientId)
                : consultation_recorded_data_pending_for_bhw($pdo, $patientId);

            Api::success([
                'id'       => $result['id'] ?? null,
                'mode'     => $mode,
                'status'   => $result['status'] ?? null,
                'recorded' => $recorded,
                'pending'  => consultation_recorded_data_pending_for_bhw($pdo, $patientId),
            ], $result['message'] ?? 'Saved.');
            break;

        default:
            Api::error('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    $msg = $e->getMessage();
    $denied = stripos($msg, 'barangay') !== false || stripos($msg, 'ACCESS DENIED') !== false;
    Api::error($denied ? 'ACCESS DENIED' : $msg, $denied ? 403 : 400);
} catch (Throwable $e) {
    Api::error('Operation failed: ' . $e->getMessage(), 500);
}
