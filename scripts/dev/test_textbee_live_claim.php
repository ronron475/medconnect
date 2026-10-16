<?php
/**
 * Live-mode SMS claim checks. TEXTBEE_SEND_ENABLED is true only in this process.
 * A test transport replaces HTTP, so TextBee is never contacted.
 *
 * php scripts/dev/test_textbee_live_claim.php
 */
if (getenv('MEDCONNECT_SMS_CLAIM_CHILD') === '1') {
    define('TEXTBEE_SEND_ENABLED', true);
    define('TEXTBEE_API_KEY', 'mc-test-not-real');
    define('TEXTBEE_DEVICE_ID', '');
    define('TEXTBEE_API_BASE', 'https://api.textbee.dev');
    require_once dirname(__DIR__, 2) . '/app/core/TextBeeSmsClient.php';
    require_once dirname(__DIR__, 2) . '/app/includes/followup_sms.php';
    require_once dirname(__DIR__, 2) . '/config/db.php';
    $log = (string) getenv('MEDCONNECT_SMS_CLAIM_LOG');
    TextBeeSmsClient::setTestTransport(static function () use ($log): array {
        if ($log !== '') {
            file_put_contents($log, "1\n", FILE_APPEND | LOCK_EX);
        }
        usleep(300000);
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'Could not reach TextBee.',
            'provider_ref' => null,
            'mocked' => false,
            'disposition' => 'uncertain',
        ];
    });
    $result = followup_sms_send($pdo, (int) getenv('MEDCONNECT_SMS_FOLLOWUP'), (int) getenv('MEDCONNECT_SMS_ACTOR'));
    echo json_encode([
        'status' => (string) ($result['status'] ?? ''),
        'network' => TextBeeSmsClient::networkCallCount(),
    ]);
    exit;
}

define('TEXTBEE_SEND_ENABLED', true);
define('TEXTBEE_API_KEY', 'mc-test-not-real');
define('TEXTBEE_DEVICE_ID', '');
define('TEXTBEE_API_BASE', 'https://api.textbee.dev');

require_once dirname(__DIR__, 2) . '/app/core/TextBeeSmsClient.php';
require_once dirname(__DIR__, 2) . '/app/includes/followup_sms.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

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

TextBeeSmsClient::setTestTransport(static function (): array {
    throw new RuntimeException('unexpected gateway call');
});

$userIds = [];
$regIds = [];
$followupIds = [];
$calls = 0;

$setTransport = static function (string $disposition) use (&$calls): void {
    TextBeeSmsClient::setTestTransport(static function () use ($disposition, &$calls): array {
        $calls++;
        if ($disposition === 'accepted') {
            return [
                'success' => true,
                'status' => 'accepted',
                'message' => 'accepted by test double',
                'provider_ref' => 'batch-test',
                'mocked' => false,
                'disposition' => 'accepted',
            ];
        }
        if ($disposition === 'rejected') {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'TextBee rejected the SMS. x-api-key: mc-test-not-real',
                'provider_ref' => null,
                'mocked' => false,
                'disposition' => 'rejected',
            ];
        }
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'Could not reach TextBee.',
            'provider_ref' => null,
            'mocked' => false,
            'disposition' => 'uncertain',
        ];
    });
};

