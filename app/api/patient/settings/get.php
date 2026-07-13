<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_settings.php';

$userId = patient_settings_require_patient_ready($pdo);
$settings = patient_settings_load($pdo, $userId);

echo json_encode(['success' => true, 'settings' => $settings], JSON_UNESCAPED_UNICODE);
