<?php
/**
 * BHW API: External Healthcare Visits (patient history — not MedConnect consultations).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_external_healthcare_visits.php';

$ctx = bhw_api_bootstrap($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$bhwId = (int) ($_SESSION['user_id'] ?? 0);

try {
    switch ($action) {
        case 'list':
            $patientId = (int) ($_GET['patient_id'] ?? 0);
            bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
            Api::success([
                'visits' => patient_external_healthcare_visits_for_display($pdo, $patientId),
                'facility_types' => patient_external_healthcare_facility_types(),
                'information_sources' => patient_external_healthcare_info_sources(),
            ]);
            break;

        case 'save':
            if ($method !== 'POST') {
                Api::error('Method not allowed.', 405);
            }
            $patientId = (int) ($_POST['patient_id'] ?? 0);
            bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
            if ($bhwId <= 0) {
                Api::error('BHW session required.', 401);
            }
            if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
                Api::error('ACCESS DENIED: Patient is not registered in your assigned barangay.', 403);
            }

            $result = patient_external_healthcare_visits_save(
                $pdo,
                $patientId,
                $bhwId,
                'bhw',
                $_POST
            );
            if (!$result['success']) {
                Api::error($result['message'] ?? 'Could not save external visit.', 400);
            }

            bhw_audit($pdo, $patientId, 'bhw_external_healthcare_visit', 'BHW recorded an external healthcare visit (patient history).', [
                'external_visit_id' => $result['id'] ?? null,
                'facility_type' => $_POST['facility_type'] ?? null,
                'facility_name' => $_POST['facility_name'] ?? null,
                'visit_date' => $_POST['visit_date'] ?? null,
                'information_source' => $_POST['information_source'] ?? null,
                'bhw_barangay_id' => (int) ($ctx['barangay_id'] ?? 0),
                'bhw_barangay' => (string) ($ctx['barangay_name'] ?? ''),
                'patient_barangay' => bhw_patient_registered_barangay($pdo, $patientId),
            ]);

            Api::success([
                'id'     => $result['id'] ?? null,
                'visits' => patient_external_healthcare_visits_for_display($pdo, $patientId),
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
