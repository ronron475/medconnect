<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

$ctx = bhw_api_bootstrap($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'assess';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($action === 'assess') {
        if ($method !== 'POST') {
            Api::error('Method not allowed.', 405);
        }
        $complaint = trim($_POST['chief_complaint'] ?? '');
        $symptoms = $_POST['symptoms'] ?? [];
        if (is_string($symptoms)) {
            $symptoms = json_decode($symptoms, true) ?: array_filter(explode(',', $symptoms));
        }
        $assessment = BhwWorkflows::assessTriage($complaint, (array) $symptoms);
        require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_clinical.php';
        Api::success([
            'assessment'   => $assessment,
            'is_emergency' => bhw_triage_is_emergency($assessment),
        ], 'AI assessment complete.');
    } elseif ($action === 'submit') {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $complaint = trim($_POST['chief_complaint'] ?? '');
        $symptoms = $_POST['symptoms'] ?? [];
        if (is_string($symptoms)) {
            $symptoms = json_decode($symptoms, true) ?: [];
        }
        $teleconsultConsent = !empty($_POST['teleconsult_consent']) && $_POST['teleconsult_consent'] !== '0';

        $result = BhwWorkflows::submitTriageAndBook(
            $pdo,
            $ctx,
            $patientId,
            (array) $symptoms,
            $complaint,
            $slotId,
            $teleconsultConsent
        );

        if (!empty($result['emergency'])) {
            Api::success($result, $result['message'] ?? 'Emergency referral created.');
        } else {
            Api::success($result, 'Triage submitted and consultation booked.');
        }
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (InvalidArgumentException|RuntimeException $e) {
    Api::error($e->getMessage());
} catch (Throwable $e) {
    Api::error('Triage failed: ' . $e->getMessage(), 500);
}
