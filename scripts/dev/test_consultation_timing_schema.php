<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/consultation_queue_timing.php';

consultation_timing_ensure_schema($pdo);
$video = $pdo->query('SHOW COLUMNS FROM video_sessions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$consult = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$need = [
    'video_sessions.patient_joined_at' => in_array('patient_joined_at', $video, true),
    'consultations.early_start_offered_at' => in_array('early_start_offered_at', $consult, true),
    'consultations.early_start_response' => in_array('early_start_response', $consult, true),
    'consultations.early_start_responded_at' => in_array('early_start_responded_at', $consult, true),
];
$fail = 0;
foreach ($need as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . '  schema ' . $name . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}
exit($fail > 0 ? 1 : 0);