try {
    check(TextBeeSmsClient::sendEnabled() && TextBeeSmsClient::networkCallCount() === 0, 'live flag is local to this test and no network call has been made');
    $insertUser = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())');
    $marker = 'mclive_' . bin2hex(random_bytes(3));
    $insertUser->execute(['Test', 'Patient', $marker . '@example.test', password_hash('x', PASSWORD_DEFAULT), 'patient']);
    $patientId = (int) $pdo->lastInsertId();
    $userIds[] = $patientId;
    $insertUser->execute(['Test', 'Doctor', $marker . '.doctor@example.test', password_hash('x', PASSWORD_DEFAULT), 'provider']);
    $doctorId = (int) $pdo->lastInsertId();
    $userIds[] = $doctorId;

    $cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $available = [];
    foreach ($cols as $col) {
        $available[(string) $col['Field']] = $col;
    }
    $values = [
        'email' => $marker . '@example.test',
        'user_id' => $patientId,
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'contact_number' => '09170000333',
        'status' => 'verified',
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $names = [];
    $params = [];
    foreach ($values as $name => $value) {
        if (isset($available[$name])) {
            $names[] = $name;
            $params[] = $value;
        }
    }
    foreach ($available as $name => $col) {
        if (in_array($name, $names, true)) {
            continue;
        }
        $nullable = strtoupper((string) ($col['Null'] ?? '')) === 'YES';
        if ($nullable || ($col['Default'] ?? null) !== null || str_contains(strtolower((string) ($col['Extra'] ?? '')), 'auto_increment')) {
            continue;
        }
        $type = strtolower((string) ($col['Type'] ?? ''));
        $names[] = $name;
        $params[] = str_contains($type, 'int') ? 0 : (str_contains($type, 'date') || str_contains($type, 'time') ? date('Y-m-d H:i:s') : '');
    }
    $pdo->prepare('INSERT INTO patient_registrations (' . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')')->execute($params);
    $regIds[] = (int) $pdo->lastInsertId();

    $makeFollowup = static function (PDO $pdo, int $patientId, int $doctorId) use (&$followupIds): int {
        $pdo->prepare("INSERT INTO followups (patient_id, provider_id, followup_date, status, message) VALUES (?, ?, '2026-12-20', 'scheduled', 'live claim test')")
            ->execute([$patientId, $doctorId]);
        $id = (int) $pdo->lastInsertId();
        $followupIds[] = $id;
        return $id;
    };
    $statusOf = static function (PDO $pdo, int $followupId): string {
        $stmt = $pdo->prepare("SELECT status FROM followup_sms_logs WHERE followup_id = ? AND status <> 'duplicate' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$followupId]);
        return (string) $stmt->fetchColumn();
    };

    $uncertainId = $makeFollowup($pdo, $patientId, $doctorId);
    $calls = 0;
    $setTransport('uncertain');
    $first = followup_sms_send($pdo, $uncertainId, $doctorId);
    $second = followup_sms_send($pdo, $uncertainId, $doctorId);
    check(($first['status'] ?? '') === 'pending' && $calls === 1, 'timeout or ambiguous response leaves a pending claim');
    check(($second['status'] ?? '') === 'duplicate' && $calls === 1, 'pending claim blocks another automatic send');

    $rejectId = $makeFollowup($pdo, $patientId, $doctorId);
    $calls = 0;
    $setTransport('rejected');
    $rejected = followup_sms_send($pdo, $rejectId, $doctorId);
    $retry = followup_sms_send($pdo, $rejectId, $doctorId);
    $errorStmt = $pdo->prepare('SELECT error_message FROM followup_sms_logs WHERE followup_id = ? AND status = ? ORDER BY id DESC LIMIT 1');
    $errorStmt->execute([$rejectId, 'failed']);
    $storedError = (string) $errorStmt->fetchColumn();
    check(($rejected['status'] ?? '') === 'failed' && ($retry['status'] ?? '') === 'failed' && $calls === 2, 'definite gateway rejection stays retryable');
    check(!str_contains($storedError, 'mc-test-not-real'), 'stored gateway error does not keep the test key');

    $okId = $makeFollowup($pdo, $patientId, $doctorId);
    $calls = 0;
    $setTransport('accepted');
    $accepted = followup_sms_send($pdo, $okId, $doctorId);
    $acceptedAgain = followup_sms_send($pdo, $okId, $doctorId);
    $acceptedRow = $pdo->prepare('SELECT message_body, recipient, provider_ref FROM followup_sms_logs WHERE followup_id = ? AND status = ?');
    $acceptedRow->execute([$okId, 'accepted']);
    $acceptedLog = $acceptedRow->fetch(PDO::FETCH_ASSOC) ?: [];
    check(($accepted['status'] ?? '') === 'accepted' && ($acceptedAgain['status'] ?? '') === 'duplicate' && $calls === 1, 'confirmed acceptance is stored once and then blocks a resend');
    check(($acceptedLog['message_body'] ?? 'x') === '' && strlen((string) ($acceptedLog['recipient'] ?? '')) === 4 && ($acceptedLog['provider_ref'] ?? '') === 'batch-test', 'accepted log keeps an empty body, redacted recipient, and gateway reference');

    $acceptId = $makeFollowup($pdo, $patientId, $doctorId);
    $calls = 0;
    $setTransport('accepted');
    followup_sms_test_fail_after_accept(true);
    $afterAccept = followup_sms_send($pdo, $acceptId, $doctorId);
    followup_sms_test_fail_after_accept(false);
    $afterAcceptAgain = followup_sms_send($pdo, $acceptId, $doctorId);
    check(($afterAccept['status'] ?? '') === 'pending' && $statusOf($pdo, $acceptId) === 'pending', 'failure after acceptance leaves the claim unresolved');
    check(($afterAcceptAgain['status'] ?? '') === 'duplicate' && $calls === 1, 'unresolved acceptance does not send a second time');

    $oldId = $makeFollowup($pdo, $patientId, $doctorId);
    $pdo->prepare("INSERT INTO followup_sms_logs (followup_id, patient_id, sent_by, recipient, message_body, status) VALUES (?, ?, ?, '09170000333', 'OLD', 'mocked')")
        ->execute([$oldId, $patientId, $doctorId]);
    $calls = 0;
    $oldResult = followup_sms_send($pdo, $oldId, $doctorId);
    $oldRow = $pdo->prepare('SELECT message_body, recipient FROM followup_sms_logs WHERE followup_id = ? AND message_body = ?');
    $oldRow->execute([$oldId, 'OLD']);
    $old = $oldRow->fetch(PDO::FETCH_ASSOC) ?: [];
    check(($oldResult['status'] ?? '') === 'duplicate' && $calls === 0 && ($old['message_body'] ?? '') === 'OLD', 'existing mocked log still blocks a send and stays unchanged');

    $raceId = $makeFollowup($pdo, $patientId, $doctorId);
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mc_sms_claim_' . bin2hex(random_bytes(4)) . '.log';
    $env = getenv() ?: [];
    $env['MEDCONNECT_SMS_CLAIM_CHILD'] = '1';
    $env['MEDCONNECT_SMS_FOLLOWUP'] = (string) $raceId;
    $env['MEDCONNECT_SMS_ACTOR'] = (string) $doctorId;
    $env['MEDCONNECT_SMS_CLAIM_LOG'] = $logFile;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $procs[] = [
            'proc' => proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env),
            'pipes' => $pipes,
        ];
    }
    $statuses = [];
    $networks = 0;
    foreach ($procs as $item) {
        $raw = is_resource($item['proc']) ? stream_get_contents($item['pipes'][1]) : '';
        $err = is_resource($item['proc']) ? stream_get_contents($item['pipes'][2]) : '';
        if (is_resource($item['proc'])) {
            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);
            proc_close($item['proc']);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $statuses[] = 'bad';
            if ($err !== '') {
                error_log('sms claim child: ' . trim($err));
            }
            continue;
        }
        $statuses[] = (string) ($decoded['status'] ?? '');
        $networks += (int) ($decoded['network'] ?? 0);
    }
    $claimRows = $pdo->prepare("SELECT COUNT(*) FROM followup_sms_logs WHERE followup_id = ? AND status = 'pending'");
    $claimRows->execute([$raceId]);
    $pendingCount = (int) $claimRows->fetchColumn();
    $transportHits = is_file($logFile) ? count(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    sort($statuses);
    check($statuses === ['duplicate', 'pending'] && $pendingCount === 1 && $transportHits === 1 && $networks === 0, 'concurrent attempts cannot both claim and send', implode(',', $statuses) . ' hits=' . $transportHits);
    if (is_file($logFile)) {
        unlink($logFile);
    }
    check(TextBeeSmsClient::networkCallCount() === 0, 'no test contacted TextBee');
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL  live claim checks — ' . $e->getMessage() . "\n";
} finally {
    followup_sms_test_fail_after_accept(false);
    TextBeeSmsClient::setTestTransport(static function (): array {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'closed',
            'provider_ref' => null,
            'mocked' => false,
            'disposition' => 'uncertain',
        ];
    });
    if (isset($pdo) && $pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($followupIds) {
            $in = implode(',', array_fill(0, count($followupIds), '?'));
            $pdo->prepare("DELETE FROM followup_sms_logs WHERE followup_id IN ({$in})")->execute($followupIds);
            $pdo->prepare("DELETE FROM followups WHERE id IN ({$in})")->execute($followupIds);
        }
        if ($regIds) {
            $in = implode(',', array_fill(0, count($regIds), '?'));
            $pdo->prepare("DELETE FROM patient_registrations WHERE id IN ({$in})")->execute($regIds);
        }
        if ($userIds) {
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $pdo->prepare("DELETE FROM users WHERE id IN ({$in})")->execute($userIds);
        }
    }
}

echo $failed === 0 ? "OK\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
