<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$rows = $pdo->query("
SELECT a.id, a.status, a.email, a.first_name, a.last_name, a.barangay_id, a.appointment_date,
       a.approved_by, a.approved_at, a.user_id,
  (SELECT COUNT(*) FROM bhw_application_documents d WHERE d.application_id = a.id) AS docs,
  (SELECT COUNT(*) FROM bhw_application_documents d WHERE d.application_id = a.id AND d.document_type = 'appointment_letter') AS letter,
  (SELECT COUNT(*) FROM bhw_application_documents d WHERE d.application_id = a.id AND d.document_type = 'government_id') AS gid
FROM bhw_applications a
ORDER BY a.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    echo sprintf(
        "#%d %s %s email=%s brgy=%s appt=%s approved_by=%s approved_at=%s docs=%s letter=%s gid=%s user=%s\n",
        $r['id'],
        $r['status'],
        trim($r['first_name'] . ' ' . $r['last_name']),
        $r['email'],
        $r['barangay_id'],
        $r['appointment_date'] ?: '-',
        $r['approved_by'] ?: '-',
        $r['approved_at'] ?: '-',
        $r['docs'],
        $r['letter'],
        $r['gid'],
        $r['user_id'] ?: '-'
    );
}
echo 'total_apps=' . count($rows) . PHP_EOL;

$activeUsers = (int) $pdo->query("
  SELECT COUNT(*) FROM users
  WHERE LOWER(TRIM(role))='bhw' AND COALESCE(is_active,0)=1 AND barangay_id>0
")->fetchColumn();
echo "active_bhw_users={$activeUsers}\n";
