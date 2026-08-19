<?php
/**
 * Legacy Super Admin BHW approval queue URL.
 * Pending review is consolidated under Barangay Health Workers (BHW Management hub).
 */
require_once __DIR__ . '/_bootstrap.php';

$params = ['tab' => 'pending'];
if (isset($_GET['approved'])) {
    $params['approved'] = '1';
}
if (isset($_GET['rejected'])) {
    $params['rejected'] = '1';
}

header('Location: ' . ASSET_BASE . '/views/superadmin/bhw_applications.php?' . http_build_query($params));
exit;
