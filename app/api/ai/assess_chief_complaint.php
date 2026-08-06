<?php
/**
 * Canonical chief-complaint NLP + CDS triage API.
 * Shared by CDS demo, patient registration Step 3, BHW, and patient portal.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePost();

set_time_limit(210);

$complaint = trim((string) ($_POST['chief_complaint'] ?? $_POST['complaint'] ?? $_POST['text'] ?? ''));
$symptoms = $_POST['symptoms'] ?? [];
if (is_string($symptoms)) {
    $decoded = json_decode($symptoms, true);
    $symptoms = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $symptoms)));
}
if (!is_array($symptoms)) {
    $symptoms = [];
}

$debugMode = filter_var($_POST['debug'] ?? $_GET['debug'] ?? getenv('MEDCONNECT_NLP_DEBUG'), FILTER_VALIDATE_BOOLEAN);
if ($debugMode) {
    NlpPipelineDebug::enable(true);
    NlpPipelineDebug::reset();
}

if ($complaint === '' && $symptoms === []) {
    Api::error('Enter a chief complaint to analyze.');
}

if (mb_strlen($complaint) > 1000) {
    Api::error('Chief complaint is too long (max 1000 characters).');
}

$assessment = ChiefComplaintNlpService::assess($complaint, $symptoms);

if (!empty($assessment['error'])) {
    Api::error('Unable to analyze complaint.');
}

$summary = ChiefComplaintNlpService::buildCdsSummary($assessment, $complaint);
$clinicalUrgency = ChiefComplaintNlpService::buildClinicalUrgency($assessment);
$registration = ChiefComplaintNlpService::buildRegistrationPayload($assessment, $complaint);

Api::success([
    'assessment'       => $assessment,
    'summary'          => $summary,
    'clinical_urgency' => $clinicalUrgency,
    'registration'     => $registration,
    'pipeline_debug'   => NlpPipelineDebug::isEnabled() ? NlpPipelineDebug::trace() : null,
    'service'          => AiServiceClient::connectionStatus(),
    'engine_chain'     => ChiefComplaintNlpService::ENGINE_CHAIN,
], 'Chief complaint analysis complete.');
