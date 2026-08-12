<?php
/**
 * Retired BHW endpoint.
 *
 * Clinical triage and consultation booking are not BHW functions: the BHW is a
 * barangay support user, not the clinical triage decision-maker. The route stays
 * so old clients get an explicit refusal instead of a 404 that looks like an outage.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_scope.php';

bhw_api_bootstrap($pdo);

Api::error('Clinical triage and consultation booking are not available to BHW accounts.', 403);
