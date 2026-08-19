<?php
/**
 * Legacy Super Admin queue monitoring URL.
 * Queue monitoring is a tab on Consultation Monitoring.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/portal_paths.php';

header('Location: ' . portal_view_url('live_consultation_monitor.php', 'tab=queue'));
exit;
