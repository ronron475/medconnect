<?php
require __DIR__ . '/../../config/db.php';

$hasFn = (bool) $pdo->query("SHOW COLUMNS FROM digital_referrals LIKE 'facility_name'")->fetch();
$dest = $hasFn ? 'facility_name' : 'destination_facility';
echo "dest_col={$dest}\n";

$sql = "SELECT id, status, referral_type, `{$dest}` AS dest, LEFT(reason, 60) AS reason
        FROM digital_referrals
        WHERE status = 'pending'
        ORDER BY id DESC
        LIMIT 20";
foreach ($pdo->query($sql) as $r) {
    echo $r['id'] . ' | ' . $r['status'] . ' | ' . $r['referral_type'] . ' | ' . $r['dest'] . ' | ' . $r['reason'] . "\n";
}

$update = $pdo->prepare("
    UPDATE digital_referrals
    SET status = 'completed'
    WHERE status = 'pending'
      AND referral_type = 'Hospital'
      AND `{$dest}` = 'Nearest hospital / ER — emergency triage'
");
$update->execute();
echo "updated_rows=" . $update->rowCount() . "\n";
