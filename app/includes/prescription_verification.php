<?php
/**
 * Prescription verification tokens and revocation.
 *
 * Tokens are created only when a new prescription row is inserted.
 * Legacy rows stay without a token and cannot verify as Valid.
 * QR images are rendered after the database transaction, never inside it.
 *
 * QR drawing uses the MIT-licensed encoder at lib/qrcode/qrcode.php
 * (Kazuhiko Arase). It is stored in the repository because it depends only
 * on PHP, which matches local PHP 8.1 and the PHP 8.2 deployment image.
 */

const PRESCRIPTION_STATUS_ACTIVE = 'active';
const PRESCRIPTION_STATUS_REVOKED = 'revoked';

function prescription_verification_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM prescriptions')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $done = true;
        return;
    }

    $byName = [];
    foreach ($cols as $col) {
        $byName[(string) ($col['Field'] ?? '')] = true;
    }

    try {
        if (!isset($byName['verification_token'])) {
            $pdo->exec('ALTER TABLE prescriptions ADD COLUMN verification_token VARCHAR(64) NULL DEFAULT NULL AFTER notes');
        }
        if (!isset($byName['status'])) {
            $pdo->exec("ALTER TABLE prescriptions ADD COLUMN status ENUM('active','revoked') NULL DEFAULT NULL AFTER verification_token");
        }
    } catch (PDOException $e) {
        error_log('prescription_verification_schema_ensure columns: ' . $e->getMessage());
    }

    try {
        $indexes = $pdo->query('SHOW INDEX FROM prescriptions')->fetchAll(PDO::FETCH_ASSOC);
        $hasTokenIndex = false;
        foreach ($indexes as $index) {
            if ((string) ($index['Key_name'] ?? '') === 'uq_rx_verification_token') {
                $hasTokenIndex = true;
                break;
            }
        }
        if (!$hasTokenIndex) {
            $pdo->exec('CREATE UNIQUE INDEX uq_rx_verification_token ON prescriptions (verification_token)');
        }
    } catch (PDOException $e) {
        error_log('prescription_verification_schema_ensure index: ' . $e->getMessage());
    }

    $done = true;
}

function prescription_verification_new_token(): string
{
    return bin2hex(random_bytes(32));
}

function prescription_verification_host_is_local(string $host): bool
{
    if (function_exists('medconnect_is_local_dev_host')) {
        return medconnect_is_local_dev_host($host);
    }

    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        return true;
    }

    return (bool) preg_match(
        '/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/',
        $host
    );
}

/**
 * Verification links follow the configured base URL.
 * A non-local HTTP base is raised to HTTPS so an incoming forwarded-protocol
 * header cannot leave a production QR on HTTP. Local and private hosts stay
 * on the scheme already configured for development.
 */
function prescription_verification_canonical_base(string $base): string
{
    $base = rtrim(trim($base), '/');
    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['host'])) {
        return $base;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $host = (string) $parts['host'];
    if ($scheme === 'http' && !prescription_verification_host_is_local($host)) {
        $scheme = 'https';
    }

    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = rtrim((string) ($parts['path'] ?? ''), '/');

    return $scheme . '://' . $host . $port . $path;
}

function prescription_verification_url(string $token): string
{
    if (!defined('BASE_URL')) {
        throw new RuntimeException('Application base URL is not configured.');
    }

    return prescription_verification_canonical_base((string) BASE_URL)
        . '/app/api/prescriptions/verify.php?t='
        . rawurlencode($token);
}

/**
 * Valid only for an exact token match and an explicit active status.
 * Any other status, including NULL and unrecognized values, is not valid.
 */
function prescription_verification_classify(?string $storedToken, ?string $status, string $presentedToken): string
{
    $stored = strtolower(trim((string) $storedToken));
    $presented = strtolower(trim($presentedToken));
    if (!preg_match('/^[a-f0-9]{64}$/', $presented) || !preg_match('/^[a-f0-9]{64}$/', $stored)) {
        return 'unavailable';
    }
    if (!hash_equals($stored, $presented)) {
        return 'unavailable';
    }

    $status = trim((string) $status);
    if ($status === PRESCRIPTION_STATUS_ACTIVE) {
        return 'valid';
    }
    if ($status === PRESCRIPTION_STATUS_REVOKED) {
        return 'revoked';
    }

    return 'unavailable';
}

/**
 * Insert one issued prescription and its token.
 * The caller owns the transaction. A duplicate token is retried inside that transaction.
 *
 * @return int New prescription id
 */
function prescription_issue(
    PDO $pdo,
    int $consultationId,
    int $patientId,
    int $providerId,
    string $medication,
    string $dosage,
    string $frequency,
    string $duration,
    ?string $notes
): int {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nowSql = $driver === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
    $lastError = null;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $token = prescription_verification_new_token();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO prescriptions
                    (consultation_id, patient_id, provider_id, medication_name, dosage, frequency, duration, notes, verification_token, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, {$nowSql})
            ");
            $stmt->execute([
                $consultationId,
                $patientId,
                $providerId,
                $medication,
                $dosage,
                $frequency,
                $duration,
                $notes,
                $token,
                PRESCRIPTION_STATUS_ACTIVE,
            ]);

            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            $duplicate = (string) $e->getCode() === '23000' || $driverCode === 1062 || $driverCode === 19;
            if (!$duplicate) {
                throw $e;
            }
            $lastError = $e;
        }
    }

    throw $lastError ?? new RuntimeException('Could not store a verification token.');
}

/**
 * @return array{state:string,provider_name:string,issued_on:string}
 */
