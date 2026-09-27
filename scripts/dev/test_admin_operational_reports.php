<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/db.php';
require_once $root . '/app/includes/admin_operational_reports.php';
require_once $root . '/app/includes/portal_auth.php';

$pass = 0;
$fail = 0;

function check(bool $ok, string $label): void
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

function renderModule(array $module): string
{
    $reportModule = $module;
    $reportPages = ['appointments' => 1, 'users' => 1, 'audit' => 1];
    $reportBasePath = '/views/admin/analytics.php';
    $reportExportBase = '/app/api/admin/export_report.php';
    ob_start();
    require dirname(__DIR__, 2) . '/resources/views/admin/partials/operational_report_module.php';

    return (string) ob_get_clean();
}

$exportSrc = (string) file_get_contents($root . '/app/api/admin/export_report.php');
$analyticsSrc = (string) file_get_contents($root . '/resources/views/admin/analytics.php');
$superSrc = (string) file_get_contents($root . '/resources/views/superadmin/analytics.php');
$authSrc = (string) file_get_contents($root . '/resources/views/admin/_portal_access.php');

$marker = 'rptui_' . bin2hex(random_bytes(3));
$userId = 0;
$consultId = 0;

try {
    check(str_contains($exportSrc, 'admin_operational_report_all'), 'CSV export uses the shared report source');
    check(!str_contains($exportSrc, 'FROM consultations') && !str_contains($exportSrc, 'FROM users') && !str_contains($exportSrc, 'FROM patient_audit_logs'), 'CSV file does not keep a second copy of the report SQL');
    check(str_contains($analyticsSrc, 'admin_operational_report_page'), 'Analytics page loads report pages from the shared source');
    check(str_contains($superSrc, "_module.php"), 'SuperAdmin analytics reuses the Admin module');
    check(str_contains($authSrc, "auth_require_role(['admin', 'superadmin'])"), 'Report page allows Admin and SuperAdmin');
    check(str_contains($exportSrc, 'portal_api_require_admin_portal') || str_contains((string) file_get_contents($root . '/app/api/admin/_auth.php'), 'portal_api_require_admin_portal'), 'CSV endpoint keeps the admin portal gate');

    foreach (['provider', 'bhw', 'patient', 'admin', 'superadmin'] as $role) {
        $_SESSION['user_role'] = $role;
        $allowed = portal_is_admin_portal();
        if (in_array($role, ['admin', 'superadmin'], true)) {
            check($allowed, "Role {$role} is allowed into admin reports");
        } else {
            check(!$allowed, "Role {$role} is denied admin reports");
        }
    }

    $headers = [
        'appointments' => ['ID', 'Patient ID', 'Provider', 'Type', 'Status', 'Date', 'Time'],
        'users' => ['ID', 'First Name', 'Last Name', 'Email', 'Role', 'Status', 'Joined'],
        'audit' => ['ID', 'User ID', 'Action', 'Description', 'IP Address', 'Timestamp'],
    ];
    foreach ($headers as $type => $expected) {
        $page = admin_operational_report_page($pdo, $type, 1, 25);
        $all = admin_operational_report_all($pdo, $type);
        check($page['headers'] === $expected, "{$type} table headers match the CSV headers");
        check($page['error'] === null, "{$type} query succeeds");
        check($page['rows'] === array_slice($all, 0, 25), "{$type} first page matches the full dataset prefix");
        check(count($all) === $page['total'], "{$type} table total matches the full CSV dataset");
        if (count($all) > 25) {
            $page2 = admin_operational_report_page($pdo, $type, 2, 25);
            check($page2['rows'] === array_slice($all, 25, 25), "{$type} page 2 stays inside the full dataset");
            check($page['rows'] !== $page2['rows'], "{$type} pagination moves to the next records");
        } else {
            check(true, "{$type} dataset fits on one page");
        }
    }

    $auditTotal = (int) $pdo->query('SELECT COUNT(*) FROM patient_audit_logs')->fetchColumn();
    $auditReport = count(admin_operational_report_all($pdo, 'audit'));
    check($auditReport === min(500, $auditTotal), 'Audit snapshot stays at the existing 500-row cap');

    $insertUser = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())');
    $insertUser->execute(['Report', $marker, $marker . '@t.test', password_hash('x', PASSWORD_DEFAULT), 'patient']);
    $userId = (int) $pdo->lastInsertId();
    $users = admin_operational_report_all($pdo, 'users');
    $foundUser = false;
    foreach ($users as $row) {
        if (($row[3] ?? '') === $marker . '@t.test' && ($row[5] ?? '') === 'Active') {
            $foundUser = true;
            break;
        }
    }
    check($foundUser, 'New user appears in the demographics report');

    $insertConsult = $pdo->prepare('INSERT INTO consultations (patient_id, provider_id, provider_name, consult_date, consult_time, consult_type, status, created_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $insertConsult->execute([$userId, null, $marker, date('Y-m-d'), '08:15:00', 'General Consultation', 'scheduled']);
    $consultId = (int) $pdo->lastInsertId();
    $appts = admin_operational_report_all($pdo, 'appointments');
    $foundConsult = false;
    foreach ($appts as $row) {
        if ((int) ($row[0] ?? 0) === $consultId && ($row[2] ?? '') === $marker && ($row[4] ?? '') === 'scheduled') {
            $foundConsult = true;
            break;
        }
    }
    check($foundConsult, 'New consultation appears in the appointment report');

    $pdo->prepare('UPDATE consultations SET status = ? WHERE id = ?')->execute(['completed', $consultId]);
    $appts = admin_operational_report_all($pdo, 'appointments');
    $updated = false;
    foreach ($appts as $row) {
        if ((int) ($row[0] ?? 0) === $consultId && ($row[4] ?? '') === 'completed') {
            $updated = true;
            break;
        }
    }
    check($updated, 'Updated consultation status appears in the appointment report');

    $emptyHtml = renderModule([
        'type' => 'appointments',
        'title' => 'Appointment Summary',
        'description' => 'Complete list of all consultations, provider assignments, and completion status.',
        'headers' => $headers['appointments'],
        'rows' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => 25,
        'error' => null,
    ]);
    check(str_contains($emptyHtml, 'No records found') && str_contains($emptyHtml, 'Download CSV'), 'Empty report shows the empty state and the CSV control');

    $errorHtml = renderModule([
        'type' => 'users',
        'title' => 'User Demographics',
        'description' => 'Breakdown of registered patients by age, gender, and barangay sector.',
        'headers' => $headers['users'],
        'rows' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => 25,
        'error' => 'Unable to load this report.',
    ]);
    check(str_contains($errorHtml, 'Unable to load this report.'), 'Failed report shows an error state');

    $live = admin_operational_report_page($pdo, 'appointments', 1, 25);
    $liveHtml = renderModule($live);
    $firstId = (string) ($live['rows'][0][0] ?? '');
    check($firstId !== '' && str_contains($liveHtml, '>' . $firstId . '<'), 'Live appointment table renders the current database row');
    check(strpos($liveHtml, 'Download CSV') < strpos($liveHtml, '<table'), 'Download CSV is rendered above the table');
} catch (Throwable $e) {
    $fail++;
    echo 'FAIL  test aborted: ' . $e->getMessage() . "\n";
} finally {
    if ($consultId > 0) {
        $pdo->prepare('DELETE FROM consultations WHERE id = ?')->execute([$consultId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
    $left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'rptui_%'")->fetchColumn();
    check($left === 0, 'Temporary report rows were removed');
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
