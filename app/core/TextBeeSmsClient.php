<?php
/**
 * Follow-up SMS through the existing Railway service.
 *
 * TEXTBEE_SEND_ENABLED must be true before this server calls Railway.
 * Railway holds the TextBee key and performs the gateway request.
 * A mocked result means the message was not sent.
 */
final class TextBeeSmsClient
{
    /** @var null|callable(string,string):array */
    private static $testTransport = null;

    private static int $networkCalls = 0;

    /**
     * Replaces the HTTP call in this process. Production leaves this unset.
     *
     * @param null|callable(string,string):array $transport
     */
    public static function setTestTransport(?callable $transport): void
    {
        self::$testTransport = $transport;
    }

    public static function networkCallCount(): int
    {
        return self::$networkCalls;
    }

    public static function hasTestTransport(): bool
    {
        return self::$testTransport !== null;
    }

    public static function sendEnabled(): bool
    {
        return defined('TEXTBEE_SEND_ENABLED') && TEXTBEE_SEND_ENABLED;
    }

    public static function isConfigured(): bool
    {
        if (!defined('AI_SERVICE_BASE_URL') || trim((string) AI_SERVICE_BASE_URL) === '') {
            return false;
        }
        if (!function_exists('medconnect_ai_service_token')) {
            $auth = dirname(__DIR__) . '/includes/ai_service_auth.php';
            if (is_file($auth)) {
                require_once $auth;
            }
        }
        if (!function_exists('medconnect_ai_service_token')) {
            return false;
        }

        return medconnect_ai_service_token() !== '';
    }

    public static function toE164(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        if (preg_match('/^09\d{9}$/', $digits)) {
            return '+63' . substr($digits, 1);
        }
        if (preg_match('/^639\d{9}$/', $digits)) {
            return '+' . $digits;
        }
        if (preg_match('/^9\d{9}$/', $digits)) {
            return '+63' . $digits;
        }
        return '';
    }

    /**
     * @return array{success:bool,status:string,message:string,provider_ref:?string,mocked:bool,disposition:string}
     */
    public static function send(string $e164, string $body): array
    {
        $body = trim($body);
        if ($e164 === '' || !preg_match('/^\+\d{10,15}$/', $e164)) {
            return self::result(false, 'failed', 'Patient mobile number is missing or invalid.', null, false, 'rejected');
        }
        if ($body === '') {
            return self::result(false, 'failed', 'SMS message is empty.', null, false, 'rejected');
        }

        if (!self::sendEnabled()) {
            return self::result(
                true,
                'mocked',
                'SMS was not sent. TextBee live sending is disabled, so this reminder was logged as a mock.',
                'mock-' . bin2hex(random_bytes(4)),
                true,
                'mocked'
            );
        }

        if (self::$testTransport !== null) {
            $fake = (self::$testTransport)($e164, $body);
            return is_array($fake)
                ? $fake
                : self::result(false, 'failed', 'Could not reach the SMS gateway.', null, false, 'uncertain');
        }

        if (!self::isConfigured()) {
            return self::result(false, 'failed', 'SMS gateway is not configured.', null, false, 'rejected');
        }

        $payload = json_encode([
            'recipient' => $e164,
            'message' => $body,
        ], JSON_UNESCAPED_UNICODE);
        $headers = array_merge(
            ['Content-Type: application/json'],
            function_exists('medconnect_ai_service_auth_headers') ? medconnect_ai_service_auth_headers() : []
        );
        $url = rtrim((string) AI_SERVICE_BASE_URL, '/') . '/sms/send';
        $ch = curl_init($url);
        if ($ch === false) {
            return self::result(false, 'failed', 'Could not reach the SMS gateway.', null, false, 'uncertain');
        }
        self::$networkCalls++;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($raw)) {
            return self::result(false, 'failed', 'Could not reach the SMS gateway.', null, false, 'uncertain');
        }

        return self::interpretGatewayResponse($http, $raw);
    }

    /**
     * @return array{success:bool,status:string,message:string,provider_ref:?string,mocked:bool,disposition:string}
     */
    private static function interpretGatewayResponse(int $http, string $raw): array
    {
        $decoded = json_decode($raw, true);
        $isJson = is_array($decoded);
        $disposition = $isJson ? (string) ($decoded['disposition'] ?? '') : '';
        $mocked = $isJson && (!empty($decoded['mocked']) || $disposition === 'mocked');
        if ($http >= 200 && $http < 300 && $mocked) {
            return self::result(
                true,
                'mocked',
                'SMS was not sent. TextBee live sending is disabled.',
                self::safeProviderRef($isJson ? ($decoded['provider_ref'] ?? null) : null),
                true,
                'mocked'
            );
        }
        if ($http >= 200 && $http < 300 && ($disposition === 'accepted' || ($isJson && ($decoded['status'] ?? '') === 'accepted'))) {
            return self::result(
                true,
                'accepted',
                'TextBee accepted the SMS for delivery. Acceptance does not confirm the patient received it.',
                self::safeProviderRef($isJson ? ($decoded['provider_ref'] ?? null) : null),
                false,
                'accepted'
            );
        }
        if ($http === 400 || $http === 401 || $http === 403 || $http === 503 || $disposition === 'rejected') {
            $message = $http === 503 || ($isJson && ($decoded['message'] ?? '') === 'SMS gateway is not configured.')
                ? 'SMS gateway is not configured.'
                : 'SMS request was rejected.';
            return self::result(false, 'failed', $message, null, false, 'rejected');
        }

        return self::result(false, 'failed', 'The SMS gateway did not confirm whether the message was accepted.', null, false, 'uncertain');
    }

    private static function safeProviderRef(mixed $ref): ?string
    {
        if (!is_string($ref)) {
            return null;
        }
        $ref = trim($ref);
        if ($ref === '' || preg_match('/^[A-Za-z0-9_-]{1,80}$/', $ref) !== 1) {
            return null;
        }

        return $ref;
    }

    /**
     * @return array{success:bool,status:string,message:string,provider_ref:?string,mocked:bool,disposition:string}
     */
    private static function result(bool $success, string $status, string $message, ?string $ref, bool $mocked, string $disposition): array
    {
        return [
            'success' => $success,
            'status' => $status,
            'message' => $message,
            'provider_ref' => $ref,
            'mocked' => $mocked,
            'disposition' => $disposition,
        ];
    }
}
