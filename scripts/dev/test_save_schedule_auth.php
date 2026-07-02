<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'day' => date('l'),
    'start_time' => '09:00',
    'end_time' => '17:00',
    'duration' => '30',
    'is_active' => '1',
];

session_start();
$_SESSION['user_id'] = 3;
$_SESSION['user_role'] = 'provider';

ob_start();
require dirname(__DIR__, 2) . '/app/api/provider/save_schedule.php';
$output = ob_get_clean();

echo $output . PHP_EOL;
