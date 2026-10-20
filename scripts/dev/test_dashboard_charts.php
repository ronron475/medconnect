<?php
/**
 * Dashboard chart payload + period helpers (Admin live user graphs).
 * Run: php scripts/dev/test_dashboard_charts.php
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';
require BASE_PATH . '/config/db.php';
require BASE_PATH . '/app/includes/admin_dashboard_charts.php';

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "PASS  {$msg}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$msg}\n";
}

// --- Period resolution ---
$preset = admin_chart_resolve_period(30);
assert_true($preset['mode'] === 'preset', 'preset mode for days=30');
assert_true((int) $preset['days'] === 30, 'days normalized to 30');
assert_true($preset['bucket'] === 'day', '1 month uses day buckets');
assert_true($preset['period_label'] === '1 Month', '1 Month label');

$year = admin_chart_resolve_period(365);
assert_true($year['bucket'] === 'month', '1 year uses month buckets');
assert_true($year['period_label'] === '1 Year', '1 Year label');

$custom = admin_chart_resolve_period(30, '2026-01-10', '2026-01-05');
assert_true($custom['mode'] === 'custom', 'custom mode when from/to present');
assert_true($custom['from'] === '2026-01-05' && $custom['to'] === '2026-01-10', 'custom swaps inverted dates');
assert_true($custom['bucket'] === 'day', 'short custom range uses day buckets');

$invalid = admin_chart_resolve_period(7, 'not-a-date', '2026-01-01');
assert_true($invalid['mode'] === 'preset' && (int) $invalid['days'] === 7, 'invalid from falls back to preset days');

assert_true(admin_chart_valid_ymd('2026-02-30') === '', 'rejects impossible calendar date');
assert_true(admin_chart_valid_ymd('2026-02-28') === '2026-02-28', 'accepts valid YMD');

$axis = admin_chart_build_axis('2026-01-01', '2026-01-03', 'day');
assert_true(count($axis) === 3, 'day axis has 3 points');
assert_true(($axis[0]['bucket_key'] ?? '') === '2026-01-01', 'day axis starts correctly');

$monthAxis = admin_chart_build_axis('2026-01-15', '2026-03-20', 'month');
assert_true(count($monthAxis) === 3, 'month axis spans Jan–Mar');

// --- Live payload from DB ---
$payload = admin_dashboard_chart_payload($pdo, 30);
assert_true(isset($payload['overview'], $payload['distribution'], $payload['registrations_by_role']), 'payload has overview/distribution/by-role');
assert_true(isset($payload['period_overview']), 'payload includes period_overview');

$byRole = $payload['registrations_by_role'];
assert_true(isset($byRole['series'], $byRole['roles'], $byRole['totals'], $byRole['total']), 'by-role structure complete');
assert_true(count($byRole['roles']) === 4, 'tracks 4 UI roles');

$roleKeys = array_column($byRole['roles'], 'role');
assert_true($roleKeys === ['patient', 'provider', 'bhw', 'admin'], 'role keys patient/provider/bhw/admin');

foreach ($byRole['roles'] as $roleRow) {
    assert_true(isset($roleRow['label'], $roleRow['color'], $roleRow['total']), 'each role row has label/color/total');
    assert_true(is_int($roleRow['total']) && $roleRow['total'] >= 0, 'role total is non-negative int');
}

foreach ($byRole['series'] as $point) {
    assert_true(isset($point['date'], $point['label'], $point['counts'], $point['total']), 'series point shape');
    foreach (['patient', 'provider', 'bhw', 'admin'] as $rk) {
        assert_true(array_key_exists($rk, $point['counts']), "series point has {$rk} count");
    }
    break; // shape check once
}

$overview = $payload['overview'];
assert_true(
    isset($overview['patients'], $overview['doctors'], $overview['bhw'], $overview['administrators'], $overview['total_users']),
    'overview has all role counts'
);

$distRoles = array_column($payload['distribution'], 'role');
assert_true(in_array('patient', $distRoles, true), 'distribution includes patients');
assert_true(in_array('provider', $distRoles, true), 'distribution includes doctors');
assert_true(in_array('bhw', $distRoles, true), 'distribution includes bhw');
assert_true(in_array('admin', $distRoles, true), 'distribution includes administrators');
assert_true(!in_array('superadmin', $distRoles, true), 'superadmin merged into administrators');

$customPayload = admin_dashboard_chart_payload($pdo, 30, date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));
assert_true(($customPayload['mode'] ?? '') === 'custom', 'payload mode=custom for from/to');
assert_true(isset($customPayload['registrations_by_role']['series']), 'custom payload has by-role series');
assert_true(is_int($customPayload['period_overview']['total_users'] ?? null), 'period_overview totals are ints');

// Privacy: payload must not leak PII (emails/names).
$encoded = json_encode($payload);
assert_true($encoded !== false, 'payload JSON-encodes');
assert_true(
    !preg_match('/"email"\s*:/', $encoded) && !preg_match('/"password"/i', $encoded),
    'payload omits email/password fields'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
