<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_activity.php';

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$bhwId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'log' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $event = trim($_POST['event'] ?? '');
        if ($event === 'consultations_viewed') {
            bhw_activity_log($pdo, 'bhw_consultations_viewed', 'BHW viewed consultation center.');
        }
        Api::success([], 'Logged.');
    }

    switch ($action) {
        case 'list':
            $result = bhw_activity_list($pdo, $bhwId, [
                'page'      => $_GET['page'] ?? 1,
                'per_page'  => $_GET['per_page'] ?? 20,
                'q'         => trim($_GET['q'] ?? ''),
                'module'    => trim($_GET['module'] ?? ''),
                'action'    => trim($_GET['action_type'] ?? ''),
                'period'    => trim($_GET['period'] ?? ''),
                'date_from' => trim($_GET['date_from'] ?? ''),
                'date_to'   => trim($_GET['date_to'] ?? ''),
            ]);
            Api::success($result);
            break;
        case 'get':
            $id = (int) ($_GET['id'] ?? 0);
            $row = bhw_activity_get($pdo, $bhwId, $id);
            if (!$row) {
                Api::error('Activity not found.', 404);
            }
            Api::success(['activity' => $row]);
            break;
        case 'modules':
            Api::success([
                'modules' => array_values(array_unique(bhw_activity_module_map())),
                'actions' => array_keys(bhw_activity_module_map()),
            ]);
            break;
        default:
            Api::error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    Api::error('Activity log failed: ' . $e->getMessage(), 500);
}
