<?php
/** @deprecated Use views/provider/partials/sidebar.php */
header('Location: ' . rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/') . '/views/provider/dashboard.php', true, 301);
exit;
