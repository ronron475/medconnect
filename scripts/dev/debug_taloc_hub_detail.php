<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/core/BhwApplicationService.php';

$svc = new BhwApplicationService($pdo);
$talocId = (int) $pdo->query("SELECT id FROM barangays WHERE name LIKE 'Taloc%' LIMIT 1")->fetchColumn();
echo "taloc_id={$talocId}\n";

$user = $pdo->query("SELECT id, email, barangay_id FROM users WHERE email='taloc@gmail.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo 'user=' . json_encode($user) . "\n";

$app = $pdo->query("SELECT id, barangay_id, user_id, appointment_date, status FROM bhw_applications WHERE email='taloc@gmail.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo 'app=' . json_encode($app) . "\n";

$docs = $pdo->query('SELECT id, document_type, original_name FROM bhw_application_documents WHERE application_id=' . (int) ($app['id'] ?? 0))->fetchAll(PDO::FETCH_ASSOC);
echo 'docs=' . json_encode($docs) . "\n";

$detail = $svc->barangayHubDetail($talocId);
foreach ($detail['items'] as $item) {
    echo sprintf(
        "item kind=%s name=%s email=%s docs=%s appt=%s approval=%s\n",
        $item['kind'] ?? '-',
        $item['display_name'] ?? '-',
        $item['email'] ?? '-',
        $item['document_count'] ?? 0,
        $item['appointment_date'] ?? '-',
        $item['approval_label'] ?? '-'
    );
}
