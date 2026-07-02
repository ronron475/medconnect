<?php
/**
 * API: Change user account status (Super Administrator — delegates to admin endpoint logic).
 * URL: /app/api/superadmin/account_status.php
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';

portal_api_require_superadmin();

require dirname(__DIR__) . '/admin/account_status.php';
