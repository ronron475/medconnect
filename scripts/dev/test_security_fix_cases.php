<?php
/**
 * Included by test_textbee_prc_records.php after a local database connection exists.
 * Creates temporary rows and deletes them. Does not mark a license verified.
 */
date_default_timezone_set('Asia/Manila');

$cleanError = followup_sms_sanitize_error('x-api-key: not-a-real-key phone +639170000111 token ABCDEFGHIJKLMNOPQRSTUVWXYZ123456');
check(
    is_string($cleanError)
        && !str_contains($cleanError, 'not-a-real-key')
        && !str_contains($cleanError, '639170000111')
        && !str_contains($cleanError, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456'),
    'gateway error text is sanitized'
);

$loginSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/api/login.php');
$providerPos = strpos($loginSrc, "if (\$user['role'] === 'provider')");
$denialPos = strpos($loginSrc, 'provider_login_verification_denial');
$bhwPos = strpos($loginSrc, "if (\$user['role'] === 'bhw')");
$regenPos = strpos($loginSrc, 'session_regenerate_id(true)');
check(
    $providerPos !== false && $denialPos !== false && $bhwPos !== false && $regenPos !== false
        && $denialPos > $providerPos && $denialPos < $bhwPos && $regenPos > $bhwPos,
    'provider verification denial happens before a session is created'
);

$sessionBefore = $_SESSION ?? [];
$failureMessage = provider_login_verification_denial($pdo, 0, static function (): void {
    throw new PDOException('simulated verification query failure');
});
check(
    is_string($failureMessage)
        && !str_contains($failureMessage, 'simulated')
        && !str_contains(strtolower($failureMessage), 'sql'),
    'verification query failure denies provider login without SQL details'
);
check(($_SESSION ?? []) === $sessionBefore, 'verification failure does not create a session');
check(provider_login_verification_denial($pdo, 1, static fn () => 'verified') === null, 'verified status passes the login gate');
$reviewDenial = provider_login_verification_denial($pdo, 1, static fn () => 'needs_review');
check(is_string($reviewDenial) && str_contains($reviewDenial, 'awaiting'), 'needs_review status denies login');
check(provider_login_verification_denial($pdo, 1, static fn () => false) !== null, 'missing verification row denies login');

$verifiedId = $pdo->query("SELECT user_id FROM provider_profiles WHERE verification_status = 'verified' LIMIT 1")->fetchColumn();
if ($verifiedId) {
    check(provider_login_verification_denial($pdo, (int) $verifiedId) === null, 'existing verified provider can pass the login gate');
} else {
    echo "SKIP  existing verified provider login gate\n";
}
$reviewId = $pdo->query("SELECT user_id FROM provider_profiles WHERE verification_status = 'needs_review' LIMIT 1")->fetchColumn();
if ($reviewId) {
    $realReview = provider_login_verification_denial($pdo, (int) $reviewId);
    check(is_string($realReview) && $realReview !== '', 'existing needs_review provider cannot pass the login gate');
} else {
    echo "SKIP  existing needs_review provider login gate\n";
}

$approvalSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/DoctorApplicationService.php');
$accountSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/includes/user_account_status.php');
check(str_contains($approvalSrc, "'needs_review'"), 'account approval records needs_review');
check(!str_contains($approvalSrc, "verification_status = 'verified'") && !str_contains($accountSrc, "verification_status = 'verified'"), 'account approval does not set a license to verified');

$smsEndpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/app/api/provider/send_followup_sms.php');
check(strpos($smsEndpoint, 'followup_sms_provider_gate') < strpos($smsEndpoint, 'followup_sms_send'), 'doctor eligibility is checked before an SMS claim');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
if (!defined('VIEWS_PATH')) {
    define('VIEWS_PATH', BASE_PATH . '/resources/views');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}
if (!defined('ASSET_BASE')) {
    define('ASSET_BASE', '');
}
require_once dirname(__DIR__, 2) . '/app/includes/bhw_workflows.php';

$marker = 'mcfix_' . bin2hex(random_bytes(3));
$userIds = [];
$regIds = [];
$followupIds = [];
$profileUserId = 0;

$insertUser = static function (PDO $pdo, string $role, string $label) use ($marker, &$userIds): int {
    $stmt = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())');
    $stmt->execute(['Test', $label, $marker . '.' . $label . '@example.test', password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), $role]);
    $id = (int) $pdo->lastInsertId();
    $userIds[] = $id;
    return $id;
};

