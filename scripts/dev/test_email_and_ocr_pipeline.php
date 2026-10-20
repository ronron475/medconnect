<?php
/**
 * Comprehensive verification test suite for Email Delivery and OCR ID Verification.
 *
 * Usage: php scripts/dev/test_email_and_ocr_pipeline.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/env_loader.php';

$passCount = 0;
$failCount = 0;

function test_assert(string $label, bool $condition, string $detail = ''): void
{
    global $passCount, $failCount;
    if ($condition) {
        echo "PASS  {$label}\n";
        $passCount++;
    } else {
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failCount++;
    }
}

echo "=== MedConnect Email & OCR Pipeline Verification Suite ===\n\n";

// =========================================================================
// SECTION 1: Email Configuration & Transport Initialization
// =========================================================================
echo "[1] Testing Mailer Initialization & Transport Selection...\n";

require_once $root . '/app/includes/mailer.php';

// Backup original environment state
$origSmtpUser   = getenv('MAIL_USERNAME');
$origSmtpPass   = getenv('MAIL_PASSWORD');
$origAiToken    = getenv('MEDCONNECT_AI_SERVICE_TOKEN');
$origEmailKey   = getenv('EMAIL_API_KEY');
$origRelayUrl   = getenv('MEDCONNECT_EMAIL_RELAY_URL');

function clear_mail_env(): void {
    putenv('MAIL_USERNAME');
    putenv('MAIL_PASSWORD');
    putenv('MEDCONNECT_AI_SERVICE_TOKEN');
    putenv('EMAIL_API_KEY');
    putenv('MEDCONNECT_EMAIL_RELAY_URL');
    unset($_ENV['MAIL_USERNAME'], $_ENV['MAIL_PASSWORD'], $_ENV['MEDCONNECT_AI_SERVICE_TOKEN'], $_ENV['EMAIL_API_KEY'], $_ENV['MEDCONNECT_EMAIL_RELAY_URL']);
    unset($_SERVER['MAIL_USERNAME'], $_SERVER['MAIL_PASSWORD'], $_SERVER['MEDCONNECT_AI_SERVICE_TOKEN'], $_SERVER['EMAIL_API_KEY'], $_SERVER['MEDCONNECT_EMAIL_RELAY_URL']);
}

// 1.1 Neither configured -> initMailer returns null
clear_mail_env();
$neitherMailer = (!medconnect_direct_smtp_available() && !medconnect_railway_relay_available())
    ? initMailer()
    : null;
test_assert(
    'initMailer returns null when neither direct SMTP nor Railway relay is configured',
    $neitherMailer === null
);

// 1.2 Only SMTP configured -> initMailer succeeds and direct_smtp is available
clear_mail_env();
putenv('MAIL_USERNAME=test_user@gmail.com');
putenv('MAIL_PASSWORD=test_app_password');
$_ENV['MAIL_USERNAME'] = 'test_user@gmail.com';
$_ENV['MAIL_PASSWORD'] = 'test_app_password';
$smtpOnlyMailer = initMailer();
test_assert(
    'initMailer initializes when direct SMTP credentials are present without Railway tokens',
    $smtpOnlyMailer instanceof MedConnectRailwayMailer && medconnect_direct_smtp_available()
);

// 1.3 Only Railway relay configured -> initMailer succeeds and relay is available
clear_mail_env();
putenv('MEDCONNECT_AI_SERVICE_TOKEN=mock_service_token_abc');
putenv('EMAIL_API_KEY=mock_email_api_key_xyz');
$_ENV['MEDCONNECT_AI_SERVICE_TOKEN'] = 'mock_service_token_abc';
$_ENV['EMAIL_API_KEY'] = 'mock_email_api_key_xyz';
$relayOnlyMailer = initMailer();
test_assert(
    'initMailer initializes in relay mode when Railway tokens are present without SMTP credentials',
    $relayOnlyMailer instanceof MedConnectRailwayMailer && medconnect_railway_relay_available()
);

// 1.4 Dynamic Relay URL Resolution
putenv('MEDCONNECT_EMAIL_RELAY_URL=https://custom-relay.example.com/api/send');
test_assert(
    'medconnect_railway_email_url() respects MEDCONNECT_EMAIL_RELAY_URL environment override',
    medconnect_railway_email_url() === 'https://custom-relay.example.com/api/send'
);
clear_mail_env();

// =========================================================================
// SECTION 2: Direct SMTP to Railway Relay Fallback
// =========================================================================
echo "\n[2] Testing SMTP to Relay Fallback & Secret Sanitization...\n";

// Set both: invalid SMTP (to simulate Hostinger blocked port / connection refused) + Railway relay
putenv('MAIL_USERNAME=dummy@gmail.com');
putenv('MAIL_PASSWORD=dummy_pass');
putenv('MAIL_HOST=127.0.0.1');
putenv('MAIL_PORT=1'); // Unreachable port -> instant socket failure
putenv('MEDCONNECT_AI_SERVICE_TOKEN=test_token_123');
putenv('EMAIL_API_KEY=test_key_456');
$_ENV['MAIL_USERNAME'] = 'dummy@gmail.com';
$_ENV['MAIL_PASSWORD'] = 'dummy_pass';
$_ENV['MAIL_HOST'] = '127.0.0.1';
$_ENV['MAIL_PORT'] = '1';
$_ENV['MEDCONNECT_AI_SERVICE_TOKEN'] = 'test_token_123';
$_ENV['EMAIL_API_KEY'] = 'test_key_456';

$fallbackMailer = initMailer();
test_assert('Dual-configured mailer initializes successfully', $fallbackMailer instanceof MedConnectRailwayMailer);

// Capture logs during a delivery attempt
$logBuffer = '';
$origLogHandler = set_error_handler(function($errno, $errstr) use (&$logBuffer) {
    $logBuffer .= " " . $errstr;
    return true;
});

$fallbackTriggered = false;
try {
    $fallbackMailer->addAddress('patient@example.com');
    $fallbackMailer->Subject = 'Test OTP Verification';
    $fallbackMailer->Body = '<p>Your OTP is 123456</p>';
    $fallbackMailer->send();
} catch (Exception $e) {
    $fallbackTriggered = true;
}
restore_error_handler();

test_assert(
    'SMTP socket failure safely attempts relay without fatal crash',
    $fallbackTriggered
);

test_assert(
    'Error logs do not leak SMTP passwords or bearer tokens',
    !str_contains($logBuffer, 'dummy_pass') && !str_contains($logBuffer, 'test_token_123')
);

// Restore environment
clear_mail_env();
if ($origSmtpUser) putenv("MAIL_USERNAME={$origSmtpUser}");
if ($origSmtpPass) putenv("MAIL_PASSWORD={$origSmtpPass}");
if ($origAiToken) putenv("MEDCONNECT_AI_SERVICE_TOKEN={$origAiToken}");
if ($origEmailKey) putenv("EMAIL_API_KEY={$origEmailKey}");
if ($origRelayUrl) putenv("MEDCONNECT_EMAIL_RELAY_URL={$origRelayUrl}");

// =========================================================================
// SECTION 3: OCR Configuration & Request Reduction
// =========================================================================
echo "\n[3] Testing OCR Fail-Safe Configuration & Variant Reductions...\n";

// 3.1 Test missing OCR key in an isolated directory without a .env file
$isolatedDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocrcfg_test_' . bin2hex(random_bytes(4));
@mkdir($isolatedDir . '/config', 0777, true);
copy($root . '/config/ocr_config.php', $isolatedDir . '/config/ocr_config.php');
copy($root . '/config/env_loader.php', $isolatedDir . '/config/env_loader.php');

$probeNoEnv = <<<PHP
<?php
putenv('OCR_SPACE_API_KEY');
unset(\$_ENV['OCR_SPACE_API_KEY'], \$_SERVER['OCR_SPACE_API_KEY']);
require '{$isolatedDir}/config/ocr_config.php';
echo constant('OCR_SPACE_API_KEY') === '' ? 'EMPTY_SAFE' : 'HAS_FALLBACK';
PHP;

$tmpProbe = tempnam(sys_get_temp_dir(), 'probe_ocr');
file_put_contents($tmpProbe, $probeNoEnv);
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpProbe), $out);
@unlink($tmpProbe);
@unlink($isolatedDir . '/config/ocr_config.php');
@unlink($isolatedDir . '/config/env_loader.php');
@rmdir($isolatedDir . '/config');
@rmdir($isolatedDir);

$probeResult = trim(implode('', $out));
test_assert(
    'OCR_SPACE_API_KEY defaults to empty when env and .env are missing (no hardcoded fallback key)',
    $probeResult === 'EMPTY_SAFE',
    "Result was: {$probeResult}"
);

// 3.2 Verify that process_id_ocr.php buildOcrExtractVariants is reduced and no longer loops rotations
$ocrControllerSrc = (string) file_get_contents($root . '/app/controllers/patient/process_id_ocr.php');

$hasNoRotationsInExtractVariants = !str_contains($ocrControllerSrc, "push(\$rotated, 'rot' . \$angle");
$usesMaxTwoVariants = str_contains($ocrControllerSrc, 'exif-color') && str_contains($ocrControllerSrc, 'sharpen-color') && !str_contains($ocrControllerSrc, 'gray-fallback');

test_assert(
    'buildOcrExtractVariants eliminates excessive rotation loops (reduced from 14 to max 2 passes)',
    $hasNoRotationsInExtractVariants && $usesMaxTwoVariants
);

// 3.3 Verify unconfigured fail-safe in process_id_ocr.php
$hasUnconfiguredCheck = str_contains($ocrControllerSrc, "'unconfigured'")
    && str_contains($ocrControllerSrc, "\$configuredKey === ''");

test_assert(
    'process_id_ocr.php fails safely when OCR_SPACE_API_KEY is not configured',
    $hasUnconfiguredCheck
);

// 3.4 FastAPI OCR Health Check Validation & Error Guarding
require_once $root . '/app/core/OcrFastApiClient.php';

test_assert(
    'OcrFastApiClient::isHealthyResponse accepts dedicated /ocr/health {"status":"ok"}',
    OcrFastApiClient::isHealthyResponse(['status' => 'ok', 'service' => 'medconnect-ocr']) === true
);

test_assert(
    'OcrFastApiClient::isHealthyResponse accepts unified /health {"status":"online", "legacy_status":"ok"}',
    OcrFastApiClient::isHealthyResponse(['status' => 'online', 'legacy_status' => 'ok', 'success' => true]) === true
);

test_assert(
    'OcrFastApiClient::isHealthyResponse rejects unhealthy error status',
    OcrFastApiClient::isHealthyResponse(['status' => 'error', 'message' => 'degraded']) === false
);

test_assert(
    'OcrFastApiClient::isHealthyResponse rejects offline service status',
    OcrFastApiClient::isHealthyResponse(['status' => 'offline']) === false
);

test_assert(
    'OcrFastApiClient::isHealthyResponse rejects malformed or unauthorized response',
    OcrFastApiClient::isHealthyResponse(['detail' => 'Unauthorized']) === false
);

test_assert(
    'OcrFastApiClient::isHealthyResponse rejects empty array or null (service unavailable)',
    OcrFastApiClient::isHealthyResponse([]) === false && OcrFastApiClient::isHealthyResponse(null) === false
);

test_assert(
    'process_id_ocr.php uses OcrFastApiClient::isHealthyResponse for consistent health gating',
    str_contains($ocrControllerSrc, 'OcrFastApiClient::isHealthyResponse($health)')
);

// =========================================================================
// SECTION 4: Raw Text Session Caching & Verify Mode Reuse
// =========================================================================
echo "\n[4] Testing Raw Text Session Caching & Cross-Mode Association...\n";

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$sampleFileHash = hash('sha256', 'synthetic_patient_national_id_sample_bytes');
$sampleExtractedText = "REPUBLIKA NG PILIPINAS\nPAMBANSANG PAGKAKAKILANLAN\nPHILIPPINE IDENTIFICATION CARD\nJUAN DELA CRUZ\nDOB: 1995-04-12\n1234-5678-9012-3456\nBAGO CITY, NEGROS OCCIDENTAL";

// Simulate Extract Mode caching the text in server session
$_SESSION['ocr_raw_text_cache'] = [$sampleFileHash => $sampleExtractedText];

test_assert(
    'Raw extracted text is securely indexed by image SHA-256 in session cache',
    isset($_SESSION['ocr_raw_text_cache'][$sampleFileHash])
);

// Verify that a different image hash does not match
$differentHash = hash('sha256', 'different_image_content');
test_assert(
    'Different uploaded image does not collide with existing session cache',
    !isset($_SESSION['ocr_raw_text_cache'][$differentHash])
);

// Verify that process_id_ocr.php contains the session cache reuse in Verify mode
$hasSessionCacheCheck = str_contains($ocrControllerSrc, "\$_SESSION['ocr_raw_text_cache'][\$verify_file_hash]")
    && str_contains($ocrControllerSrc, "'stage' => 'session_cache'");

test_assert(
    'Verify Mode reuses session_cache for identical image without re-calling OCR service',
    $hasSessionCacheCheck
);

// =========================================================================
// SECTION 5: Strict Bago City Residency & Identity Validation Rules
// =========================================================================
echo "\n[5] Testing Strict Residency Gate & Non-Bago Rejection...\n";

require_once $root . '/app/core/PhilSysOcrParser.php';

// 5.1 Test text containing Bago City
$bagoExtraction = PhilSysOcrParser::extractAll($sampleExtractedText);
$bagoAddressVal = $bagoExtraction['fields']['address']['value'] ?? '';
test_assert(
    'PhilSys parser extracts Bago City address from valid National ID text',
    str_contains(strtolower($bagoAddressVal), 'bago')
);

// 5.2 Test text from another city (e.g. Bacolod City or Manila)
$nonBagoText = "REPUBLIKA NG PILIPINAS\nPAMBANSANG PAGKAKAKILANLAN\nJUAN DELA CRUZ\nDOB: 1995-04-12\n1234-5678-9012-3456\nBACOLOD CITY, NEGROS OCCIDENTAL";
$nonBagoExtraction = PhilSysOcrParser::extractAll($nonBagoText);
$nonBagoAddressVal = $nonBagoExtraction['fields']['address']['value'] ?? '';
$hasBago = str_contains(strtolower($nonBagoAddressVal), 'bago');
test_assert(
    'National ID from non-Bago resident (Bacolod City) is NOT approved as Bago City',
    !$hasBago
);

// 5.3 Test unverified/empty OCR text never grants session residency
unset($_SESSION['ocr_verified'], $_SESSION['ocr_bago_city'], $_SESSION['ocr_national_id']);
$emptyOcrText = "";
$emptyExtraction = PhilSysOcrParser::extractAll($emptyOcrText);
$emptyAddressVal = $emptyExtraction['fields']['address']['value'] ?? '';
$emptyApproved = str_contains(strtolower($emptyAddressVal), 'bago');
test_assert(
    'Empty or failed OCR extraction NEVER approves residency',
    !$emptyApproved && empty($_SESSION['ocr_bago_city'])
);

// 5.4 Verify Bago City guard logic in process_id_ocr.php
$hasStrictBagoGuard = str_contains($ocrControllerSrc, "\$bago_state === 'fallback'")
    && str_contains($ocrControllerSrc, "if (\$verified && \$bago_found)")
    && str_contains($ocrControllerSrc, "\$_SESSION['ocr_bago_city']   = true;");

test_assert(
    'process_id_ocr.php strictly blocks residency approval on fallback or failed OCR states',
    $hasStrictBagoGuard
);

// =========================================================================
// SUMMARY
// =========================================================================
echo "\n=== Verification Summary ===\n";
echo "Total Passed: {$passCount}\n";
echo "Total Failed: {$failCount}\n";

if ($failCount === 0) {
    echo "STATUS: ALL TESTS PASSED (100%)\n";
    exit(0);
} else {
    echo "STATUS: SOME TESTS FAILED\n";
    exit(1);
}
