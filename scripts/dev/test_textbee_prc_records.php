<?php
/**
 * Local checks for TextBee mock mode, PRC validation, and patient-list filters.
 * Does not enable live SMS and does not call TextBee.
 *
 * php scripts/dev/test_textbee_prc_records.php
 */
define('TEXTBEE_SEND_ENABLED', false);
define('TEXTBEE_API_KEY', '');
define('TEXTBEE_DEVICE_ID', '');
define('TEXTBEE_API_BASE', 'https://api.textbee.dev');

require_once dirname(__DIR__, 2) . '/app/core/TextBeeSmsClient.php';
require_once dirname(__DIR__, 2) . '/app/includes/provider_verification.php';
require_once dirname(__DIR__, 2) . '/app/includes/admin_patient_records.php';
require_once dirname(__DIR__, 2) . '/app/includes/followup_sms.php';

$failed = 0;

function check(bool $ok, string $label, string $detail = ''): void
{
    global $failed;
    if ($ok) {
        echo "PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$mock = TextBeeSmsClient::send('+639171234567', 'Hello from the test.');
check($mock['status'] === 'mocked' && !empty($mock['mocked']) && $mock['success'] === true, 'disabled TextBee returns a mock, not a delivery receipt');
check(str_starts_with((string) $mock['provider_ref'], 'mock-'), 'mock reference is local');
check(TextBeeSmsClient::send('123', 'Hello')['status'] === 'failed', 'invalid mobile is rejected before send');
check(TextBeeSmsClient::toE164('09171234567') === '+639171234567', 'PH mobile converts to E.164');

check(provider_verification_validate_prc('0123456') === null, 'numeric PRC license is accepted');
check(provider_verification_validate_prc('ABCDE') !== null, 'PRC license without a digit is rejected');
check(provider_verification_validate_prc('12') !== null, 'short PRC license is rejected');
check(provider_verification_normalize_prc(' 0123 456 ') === '0123456', 'PRC license is normalized');

$filters = admin_patient_records_normalize([
    'page' => '-4',
    'per_page' => '500',
    'status' => 'nope',
    'sort' => 'drop table',
    'consult_date' => '17-10-2026',
    'q' => '  Ana  ',
]);
check($filters['page'] === 1 && $filters['per_page'] === 50, 'pagination input is clamped');
check($filters['status'] === 'all' && $filters['sort'] === 'name', 'unknown filters fall back to safe values');
check($filters['consult_date'] === '' && $filters['q'] === 'Ana', 'invalid date is ignored and search is trimmed');

$body = followup_sms_body('Ana Cruz', 'Oct 20, 2026 at 9:00 AM', 44);
check(str_contains($body, 'Ana Cruz') && str_contains($body, 'Oct 20, 2026') && str_contains($body, '#44'), 'SMS body includes name, date, and reference');
check(!str_contains(strtolower($body), 'diagnosis'), 'SMS body does not include a diagnosis');

try {
    require_once dirname(__DIR__, 2) . '/config/db.php';
    require_once dirname(__DIR__, 2) . '/app/includes/consultation_followup.php';
    provider_verification_ensure_schema($pdo);
    $prc = 'TSTLOCK17617';
    $pdo->prepare('DELETE FROM prc_license_locks WHERE prc_license_number = ?')->execute([$prc]);
    $firstClaim = prc_license_lock_claim($pdo, $prc, 'application', 990001);
    $secondClaim = prc_license_lock_claim($pdo, $prc, 'application', 990002);
    $sameClaim = prc_license_lock_claim($pdo, $prc, 'application', 990001);
    check($firstClaim === null && $sameClaim === null, 'same application can keep its PRC lock');
    check(is_string($secondClaim) && $secondClaim !== '', 'a second application cannot take the same PRC number', (string) $secondClaim);
    prc_license_lock_release($pdo, $prc, 'application', 990001);
    $left = $pdo->prepare('SELECT COUNT(*) FROM prc_license_locks WHERE prc_license_number = ?');
    $left->execute([$prc]);
    check((int) $left->fetchColumn() === 0, 'released PRC lock is removed');

    $listed = admin_patient_records_list($pdo, ['q' => 'a', 'page' => 1, 'per_page' => 5, 'status' => 'active']);
    check(is_array($listed['patients']) && $listed['per_page'] === 5, 'admin patient list query runs with server-side paging', 'total=' . $listed['total']);

    $patient = $pdo->query("
        SELECT u.id
        FROM users u
        INNER JOIN patient_registrations pr ON pr.user_id = u.id
        WHERE u.role = 'patient'
          AND pr.contact_number IS NOT NULL
          AND pr.contact_number <> ''
        ORDER BY u.id DESC
        LIMIT 1
    ")->fetchColumn();
    $provider = $pdo->query("SELECT id FROM users WHERE role = 'provider' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$patient || !$provider) {
        echo "SKIP  follow-up SMS duplicate check (no local patient and provider)\n";
    } else {
        followup_sms_ensure_schema($pdo);
        consultation_followup_ensure_schema($pdo);
        $pdo->prepare("INSERT INTO followups (patient_id, provider_id, followup_date, status, message) VALUES (?, ?, '2026-10-20', 'scheduled', 'test reminder')")
            ->execute([(int) $patient, (int) $provider]);
        $followupId = (int) $pdo->lastInsertId();
        try {
            $sms1 = followup_sms_send($pdo, $followupId, (int) $provider);
            $log = $pdo->prepare('SELECT recipient, message_body, status FROM followup_sms_logs WHERE followup_id = ? AND status = ? ORDER BY id DESC LIMIT 1');
            $log->execute([$followupId, 'mocked']);
            $logRow = $log->fetch(PDO::FETCH_ASSOC) ?: [];
            $sms2 = followup_sms_send($pdo, $followupId, (int) $provider);
            check(($sms1['status'] ?? '') === 'mocked' && !empty($sms1['mocked']), 'scheduled follow-up SMS is mocked and not delivered', (string) ($sms1['message'] ?? ''));
            check(($logRow['message_body'] ?? 'x') === '' && strlen((string) ($logRow['recipient'] ?? '')) === 4, 'new SMS log stores an empty body and a redacted recipient');
            check(($sms2['status'] ?? '') === 'duplicate', 'second SMS for the same follow-up is blocked', (string) ($sms2['status'] ?? ''));
            check(TextBeeSmsClient::networkCallCount() === 0, 'mock mode does not call TextBee');
        } finally {
            $pdo->prepare('DELETE FROM followup_sms_logs WHERE followup_id = ?')->execute([$followupId]);
            $pdo->prepare('DELETE FROM followups WHERE id = ?')->execute([$followupId]);
        }
    }
    require __DIR__ . '/test_security_fix_cases.php';
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL  database checks — ' . $e->getMessage() . "\n";
}

$liveScript = __DIR__ . '/test_textbee_live_claim.php';
$liveCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($liveScript);
$liveOut = [];
$liveCode = 1;
exec($liveCmd . ' 2>&1', $liveOut, $liveCode);
foreach ($liveOut as $line) {
    echo $line . "\n";
}
check($liveCode === 0, 'live-claim test script passed');

echo $failed === 0 ? "OK\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
