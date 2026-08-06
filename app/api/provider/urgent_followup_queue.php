<?php
/**
 * API: Provider urgent follow-up queue (JSON).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requireRole('provider');

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/urgent_followup_workflow.php';

$providerId = (int) $_SESSION['user_id'];
$queue = urgent_followup_queue_load($pdo, $providerId);

Api::success([
    'queue' => $queue,
    'count' => count($queue),
], 'Urgent follow-up queue loaded.');
