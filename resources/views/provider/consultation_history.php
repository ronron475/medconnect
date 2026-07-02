<?php
$target = ASSET_BASE . '/views/provider/medical_records.php?view=history';
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '&' . $_SERVER['QUERY_STRING'];
}
header('Location: ' . $target, true, 301);
exit;
