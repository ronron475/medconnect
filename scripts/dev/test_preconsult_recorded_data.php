<?php
declare(strict_types=1);
$root = dirname(dirname(__DIR__));
require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/app/includes/consultation_recorded_data.php';

$pass = 0;
$fail = 0;
function a(bool $ok, string $l): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$l}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$l}\n";
}

consultation_recorded_data_ensure_schema($pdo);
$marker = 'crd_' . bin2hex(random_bytes(3));
$ids = [];
$cids = [];
$rids = [];

try {
    $iu = $pdo->prepare('INSERT INTO users (first_name,last_name,email,password,role,is_active,created_at) VALUES (?,?,?,?,?,1,NOW())');
    foreach ([['patient', 'p'], ['provider', 'a'], ['provider', 'b'], ['provider', 'c'], ['bhw', 'h']] as $u) {
        $iu->execute(['T', strtoupper($u[1]), $marker . '_' . $u[1] . '@t.test', password_hash('x', PASSWORD_DEFAULT), $u[0]]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    [$pid, $docA, $docB, $docC, $bhw] = $ids;

    $pending = consultation_recorded_data_save($pdo, $pid, 0, $bhw, 'bhw', [
        'temperature_c' => 38.5,
        'chief_complaint' => 'Fever',
    ], null, false);
    a(!empty($pending['success']), 'save pending without consult');
    a(($pending['status'] ?? '') === 'pending', 'status pending');
    if (!empty($pending['id'])) {
        $rids[] = (int) $pending['id'];
    }

    $ic = $pdo->prepare("INSERT INTO consultations (patient_id,provider_id,provider_name,consult_date,consult_time,consult_type,status,created_at) VALUES (?,?,?,CURDATE(),CURTIME(),'General Consultation','scheduled',NOW())");
    $ic->execute([$pid, $docA, 'A']);
    $c1 = (int) $pdo->lastInsertId();
    $cids[] = $c1;
    $ic->execute([$pid, $docB, 'B']);
    $c2 = (int) $pdo->lastInsertId();
    $cids[] = $c2;
    $ic->execute([$pid, $docC, 'C']);
    $c3 = (int) $pdo->lastInsertId();
    $cids[] = $c3;

    a(empty(consultation_recorded_data_for_doctor($pdo, $c1, $pid)['available']), 'C1 no pending before attach');
    $att = consultation_recorded_data_attach_pending_to_consultation($pdo, $pid, $c3);
    a(($att['attached'] ?? 0) >= 1, 'attach to C3');

    $dto = consultation_recorded_data_for_doctor($pdo, $c3, $pid);
    $t = '';
    foreach (($dto['fields'] ?? []) as $f) {
        if (($f['key'] ?? '') === 'temperature_c') {
            $t = (string) ($f['value'] ?? '');
        }
    }
    a(str_contains($t, '38.5'), 'C3 sees 38.5');
    a(empty(consultation_recorded_data_for_doctor($pdo, $c1, $pid)['available']), 'C1 still empty');
    a(empty(consultation_recorded_data_for_doctor($pdo, $c2, $pid)['available']), 'C2 still empty');
    a(consultation_recorded_data_pending_latest($pdo, $pid) === null, 'pending consumed');
    a(empty(consultation_recorded_data_for_authorized_provider($pdo, $docA, $c3, $pid)['allowed']), 'A denied on C3');

    $direct = consultation_recorded_data_save($pdo, $pid, $c3, $bhw, 'bhw', ['temperature_c' => 39.0], null, true);
    a(!empty($direct['success']), 'direct save on C3');
    if (!empty($direct['id'])) {
        $rids[] = (int) $direct['id'];
    }
} catch (Throwable $e) {
    a(false, 'threw: ' . $e->getMessage());
} finally {
    try {
        if ($rids) {
            $pdo->exec('DELETE FROM consultation_recorded_data WHERE id IN (' . implode(',', $rids) . ')');
        }
        if ($cids) {
            $pdo->exec('DELETE FROM consultation_recorded_data WHERE consultation_id IN (' . implode(',', $cids) . ')');
            $pdo->exec('DELETE FROM consultations WHERE id IN (' . implode(',', $cids) . ')');
        }
        if ($ids) {
            $pdo->exec('DELETE FROM consultation_recorded_data WHERE patient_id IN (' . implode(',', $ids) . ')');
            $pdo->exec('DELETE FROM users WHERE id IN (' . implode(',', $ids) . ')');
        }
    } catch (Throwable $e) {
        echo 'WARN ' . $e->getMessage() . "\n";
    }
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
