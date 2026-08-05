<?php
/**
 * Vercel serverless front controller (vercel-php).
 * Mirrors root .htaccess routing so MedConnect works without Apache.
 */
declare(strict_types=1);

// Serverless-friendly session / temp storage
$tmp = sys_get_temp_dir();
if (is_dir($tmp) && is_writable($tmp)) {
    $sessionDir = $tmp . '/medconnect-sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }
    if (is_dir($sessionDir)) {
        ini_set('session.save_path', $sessionDir);
    }
}

putenv('DB_ENV=cloud');
$_ENV['DB_ENV'] = 'cloud';
putenv('MEDCONNECT_AI_AUTO_START=false');
$_ENV['MEDCONNECT_AI_AUTO_START'] = 'false';
if (getenv('MEDCONNECT_AI_SERVICE_ENABLED') === false) {
    putenv('MEDCONNECT_AI_SERVICE_ENABLED=false');
    $_ENV['MEDCONNECT_AI_SERVICE_ENABLED'] = 'false';
}
if (getenv('MEDCONNECT_AI_REQUIRE_PYTHON') === false) {
    putenv('MEDCONNECT_AI_REQUIRE_PYTHON=false');
    $_ENV['MEDCONNECT_AI_REQUIRE_PYTHON'] = 'false';
}
if (getenv('MEDCONNECT_TRUST_PROXY') === false) {
    putenv('MEDCONNECT_TRUST_PROXY=true');
    $_ENV['MEDCONNECT_TRUST_PROXY'] = 'true';
}

$root = dirname(__DIR__);
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
$path = rawurldecode($path);
$path = '/' . ltrim($path, '/');
if ($path !== '/') {
    $path = rtrim($path, '/') ?: '/';
}

/**
 * Resolve a file under $base, blocking path traversal.
 */
function medconnect_vercel_safe_file(string $base, string $relative): ?string
{
    $relative = str_replace(["\0", '\\'], ['', '/'], $relative);
    $relative = ltrim($relative, '/');
    if ($relative === '' || str_contains($relative, '..')) {
        return null;
    }
    $full = $base . '/' . $relative;
    $baseReal = realpath($base);
    $fullReal = realpath($full);
    if ($baseReal === false || $fullReal === false) {
        return is_file($full) ? $full : null;
    }
    if (!str_starts_with($fullReal, $baseReal)) {
        return null;
    }
    return is_file($fullReal) ? $fullReal : null;
}

function medconnect_vercel_serve_static(string $file): void
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'map' => 'application/json',
        'txt' => 'text/plain; charset=utf-8',
        'pdf' => 'application/pdf',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

// Static assets (fallback if rewrite misses)
if (preg_match('#^/assets/(.+)$#', $path, $m)) {
    $file = medconnect_vercel_safe_file($root . '/public/assets', $m[1]);
    if ($file !== null) {
        medconnect_vercel_serve_static($file);
    }
    http_response_code(404);
    exit('Not found');
}

// Block sensitive trees
if (preg_match('#^/(bootstrap|config|data|database|resources|storage|scripts|vendor|ai_service)(/|$)#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if (preg_match('#^/app/(core|includes|models)(/|$)#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if (preg_match('#\.(env|sql|log|md|lock|git|sh|bak)$#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

// Landing
if ($path === '/' || $path === '/index.php') {
    require $root . '/public/index.php';
    exit;
}

// Portal views: /views/foo.php
if (preg_match('#^/views/(.+)$#i', $path, $m)) {
    $_GET['path'] = $m[1];
    require $root . '/public/view.php';
    exit;
}

// Public PHP pages
if (preg_match('#^/(forgot_password|reset_password|setup_password|verify|verification-success|announcements|nlp_step3_demo|nlp_cds_demo)\.php$#i', $path, $m)) {
    $page = medconnect_vercel_safe_file($root . '/public', $m[1] . '.php');
    if ($page !== null) {
        require $page;
        exit;
    }
}

// OCR demo alias
if (strcasecmp($path, '/ocr-demo') === 0) {
    require $root . '/public/nlp_step3_demo.php';
    exit;
}

// API health aliases
if (strcasecmp($path, '/api/health') === 0) {
    require $root . '/app/api/ai/health.php';
    exit;
}
if (strcasecmp($path, '/api/groq_health') === 0) {
    require $root . '/app/api/ai/groq_health.php';
    exit;
}

// Legacy auth redirects → API
$legacyAuth = [
    '/login.php' => '/app/api/login.php',
    '/logout.php' => '/app/api/logout.php',
    '/register.php' => '/app/api/register.php',
    '/send_otp.php' => '/app/api/send_otp.php',
    '/verify_otp.php' => '/app/api/verify_otp.php',
    '/request_password_reset.php' => '/app/api/request_password_reset.php',
    '/reset_password_otp.php' => '/app/api/reset_password_otp.php',
];
if (isset($legacyAuth[strtolower($path)])) {
    $path = $legacyAuth[strtolower($path)];
}

// Controllers: /controllers/* or /app/controllers/*
if (preg_match('#^/(?:app/)?controllers/(.+\.php)$#i', $path, $m)) {
    $file = medconnect_vercel_safe_file($root . '/app/controllers', $m[1]);
    if ($file !== null) {
        require $file;
        exit;
    }
    http_response_code(404);
    exit('Not found');
}

// App APIs: /app/api/*
if (preg_match('#^/app/api/(.+\.php)/?$#i', $path, $m)) {
    $file = medconnect_vercel_safe_file($root . '/app/api', $m[1]);
    if ($file !== null) {
        require $file;
        exit;
    }
    http_response_code(404);
    exit('Not found');
}

// Hospital referral module
if (preg_match('#^/hospital-referral/(.+)$#i', $path, $m)) {
    $file = medconnect_vercel_safe_file($root . '/modules/hospital_referral', $m[1]);
    if ($file !== null) {
        if (str_ends_with(strtolower($file), '.php')) {
            require $file;
        } else {
            medconnect_vercel_serve_static($file);
        }
        exit;
    }
}

// Address selector module
if (preg_match('#^/philippine-address-selector-main/(.+)$#i', $path, $m)) {
    $file = medconnect_vercel_safe_file($root . '/modules/address_selector', $m[1]);
    if ($file !== null) {
        if (str_ends_with(strtolower($file), '.php')) {
            require $file;
        } else {
            medconnect_vercel_serve_static($file);
        }
        exit;
    }
}

// Direct public/*.php
if (preg_match('#^/([^/]+\.php)$#i', $path, $m)) {
    $file = medconnect_vercel_safe_file($root . '/public', $m[1]);
    if ($file !== null) {
        require $file;
        exit;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Page not found.';
exit;
