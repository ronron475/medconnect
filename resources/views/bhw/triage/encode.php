<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
$patientId = isset($_GET['patient_id']) ? '?patient_id=' . (int) $_GET['patient_id'] . '&preview=1' : '?preview=1';
header('Location: ' . ASSET_BASE . '/views/bhw/triage/submit.php' . $patientId, true, 302);
exit;