function prescription_verification_public(PDO $pdo, string $token): array
{
    $hidden = ['state' => 'unavailable', 'provider_name' => '', 'issued_on' => ''];
    $presented = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{64}$/', $presented)) {
        return $hidden;
    }

    $stmt = $pdo->prepare('
        SELECT p.verification_token, p.status, p.created_at, u.first_name, u.last_name
        FROM prescriptions p
        LEFT JOIN users u ON u.id = p.provider_id
        WHERE p.verification_token = ?
        LIMIT 1
    ');
    $stmt->execute([$presented]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $hidden;
    }

    $state = prescription_verification_classify(
        (string) ($row['verification_token'] ?? ''),
        isset($row['status']) ? (string) $row['status'] : null,
        $presented
    );
    if ($state === 'unavailable') {
        return $hidden;
    }

    $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
    $issued = '';
    $created = trim((string) ($row['created_at'] ?? ''));
    if ($created !== '') {
        $ts = strtotime($created);
        if ($ts !== false) {
            $issued = date('M j, Y', $ts);
        }
    }

    return [
        'state' => $state,
        'provider_name' => $name,
        'issued_on' => $issued,
    ];
}

/**
 * Same public result, plus medication fields when the signed-in user is the
 * patient who owns the prescription. Providers and other accounts do not
 * receive those fields from this page.
 *
 * @return array{state:string,provider_name:string,issued_on:string,medication_name?:string,dosage?:string,frequency?:string,duration?:string}
 */
function prescription_verification_for_viewer(PDO $pdo, string $token, int $viewerId): array
{
    $result = prescription_verification_public($pdo, $token);
    if ($viewerId <= 0 || ($result['state'] ?? '') === 'unavailable') {
        return $result;
    }

    $presented = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{64}$/', $presented)) {
        return $result;
    }

    $stmt = $pdo->prepare('
        SELECT medication_name, dosage, frequency, duration, patient_id
        FROM prescriptions
        WHERE verification_token = ?
        LIMIT 1
    ');
    $stmt->execute([$presented]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $result;
    }

    if ((int) ($row['patient_id'] ?? 0) !== $viewerId) {
        return $result;
    }

    $result['medication_name'] = (string) ($row['medication_name'] ?? '');
    $result['dosage'] = (string) ($row['dosage'] ?? '');
    $result['frequency'] = (string) ($row['frequency'] ?? '');
    $result['duration'] = (string) ($row['duration'] ?? '');

    return $result;
}

function prescription_verification_qr_svg(string $url): string
{
    $path = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/lib/qrcode/qrcode.php';
    if (!is_file($path)) {
        return '';
    }
    require_once $path;
    if (!class_exists('QRCode') || !defined('QR_ERROR_CORRECT_LEVEL_M')) {
        return '';
    }

    $qr = QRCode::getMinimumQRCode($url, QR_ERROR_CORRECT_LEVEL_M);
    ob_start();
    $qr->printSVG(3);
    $svg = (string) ob_get_clean();
    $svg = trim($svg);
    if (!str_starts_with($svg, '<svg') || stripos($svg, '<script') !== false) {
        return '';
    }

    return $svg;
}

function prescription_verification_patient_html(?string $token, ?string $status): string
{
    $state = prescription_verification_classify($token, $status, (string) $token);
    if ($state !== 'valid' && $state !== 'revoked') {
        return '';
    }

    $url = prescription_verification_url((string) $token);
    $svg = prescription_verification_qr_svg($url);
    $label = $state === 'revoked' ? 'Revoked' : 'Valid';
    $html = '<style>.rx-verify{margin-top:10px}.rx-verify__state{font-weight:700;margin:0 0 6px}.rx-verify__qr svg{width:148px;height:148px;max-width:100%}</style>';
    $html .= '<div class="rx-verify">';
    $html .= '<p class="rx-verify__state">' . htmlspecialchars($label) . '</p>';
    if ($svg !== '') {
        $html .= '<div class="rx-verify__qr" role="img" aria-label="Prescription verification code">' . $svg . '</div>';
    }
    $html .= '<p class="rx-verify__link"><a href="' . htmlspecialchars($url) . '">Open verification</a></p>';
    $html .= '</div>';

    return $html;
}

/**
 * Role and CSRF gate. Ownership is checked again by the conditional update.
 *
 * @return 'forbidden'|null
 */
function prescription_revoke_gate(string $role, bool $csrfOk, int $sessionProviderId): ?string
{
    if ($role !== 'provider' || !$csrfOk || $sessionProviderId <= 0) {
        return 'forbidden';
    }

    return null;
}

/**
 * Revoke only an active token issued by this provider.
 * A second request leaves a revoked row unchanged.
 *
 * @return 'revoked'|'already_revoked'|'forbidden'|'ineligible'
 */
function prescription_revoke_for_provider(PDO $pdo, int $prescriptionId, int $providerId): string
{
    if ($prescriptionId <= 0 || $providerId <= 0) {
        return 'forbidden';
    }

    $update = $pdo->prepare("
        UPDATE prescriptions
        SET status = 'revoked'
        WHERE id = ?
          AND provider_id = ?
          AND status = 'active'
          AND verification_token IS NOT NULL
          AND verification_token <> ''
    ");
    $update->execute([$prescriptionId, $providerId]);
    if ($update->rowCount() === 1) {
        return 'revoked';
    }

    $select = $pdo->prepare('SELECT provider_id, status FROM prescriptions WHERE id = ? LIMIT 1');
    $select->execute([$prescriptionId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['provider_id'] ?? 0) !== $providerId) {
        return 'forbidden';
    }
    if ((string) ($row['status'] ?? '') === PRESCRIPTION_STATUS_REVOKED) {
        return 'already_revoked';
    }

    return 'ineligible';
}

function prescription_notify_quietly(callable $send): void
{
    try {
        $send();
    } catch (Throwable $e) {
        error_log('prescription notification: ' . $e->getMessage());
    }
}
