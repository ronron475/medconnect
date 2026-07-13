<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_health_summary.php';

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_settings.php';

$userId = patient_settings_require_patient_ready($pdo);
$summary = patient_health_summary_load($pdo, $userId);

echo json_encode(['success' => true, 'summary' => $summary], JSON_UNESCAPED_UNICODE);
