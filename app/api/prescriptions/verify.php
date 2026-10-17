<?php
/**
 * Prescription verification.
 * Anyone with the token can see validity, the issuing provider's name, and
 * the issue date. Medication details are added only when the signed-in user
 * is the patient who owns the prescription.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/prescription_verification.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    header('Allow: GET');
    echo 'Method not allowed.';
    exit;
}

clinical_tables_ensure($pdo);

$token = (string) ($_GET['t'] ?? '');
$viewerId = (int) ($_SESSION['user_id'] ?? 0);
$result = prescription_verification_for_viewer($pdo, $token, $viewerId);
$state = (string) ($result['state'] ?? 'unavailable');
if (!in_array($state, ['valid', 'revoked', 'unavailable'], true)) {
    $state = 'unavailable';
    $result['provider_name'] = '';
    $result['issued_on'] = '';
}

$labels = [
    'valid' => 'Valid',
    'revoked' => 'Revoked',
    'unavailable' => 'Invalid or unavailable',
];
$label = $labels[$state];
$provider = $state === 'unavailable' ? '' : trim((string) ($result['provider_name'] ?? ''));
$issued = $state === 'unavailable' ? '' : trim((string) ($result['issued_on'] ?? ''));

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Prescription verification</title>
    <style>
        body { margin: 0; font-family: Georgia, "Times New Roman", serif; background: #f4f7fb; color: #10233f; }
        main { max-width: 28rem; margin: 3rem auto; padding: 1.5rem; background: #fff; border: 1px solid #d5e0ec; border-radius: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        .state { font-size: 1.5rem; font-weight: 700; margin: 0 0 1rem; }
        .state.valid { color: #0f766e; }
        .state.revoked { color: #9f1239; }
        .state.unavailable { color: #475569; }
        p { margin: 0.35rem 0; }
        .note { margin-top: 1.25rem; font-size: 0.9rem; color: #526277; }
    </style>
</head>
<body>
<main>
    <h1>Prescription verification</h1>
    <p class="state <?= htmlspecialchars($state) ?>"><?= htmlspecialchars($label) ?></p>
    <?php if ($state !== 'unavailable'): ?>
        <p><strong>Issuing provider:</strong> <?= htmlspecialchars($provider !== '' ? $provider : 'Not available') ?></p>
        <p><strong>Issue date:</strong> <?= htmlspecialchars($issued !== '' ? $issued : 'Not available') ?></p>
        <?php if (isset($result['medication_name'])): ?>
        <p><strong>Medication:</strong> <?= htmlspecialchars((string) $result['medication_name']) ?></p>
        <p><strong>Dosage:</strong> <?= htmlspecialchars((string) ($result['dosage'] ?? '')) ?></p>
        <p><strong>Frequency:</strong> <?= htmlspecialchars((string) ($result['frequency'] ?? '')) ?></p>
        <p><strong>Duration:</strong> <?= htmlspecialchars((string) ($result['duration'] ?? '')) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <p class="note">This page confirms the prescription record stored by medConnect. It does not verify a PRC license. Medication details are shown only to the signed-in patient who owns the prescription. Patient name, notes, and diagnosis are not shown.</p>
</main>
</body>
</html>
