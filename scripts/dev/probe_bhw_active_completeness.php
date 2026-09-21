<?php
/**
 * Probe active BHW completeness across barangays.
 * Run: php scripts/dev/probe_bhw_active_completeness.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/bhw_application_schema.php';

bhw_application_ensure_schema($pdo);

$users = (int) $pdo->query("
    SELECT COUNT(*) FROM users
    WHERE LOWER(TRIM(role)) = 'bhw'
      AND COALESCE(is_active, 0) = 1
      AND barangay_id IS NOT NULL
      AND barangay_id > 0
")->fetchColumn();

$apps = (int) $pdo->query("
    SELECT COUNT(*) FROM bhw_applications
    WHERE status IN ('active', 'approved')
")->fetchColumn();

$orphan = (int) $pdo->query("
    SELECT COUNT(*) FROM users u
    WHERE LOWER(TRIM(u.role)) = 'bhw'
      AND COALESCE(u.is_active, 0) = 1
      AND u.barangay_id IS NOT NULL
      AND u.barangay_id > 0
      AND NOT EXISTS (
        SELECT 1 FROM bhw_applications a
        WHERE a.user_id = u.id OR LOWER(a.email) = LOWER(u.email)
      )
")->fetchColumn();

$missingAppt = (int) $pdo->query("
    SELECT COUNT(*) FROM bhw_applications a
    WHERE a.status IN ('active', 'approved')
      AND (a.appointment_date IS NULL OR a.appointment_date = '0000-00-00')
")->fetchColumn();

$missingAppr = (int) $pdo->query("
    SELECT COUNT(*) FROM bhw_applications a
    WHERE a.status IN ('active', 'approved')
      AND (a.approved_by IS NULL OR a.approved_at IS NULL)
")->fetchColumn();

echo "active_bhw_users={$users} apps_active={$apps} orphans={$orphan} missing_appt={$missingAppt} missing_approval={$missingAppr}\n";

$docStats = $pdo->query("
    SELECT a.id, a.email, a.barangay_id, a.appointment_date, a.approved_by, a.approved_at, a.user_id,
           SUM(d.document_type = 'appointment_letter') AS has_appt,
           SUM(d.document_type = 'government_id') AS has_gid,
           COUNT(d.id) AS doc_count
    FROM bhw_applications a
    LEFT JOIN bhw_application_documents d ON d.application_id = a.id
    WHERE a.status IN ('active', 'approved')
    GROUP BY a.id
")->fetchAll(PDO::FETCH_ASSOC);

$zero = 0;
$noLetter = 0;
$noGid = 0;
foreach ($docStats as $r) {
    if ((int) $r['doc_count'] === 0) {
        $zero++;
    }
    if ((int) $r['has_appt'] === 0) {
        $noLetter++;
    }
    if ((int) $r['has_gid'] === 0) {
        $noGid++;
    }
}
echo 'active_apps=' . count($docStats) . " zero_docs={$zero} no_letter={$noLetter} no_gid={$noGid}\n";

$sa = $pdo->query("SELECT id, email FROM users WHERE role = 'superadmin' ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "superadmins=" . json_encode($sa) . "\n";

$samples = array_slice($docStats, 0, 8);
echo "samples=" . json_encode($samples, JSON_UNESCAPED_UNICODE) . "\n";
