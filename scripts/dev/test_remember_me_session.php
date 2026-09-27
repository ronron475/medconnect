<?php
/**
 * Session idle timeout vs persistent remember-me tokens.
 * Run: php scripts/dev/test_remember_me_session.php
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

require_once $root . '/app/includes/session_timeout.php';
require_once $root . '/app/includes/session_cookie.php';

ob_start();

$_SESSION = [
    'user_id' => 7,
    'user_role' => 'patient',
    'remember_me_extended' => true,
    'last_activity' => time() - (31 * 60),
];
if (session_timeout_minutes_for_current_user() === SESSION_TIMEOUT_DEFAULT_MINUTES
    && session_timeout_is_idle_expired()
) {
    pass('remember-me flag does not extend idle timeout past 30 minutes');
} else {
    fail('remember-me flag does not extend idle timeout past 30 minutes', (string) session_timeout_minutes_for_current_user());
}

$_SESSION['last_activity'] = time() - (29 * 60);
if (!session_timeout_is_idle_expired()) {
    pass('activity inside 30 minutes keeps the session');
} else {
    fail('activity inside 30 minutes keeps the session');
}

$_SESSION['user_role'] = 'provider';
$_SESSION['provider_auto_logout'] = 15;
$_SESSION['last_activity'] = time() - (16 * 60);
$_SESSION['remember_me_extended'] = true;
if (session_timeout_minutes_for_current_user() === 15 && session_timeout_is_idle_expired()) {
    pass('provider idle preference still applies with remember me');
} else {
    fail('provider idle preference still applies with remember me');
}

$sessionCookie = medconnect_session_cookie_params();
$rememberCookie = remember_me_cookie_params();
$rememberLifetime = (int) $rememberCookie['expires'] - time();
if (($sessionCookie['lifetime'] ?? -1) === 0
    && ($rememberCookie['httponly'] ?? false) === true
    && ($rememberCookie['samesite'] ?? '') === 'Strict'
    && $rememberLifetime > (29 * 86400)
    && $rememberLifetime < (31 * 86400)
) {
    pass('session cookie stays a browser session and remember cookie is persistent');
} else {
    fail('session cookie stays a browser session and remember cookie is persistent');
}

$timeoutSrc = (string) file_get_contents($root . '/app/includes/session_timeout.php');
$expireSrc = (string) file_get_contents($root . '/app/api/provider/session/expire.php');
$idleJs = (string) file_get_contents($root . '/public/assets/js/provider-idle.js');
$layout = (string) file_get_contents($root . '/resources/views/provider/partials/layout_open.php');
if (!str_contains($timeoutSrc, 'REMEMBER_ME_DAYS')
    && !str_contains($timeoutSrc, 'remember_me_revoke')
    && str_contains($timeoutSrc, 'remember_me_mark_idle_hold')
    && !str_contains($expireSrc, 'remember_me_revoke')
    && str_contains($expireSrc, 'remember_me_mark_idle_hold')
    && !str_contains($idleJs, 'data-remember-extended')
    && !str_contains($layout, "remember_me_extended")
) {
    pass('idle expiry does not revoke the persistent token or skip the idle timer');
} else {
    fail('idle expiry does not revoke the persistent token or skip the idle timer');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTPS'] = 'off';

remember_me_issue_token($pdo, 42);
$issued = (string) ($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
$parsed = remember_me_parse_cookie($issued);
$row = $pdo->query('SELECT validator_hash FROM remember_tokens')->fetch(PDO::FETCH_ASSOC);
if ($parsed !== null
    && is_array($row)
    && $row['validator_hash'] !== $parsed[1]
    && password_verify($parsed[1], (string) $row['validator_hash'])
    && !str_contains((string) $row['validator_hash'], $parsed[1])
) {
    pass('remember-me stores a hash, not the cookie validator');
} else {
    fail('remember-me stores a hash, not the cookie validator');
}

$rotated = remember_me_authenticate_cookie($pdo, $issued);
$grace = remember_me_authenticate_cookie($pdo, $issued);
$stillThere = (int) $pdo->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn();
if (!empty($rotated['ok'])
    && ($rotated['cookie'] ?? '') !== $issued
    && !empty($grace['ok'])
    && !empty($grace['grace'])
    && $stillThere === 1
) {
    pass('a just-rotated cookie is accepted once inside the grace window');
} else {
    fail('a just-rotated cookie is accepted once inside the grace window');
}

$pdo->exec("UPDATE remember_tokens SET previous_valid_until = '2000-01-01 00:00:00'");
$replay = remember_me_authenticate_cookie($pdo, $issued);
$afterReplay = (int) $pdo->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn();
if (empty($replay['ok']) && ($replay['reason'] ?? '') === 'replay' && $afterReplay === 0) {
    pass('replaying a rotated validator revokes that token');
} else {
    fail('replaying a rotated validator revokes that token', (string) ($replay['reason'] ?? ''));
}

remember_me_issue_token($pdo, 42);
$live = (string) ($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
$selector = remember_me_parse_cookie($live)[0] ?? '';
$pdo->prepare('UPDATE remember_tokens SET expires_at = ? WHERE selector = ?')
    ->execute(['2000-01-01 00:00:00', $selector]);
$expired = remember_me_authenticate_cookie($pdo, $live);
$afterExpiry = (int) $pdo->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn();
if (empty($expired['ok']) && ($expired['reason'] ?? '') === 'expired' && $afterExpiry === 0) {
    pass('an expired remember-me token is rejected and deleted');
} else {
    fail('an expired remember-me token is rejected and deleted', (string) ($expired['reason'] ?? ''));
}

remember_me_issue_token($pdo, 42);
$beforeLogout = (int) $pdo->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn();
remember_me_revoke_current_cookie($pdo);
$afterLogout = (int) $pdo->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn();
if ($beforeLogout === 1 && $afterLogout === 0 && !isset($_COOKIE[REMEMBER_ME_COOKIE])) {
    pass('logout revokes the current remember-me token');
} else {
    fail('logout revokes the current remember-me token');
}

$_SESSION = [];
$_COOKIE[REMEMBER_ME_IDLE_HOLD] = '1';
$_COOKIE[REMEMBER_ME_COOKIE] = 'not-used:not-used';
remember_me_restore_session($pdo);
if (empty($_SESSION['user_id']) && isset($_COOKIE[REMEMBER_ME_COOKIE])) {
    pass('idle hold blocks remember-me from restoring a session');
} else {
    fail('idle hold blocks remember-me from restoring a session');
}

unset($_COOKIE[REMEMBER_ME_IDLE_HOLD]);
remember_me_issue_token($pdo, 42);
$kept = (string) ($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
$_SESSION = [];
remember_me_mark_idle_hold();
$heldCount = (int) $pdo->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn();
if (remember_me_idle_hold_active() && $heldCount === 1 && ($_COOKIE[REMEMBER_ME_COOKIE] ?? '') === $kept) {
    pass('idle hold keeps the persistent token');
} else {
    fail('idle hold keeps the persistent token');
}

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
ob_end_flush();
exit($failures === 0 ? 0 : 1);
