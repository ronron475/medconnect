<?php
/**
 * Provider system preferences — schema, defaults, normalization.
 */

function system_preferences_defaults(): array
{
    return [
        'theme_preference' => 'system',
        'language' => 'en',
        'time_format' => '12h',
        'date_format' => 'M j, Y',
        'auto_logout_duration' => 30,
    ];
}

function system_preferences_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_system_preferences (
            preference_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_id INT UNSIGNED NOT NULL,
            theme_preference ENUM('system','light','dark') NOT NULL DEFAULT 'system',
            language VARCHAR(10) NOT NULL DEFAULT 'en',
            time_format ENUM('12h','24h') NOT NULL DEFAULT '12h',
            date_format VARCHAR(20) NOT NULL DEFAULT 'M j, Y',
            auto_logout_duration INT UNSIGNED NOT NULL DEFAULT 30,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (preference_id),
            UNIQUE KEY uq_provider_system_prefs (provider_id),
            CONSTRAINT fk_psp_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $cols = $pdo->query('SHOW COLUMNS FROM provider_system_preferences')->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('user_id', $cols, true) && !in_array('provider_id', $cols, true)) {
        system_preferences_migrate_legacy_table($pdo);
        return;
    }

    if (!in_array('preference_id', $cols, true) && in_array('provider_id', $cols, true)) {
        $pdo->exec('ALTER TABLE provider_system_preferences ADD COLUMN preference_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
    }
}

