<?php
/**
 * Railway SMS client checks. Does not open a network connection or send SMS.
 *
 * php scripts/dev/test_textbee_railway_client.php
 */
$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

if (($argv[1] ?? '') === 'enabled') {
    define('TEXTBEE_SEND_ENABLED', true);
    define('TEXTBEE_API_KEY', 'php-test-key-must-not-print');
    require_once dirname(__DIR__, 2) . '/app/core/TextBeeSmsClient.php';

    check(TextBeeSmsClient::isConfigured() === false, 'a local API key does not configure the gateway');
    TextBeeSmsClient::setTestTransport(static function (): array {
        return [
            'success' => true,
            'status' => 'mocked',
            'message' => 'SMS was not sent.',
            'provider_ref' => 'mock-test',
            'mocked' => true,
            'disposition' => 'mocked',
        ];
    });
    $mocked = TextBeeSmsClient::send('+639171234567', 'Hello');
    check(($mocked['disposition'] ?? '') === 'mocked' && !empty($mocked['mocked']), 'enabled client can record a gateway mock');
    TextBeeSmsClient::setTestTransport(static function (): array {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'Could not reach the SMS gateway.',
            'provider_ref' => null,
            'mocked' => false,
            'disposition' => 'uncertain',
        ];
    });
    $error = TextBeeSmsClient::send('+639171234567', 'Hello');
    check(($error['disposition'] ?? '') === 'uncertain' && ($error['success'] ?? true) === false, 'enabled client keeps an uncertain gateway error');
    check(TextBeeSmsClient::networkCallCount() === 0, 'test transport does not open a connection');

    $interpret = new ReflectionMethod(TextBeeSmsClient::class, 'interpretGatewayResponse');
    $interpret->setAccessible(true);
    $accepted = $interpret->invoke(null, 200, '{"disposition":"accepted","provider_ref":"batch-1","delivered":true}');
    check(
        ($accepted['disposition'] ?? '') === 'accepted'
            && ($accepted['mocked'] ?? true) === false
            && str_contains((string) ($accepted['message'] ?? ''), 'does not confirm'),
        'acceptance is not treated as delivery'
    );
    $secretBody = '{"message":"failed for php-test-key-must-not-print bearer token","disposition":"uncertain"}';
    $uncertain = $interpret->invoke(null, 504, $secretBody);
    $encoded = json_encode($uncertain);
    check(
        ($uncertain['disposition'] ?? '') === 'uncertain'
            && is_string($encoded)
            && !str_contains($encoded, 'php-test-key-must-not-print')
            && !str_contains($encoded, 'bearer'),
        'gateway errors do not return response secrets'
    );
    echo $failed === 0 ? "OK\n" : "FAILED {$failed}\n";
    exit($failed === 0 ? 0 : 1);
}

define('TEXTBEE_SEND_ENABLED', false);
define('TEXTBEE_API_KEY', 'php-test-key-must-not-print');
require_once dirname(__DIR__, 2) . '/app/core/TextBeeSmsClient.php';

$mock = TextBeeSmsClient::send('+639171234567', 'Hello from the test.');
check(($mock['status'] ?? '') === 'mocked' && !empty($mock['mocked']) && ($mock['success'] ?? false) === true, 'disabled client returns a mock');
check(TextBeeSmsClient::networkCallCount() === 0, 'disabled client does not call the gateway');
check((TextBeeSmsClient::send('123', 'Hello')['status'] ?? '') === 'failed', 'invalid mobile is rejected before send');
check(TextBeeSmsClient::isConfigured() === false, 'Hostinger does not treat a TextBee key as configuration');

$client = (string) file_get_contents(dirname(__DIR__, 2) . '/app/core/TextBeeSmsClient.php');
$followup = (string) file_get_contents(dirname(__DIR__, 2) . '/app/includes/followup_sms.php');
$provider = (string) file_get_contents(dirname(__DIR__, 2) . '/app/api/provider/send_followup_sms.php');
check(str_contains($client, 'medconnect_ai_service_auth_headers()'), 'client uses the existing service-auth helper');
check(str_contains($client, "rtrim((string) AI_SERVICE_BASE_URL, '/') . '/sms/send'"), 'client calls the Railway SMS route');
check(!str_contains($client, 'api.textbee.dev') && !str_contains($client, 'x-api-key'), 'client does not call TextBee directly');
check(str_contains($followup, "status IN ('mocked', 'accepted', 'pending')"), 'duplicate-send protection remains');
check(str_contains($followup, "status = 'pending'"), 'pending claim remains');
check(str_contains($followup, "disposition === 'mocked'"), 'a Railway mock updates the pending claim');
check(str_contains($provider, 'auth_csrf_require()') && str_contains($provider, "user_role'] !== 'provider'"), 'provider session and CSRF checks remain');

$enabled = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' enabled';
$out = [];
$code = 1;
exec($enabled . ' 2>&1', $out, $code);
foreach ($out as $line) {
    echo $line . PHP_EOL;
}
check($code === 0, 'enabled-client checks passed');

echo $failed === 0 ? "OK\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
