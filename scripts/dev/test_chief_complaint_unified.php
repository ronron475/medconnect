<?php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$complaint = $argv[1] ?? 'sakit mata ko';
$a = ChiefComplaintNlpService::assess($complaint, []);
$s = ChiefComplaintNlpService::buildCdsSummary($a, $complaint);
$r = ChiefComplaintNlpService::buildRegistrationPayload($a, $complaint);

echo json_encode([
    'complaint' => $complaint,
    'classification' => $s['classification'],
    'symptoms' => $s['detected_symptoms'],
    'registration_urgency' => $r['urgency'],
    'match' => $s['classification'] === str_replace('_', '-', $r['urgency']),
], JSON_PRETTY_PRINT) . PHP_EOL;
