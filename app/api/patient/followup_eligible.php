<?php
/**
 * API: List completed consultations eligible for follow-up request.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePatientReady($pdo);

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/urgent_followup_workflow.php';

$patientId = (int) $_SESSION['user_id'];
$eligible = urgent_followup_eligible_consultations($pdo, $patientId);

Api::success([
    'consultations' => $eligible,
    'count'         => count($eligible),
], 'Eligible consultations loaded.');
