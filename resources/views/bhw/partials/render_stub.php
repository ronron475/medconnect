<?php
$bhw_current_file = $bhw_current_file ?? '';
if ($bhw_current_file === '' && !empty($_SERVER['SCRIPT_NAME'])) {
    $bhw_current_file = preg_replace('#^.*?/views/bhw/#', '', str_replace('\\', '/', $_SERVER['SCRIPT_NAME']));
}
require __DIR__ . '/bhw_bootstrap.php';
require __DIR__ . '/layout_open.php';
require __DIR__ . '/bhw_stub_content.php';
require __DIR__ . '/layout_close.php';
