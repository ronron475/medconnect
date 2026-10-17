<?php
/**
 * Restricted patients cannot book a pending follow-up.
 * Uses SQLite only. Does not connect to MySQL.
 *
 * CLI: php scripts/test/restricted_followup_booking_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/includes/user_account_status.php';
require_once $root . '/app/includes/urgent_followup_workflow.php';

$pass = 0;
$fail = 0;

function followup_assert(bool $ok, string $label): void
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

final class FollowupBookingTestPdo extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match('/SHOW COLUMNS FROM\s+(\w+)/i', $query, $match)) {
            return parent::query("SELECT name FROM pragma_table_info('" . $match[1] . "')");
        }
        if (stripos($query, 'SHOW TABLES') !== false) {
            return parent::query("SELECT '' WHERE 0");
        }

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $sql = ltrim($statement);
        if (
            stripos($sql, 'ENGINE=InnoDB') !== false
            || stripos($sql, 'MODIFY COLUMN') !== false
            || stripos($sql, 'INNER JOIN') !== false
            || stripos($sql, 'CREATE TABLE IF NOT EXISTS urgent_followup_cases') !== false
        ) {
            return 0;
        }

        return parent::exec($statement);
    }
}

$restrictionMessage = 'Your account currently has a restriction that prevents new consultation submissions.';
$suspendedMessage = 'Your account is currently suspended. Please contact the health office for assistance.';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    followup_assert(false, 'sqlite is available');
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

$pdo = new FollowupBookingTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        role TEXT NOT NULL,
        account_status TEXT NOT NULL,
        is_active INTEGER NOT NULL,
        last_login TEXT,
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        lockout_until TEXT,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        first_login TEXT,
        password_changed_at TEXT,
        password_reset_token TEXT,
        password_reset_expiry TEXT,
        password_setup_token TEXT,
        password_setup_expiry TEXT,
        terms_accepted_at TEXT,
        privacy_accepted_at TEXT,
        archived_at TEXT,
        archived_by INTEGER,
        archive_reason TEXT,
        restored_at TEXT,
        restored_by INTEGER,
        restore_reason TEXT,
        session_epoch INTEGER NOT NULL DEFAULT 1
    )
');
$pdo->exec('
    CREATE TABLE patient_registrations (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        patient_code TEXT,
        middle_name TEXT,
        suffix TEXT,
        civil_status TEXT,
        purok TEXT,
        full_address TEXT,
        current_medications TEXT,
        emergency_contact_name TEXT,
        emergency_contact_phone TEXT,
        emergency_contact_relation TEXT
    )
');
$pdo->exec('
    CREATE TABLE urgent_followup_cases (
        id INTEGER PRIMARY KEY,
        patient_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        updated_chief_complaint TEXT
    )
');
$pdo->exec('
    CREATE TABLE consultations (
        id INTEGER PRIMARY KEY,
        patient_id INTEGER NOT NULL,
        status TEXT NOT NULL
    )
');

$insertUser = $pdo->prepare('INSERT INTO users (id, role, account_status, is_active) VALUES (?, ?, ?, ?)');
$insertUser->execute([1, 'patient', 'restricted', 1]);
$insertUser->execute([2, 'patient', 'active', 1]);
$insertUser->execute([3, 'patient', 'suspended', 0]);

$insertCase = $pdo->prepare('INSERT INTO urgent_followup_cases (id, patient_id, status, updated_chief_complaint) VALUES (?, ?, ?, ?)');
$insertCase->execute([10, 1, 'pending', 'Follow-up fever']);
$insertCase->execute([11, 2, 'pending', 'Follow-up cough']);
$insertCase->execute([12, 3, 'pending', 'Follow-up headache']);

$restricted = patient_account_may_submit_consultation($pdo, 1);
followup_assert($restricted['allowed'] === false, 'restricted patient cannot submit a consultation');
followup_assert(($restricted['code'] ?? '') === 'account_restricted', 'restricted denial uses the existing restriction code');
followup_assert(($restricted['message'] ?? '') === $restrictionMessage, 'restricted denial uses the existing restriction message');

$active = patient_account_may_submit_consultation($pdo, 2);
followup_assert($active['allowed'] === true, 'active patient can submit a consultation');

$suspended = patient_account_may_submit_consultation($pdo, 3);
followup_assert($suspended['allowed'] === false && ($suspended['code'] ?? '') === 'account_suspended', 'suspended patient keeps the existing suspension denial');

try {
    urgent_followup_book_appointment($pdo, 1, 10, 1);
    followup_assert(false, 'restricted patient is blocked from booking a pending follow-up');
} catch (RuntimeException $e) {
    followup_assert($e->getMessage() === $restrictionMessage, 'restricted follow-up booking returns the restriction message');
}

$restrictedCase = $pdo->query('SELECT status, updated_chief_complaint FROM urgent_followup_cases WHERE id = 10')->fetch(PDO::FETCH_ASSOC);
followup_assert(
    is_array($restrictedCase) && $restrictedCase['status'] === 'pending' && $restrictedCase['updated_chief_complaint'] === 'Follow-up fever',
    'a restricted booking leaves the pending follow-up record unchanged'
);
followup_assert((int) $pdo->query('SELECT COUNT(*) FROM consultations')->fetchColumn() === 0, 'a restricted booking does not create a consultation');

try {
    urgent_followup_book_appointment($pdo, 2, 11, 1);
    followup_assert(false, 'an active patient is not rejected by the restriction check');
} catch (Throwable $e) {
    followup_assert($e->getMessage() !== $restrictionMessage, 'an active patient is not stopped by the restriction message');
}

$activeCase = $pdo->query('SELECT status FROM urgent_followup_cases WHERE id = 11')->fetchColumn();
followup_assert($activeCase === 'pending', 'an active patient follow-up case is not cancelled by the restriction check');

try {
    urgent_followup_book_appointment($pdo, 3, 12, 1);
    followup_assert(false, 'a suspended patient is still blocked from booking');
} catch (RuntimeException $e) {
    followup_assert($e->getMessage() === $suspendedMessage, 'suspension message is unchanged on follow-up booking');
}
$suspendedCase = $pdo->query('SELECT status FROM urgent_followup_cases WHERE id = 12')->fetchColumn();
followup_assert($suspendedCase === 'pending', 'a suspended booking leaves the pending follow-up record unchanged');

$endpoint = (string) file_get_contents($root . '/app/api/patient/book_followup.php');
$checkAt = strpos($endpoint, 'patient_account_may_submit_consultation');
$transactionAt = strpos($endpoint, 'beginTransaction');
followup_assert($checkAt !== false && $transactionAt !== false && $checkAt < $transactionAt, 'the booking endpoint checks the restriction before opening a transaction');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
