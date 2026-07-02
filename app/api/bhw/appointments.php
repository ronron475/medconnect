<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_clinical.php';

$ctx = bhw_api_bootstrap($pdo);
$action = $_GET['action'] ?? 'slots';

try {
    if ($action === 'slots') {
        $providerId = (int) ($_GET['provider_id'] ?? 0);
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        if ($providerId <= 0) {
            Api::error('Provider required.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Api::error('Invalid date.');
        }

        appointment_slots_sync_date($pdo, $providerId, $date);

        $stmt = $pdo->prepare("
            SELECT id, slot_date, start_time, end_time, status
            FROM appointment_slots
            WHERE provider_id = ? AND slot_date = ? AND status = 'available'
            ORDER BY start_time ASC
        ");
        $stmt->execute([$providerId, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $slots = array_values(array_filter(array_map(static function ($row) {
            $bookable = appointment_slot_is_bookable_bhw(
                (string) $row['slot_date'],
                (string) $row['start_time'],
                (string) $row['end_time']
            );
            if (!$bookable) {
                return null;
            }
            return [
                'id'         => (int) $row['id'],
                'slot_date'  => $row['slot_date'],
                'start_time' => $row['start_time'],
                'label'      => bhw_format_slot_label((string) $row['slot_date'], (string) $row['start_time']),
            ];
        }, $rows)));
        Api::success(['slots' => $slots, 'date' => $date]);
    } elseif ($action === 'providers') {
        $rows = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'provider' AND is_active = 1 ORDER BY last_name")->fetchAll(PDO::FETCH_ASSOC);
        Api::success(['providers' => $rows]);
    } elseif ($action === 'schedule') {
        $rows = BhwWorkflows::listConsultations($pdo, $ctx, $_GET['date'] ?? date('Y-m-d'));
        Api::success(['consultations' => $rows]);
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    Api::error($e->getMessage(), 500);
}
