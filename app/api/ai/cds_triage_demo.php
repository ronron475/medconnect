<?php
/**
 * Public demo API: rule-based CDS triage on chief complaint (no login required).
 * Delegates to the canonical ChiefComplaintNlpService (same as production Step 3).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePost();

set_time_limit(210);

$complaint = trim((string) ($_POST['chief_complaint'] ?? $_POST['complaint'] ?? $_POST['text'] ?? ''));
$debugMode = filter_var($_POST['debug'] ?? $_GET['debug'] ?? getenv('MEDCONNECT_NLP_DEBUG'), FILTER_VALIDATE_BOOLEAN);
if ($debugMode) {
    NlpPipelineDebug::enable(true);
    NlpPipelineDebug::reset();
}

if ($complaint === '') {
    Api::error('Enter a chief complaint to analyze.');
}

if (mb_strlen($complaint) > 1000) {
    Api::error('Chief complaint is too long (max 1000 characters).');
}

$assessment = ChiefComplaintNlpService::assess($complaint, []);

if (!empty($assessment['error'])) {
    Api::error('Unable to analyze complaint.');
}

Api::success([
    'assessment' => $assessment,
    'summary'    => ChiefComplaintNlpService::buildCdsSummary($assessment, $complaint),
    'pipeline_debug' => NlpPipelineDebug::isEnabled() ? NlpPipelineDebug::trace() : null,
    'service'    => AiServiceClient::connectionStatus(),
    'engine_chain' => ChiefComplaintNlpService::ENGINE_CHAIN,
], 'CDS triage analysis complete.');
