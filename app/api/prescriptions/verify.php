<?php
/**
 * Public prescription verification.
 * Login is not required. The response is limited to validity, the issuing
 * provider's name, and the issue date.
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
$result = prescription_verification_public($pdo, $token);
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
    <?php endif; ?>
    <p class="note">This page confirms the prescription record stored by medConnect. It does not verify a PRC license and does not show patient or medication details.</p>
</main>
</body>
</html>
