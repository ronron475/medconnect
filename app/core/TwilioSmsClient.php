<?php
/**
 * Optional Twilio SMS sender for follow-up reminders.
 * Configure TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER in environment.
 */
final class TwilioSmsClient
{
    public static function isConfigured(): bool
    {
        if (defined('TWILIO_CONFIGURED')) {
            return (bool) TWILIO_CONFIGURED;
        }
        return self::accountSid() !== ''
            && self::authToken() !== ''
            && self::fromNumber() !== '';
    }

    public static function accountSid(): string
    {
        if (defined('TWILIO_ACCOUNT_SID') && TWILIO_ACCOUNT_SID !== '') {
            return (string) TWILIO_ACCOUNT_SID;
        }
        return trim((string) (getenv('TWILIO_ACCOUNT_SID') ?: ($_ENV['TWILIO_ACCOUNT_SID'] ?? '')));
    }

    public static function authToken(): string
    {
        if (defined('TWILIO_AUTH_TOKEN') && TWILIO_AUTH_TOKEN !== '') {
            return (string) TWILIO_AUTH_TOKEN;
        }
        return trim((string) (getenv('TWILIO_AUTH_TOKEN') ?: ($_ENV['TWILIO_AUTH_TOKEN'] ?? '')));
    }

    public static function fromNumber(): string
    {
        if (defined('TWILIO_FROM_NUMBER') && TWILIO_FROM_NUMBER !== '') {
            return (string) TWILIO_FROM_NUMBER;
        }
        return trim((string) (getenv('TWILIO_FROM_NUMBER') ?: ($_ENV['TWILIO_FROM_NUMBER'] ?? '')));
    }

    /**
     * Convert PH mobile (09XXXXXXXXX) to E.164 (+639XXXXXXXXX) when needed.
     */
    public static function toE164(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+63' . substr($digits, 1);
        }
        if (str_starts_with($mobile, '+')) {
            return '+' . ltrim($digits, '+');
        }
        return '+' . $digits;
    }

    /**
     * @return array{success:bool,message:string,sid?:string}
     */
    public static function send(string $toMobile, string $body): array
    {
        if (!self::isConfigured()) {
            return [
                'success' => false,
                'message' => 'Twilio is not configured. Follow-up saved with in-app/email reminder only.',
            ];
        }

        $to = self::toE164($toMobile);
        if ($to === '' || strlen(preg_replace('/\D+/', '', $to) ?? '') < 10) {
            return ['success' => false, 'message' => 'Patient mobile number is missing or invalid.'];
        }

        $body = trim($body);
        if ($body === '') {
            return ['success' => false, 'message' => 'SMS body is empty.'];
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(self::accountSid()) . '/Messages.json';
        $payload = http_build_query([
            'To' => $to,
            'From' => self::fromNumber(),
            'Body' => $body,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERPWD => self::accountSid() . ':' . self::authToken(),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($raw)) {
            return ['success' => false, 'message' => 'Could not reach Twilio SMS service.'];
        }

        $decoded = json_decode($raw, true);
        if ($http >= 200 && $http < 300 && is_array($decoded) && !empty($decoded['sid'])) {
            return [
                'success' => true,
                'message' => 'SMS reminder sent.',
                'sid' => (string) $decoded['sid'],
            ];
        }

        $err = is_array($decoded)
            ? trim((string) ($decoded['message'] ?? $decoded['error_message'] ?? 'Twilio rejected the SMS.'))
            : 'Twilio rejected the SMS.';

        return ['success' => false, 'message' => $err];
    }
}
