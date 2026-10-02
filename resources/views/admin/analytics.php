<?php
// Old URL for Operational Reports; keep bookmarks and filter links working.
$query = $_GET;
unset($query['path']);
$query = http_build_query($query);
header('Location: operational_reports.php' . ($query !== '' ? '?' . $query : ''), true, 301);
exit;
