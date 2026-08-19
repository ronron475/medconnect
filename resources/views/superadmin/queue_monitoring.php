<?php
/**
 * Legacy Super Admin queue monitoring URL.
 * Queue monitoring is consolidated under Doctors (Doctor Management hub).
 */
require_once __DIR__ . '/_bootstrap.php';

header('Location: ' . ASSET_BASE . '/views/superadmin/doctor_applications.php?tab=queue');
exit;
