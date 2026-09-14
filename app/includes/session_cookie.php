<?php
/**
 * MedConnect session cookie helpers (isolation + consistent logout expiry).
 * Must remain safe to load before BASE_URL / ASSET_BASE are defined.
 */
declare(strict_types=1);

if (!defined('MEDCONNECT_SESSION_NAME')) {
    $envName = trim((string) (getenv('MEDCONNECT_SESSION_NAME') ?: ''));
    define(
        'MEDCONNECT_SESSION_NAME',
        $envName !== '' ? preg_replace('/[^A-Za-z0-9_]/', '', $envName) ?: 'MEDCONNECT_SESSION' : 'MEDCONNECT_SESSION'
    );
}

/**
 * Cookie path scoped to this MedConnect install (not the whole host).
 * Examples: "/" (vhost/public docroot) or "/medconnect" (XAMPP subdirectory).
 */
function medconnect_session_cookie_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (defined('ASSET_BASE')) {
        $base = rtrim((string) ASSET_BASE, '/');
        $cached = $base === '' ? '/' : $base;
        return $cached;
    }

    $appUrlOverride = (string) (getenv('MEDCONNECT_APP_URL') ?: '');
    if ($appUrlOverride !== '') {
        $parsed = @parse_url(rtrim($appUrlOverride, '/'));
        if (is_array($parsed)) {
            $path = rtrim((string) ($parsed['path'] ?? ''), '/');
            $cached = $path === '' ? '/' : $path;
            return $cached;
        }
    }

    $onVercel = getenv('VERCEL') !== false
        || getenv('VERCEL_ENV') !== false
        || !empty($_ENV['VERCEL'])
        || !empty($_ENV['VERCEL_ENV']);
    if ($onVercel) {
        $cached = '/';
        return $cached;
    }

    $projectRoot = str_replace('\\', '/', (string) realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2));
    $publicFs = str_replace('\\', '/', (string) realpath($projectRoot . '/public') ?: ($projectRoot . '/public'));
    $docRoot = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');

    if ($docRoot !== '' && strcasecmp(rtrim($docRoot, '/'), rtrim($publicFs, '/')) === 0) {
        $cached = '/';
        return $cached;
    }

    if ($docRoot !== '' && stripos($projectRoot, $docRoot) === 0) {
        $relative = substr($projectRoot, strlen($docRoot));
        $relative = '/' . ltrim(str_replace('\\', '/', (string) $relative), '/');
        $relative = rtrim($relative, '/');
        $cached = $relative === '' ? '/' : $relative;
        return $cached;
    }

    $cached = '/';
    return $cached;
}

function medconnect_session_samesite(): string
{
    $sameSite = (string) (getenv('MEDCONNECT_SESSION_SAMESITE') ?: 'Lax');
    $sameSite = ucfirst(strtolower(trim($sameSite)));
    if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
        $sameSite = 'Lax';
    }
    return $sameSite;
}

/**
 * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
 */
function medconnect_session_cookie_params(): array
{
    $isHttps = function_exists('medconnect_request_is_https')
        ? medconnect_request_is_https()
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443));

    return [
        'lifetime' => 0,
        'path'     => medconnect_session_cookie_path(),
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => medconnect_session_samesite(),
    ];
}

/**
 * Start the canonical MedConnect session (name + cookie path/params).
 * Safe to call if a wrong/default session was already opened — that session is closed first.
 *
 * @param array{read_and_close?:bool} $options
 */
function medconnect_session_start(array $options = []): void
{
    $readAndClose = !empty($options['read_and_close']);

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === MEDCONNECT_SESSION_NAME) {
            return;
        }
        // Premature session_start() before bootstrap used PHPSESSID / wrong path.
        session_write_close();
        $_SESSION = [];
    }

    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', medconnect_session_samesite());

    $isHttps = function_exists('medconnect_request_is_https')
        ? medconnect_request_is_https()
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443));
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }

    if (session_name() !== MEDCONNECT_SESSION_NAME) {
        session_name(MEDCONNECT_SESSION_NAME);
    }

    session_set_cookie_params(medconnect_session_cookie_params());

    if ($readAndClose) {
        session_start(['read_and_close' => true]);
    } else {
        session_start();
    }
}

/** Expire the MedConnect session cookie (call after clearing $_SESSION). */
function medconnect_expire_session_cookie(): void
{
    if (!ini_get('session.use_cookies')) {
        return;
    }
    $params = medconnect_session_cookie_params();
    $name = session_status() !== PHP_SESSION_NONE ? session_name() : MEDCONNECT_SESSION_NAME;
    setcookie($name, '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => true,
        'samesite' => $params['samesite'],
    ]);
}

/**
 * Canonical authenticated identity keys (does not regenerate session id).
 *
 * @param array{id?:int|string,user_id?:int|string,first_name?:string,last_name?:string,email?:string,role?:string,profile_picture?:?string} $user
 */
function medconnect_session_set_identity(array $user): void
{
    $uid = (int) ($user['id'] ?? $user['user_id'] ?? 0);
    $role = (string) ($user['role'] ?? '');
    $first = (string) ($user['first_name'] ?? '');
    $last = (string) ($user['last_name'] ?? '');

    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $uid;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_email'] = (string) ($user['email'] ?? '');
    $_SESSION['user_name'] = trim($first . ' ' . $last);
    $_SESSION['first_name'] = $first;
    $_SESSION['last_name'] = $last;
    if (array_key_exists('profile_picture', $user)) {
        $_SESSION['profile_picture'] = !empty($user['profile_picture']) ? (string) $user['profile_picture'] : null;
    }
    $_SESSION['last_activity'] = time();
    if (empty($_SESSION['login_time'])) {
        $_SESSION['login_time'] = time();
    }

    // Role-scoped aliases (same as user_id in MedConnect; never trust client-supplied IDs).
    unset(
        $_SESSION['patient_id'],
        $_SESSION['doctor_id'],
        $_SESSION['provider_id'],
        $_SESSION['bhw_id'],
        $_SESSION['admin_id'],
        $_SESSION['superadmin_id']
    );
    switch ($role) {
        case 'patient':
            $_SESSION['patient_id'] = $uid;
            break;
        case 'provider':
            $_SESSION['doctor_id'] = $uid;
            $_SESSION['provider_id'] = $uid;
            break;
        case 'bhw':
            $_SESSION['bhw_id'] = $uid;
            break;
        case 'admin':
            $_SESSION['admin_id'] = $uid;
            break;
        case 'superadmin':
            $_SESSION['superadmin_id'] = $uid;
            break;
    }
}