$insertRegistration = static function (PDO $pdo, int $userId, string $email, string $phone, int $barangayId, string $barangayName) use (&$regIds): void {
    $cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $available = [];
    foreach ($cols as $col) {
        $available[(string) $col['Field']] = $col;
    }
    $values = [
        'email' => $email,
        'user_id' => $userId,
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'contact_number' => $phone,
        'barangay' => $barangayName,
        'barangay_id' => $barangayId,
        'national_id' => 'MC' . $userId . bin2hex(random_bytes(2)),
        'patient_code' => 'MC' . $userId,
        'status' => 'verified',
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $names = [];
    $params = [];
    foreach ($values as $name => $value) {
        if (!isset($available[$name])) {
            continue;
        }
        $names[] = $name;
        $params[] = $value;
    }
    foreach ($available as $name => $col) {
        if (in_array($name, $names, true)) {
            continue;
        }
        $nullable = strtoupper((string) ($col['Null'] ?? '')) === 'YES';
        $default = $col['Default'] ?? null;
        $extra = strtolower((string) ($col['Extra'] ?? ''));
        if ($nullable || $default !== null || str_contains($extra, 'auto_increment')) {
            continue;
        }
        $type = strtolower((string) ($col['Type'] ?? ''));
        $names[] = $name;
        if (str_contains($type, 'int')) {
            $params[] = 0;
        } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
            $params[] = date('Y-m-d H:i:s');
        } else {
            $params[] = '';
        }
    }
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $pdo->prepare('INSERT INTO patient_registrations (' . implode(',', $names) . ') VALUES (' . $placeholders . ')')->execute($params);
    $regIds[] = (int) $pdo->lastInsertId();
};