function system_preferences_migrate_legacy_table(PDO $pdo): void
{
    $pdo->exec('RENAME TABLE provider_system_preferences TO provider_system_preferences_legacy');

    $pdo->exec("
        CREATE TABLE provider_system_preferences (
            preference_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_id INT UNSIGNED NOT NULL,
            theme_preference ENUM('system','light','dark') NOT NULL DEFAULT 'system',
            language VARCHAR(10) NOT NULL DEFAULT 'en',
            time_format ENUM('12h','24h') NOT NULL DEFAULT '12h',
            date_format VARCHAR(20) NOT NULL DEFAULT 'M j, Y',
            auto_logout_duration INT UNSIGNED NOT NULL DEFAULT 30,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (preference_id),
            UNIQUE KEY uq_provider_system_prefs (provider_id),
            CONSTRAINT fk_psp_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $legacyCols = $pdo->query('SHOW COLUMNS FROM provider_system_preferences_legacy')->fetchAll(PDO::FETCH_COLUMN);
    if (!$legacyCols) {
        $pdo->exec('DROP TABLE IF EXISTS provider_system_preferences_legacy');
        return;
    }

    $hasThemePref = in_array('theme_preference', $legacyCols, true);
    $themeCol = in_array('theme', $legacyCols, true) ? 'theme' : ($hasThemePref ? 'theme_preference' : null);
    $logoutCol = in_array('auto_logout_duration', $legacyCols, true)
        ? 'auto_logout_duration'
        : (in_array('auto_logout_minutes', $legacyCols, true) ? 'auto_logout_minutes' : null);
    $providerCol = in_array('provider_id', $legacyCols, true) ? 'provider_id' : 'user_id';

    $themeSelect = $themeCol
        ? "COALESCE(NULLIF({$themeCol}, ''), 'system')"
        : "'system'";
    $logoutSelect = $logoutCol ? "COALESCE({$logoutCol}, 30)" : '30';

    $pdo->exec("
        INSERT INTO provider_system_preferences
            (provider_id, theme_preference, language, time_format, date_format, auto_logout_duration, created_at, updated_at)
        SELECT
            {$providerCol},
            {$themeSelect},
            COALESCE(language, 'en'),
            COALESCE(time_format, '12h'),
            COALESCE(date_format, 'M j, Y'),
            {$logoutSelect},
            COALESCE(created_at, NOW()),
            COALESCE(updated_at, NOW())
        FROM provider_system_preferences_legacy
    ");

    $pdo->exec('DROP TABLE provider_system_preferences_legacy');
}

function system_preferences_ensure_defaults(PDO $pdo, int $providerId): void
{
    system_preferences_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT preference_id FROM provider_system_preferences WHERE provider_id = ? LIMIT 1');
    $stmt->execute([$providerId]);
    if ($stmt->fetch()) {
        return;
    }

    $defaults = system_preferences_defaults();
    $pdo->prepare('
        INSERT INTO provider_system_preferences
            (provider_id, theme_preference, language, time_format, date_format, auto_logout_duration)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        $providerId,
        $defaults['theme_preference'],
        $defaults['language'],
        $defaults['time_format'],
        $defaults['date_format'],
        $defaults['auto_logout_duration'],
    ]);
}

/**
 * Normalize DB row to frontend/session shape.
 *
 * @return array<string, mixed>
 */
function system_preferences_normalize(?array $row): array
{
    $defaults = system_preferences_defaults();
    if (!$row) {
        return [
            'preference_id' => null,
            'theme' => $defaults['theme_preference'],
            'theme_preference' => $defaults['theme_preference'],
            'language' => $defaults['language'],
            'time_format' => $defaults['time_format'],
            'date_format' => $defaults['date_format'],
            'auto_logout_minutes' => $defaults['auto_logout_duration'],
            'auto_logout_duration' => $defaults['auto_logout_duration'],
        ];
    }

    $theme = $row['theme_preference'] ?? $row['theme'] ?? $defaults['theme_preference'];
    $logout = (int) ($row['auto_logout_duration'] ?? $row['auto_logout_minutes'] ?? $defaults['auto_logout_duration']);

    return [
        'preference_id' => isset($row['preference_id']) ? (int) $row['preference_id'] : null,
        'theme' => $theme,
        'theme_preference' => $theme,
        'language' => (string) ($row['language'] ?? $defaults['language']),
        'time_format' => (string) ($row['time_format'] ?? $defaults['time_format']),
        'date_format' => (string) ($row['date_format'] ?? $defaults['date_format']),
        'auto_logout_minutes' => $logout,
        'auto_logout_duration' => $logout,
    ];
}

/**
 * @return array<string, mixed>
 */
function system_preferences_get(PDO $pdo, int $providerId): array
{
    system_preferences_ensure_defaults($pdo, $providerId);

    $stmt = $pdo->prepare('SELECT * FROM provider_system_preferences WHERE provider_id = ? LIMIT 1');
    $stmt->execute([$providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return system_preferences_normalize($row ?: null);
}

function system_preferences_apply_to_session(array $prefs): void
{
    $theme = $prefs['theme_preference'] ?? $prefs['theme'] ?? 'system';
    $_SESSION['provider_theme'] = $theme;
    $_SESSION['user_theme'] = $theme;
    $_SESSION['provider_language'] = $prefs['language'] ?? 'en';
    $_SESSION['provider_time_format'] = $prefs['time_format'] ?? '12h';
    $_SESSION['provider_date_format'] = $prefs['date_format'] ?? 'M j, Y';
    $_SESSION['provider_auto_logout'] = (int) ($prefs['auto_logout_duration'] ?? $prefs['auto_logout_minutes'] ?? 30);
    $_SESSION['provider_last_activity'] = time();
}

/**
 * @return array{success:bool,message:string,data?:array}
 */
function system_preferences_validate_input(array $input): array
{
    $theme = (string) ($input['theme_preference'] ?? $input['theme'] ?? 'system');
    if (!in_array($theme, ['system', 'light', 'dark'], true)) {
        return ['success' => false, 'message' => 'Invalid theme preference.'];
    }

    $language = (string) ($input['language'] ?? 'en');
    if (!in_array($language, ['en', 'fil'], true)) {
        return ['success' => false, 'message' => 'Invalid language selection.'];
    }

    $timeFormat = (string) ($input['time_format'] ?? '12h');
    if (!in_array($timeFormat, ['12h', '24h'], true)) {
        return ['success' => false, 'message' => 'Invalid time format.'];
    }

    $dateFormat = (string) ($input['date_format'] ?? 'M j, Y');
    $allowedDates = ['M j, Y', 'j M Y', 'Y-m-d'];
    if (!in_array($dateFormat, $allowedDates, true)) {
        return ['success' => false, 'message' => 'Invalid date format.'];
    }

    $logout = (int) ($input['auto_logout_duration'] ?? $input['auto_logout_minutes'] ?? 30);
    if (!in_array($logout, [15, 30, 60, 120], true)) {
        return ['success' => false, 'message' => 'Invalid auto logout duration.'];
    }

    return [
        'success' => true,
        'message' => 'ok',
        'data' => [
            'theme_preference' => $theme,
            'language' => $language,
            'time_format' => $timeFormat,
            'date_format' => $dateFormat,
            'auto_logout_duration' => $logout,
        ],
    ];
}

/**
 * @return array{success:bool,message:string,data?:array}
 */
function system_preferences_save(PDO $pdo, int $providerId, array $input): array
{
    $validated = system_preferences_validate_input($input);
    if (!$validated['success']) {
        return $validated;
    }

    system_preferences_ensure_defaults($pdo, $providerId);
    $data = $validated['data'];

    $stmt = $pdo->prepare('
        UPDATE provider_system_preferences
        SET theme_preference = ?, language = ?, time_format = ?, date_format = ?, auto_logout_duration = ?
        WHERE provider_id = ?
    ');
    $stmt->execute([
        $data['theme_preference'],
        $data['language'],
        $data['time_format'],
        $data['date_format'],
        $data['auto_logout_duration'],
        $providerId,
    ]);

    $prefs = system_preferences_get($pdo, $providerId);
    system_preferences_apply_to_session($prefs);

    require_once __DIR__ . '/theme_preferences.php';
    theme_preferences_save($pdo, $providerId, 'provider', $data['theme_preference']);

    return [
        'success' => true,
        'message' => 'Preferences updated successfully.',
        'data' => ['system' => $prefs],
    ];
}
