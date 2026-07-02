<?php
/**
 * API: Create doctor (provider) account
 * URL: /app/api/admin/create_doctor.php
 */
$_POST['role'] = 'provider';
require __DIR__ . '/create_staff.php';
