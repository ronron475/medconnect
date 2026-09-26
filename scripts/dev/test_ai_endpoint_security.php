<?php
/**
 * Focused security checks for AI/NLP endpoint auth + abuse controls.
 * Run: php scripts/dev/test_ai_endpoint_security.php
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

// Avoid full bootstrap (DB). Stub Api for helper load if needed.
if (!class_exists('Api', false)) {
    final class Api
    {
        public static function error(string $message, int $status = 400, array $data = []): void
        {
            throw new RuntimeException('API_ERROR:' . $status . ':' . $message);
        }

        public static function requireAuth(): void
        {
            if (empty($_SESSION['user_id'])) {
                self::error('auth', 401);
            }
        }
    }
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

require_once $root . '/app/includes/session_cookie.php';
if (session_status() === PHP_SESSION_NONE) {
    medconnect_session_start();
}
require_once $root . '/app/includes/ai_endpoint_security.php';

// ── helpers ───────────────────────────────────────────────────
if (function_exists('ai_endpoint_rate_limit')
    && function_exists('ai_endpoint_require_roles')
    && function_exists('ai_endpoint_can_expose_debug')
    && function_exists('ai_endpoint_can_start_ai_service')
    && function_exists('ai_endpoint_public_connection_status')
) {
    pass('ai_endpoint_security helpers loaded');
} else {
    fail('ai_endpoint_security helpers loaded');
}

$public = ai_endpoint_public_connection_status([
    'online' => true,
    'url' => 'https://secret.example/path',
    'python_executable' => '/opt/secret/python',
    'diagnostics' => ['key' => 'should-not-leak'],
    'groq_configured' => true,
    'groq_connected' => true,
    'engine' => 'fastapi',
    'service' => 'x',
    'port_open' => true,
    'status' => 'online',
    'ai_service_enabled' => true,
]);
if (!isset($public['url']) && !isset($public['diagnostics']) && !isset($public['python_executable'])
    && !empty($public['online']) && isset($public['groq_configured'])
) {
    pass('public connection status strips sensitive fields');
} else {
    fail('public connection status strips sensitive fields', json_encode($public));
}

// ── source gates ──────────────────────────────────────────────
$checks = [
    'assess_chief_complaint.php' => [
        'ai_endpoint_rate_limit',
        'ai_endpoint_can_expose_debug',
        'ai_endpoint_public_connection_status',
    ],
    'analyze_medical_profile.php' => [
        'ai_endpoint_rate_limit',
        'ai_endpoint_public_connection_status',
    ],
    'analyze_medical_text.php' => [
        'ai_endpoint_require_roles',
        'ai_endpoint_rate_limit',
        'requireCsrf',
    ],
    'recognize_symptoms.php' => [
        'ai_endpoint_require_roles',
        'ai_endpoint_rate_limit',
        'requireCsrf',
    ],
    'analyze_transcript.php' => [
        'requireRole',
        'requireCsrf',
        'ai_endpoint_rate_limit',
    ],
    'transcribe_chunk.php' => [
        'requireRole',
        'requireCsrf',
        'ai_endpoint_rate_limit',
    ],
    'health.php' => [
        'ai_endpoint_rate_limit',
        'ai_endpoint_can_start_ai_service',
    ],
    'service_status.php' => [
        'ai_endpoint_rate_limit',
        'ai_endpoint_can_start_ai_service',
        'ai_endpoint_public_connection_status',
    ],
    'gemini_health.php' => [
        'ai_endpoint_rate_limit',
        'ai_endpoint_can_expose_debug',
    ],
    'groq_health.php' => [
        'ai_endpoint_rate_limit',
        'ai_endpoint_can_expose_debug',
    ],
];

foreach ($checks as $file => $needles) {
    $src = file_get_contents($root . '/app/api/ai/' . $file) ?: '';
    $missing = [];
    foreach ($needles as $n) {
        if (!str_contains($src, $n)) {
            $missing[] = $n;
        }
    }
    if ($missing === []) {
        pass("{$file} has security gates");
    } else {
        fail("{$file} has security gates", implode(',', $missing));
    }
}

// Demos must remain ungated by login (still CSRF/demo-token + their own throttle)
foreach (['gemini_clinical_interview_demo.php', 'nlp_step3_demo_interview.php'] as $demo) {
    $src = file_get_contents($root . '/app/api/ai/' . $demo) ?: '';
    if (str_contains($src, 'security_throttle')
        && str_contains($src, 'auth_csrf_validate')
        && !str_contains($src, 'ai_endpoint_require_roles')
        && !str_contains($src, 'Api::requireAuth')
    ) {
        pass("{$demo} stays public demo (CSRF+throttle, no login gate)");
    } else {
        fail("{$demo} stays public demo (CSRF+throttle, no login gate)");
    }
}

// assess stays public for trainer/registration (rate-limited, not requireAuth)
$assess = file_get_contents($root . '/app/api/ai/assess_chief_complaint.php') ?: '';
if (str_contains($assess, 'ai_endpoint_rate_limit')
    && !str_contains($assess, 'Api::requireAuth')
    && !str_contains($assess, 'ai_endpoint_require_roles')
) {
    pass('assess_chief_complaint remains public with rate limit');
} else {
    fail('assess_chief_complaint remains public with rate limit');
}

// No error_detail / stack leak patterns in hardened files
foreach (['analyze_medical_profile.php', 'service_status.php', 'assess_chief_complaint.php'] as $file) {
    $src = file_get_contents($root . '/app/api/ai/' . $file) ?: '';
    if (str_contains($src, 'error_detail') || preg_match('/Api::error\([^)]*\$e->getMessage\(\)/', $src)) {
        fail("{$file} does not leak exception detail to clients");
    } else {
        pass("{$file} does not leak exception detail to clients");
    }
}

// Rate limiter integration smoke (does not assert exact count across APCu)
$bucket = 'ai_endpoint_security_selftest_' . bin2hex(random_bytes(4));
$allowed = 0;
$blocked = false;
for ($i = 0; $i < 6; $i++) {
    $rl = mc_rate_limiter_allow($bucket, 5, 60, null);
    if ($rl['allowed']) {
        $allowed++;
    } else {
        $blocked = true;
        break;
    }
}
if ($allowed === 5 && $blocked) {
    pass('mc_rate_limiter blocks after max hits');
} else {
    $rl = mc_rate_limiter_allow($bucket, 5, 60, null);
    if (!$rl['allowed']) {
        pass('mc_rate_limiter blocks after max hits');
    } else {
        fail('mc_rate_limiter blocks after max hits', "allowed={$allowed}");
    }
}

echo "\n";
if ($failures === 0) {
    echo "PASS — all AI endpoint security checks passed\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed\n";
exit(1);
