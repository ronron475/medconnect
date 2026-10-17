<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_nav_inbox.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_prescription_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_records_history.php';

function bhw_records_text(array $row, string $key): string
{
    return trim((string) ($row[$key] ?? ''));
}

/**
 * Fields the BHW patient profile already returns. Identity documents,
 * consent, and OCR payloads stay off this view.
 */
function bhw_records_patient_summary(?array $patient): array
{
    if (!$patient) {
        return [];
    }
    $blood = bhw_records_text($patient, 'blood_type');
    if (strcasecmp($blood, 'unknown') === 0) {
        $blood = '';
    }

    return [
        'id' => (int) ($patient['id'] ?? 0),
        'first_name' => bhw_records_text($patient, 'first_name'),
        'middle_name' => bhw_records_text($patient, 'middle_name'),
        'last_name' => bhw_records_text($patient, 'last_name'),
        'suffix' => bhw_records_text($patient, 'suffix'),
        'age' => bhw_records_text($patient, 'age'),
        'gender' => bhw_records_text($patient, 'gender'),
        'contact_number' => bhw_records_text($patient, 'contact_number'),
        'email' => bhw_records_text($patient, 'email'),
        'barangay' => bhw_records_text($patient, 'barangay'),
        'purok' => bhw_records_text($patient, 'purok'),
        'city_municipality' => bhw_records_text($patient, 'city_municipality'),
        'province' => bhw_records_text($patient, 'province'),
        'address' => bhw_records_text($patient, 'address'),
        'full_address' => bhw_records_text($patient, 'full_address'),
        'allergies' => bhw_records_text($patient, 'allergies'),
        'existing_conditions' => bhw_records_text($patient, 'existing_conditions'),
        'current_medications' => bhw_records_text($patient, 'current_medications'),
        'blood_type' => $blood,
    ];
}

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $patientId = (int) ($_GET['patient_id'] ?? 0);
        bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
        $records = [];
        $rxView = bhw_records_prescription_view($pdo, $ctx, $patientId);
        $records['prescriptions_shared'] = $rxView['shared'];
        $records['prescriptions'] = $rxView['prescriptions'];
        try {
            $records['consultations'] = bhw_records_consultations($pdo, $ctx, $patientId);
        } catch (Throwable $e) {
            $records['consultations'] = [];
        }
        $patient = bhw_records_patient_summary(BhwWorkflows::getPatient($pdo, $ctx, $patientId));
        $bhwId = (int) ($_SESSION['user_id'] ?? 0);
        $isRefresh = (($_GET['refresh'] ?? '') === '1');
        if (!$isRefresh) {
            bhw_audit($pdo, $patientId, 'bhw_records_viewed', 'BHW viewed patient records.');
            if ($bhwId > 0) {
                bhw_nav_mark_records_read($pdo, $bhwId, $patientId);
            }
        }
        Api::success([
            'records' => $records,
            'patient' => $patient,
            'bhw_records' => bhw_nav_records_unread_count($pdo, $bhwId, $ctx),
        ]);
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    Api::error('Request failed. Please try again.', 500);
}
