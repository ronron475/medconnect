<?php
/**
 * Dev audit: doctor final case level → patient connection
 * Usage: php scripts/dev/audit_case_level_connection.php
 */
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/patient_consultation_records.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/provider_clinical_support.php';

provider_clinical_support_ensure_schema($pdo);
patient_consultation_records_schema_ensure($pdo);

echo "=== Completed consultations (latest 5) ===\n";
$rows = $pdo->query("
    SELECT c.id, c.patient_id, c.provider_id, c.status,
           cn.signature_data IS NOT NULL AND TRIM(cn.signature_data) <> '' AS finalized_soap,
           cn.finalized_at
    FROM consultations c
    LEFT JOIN clinical_notes cn ON cn.consultation_id = c.id
    WHERE c.status = 'completed'
    ORDER BY c.id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $cid = (int) $row['id'];
    $pid = (int) $row['patient_id'];
    echo "\nConsultation #{$cid} | patient_id={$pid} | provider_id={$row['provider_id']}\n";
    echo "  status={$row['status']} | finalized_soap=" . ($row['finalized_soap'] ? 'yes' : 'no') . "\n";

    $events = $pdo->prepare("
        SELECT id, event_type, urgency_bucket, ai_urgency_bucket, doctor_urgency_bucket, created_at
        FROM consultation_clinical_support
        WHERE consultation_id = ?
        ORDER BY id DESC
        LIMIT 3
    ");
    $events->execute([$cid]);
    foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        echo "  support event: {$ev['event_type']} | urgency={$ev['urgency_bucket']} | ai={$ev['ai_urgency_bucket']} | doctor={$ev['doctor_urgency_bucket']} @ {$ev['created_at']}\n";
    }

    $outcome = patient_consultation_clinical_outcome($pdo, $cid, $pid, false);
    if ($outcome) {
        echo "  patient outcome: final={$outcome['final_case_level']} | ai={$outcome['ai_case_level']}\n";
    } else {
        echo "  patient outcome: (none)\n";
    }
}
