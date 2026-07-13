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
        $date = trim($_GET['date'] ?? '');
        $rangeDays = (int) ($_GET['range_days'] ?? 0);
        $priority = strtolower(trim($_GET['priority'] ?? 'standard'));
        if ($providerId <= 0) {
            Api::error('Provider required.');
        }

        $activeDays = bhw_provider_active_weekdays($pdo, $providerId);
        $maxDays = $priority === 'urgent' ? 7 : 28;

        if ($rangeDays > 0 || $date === '') {
            $slots = $priority === 'urgent'
                ? bhw_fetch_priority_slots($pdo, $providerId, $maxDays, 8)
                : bhw_fetch_bookable_slots_range($pdo, $providerId, $maxDays);
            $nextDate = bhw_provider_next_bookable_date($pdo, $providerId, $maxDays);
            $notice = '';
            if ($slots === []) {
                $notice = $activeDays === []
                    ? 'This provider has not published a weekly schedule yet.'
                    : 'No open slots in the next ' . $maxDays . ' days.';
            }

            Api::success([
                'slots'                 => $slots,
                'date'                  => $nextDate,
                'range_days'            => $maxDays,
                'priority_mode'         => $priority,
                'active_days'           => $activeDays,
                'active_days_label'     => bhw_provider_format_weekdays($activeDays),
                'suggested_date'        => $nextDate,
                'notice'                => $notice,
                'provider_has_schedule' => $activeDays !== [],
            ]);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Api::error('Invalid date.');
        }

        $activeDays = bhw_provider_active_weekdays($pdo, $providerId);
        $slots = bhw_fetch_bookable_slots($pdo, $providerId, $date);
        $suggestedDate = null;
        $notice = '';

        if ($slots === []) {
            if ($activeDays === []) {
                $notice = 'This provider has not published a weekly schedule yet.';
            } elseif (!bhw_provider_day_is_active($pdo, $providerId, $date)) {
                $notice = 'Provider is not available on this day. Available: ' . bhw_provider_format_weekdays($activeDays) . '.';
            } else {
                $notice = 'No open slots remain for this date.';
            }

            $nextDate = bhw_provider_next_bookable_date($pdo, $providerId);
            if ($nextDate !== null && $nextDate !== $date) {
                $suggestedDate = $nextDate;
            }
        }

        Api::success([
            'slots'           => $slots,
            'date'            => $date,
            'active_days'     => $activeDays,
            'active_days_label' => bhw_provider_format_weekdays($activeDays),
            'suggested_date'  => $suggestedDate,
            'notice'          => $notice,
            'provider_has_schedule' => $activeDays !== [],
        ]);
    } elseif ($action === 'providers') {
        $rows = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'provider' AND is_active = 1 ORDER BY last_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$provider) {
            $pid = (int) $provider['id'];
            $days = bhw_provider_active_weekdays($pdo, $pid);
            $provider['active_days'] = $days;
            $provider['active_days_label'] = bhw_provider_format_weekdays($days);
            $provider['next_available_date'] = bhw_provider_next_bookable_date($pdo, $pid);
        }
        unset($provider);
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
