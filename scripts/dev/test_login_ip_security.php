<?php
/**
 * Login client-IP resolution: spoofed headers vs a configured trusted proxy.
 * Run: php scripts/dev/test_login_ip_security.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function pass(string $m): void
{
    echo "PASS  {$m}\n";
}

function fail(string $m, string $d = ''): void
{
    global $failures;
    $failures++;
    echo "FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n";
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

require_once $root . '/app/includes/login_security.php';

function setFlag(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key]);
        return;
    }
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

/**
 * @param array<string, string> $server
 */
function withRequest(array $server, ?string $trustProxy, ?string $trustedProxies, callable $assert): void
{
    $previous = $_SERVER;
    $_SERVER = $server;
    setFlag('MEDCONNECT_TRUST_PROXY', $trustProxy);
    setFlag('MEDCONNECT_TRUSTED_PROXIES', $trustedProxies);
    try {
        $assert();
    } finally {
        $_SERVER = $previous;
        setFlag('MEDCONNECT_TRUST_PROXY', null);
        setFlag('MEDCONNECT_TRUSTED_PROXIES', null);
    }
}

$direct = [
    'REMOTE_ADDR' => '198.51.100.10',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.99',
    'HTTP_X_REAL_IP' => '203.0.113.8',
    'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
];

withRequest($direct, 'true', '', function () use ($direct): void {
    $ip = login_security_ip();
    if ($ip === '198.51.100.10') {
        pass('direct client ignores spoofed forwarding headers');
    } else {
        fail('direct client ignores spoofed forwarding headers', $ip);
    }
});

withRequest($direct, 'true', '203.0.113.5', function (): void {
    $ip = login_security_ip();
    if ($ip === '198.51.100.10') {
        pass('spoofed headers ignored when the peer is not the trusted proxy');
    } else {
        fail('spoofed headers ignored when the peer is not the trusted proxy', $ip);
    }
});

withRequest($direct, 'false', '198.51.100.10', function (): void {
    $ip = login_security_ip();
    if ($ip === '198.51.100.10') {
        pass('MEDCONNECT_TRUST_PROXY=false ignores forwarding headers');
    } else {
        fail('MEDCONNECT_TRUST_PROXY=false ignores forwarding headers', $ip);
    }
});

withRequest([
    'REMOTE_ADDR' => '198.51.100.10',
    'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
], null, null, function (): void {
    $first = login_security_ip();
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';
    $_SERVER['HTTP_X_REAL_IP'] = '9.9.9.9';
    $second = login_security_ip();
    if ($first === '198.51.100.10' && $second === '198.51.100.10') {
        pass('changing request headers does not change the limited IP');
    } else {
        fail('changing request headers does not change the limited IP', $first . ' / ' . $second);
    }
});

withRequest([
    'REMOTE_ADDR' => '203.0.113.5',
    'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.77',
    'HTTP_X_REAL_IP' => '203.0.113.8',
    'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
], 'true', '203.0.113.5', function (): void {
    $ip = login_security_ip();
    if ($ip === '198.51.100.77') {
        pass('trusted proxy uses the rightmost untrusted forwarded client');
    } else {
        fail('trusted proxy uses the rightmost untrusted forwarded client', $ip);
    }
});

withRequest([
    'REMOTE_ADDR' => '10.1.2.3',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
], 'true', '10.0.0.0/8', function (): void {
    $ip = login_security_ip();
    if ($ip === '198.51.100.20') {
        pass('trusted proxy CIDR accepts the forwarded client');
    } else {
        fail('trusted proxy CIDR accepts the forwarded client', $ip);
    }
});

withRequest([
    'REMOTE_ADDR' => '203.0.113.5',
    'HTTP_X_REAL_IP' => '198.51.100.30',
    'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
], 'true', '203.0.113.5', function (): void {
    $ip = login_security_ip();
    if ($ip === '198.51.100.30') {
        pass('trusted proxy without X-Forwarded-For uses X-Real-IP');
    } else {
        fail('trusted proxy without X-Forwarded-For uses X-Real-IP', $ip);
    }
});

withRequest([
    'REMOTE_ADDR' => 'not-an-ip',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
], 'true', '127.0.0.1', function (): void {
    $ip = login_security_ip();
    if ($ip === '') {
        pass('invalid peer does not fall back to a client-supplied header');
    } else {
        fail('invalid peer does not fall back to a client-supplied header', $ip);
    }
});

$login = (string) file_get_contents($root . '/app/api/login.php');
if (str_contains($login, 'security_throttle_fail($pdo, security_throttle_key(\'login_ip\', $ip), \'login_ip\', 300, 10, 15)')
    && str_contains($login, '$ip = login_security_ip();')
    && str_contains($login, 'LOGIN_MAX_FAILED_ATTEMPTS')
    && str_contains($login, 'LOGIN_LOCKOUT_SECONDS')
) {
    pass('login IP throttle and account lockout thresholds unchanged');
} else {
    fail('login IP throttle and account lockout thresholds unchanged');
}

echo "\n";
if ($failures === 0) {
    echo "PASS — login IP security checks passed\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed\n";
exit(1);
