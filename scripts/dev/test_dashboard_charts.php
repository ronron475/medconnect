<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
require BASE_PATH . '/config/db.php';
require BASE_PATH . '/app/includes/admin_dashboard_charts.php';

echo json_encode(admin_dashboard_chart_payload($pdo, 30), JSON_PRETTY_PRINT);
