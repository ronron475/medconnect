<?php
/**
 * Purge patient account(s) by name/email pattern (dev/maintenance).
 * Usage: php scripts/dev/purge_patient_accounts.php [--apply] [--email=addr]
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$emailFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $emailFilter = substr($arg, 8);
    }
}

require_once dirname(__DIR__, 2) . '/config/db.php';

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Database: {$dbName}\n";
echo 'Mode: ' . ($apply ? 'APPLY (destructive)' : 'DRY RUN') . "\n\n";

$where = [
    "role = 'patient'",
    '('
    . "first_name LIKE '%Mitche%' OR last_name LIKE '%Yuma%'"
    . " OR CONCAT(first_name, ' ', last_name) LIKE '%Mitche Ann%'"
    . " OR email LIKE '%mitche%' OR email LIKE '%yuma%' OR email LIKE '%gonzales%'"
    . ')',
];
$params = [];
if ($emailFilter !== null && $emailFilter !== '') {
    $where[] = 'email = ?';
    $params[] = $emailFilter;
}

$sql = 'SELECT id, first_name, last_name, email, created_at FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$users) {
    echo "No matching patient accounts found.\n";
    exit(0);
}

echo "Matched users:\n";
foreach ($users as $u) {
    echo sprintf(
        "  #%d %s %s <%s> (%s)\n",
        (int) $u['id'],
        $u['first_name'],
        $u['last_name'],
        $u['email'],
        $u['created_at']
    );
}
echo "\n";

$ids = array_map(static fn(array $u): int => (int) $u['id'], $users);
$emails = array_values(array_unique(array_map(static fn(array $u): string => (string) $u['email'], $users)));
$idList = implode(',', $ids);
$emailPlaceholders = implode(',', array_fill(0, count($emails), '?'));

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function run_delete(PDO $pdo, string $label, string $sql, array $params, bool $apply): void
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    echo sprintf("%s: %d row(s)%s\n", $label, $count, $apply ? '' : ' (would delete)');
}

$steps = [];

if (table_exists($pdo, 'complaint_evidence')) {
    $steps[] = ['complaint_evidence', "DELETE FROM complaint_evidence WHERE patient_id IN ($idList)", []];
}
if (table_exists($pdo, 'patient_chief_complaints')) {
    $steps[] = ['patient_chief_complaints', "DELETE FROM patient_chief_complaints WHERE patient_id IN ($idList)", []];
}
if (table_exists($pdo, 'patient_locations')) {
    $steps[] = ['patient_locations', "DELETE FROM patient_locations WHERE patient_id IN ($idList)", []];
}
if (table_exists($pdo, 'notifications')) {
    $steps[] = ['notifications', "DELETE FROM notifications WHERE user_id IN ($idList)", []];
}
if (table_exists($pdo, 'remember_tokens')) {
    $steps[] = ['remember_tokens', "DELETE FROM remember_tokens WHERE user_id IN ($idList)", []];
}
if (table_exists($pdo, 'active_sessions')) {
    $steps[] = ['active_sessions', "DELETE FROM active_sessions WHERE user_id IN ($idList)", []];
}
if (table_exists($pdo, 'user_preferences')) {
    $steps[] = ['user_preferences', "DELETE FROM user_preferences WHERE user_id IN ($idList)", []];
}
if (table_exists($pdo, 'password_history')) {
    $steps[] = ['password_history', "DELETE FROM password_history WHERE user_id IN ($idList)", []];
}
if (table_exists($pdo, 'patient_notification_preferences')) {
    $steps[] = ['patient_notification_preferences', "DELETE FROM patient_notification_preferences WHERE user_id IN ($idList)", []];
}
if (table_exists($pdo, 'patient_privacy_preferences')) {
    $steps[] = ['patient_privacy_preferences', "DELETE FROM patient_privacy_preferences WHERE user_id IN ($idList)", []];
}
if (table_exists($pdo, 'patient_medical_update_requests')) {
    $steps[] = ['patient_medical_update_requests', "DELETE FROM patient_medical_update_requests WHERE patient_id IN ($idList)", []];
}
if (table_exists($pdo, 'chatbot_conversations')) {
    $steps[] = ['chatbot_conversations', "DELETE FROM chatbot_conversations WHERE user_id IN ($idList)", []];
}
if (table_exists($pdo, 'appointment_slots')) {
    $steps[] = ['appointment_slots (patient hold)', "UPDATE appointment_slots SET patient_id = NULL WHERE patient_id IN ($idList)", []];
}

$steps[] = [
    'patient_registrations',
    "DELETE FROM patient_registrations WHERE user_id IN ($idList) OR email IN ($emailPlaceholders)",
    $emails,
];
$steps[] = ['users', "DELETE FROM users WHERE id IN ($idList)", []];

if (!$apply) {
    foreach ($steps as [$label, $sql, $params]) {
        $countSql = preg_replace('/^DELETE FROM /', 'SELECT COUNT(*) FROM ', $sql);
        $countSql = preg_replace('/^UPDATE appointment_slots SET patient_id = NULL WHERE /', 'SELECT COUNT(*) FROM appointment_slots WHERE ', $countSql);
        try {
            $stmt = $pdo->prepare($countSql);
            $stmt->execute($params);
            $count = (int) $stmt->fetchColumn();
            echo sprintf("%s: %d row(s) (would delete)\n", $label, $count);
        } catch (Throwable $e) {
            echo sprintf("%s: skipped (%s)\n", $label, $e->getMessage());
        }
    }
    echo "\nRe-run with --apply to delete.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ($steps as [$label, $sql, $params]) {
        run_delete($pdo, $label, $sql, $params, true);
    }
    $pdo->commit();
    echo "\nDone. Purged " . count($users) . " account(s).\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Rollback: ' . $e->getMessage() . "\n");
    exit(1);
}
