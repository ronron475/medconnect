<?php
if (!defined('ASSET_BASE')) {
    require_once dirname(__DIR__, 2) . '/mc_load.php';
}
$target = ASSET_BASE . '/views/provider/medical_records.php';
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}
header('Location: ' . $target, true, 301);
exit;
