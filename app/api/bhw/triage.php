<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/TriageLevelService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_clinical.php';

$ctx = bhw_api_bootstrap($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'assess';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($action === 'assess') {
        if ($method !== 'POST') {
            Api::error('Method not allowed.', 405);
        }
        $complaint = trim($_POST['chief_complaint'] ?? '');
        if ($complaint === '') {
            Api::error('Describe the patient\'s health concern.');
        }
        if (mb_strlen($complaint) > 500) {
            Api::error('You have reached the maximum limit of 500 characters.');
        }
        if (!bhw_complaint_is_substantive($complaint)) {
            Api::error('Please describe the patient\'s main health concern in their own words.');
        }
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        if ($patientId <= 0) {
            Api::error('Select a patient before running triage.');
        }
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            Api::error('Patient not in your assigned barangay.');
        }
        $result = BhwWorkflows::assessTriageWithPipeline($complaint, $patientId);
        $assessment = $result['assessment'];
        require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_clinical.php';
        $routing = $result['routing'] ?? bhw_triage_routing_meta($assessment);
        Api::success([
            'assessment'       => $assessment,
            'pipeline'         => $result['pipeline'],
            'routing'          => $routing,
            'triage_tier'      => $routing['tier'] ?? bhw_triage_resolve_tier($assessment),
            'is_emergency'     => ($routing['tier'] ?? '') === TriageLevelService::EMERGENCY,
            'is_urgent'        => ($routing['tier'] ?? '') === TriageLevelService::URGENT,
            'assessment_token' => $result['assessment_token'] ?? '',
        ], 'AI assessment complete.');
    } elseif ($action === 'submit') {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $complaint = trim($_POST['chief_complaint'] ?? '');
        if ($complaint === '') {
            Api::error('Describe the patient\'s health concern.');
        }
        if (mb_strlen($complaint) > 500) {
            Api::error('You have reached the maximum limit of 500 characters.');
        }
        if (!bhw_complaint_is_substantive($complaint)) {
            Api::error('Please describe the patient\'s main health concern in their own words.');
        }
        $teleconsultConsent = !empty($_POST['teleconsult_consent']) && $_POST['teleconsult_consent'] !== '0';
        $assessmentToken = trim((string) ($_POST['assessment_token'] ?? ''));

        $result = BhwWorkflows::submitTriageAndBook(
            $pdo,
            $ctx,
            $patientId,
            [],
            $complaint,
            $slotId,
            $teleconsultConsent,
            $assessmentToken
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