try {
    $barangay = $pdo->query('SELECT id, name FROM barangays ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$barangay) {
        echo "SKIP  barangay reminder checks (no barangay row)\n";
    } else {
        $barangayId = (int) $barangay['id'];
        $barangayName = (string) $barangay['name'];
        $doctorA = $insertUser($pdo, 'provider', 'doctor-a');
        $doctorB = $insertUser($pdo, 'provider', 'doctor-b');
        $bhwId = $insertUser($pdo, 'bhw', 'bhw');
        $superId = $insertUser($pdo, 'superadmin', 'super');
        $patientOk = $insertUser($pdo, 'patient', 'patient-ok');
        $patientNoEmail = $insertUser($pdo, 'patient', 'patient-nomail');
        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['not-an-email', $patientNoEmail]);
        $patientBadPhone = $insertUser($pdo, 'patient', 'patient-nophone');
        $insertRegistration($pdo, $patientOk, $marker . '.patient-ok@example.test', '09170000111', $barangayId, $barangayName);
        $insertRegistration($pdo, $patientNoEmail, 'not-an-email', '09170000222', $barangayId, $barangayName);
        $insertRegistration($pdo, $patientBadPhone, $marker . '.patient-nophone@example.test', '12345', $barangayId, $barangayName);

        $insertFollowup = static function (PDO $pdo, int $patientId, int $providerId, string $dateSql, string $status) use (&$followupIds): int {
            $pdo->prepare("INSERT INTO followups (patient_id, provider_id, followup_date, status, message) VALUES (?, ?, {$dateSql}, ?, 'security fix test')")
                ->execute([$patientId, $providerId, $status]);
            $id = (int) $pdo->lastInsertId();
            $followupIds[] = $id;
            return $id;
        };

        $future = $insertFollowup($pdo, $patientOk, $doctorA, "'2026-12-20'", 'scheduled');
        $past = $insertFollowup($pdo, $patientOk, $doctorA, 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)', 'scheduled');
        $today = $insertFollowup($pdo, $patientOk, $doctorA, 'CURDATE()', 'scheduled');
        $completed = $insertFollowup($pdo, $patientOk, $doctorA, "'2026-12-21'", 'completed');
        $cancelled = $insertFollowup($pdo, $patientOk, $doctorA, "'2026-12-22'", 'cancelled');

        $logCount = static function (PDO $pdo, int $followupId): int {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM followup_sms_logs WHERE followup_id = ?');
            $stmt->execute([$followupId]);
            return (int) $stmt->fetchColumn();
        };

        $other = followup_sms_provider_gate($pdo, $future, $doctorB);
        check(!$other['ok'] && $other['http'] === 403, 'another provider follow-up is rejected');
        check($logCount($pdo, $future) === 0, 'rejected provider request creates no SMS claim');

        $pastGate = followup_sms_provider_gate($pdo, $past, $doctorA);
        check(!$pastGate['ok'] && $pastGate['http'] === 400, 'past scheduled follow-up is rejected');
        check($logCount($pdo, $past) === 0, 'past follow-up creates no SMS claim');

        $completedGate = followup_sms_provider_gate($pdo, $completed, $doctorA);
        $cancelledGate = followup_sms_provider_gate($pdo, $cancelled, $doctorA);
        check(!$completedGate['ok'] && !$cancelledGate['ok'], 'completed and cancelled follow-ups are rejected');
        check($logCount($pdo, $completed) === 0 && $logCount($pdo, $cancelled) === 0, 'ineligible status creates no SMS claim');

        $todayGate = followup_sms_provider_gate($pdo, $today, $doctorA);
        check($todayGate['ok'], 'follow-up dated today stays eligible');
        $todaySms = followup_sms_send($pdo, $today, $doctorA);
        check(($todaySms['status'] ?? '') === 'mocked', 'today follow-up reaches the mock sender');

        $futureGate = followup_sms_provider_gate($pdo, $future, $doctorA);
        check($futureGate['ok'], 'future scheduled follow-up stays eligible');
        $futureSms = followup_sms_send($pdo, $future, $doctorA);
        check(($futureSms['status'] ?? '') === 'mocked' && TextBeeSmsClient::networkCallCount() === 0, 'future follow-up reaches the mock sender without TextBee');

        $pdo->prepare("INSERT INTO followup_sms_logs (followup_id, patient_id, sent_by, recipient, message_body, status) VALUES (?, ?, ?, '09170000999', 'HISTORICAL BODY', 'accepted')")
            ->execute([$past, $patientOk, $doctorA]);
        $beforeHist = $pdo->prepare('SELECT recipient, message_body FROM followup_sms_logs WHERE followup_id = ? AND message_body = ?');
        $beforeHist->execute([$past, 'HISTORICAL BODY']);
        $historical = $beforeHist->fetch(PDO::FETCH_ASSOC) ?: [];
        $blocked = followup_sms_send($pdo, $past, $doctorA);
        $afterHist = $pdo->prepare('SELECT recipient, message_body FROM followup_sms_logs WHERE followup_id = ? AND message_body = ?');
        $afterHist->execute([$past, 'HISTORICAL BODY']);
        $historicalAfter = $afterHist->fetch(PDO::FETCH_ASSOC) ?: [];
        check(($blocked['status'] ?? '') === 'duplicate', 'existing accepted log still blocks a duplicate');
        check($historical === $historicalAfter && ($historicalAfter['message_body'] ?? '') === 'HISTORICAL BODY', 'historical SMS log row is not modified');

        $_SESSION['user_id'] = $bhwId;
        $emailCalls = 0;
        $emailMode = 'ok';
        BhwWorkflows::$followupReminderMailer = static function () use (&$emailCalls, &$emailMode): array {
            $emailCalls++;
            if ($emailMode === 'fail') {
                return ['success' => false, 'message' => 'test mailer failure'];
            }
            return ['success' => true, 'message' => 'test double'];
        };
        $ctx = ['allowed' => true, 'barangay_id' => $barangayId, 'barangay_name' => $barangayName];
        $bothFollowup = $insertFollowup($pdo, $patientOk, $doctorA, "'2026-12-27'", 'scheduled');
        $both = BhwWorkflows::sendFollowupReminder($pdo, $ctx, $bothFollowup);
        check(!empty($both['email_sent']) && ($both['sms_status'] ?? '') === 'mocked', 'valid email and mobile report both channel outcomes');
        check(str_contains((string) $both['message'], 'Reminder email sent') && str_contains((string) $both['message'], 'SMS was not sent'), 'mock SMS is not reported as sent');

        $noEmailFollowup = $insertFollowup($pdo, $patientNoEmail, $doctorA, "'2026-12-23'", 'scheduled');
        $noEmail = BhwWorkflows::sendFollowupReminder($pdo, $ctx, $noEmailFollowup);
        check(empty($noEmail['email_sent']) && ($noEmail['sms_status'] ?? '') === 'mocked', 'missing email still attempts mock SMS');

        $emailMode = 'fail';
        $mailFailFollowup = $insertFollowup($pdo, $patientOk, $doctorA, "'2026-12-24'", 'scheduled');
        $mailFail = BhwWorkflows::sendFollowupReminder($pdo, $ctx, $mailFailFollowup);
        check(empty($mailFail['email_sent']) && ($mailFail['sms_status'] ?? '') === 'mocked', 'email failure still attempts mock SMS');
        check(!str_contains((string) $mailFail['message'], 'Reminder email sent'), 'failed email is not reported as sent');

        $emailMode = 'ok';
        $badPhoneFollowup = $insertFollowup($pdo, $patientBadPhone, $doctorA, "'2026-12-25'", 'scheduled');
        $badPhone = BhwWorkflows::sendFollowupReminder($pdo, $ctx, $badPhoneFollowup);
        check(!empty($badPhone['email_sent']) && ($badPhone['sms_status'] ?? '') === 'failed', 'invalid mobile leaves the email outcome accurate');

        $emailMode = 'fail';
        $bothFailFollowup = $insertFollowup($pdo, $patientBadPhone, $doctorA, "'2026-12-26'", 'scheduled');
        $bothFail = BhwWorkflows::sendFollowupReminder($pdo, $ctx, $bothFailFollowup);
        check(empty($bothFail['success']) && empty($bothFail['email_sent']) && ($bothFail['sms_status'] ?? '') !== 'accepted' && ($bothFail['sms_status'] ?? '') !== 'mocked', 'both failing channels are not reported as successful');

        $callsBeforeRepeat = $emailCalls;
        $again = BhwWorkflows::sendFollowupReminder($pdo, $ctx, $bothFollowup);
        check(($again['sms_status'] ?? '') === 'duplicate', 'second SMS attempt stays blocked');
        check($emailCalls === $callsBeforeRepeat, 'email guard does not send a second email inside 15 minutes');

        $otherCtx = ['allowed' => true, 'barangay_id' => $barangayId + 99999, 'barangay_name' => 'Other Sector'];
        $deniedBarangay = false;
        try {
            BhwWorkflows::sendFollowupReminder($pdo, $otherCtx, $future);
        } catch (InvalidArgumentException $e) {
            $deniedBarangay = str_contains(strtolower($e->getMessage()), 'barangay');
        }
        check($deniedBarangay, 'a BHW cannot send a reminder for another barangay');

        $profileUserId = $doctorA;
        $license = 'TST' . random_int(10000, 99999);
        $pdo->prepare('INSERT INTO provider_profiles (user_id, prc_license_number, verification_status) VALUES (?, ?, ?)')
            ->execute([$doctorA, $license, 'pending']);
        $statusOf = static function (PDO $pdo, int $userId): string {
            $stmt = $pdo->prepare('SELECT verification_status FROM provider_profiles WHERE user_id = ?');
            $stmt->execute([$userId]);
            return (string) $stmt->fetchColumn();
        };
        $eventCount = static function (PDO $pdo, int $userId): int {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM prc_verification_events WHERE provider_user_id = ?');
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        };

        $invalid = prc_record_decision($pdo, $doctorA, 'not-a-status', $superId, 'Manual review test only');
        $short = prc_record_decision($pdo, $doctorA, 'pending', $superId, 'no');
        $notSuper = prc_record_decision($pdo, $doctorA, 'pending', $patientOk, 'Manual review test only');
        check(empty($invalid['success']) && empty($short['success']) && empty($notSuper['success']), 'invalid status, short remarks, and non-superadmin decisions are rejected');
        check($statusOf($pdo, $doctorA) === 'pending' && $eventCount($pdo, $doctorA) === 0, 'rejected PRC decisions do not write status or history');

        $beforeStatus = $statusOf($pdo, $doctorA);
        $beforeEvents = $eventCount($pdo, $doctorA);
        $pdo->beginTransaction();
        $saved = prc_record_decision($pdo, $doctorA, 'needs_review', $superId, 'Manual review test only');
        $open = $pdo->inTransaction();
        $duringStatus = $statusOf($pdo, $doctorA);
        $duringEvents = $eventCount($pdo, $doctorA);
        if ($open) {
            $pdo->rollBack();
        }
        check(!empty($saved['success']) && $duringStatus === 'needs_review' && $duringEvents === $beforeEvents + 1, 'successful decision writes the status and one history event together');
        check($open && $statusOf($pdo, $doctorA) === $beforeStatus && $eventCount($pdo, $doctorA) === $beforeEvents, 'rolling back the decision removes both the status and the event');
        check(str_contains((string) ($saved['message'] ?? ''), 'manual review decision'), 'decision message says the review is manual');

        prc_record_decision_test_fault('event');
        $eventFail = prc_record_decision($pdo, $doctorA, 'needs_review', $superId, 'Manual review test only');
        prc_record_decision_test_fault(null);
        check(empty($eventFail['success']) && $statusOf($pdo, $doctorA) === 'pending' && $eventCount($pdo, $doctorA) === 0, 'history insert failure rolls back the profile status');

        prc_record_decision_test_fault('profile');
        $profileFail = prc_record_decision($pdo, $doctorA, 'needs_review', $superId, 'Manual review test only');
        prc_record_decision_test_fault(null);
        check(empty($profileFail['success']) && $eventCount($pdo, $doctorA) === 0 && $statusOf($pdo, $doctorA) === 'pending', 'profile update failure writes no history event');
    }
} finally {
    BhwWorkflows::$followupReminderMailer = null;
    prc_record_decision_test_fault(null);
    followup_sms_test_fail_after_accept(false);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($followupIds) {
        $in = implode(',', array_fill(0, count($followupIds), '?'));
        $pdo->prepare("DELETE FROM followup_sms_logs WHERE followup_id IN ({$in})")->execute($followupIds);
        $pdo->prepare("DELETE FROM followups WHERE id IN ({$in})")->execute($followupIds);
    }
    if ($userIds) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        try {
            $pdo->prepare("DELETE FROM notifications WHERE user_id IN ({$in})")->execute($userIds);
        } catch (Throwable $e) {
        }
        try {
            $pdo->prepare("DELETE FROM patient_audit_logs WHERE patient_id IN ({$in})")->execute($userIds);
        } catch (Throwable $e) {
        }
        try {
            $pdo->prepare("DELETE FROM prc_verification_events WHERE provider_user_id IN ({$in})")->execute($userIds);
        } catch (Throwable $e) {
        }
        if ($profileUserId > 0) {
            $pdo->prepare('DELETE FROM provider_profiles WHERE user_id = ?')->execute([$profileUserId]);
        }
        if ($regIds) {
            $regIn = implode(',', array_fill(0, count($regIds), '?'));
            $pdo->prepare("DELETE FROM patient_registrations WHERE id IN ({$regIn})")->execute($regIds);
        }
        $pdo->prepare("DELETE FROM users WHERE id IN ({$in})")->execute($userIds);
    }
}
