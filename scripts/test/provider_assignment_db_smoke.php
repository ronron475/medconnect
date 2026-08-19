<?php
/**
 * Read-only: print live assignment candidates from the connected database.
 * CLI: php scripts/test/provider_assignment_db_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/db.php';
require_once $root . '/app/includes/triage_provider_assignment.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Manila');

echo 'DB: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo 'Now: ' . date('Y-m-d l H:i:s') . PHP_EOL . PHP_EOL;

$candidates = triage_collect_bookable_candidates($pdo);
echo "Bookable candidates today:\n";
if ($candidates === []) {
    echo "  (none — NON-URGENT should waitlist, URGENT should not invent a slot)\n";
} else {
    foreach ($candidates as $row) {
        echo sprintf(
            "  provider #%d  slots=%d  earliest=%s  workload=%d\n",
            (int) $row['provider_id'],
            (int) $row['slot_count'],
            (string) $row['earliest_start'],
            (int) $row['workload']
        );
    }
}

$nonUrgent = triage_select_provider_from_candidates(TriageLevelService::NON_URGENT, $candidates);
$urgent = triage_select_provider_from_candidates(TriageLevelService::URGENT, $candidates);
$emergency = triage_select_provider_from_candidates(TriageLevelService::EMERGENCY, $candidates);

echo PHP_EOL . 'Selected NON-URGENT: ' . ($nonUrgent > 0 ? '#' . $nonUrgent . ' ' . triage_provider_display_name($pdo, $nonUrgent) : '(none → waiting queue)') . PHP_EOL;
echo 'Selected URGENT:     ' . ($urgent > 0 ? '#' . $urgent . ' ' . triage_provider_display_name($pdo, $urgent) : '(none → existing urgent booking/referral path)') . PHP_EOL;
echo 'Selected EMERGENCY:  ' . ($emergency > 0 ? '#' . $emergency : '(none — emergency referral workflow)') . PHP_EOL;

echo PHP_EOL . 'Context query check: ';
try {
    $pid = (int) ($pdo->query("SELECT id FROM users WHERE role = 'patient' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
    $ctx = triage_patient_review_booking_context($pdo, $pid > 0 ? $pid : 1);
    echo 'ok patient=' . $pid . ' locked=' . (!empty($ctx['locked']) ? '1' : '0')
        . ' provider=' . (int) ($ctx['provider_id'] ?? 0) . PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo "Smoke OK\n";
