<?php
/**
 * BHW API: record vitals / observations for an open consultation (not doctor SOAP).
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
            ]);
            break;

        case 'get':
            $patientId = (int) ($_GET['patient_id'] ?? 0);
            $consultationId = (int) ($_GET['consultation_id'] ?? 0);
            bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
            if (!consultation_recorded_data_assert_patient_consultation($pdo, $patientId, $consultationId)) {
                Api::error('Consultation does not belong to this patient.', 403);
            }
            Api::success([
                'recorded' => consultation_recorded_data_for_doctor($pdo, $consultationId, $patientId),
                'history'  => consultation_recorded_data_history($pdo, $consultationId, $patientId),
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
            if (!consultation_recorded_data_assert_patient_consultation($pdo, $patientId, $consultationId)) {
                Api::error('Consultation does not belong to this patient.', 403);
            }
            $result = consultation_recorded_data_save(
                $pdo,
                $patientId,
                $consultationId,
                $bhwId,
                'bhw',
                $_POST,
                !empty($_POST['triage_result_id']) ? (int) $_POST['triage_result_id'] : null,
                true
            );
            if (!$result['success']) {
                Api::error($result['message'] ?? 'Could not save recorded data.', 400);
            }
            bhw_audit($pdo, $patientId, 'bhw_consultation_recorded_data', 'BHW saved patient recorded data for consultation.', [
                'consultation_id' => $consultationId,
                'recorded_data_id' => $result['id'] ?? null,
            ]);
            Api::success([
                'id'       => $result['id'] ?? null,
                'recorded' => consultation_recorded_data_for_doctor($pdo, $consultationId, $patientId),
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
