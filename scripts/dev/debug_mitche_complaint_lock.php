<?php
declare(strict_types=1);

putenv('DB_ENV=local');
$_ENV['DB_ENV'] = 'local';
putenv('DB_HOST=localhost');
$_ENV['DB_HOST'] = 'localhost';
putenv('DB_NAME=medconnect');
$_ENV['DB_NAME'] = 'medconnect';
putenv('DB_USER=root');
$_ENV['DB_USER'] = 'root';
putenv('DB_PASS=');
$_ENV['DB_PASS'] = '';

require dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/patient_booking_status.php';
require_once dirname(__DIR__, 2) . '/app/includes/patient_chief_complaints.php';

echo 'DB=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

$ids = [];
foreach ($pdo->query("SELECT id, first_name, last_name FROM users WHERE role='patient' ORDER BY id") as $row) {
    $ids[] = $row;
}
// Also include anyone with approved triage
foreach ($pdo->query("SELECT DISTINCT patient_id AS id FROM triage_results WHERE recommendation_status='approved'") as $row) {
    $pid = (int) $row['id'];
    $u = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE id=?');
    $u->execute([$pid]);
    $found = $u->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $ids[$pid] = $found;
    }
}

$seen = [];
foreach ($ids as $row) {
    $id = (int) $row['id'];
    if (isset($seen[$id])) {
        continue;
    }
    $seen[$id] = true;

    $active = patient_portal_active_chief_complaint($pdo, $id);
    $locked = !empty($active['locked']);
    $tr = patient_portal_find_active_triage_row($pdo, $id);
    $open = patient_portal_has_open_consultation($pdo, $id);

    // Only print patients that would confuse the UI.
    if (!$locked && (int) ($tr['id'] ?? 0) === 0 && !$open) {
        continue;
    }

    echo "==== {$id} {$row['first_name']} {$row['last_name']} ====\n";
    echo ' locked=' . ($locked ? 'YES' : 'no')
        . ' source=' . ($active['source'] ?? '')
        . ' cc=' . substr((string) ($active['complaint'] ?? ''), 0, 50) . PHP_EOL;
    echo ' find_active_triage=' . (int) ($tr['id'] ?? 0)
        . ' rec=' . ($tr['recommendation_status'] ?? '-') . PHP_EOL;
    echo ' open=' . ($open ? '1' : '0')
        . ' stale=' . (patient_portal_has_stale_or_finished_consultation($pdo, $id) ? '1' : '0') . PHP_EOL;

    $c = $pdo->prepare('SELECT id, status, consult_date, consult_time FROM consultations WHERE patient_id=? ORDER BY id DESC');
    $c->execute([$id]);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $x) {
        echo " consult #{$x['id']} {$x['status']} {$x['consult_date']} {$x['consult_time']}\n";
    }
    $t = $pdo->prepare('SELECT id, recommendation_status, LEFT(chief_complaint,40) cc FROM triage_results WHERE patient_id=? ORDER BY id DESC LIMIT 5');
    $t->execute([$id]);
    foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $x) {
        echo " triage #{$x['id']} {$x['recommendation_status']} {$x['cc']}\n";
    }
    echo PHP_EOL;
}

// Explicit check for Angel (approved tips local fixture)
$angelId = 12;
$beforeNote = patient_portal_active_chief_complaint($pdo, $angelId);
echo "ANGEL_LOCK_CHECK locked=" . (!empty($beforeNote['locked']) ? 'YES' : 'NO')
    . ' cc=[' . ($beforeNote['complaint'] ?? '') . ']' . PHP_EOL;
