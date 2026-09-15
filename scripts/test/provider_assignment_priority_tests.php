<?php
/**
 * Provider assignment priority tests (no database).
 * CLI: php scripts/test/provider_assignment_priority_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/core/TriageLevelService.php';
require_once $root . '/app/includes/triage_provider_assignment.php';
require_once $root . '/app/includes/patient_slot_waitlist.php';

$pass = 0;
$fail = 0;

function pa_assert(bool $ok, string $label): void
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

$three = [
    ['provider_id' => 1, 'slot_count' => 4, 'earliest_start' => '14:00:00', 'workload' => 5],
    ['provider_id' => 2, 'slot_count' => 3, 'earliest_start' => '14:30:00', 'workload' => 1],
    ['provider_id' => 3, 'slot_count' => 2, 'earliest_start' => '14:10:00', 'workload' => 3],
];

pa_assert(
    triage_select_provider_from_candidates(TriageLevelService::NON_URGENT, $three) === 2,
    'TEST 1 NON-URGENT: lowest workload among available doctors (B=1)'
);

$excludeB = [
    ['provider_id' => 1, 'slot_count' => 4, 'earliest_start' => '14:00:00', 'workload' => 5],
    ['provider_id' => 2, 'slot_count' => 0, 'earliest_start' => '', 'workload' => 1],
    ['provider_id' => 3, 'slot_count' => 2, 'earliest_start' => '14:10:00', 'workload' => 3],
];
pa_assert(
    triage_select_provider_from_candidates(TriageLevelService::NON_URGENT, $excludeB) === 3,
    'TEST 2 NON-URGENT: lowest-workload doctor with no slot is excluded (C selected)'
);

$urgentEarlier = [
    ['provider_id' => 11, 'slot_count' => 2, 'earliest_start' => '14:10:00', 'workload' => 6],
    ['provider_id' => 12, 'slot_count' => 4, 'earliest_start' => '14:30:00', 'workload' => 2],
];
pa_assert(
    triage_select_provider_from_candidates(TriageLevelService::URGENT, $urgentEarlier) === 11,
    'TEST 3 URGENT recommended helper: earliest slot is the recommended doctor'
);

$urgentTie = [
    ['provider_id' => 11, 'slot_count' => 2, 'earliest_start' => '14:10:00', 'workload' => 6],
    ['provider_id' => 12, 'slot_count' => 2, 'earliest_start' => '14:10:00', 'workload' => 2],
];
pa_assert(
    triage_select_provider_from_candidates(TriageLevelService::URGENT, $urgentTie) === 12,
    'TEST 4 URGENT: same earliest slot uses weighted workload tie-breaker'
);

$none = [
    ['provider_id' => 1, 'slot_count' => 0, 'earliest_start' => '', 'workload' => 5],
    ['provider_id' => 2, 'slot_count' => 0, 'earliest_start' => '', 'workload' => 1],
    ['provider_id' => 3, 'slot_count' => 0, 'earliest_start' => '', 'workload' => 3],
];
pa_assert(
    triage_select_provider_from_candidates(TriageLevelService::NON_URGENT, $none) === 0,
    'TEST 5 NON-URGENT: no slots → no doctor assignment'
);

pa_assert(
    triage_select_provider_from_candidates(TriageLevelService::EMERGENCY, $three) === 0,
    'TEST 6 EMERGENCY: never uses ordinary workload/slot assignment'
);

pa_assert(
    triage_select_provider_from_candidates(TriageLevelService::URGENT, $none) === 0,
    'URGENT with no valid slots does not invent a doctor'
);

$listed = triage_sort_urgent_booking_candidates($three);
pa_assert(count($listed) === 3, 'URGENT lists all 3 eligible doctors with slots');
pa_assert((int) $listed[0]['provider_id'] === 1, 'URGENT list is ordered by earliest slot (A=14:00 first)');
pa_assert((int) $listed[1]['provider_id'] === 3, 'URGENT list second is next earliest (C=14:10)');
pa_assert((int) $listed[2]['provider_id'] === 2, 'URGENT list last is latest slot (B=14:30)');

$listedNone = triage_sort_urgent_booking_candidates($none);
pa_assert($listedNone === [], 'URGENT list is empty when no doctor has a slot');

$keep = patient_slot_waitlist_choose_offer_provider(
    [10 => 2, 20 => 3],
    [10, 20],
    [10 => 9, 20 => 1],
    10
);
pa_assert($keep === 10, 'Waitlist keeps assigned doctor when they still have a slot');

$workloadPick = patient_slot_waitlist_choose_offer_provider(
    [10 => 2, 20 => 3],
    [10, 20],
    [10 => 9, 20 => 1],
    0
);
pa_assert($workloadPick === 20, 'Waitlist offer uses weighted workload when unassigned');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
