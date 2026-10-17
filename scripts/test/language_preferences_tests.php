<?php
/**
 * Account language preference: validation, session, and per-user storage.
 * Uses SQLite for row separation. Does not connect to MySQL.
 *
 * CLI: php scripts/test/language_preferences_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once $root . '/app/includes/language_preferences.php';
require_once $root . '/app/includes/system_preferences.php';

$pass = 0;
$fail = 0;

function lang_assert(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$label}\n";
}

function lang_source(string $relative): string
{
    global $root;
    $text = file_get_contents($root . '/' . $relative);
    lang_assert(is_string($text) && $text !== '', 'readable ' . $relative);

    return is_string($text) ? $text : '';
}

foreach (['en', 'fil', 'hil'] as $code) {
    lang_assert(language_preferences_validate($code), $code . ' is accepted');
    lang_assert(language_preferences_normalize($code) === $code, $code . ' is stored as itself');
    lang_assert(language_preferences_normalize(strtoupper($code)) === $code, $code . ' is normalized from uppercase');
}

foreach (['', '   ', 'es', 'tagalog', 'english', 'tl', 'fr'] as $bad) {
    lang_assert(!language_preferences_validate($bad), 'rejected language ' . ($bad === '' ? '(empty)' : $bad));
    lang_assert(language_preferences_normalize($bad) === 'en', 'fallback to English for ' . ($bad === '' ? '(empty)' : $bad));
}

foreach (['patient', 'provider', 'bhw', 'admin', 'superadmin'] as $role) {
    $_SESSION = ['user_role' => $role];
    language_preferences_apply_to_session('hil');
    lang_assert(($_SESSION['ui_language'] ?? '') === 'hil', $role . ' session stores hil');
    if ($role === 'provider') {
        lang_assert(($_SESSION['provider_language'] ?? '') === 'hil', 'provider session keeps the shared language');
    } else {
        lang_assert(!array_key_exists('provider_language', $_SESSION), $role . ' session does not keep a provider language');
    }
    language_preferences_apply_to_session('not-a-language');
    lang_assert(($_SESSION['ui_language'] ?? '') === 'en', $role . ' invalid session value falls back to English');
}

$_SESSION = ['user_role' => 'provider', 'user_theme' => 'dark', 'provider_theme' => 'dark'];
language_preferences_apply_to_session('fil');
lang_assert(($_SESSION['user_theme'] ?? '') === 'dark' && ($_SESSION['provider_theme'] ?? '') === 'dark', 'applying language leaves the theme session values');

$sqlite = null;
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $sqlite = new PDO('sqlite::memory:');
    $sqlite->exec('
        CREATE TABLE user_preferences (
            user_id INTEGER NOT NULL,
            user_type TEXT NOT NULL,
            theme_preference TEXT NOT NULL,
            language TEXT NOT NULL
        )
    ');
    $insert = $sqlite->prepare('INSERT INTO user_preferences (user_id, user_type, theme_preference, language) VALUES (?, ?, ?, ?)');
    $insert->execute([1, 'patient', 'dark', 'en']);
    $insert->execute([1, 'provider', 'light', 'fil']);
    $insert->execute([2, 'patient', 'system', 'en']);
    $insert->execute([2, 'admin', 'dark', 'hil']);

    language_preferences_store($sqlite, 1, 'patient', 'hil');
    $patient = language_preferences_row($sqlite, 1, 'patient');
    $sameUserProvider = language_preferences_row($sqlite, 1, 'provider');
    $otherPatient = language_preferences_row($sqlite, 2, 'patient');
    $admin = language_preferences_row($sqlite, 2, 'admin');

    lang_assert($patient !== null && $patient['language'] === 'hil' && $patient['theme_preference'] === 'dark', 'saving language keeps that user theme');
    lang_assert($sameUserProvider !== null && $sameUserProvider['language'] === 'fil' && $sameUserProvider['theme_preference'] === 'light', 'a provider row for the same user id stays separate');
    lang_assert($otherPatient !== null && $otherPatient['language'] === 'en' && $otherPatient['theme_preference'] === 'system', 'another patient row is not changed');
    lang_assert($admin !== null && $admin['language'] === 'hil' && $admin['theme_preference'] === 'dark', 'an admin row is not changed by a patient save');
    lang_assert(language_preferences_normalize((string) ($patient['language'] ?? '')) === 'hil', 'a stored hil value is read back');
    lang_assert(language_preferences_normalize('nope') === 'en', 'an invalid stored value would display as English');
} else {
    lang_assert(false, 'sqlite is available for preference separation');
}

$invalid = language_preferences_save($sqlite instanceof PDO ? $sqlite : new PDO('sqlite::memory:'), 1, 'patient', 'spanish');
lang_assert($invalid['success'] === false && $invalid['message'] === 'Invalid language selection.', 'save rejects an invalid language before writing');
if ($sqlite instanceof PDO) {
    $afterReject = language_preferences_row($sqlite, 1, 'patient');
    lang_assert($afterReject !== null && $afterReject['language'] === 'hil' && $afterReject['theme_preference'] === 'dark', 'a rejected language does not change the saved row or theme');
}

$accepted = system_preferences_validate_input([
    'theme_preference' => 'dark',
    'language' => 'hil',
    'time_format' => '24h',
    'date_format' => 'Y-m-d',
    'auto_logout_duration' => 30,
]);
lang_assert(
    $accepted['success'] === true
    && ($accepted['data']['language'] ?? '') === 'hil'
    && ($accepted['data']['theme_preference'] ?? '') === 'dark',
    'provider preferences accept Hiligaynon without changing the submitted theme'
);
$rejected = system_preferences_validate_input([
    'theme_preference' => 'light',
    'language' => 'tagalog',
    'time_format' => '12h',
    'date_format' => 'M j, Y',
    'auto_logout_duration' => 15,
]);
lang_assert($rejected['success'] === false, 'provider preferences reject a language outside en, fil, and hil');

$login = lang_source('app/api/login.php');
$providerApply = strpos($login, 'system_preferences_apply_to_session($provider_prefs)');
$sharedSync = strpos($login, 'language_preferences_sync_session($pdo, (int) $user[\'id\'], (string) $user[\'role\']);');
lang_assert($providerApply !== false && $sharedSync !== false && $sharedSync > $providerApply, 'login loads the shared language after provider system preferences');

$syncFn = lang_source('app/includes/language_preferences.php');
lang_assert(str_contains($syncFn, 'UPDATE user_preferences') && str_contains($syncFn, 'WHERE user_id = ? AND user_type = ?'), 'language updates are limited to one user and role');
lang_assert(str_contains($syncFn, 'UPDATE provider_system_preferences SET language = ?'), 'provider sync writes only the language column');
lang_assert(!str_contains($syncFn, 'SET theme_preference'), 'language save does not update the theme column');
$themeFnStart = strpos(lang_source('app/includes/theme_preferences.php'), 'function theme_preferences_save');
$themeFn = $themeFnStart !== false ? substr(lang_source('app/includes/theme_preferences.php'), $themeFnStart, 900) : '';
lang_assert(str_contains($themeFn, 'SET theme_preference = ?') && !str_contains($themeFn, 'language = ?'), 'theme save still updates only the theme');
lang_assert(str_contains(lang_source('app/includes/theme_preferences.php'), 'ADD COLUMN language VARCHAR(10) NOT NULL DEFAULT \'en\''), 'existing preference tables gain a language column');

$providerLoad = lang_source('app/includes/provider_settings.php');
lang_assert(str_contains($providerLoad, "language_preferences_get(\$pdo, \$userId, 'provider')"), 'provider settings read the shared language');

foreach ([
    'resources/views/patient/partials/view_settings.php' => 'ptsLanguage',
    'resources/views/provider/settings.php' => 'value="hil"',
    'resources/views/bhw/settings/profile.php' => 'bhw_language',
    'resources/views/admin/profile.php' => 'adminLanguage',
] as $file => $needle) {
    lang_assert(str_contains(lang_source($file), $needle), 'language selector is present in ' . $file);
}
$adminProfile = lang_source('resources/views/admin/profile.php');
lang_assert(str_contains($adminProfile, 'English') && str_contains($adminProfile, 'Filipino') && str_contains($adminProfile, 'Hiligaynon'), 'admin and super admin share the three language choices');
lang_assert(str_contains(lang_source('resources/views/superadmin/profile.php'), "_module.php"), 'super admin settings still reuse the admin profile');

foreach ([
    'resources/views/provider/partials/layout_open.php',
    'resources/views/bhw/partials/layout_open.php',
    'resources/views/admin/partials/layout_open.php',
    'resources/views/superadmin/partials/layout_open.php',
    'resources/views/patient/settings.php',
] as $layout) {
    lang_assert(str_contains(lang_source($layout), 'language_preferences_html_lang()'), $layout . ' sets the document language');
}

$reports = lang_source('app/includes/bhw_reports.php');
lang_assert(!str_contains($reports, 'language_preferences'), 'BHW report filters do not use the interface language');
$api = lang_source('app/api/language/save.php');
lang_assert(str_contains($api, 'theme_preferences_require_auth()') && str_contains($api, 'theme_preferences_verify_csrf()'), 'language save requires an authenticated role and CSRF');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
