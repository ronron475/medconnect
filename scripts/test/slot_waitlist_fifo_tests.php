<?php
/**
 * FIFO waitlist planner tests (no database, no email).
 * CLI: php scripts/test/slot_waitlist_fifo_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap/app.php';
require_once $root . '/app/includes/patient_slot_waitlist.php';

$pass = 0;
$fail = 0;

function fifo_assert(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$label}\n";
}

$six = [
    ['id' => 1],
    ['id' => 2],
    ['id' => 3],
    ['id' => 4],
    ['id' => 5],
    ['id' => 6],
];
$doctorA3 = [['provider_id' => 10, 'slot_count' => 3, 'provider_name' => 'Dr A']];
$plan = patient_slot_waitlist_fifo_plan($six, $doctorA3);
fifo_assert(count($plan) === 6, '6 waiters / 3 slots yields 6 plan rows');
fifo_assert(($plan[0]['action'] ?? '') === 'offer' && (int) $plan[0]['id'] === 1, 'Patient A offered first');
fifo_assert(($plan[1]['action'] ?? '') === 'offer' && (int) $plan[1]['id'] === 2, 'Patient B offered second');
fifo_assert(($plan[2]['action'] ?? '') === 'offer' && (int) $plan[2]['id'] === 3, 'Patient C offered third');
fifo_assert(($plan[3]['action'] ?? '') === 'revert' && (int) $plan[3]['id'] === 4, 'Patient D remains waiting');
fifo_assert(($plan[4]['action'] ?? '') === 'revert', 'Patient E remains waiting');
fifo_assert(($plan[5]['action'] ?? '') === 'revert', 'Patient F remains waiting');
fifo_assert((int) ($plan[0]['provider_id'] ?? 0) === 10, 'offered patients are tied to the doctor who opened real slots');

$multi = [
    ['provider_id' => 10, 'slot_count' => 2, 'provider_name' => 'Dr A'],
    ['provider_id' => 20, 'slot_count' => 3, 'provider_name' => 'Dr B'],
    ['provider_id' => 30, 'slot_count' => 1, 'provider_name' => 'Dr C'],
];
$multiPlan = patient_slot_waitlist_fifo_plan($six, $multi);
$offers = [];
foreach ($multiPlan as $step) {
    if (($step['action'] ?? '') === 'offer') {
        $offers[] = $step;
    }
}
fifo_assert(count($offers) === 6, '6 real slots make 6 waiters eligible');
fifo_assert((int) ($offers[0]['id'] ?? 0) === 1, 'oldest waiter is still first when multiple doctors open slots');

$none = patient_slot_waitlist_fifo_plan(
    [['id' => 99]],
    [['provider_id' => 10, 'slot_count' => 0, 'provider_name' => 'Dr A']]
);
fifo_assert(($none[0]['action'] ?? '') === 'revert', 'zero slots keeps waiters waiting');

$afterCancel = [
    ['id' => 2],
    ['id' => 3],
    ['id' => 1],
];
$backPlan = patient_slot_waitlist_fifo_plan($afterCancel, [['provider_id' => 10, 'slot_count' => 1, 'provider_name' => 'Dr A']]);
fifo_assert(($backPlan[0]['action'] ?? '') === 'offer' && (int) $backPlan[0]['id'] === 2, 'after cancel, next oldest waiter is offered');
fifo_assert(($backPlan[2]['action'] ?? '') === 'revert' && (int) $backPlan[2]['id'] === 1, 'canceller at back of queue is not offered first');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
