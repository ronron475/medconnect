<?php
/**
 * Unified theme preferences — patients, providers, admins, BHW.
 */

const THEME_PREFERENCE_VALUES = ['system', 'light', 'dark'];

function theme_preferences_normalize_role(string $role): ?string
{
    $role = strtolower(trim($role));
    return match ($role) {
        'patient', 'provider', 'admin', 'bhw', 'superadmin' => $role === 'superadmin' ? 'admin' : $role,
        default => null,
    };
}

function theme_preferences_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_preferences (
            preference_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            user_type ENUM('patient','provider','admin','bhw') NOT NULL,
            theme_preference ENUM('system','light','dark') NOT NULL DEFAULT 'system',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (preference_id),
            UNIQUE KEY uq_user_prefs (user_id, user_type),
            KEY idx_user_type (user_type),
            CONSTRAINT fk_uprefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function theme_preferences_validate(string $theme): bool
{
    return in_array($theme, THEME_PREFERENCE_VALUES, true);
}

function theme_preferences_ensure_defaults(PDO $pdo, int $userId, string $userType): void
{
    theme_preferences_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT preference_id FROM user_preferences WHERE user_id = ? AND user_type = ? LIMIT 1');
    $stmt->execute([$userId, $userType]);
    if ($stmt->fetch()) {
        return;
    }

    $theme = 'system';
    if ($userType === 'provider') {
        $theme = theme_preferences_read_provider_legacy($pdo, $userId);
    }

    $pdo->prepare('
        INSERT INTO user_preferences (user_id, user_type, theme_preference)
        VALUES (?, ?, ?)
    ')->execute([$userId, $userType, $theme]);
}

function theme_preferences_read_provider_legacy(PDO $pdo, int $providerId): string
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'provider_system_preferences'")->rowCount() === 0) {
            return 'system';
        }
        $stmt = $pdo->prepare('
            SELECT theme_preference, theme
            FROM provider_system_preferences
            WHERE provider_id = ?
            LIMIT 1
        ');
        $stmt->execute([$providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 'system';
        }
        $theme = (string) ($row['theme_preference'] ?? $row['theme'] ?? 'system');
        return theme_preferences_validate($theme) ? $theme : 'system';
    } catch (PDOException $e) {
        return 'system';
    }
}

/**
 * @return array{preference_id:?int,theme_preference:string,theme:string}
 */
function theme_preferences_get(PDO $pdo, int $userId, string $userType): array
{
    theme_preferences_ensure_defaults($pdo, $userId, $userType);

    $stmt = $pdo->prepare('
        SELECT preference_id, theme_preference
        FROM user_preferences
        WHERE user_id = ? AND user_type = ?
        LIMIT 1
    ');
    $stmt->execute([$userId, $userType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $theme = theme_preferences_validate((string) ($row['theme_preference'] ?? 'system'))
        ? (string) $row['theme_preference']
        : 'system';

    return [
        'preference_id' => isset($row['preference_id']) ? (int) $row['preference_id'] : null,
        'theme_preference' => $theme,
        'theme' => $theme,
    ];
}

function theme_preferences_apply_to_session(string $theme): void
{
    if (!theme_preferences_validate($theme)) {
        $theme = 'system';
    }
    $_SESSION['user_theme'] = $theme;
    if (($_SESSION['user_role'] ?? '') === 'provider') {
        $_SESSION['provider_theme'] = $theme;
    }
}

function theme_preferences_sync_session(PDO $pdo, int $userId, string $role): void
{
    $userType = theme_preferences_normalize_role($role);
    if (!$userType) {
        return;
    }
    $prefs = theme_preferences_get($pdo, $userId, $userType);
    theme_preferences_apply_to_session($prefs['theme_preference']);
}

function theme_preferences_sync_provider_table(PDO $pdo, int $providerId, string $theme): void
{
    if (!theme_preferences_validate($theme)) {
        return;
    }
    try {
        if ($pdo->query("SHOW TABLES LIKE 'provider_system_preferences'")->rowCount() === 0) {
            return;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM provider_system_preferences')->fetchAll(PDO::FETCH_COLUMN);
        $idCol = in_array('provider_id', $cols, true) ? 'provider_id' : 'user_id';
        $themeCol = in_array('theme_preference', $cols, true) ? 'theme_preference' : 'theme';
        $pdo->prepare("UPDATE provider_system_preferences SET {$themeCol} = ? WHERE {$idCol} = ?")
            ->execute([$theme, $providerId]);
    } catch (PDOException $e) {
        /* non-fatal */
    }
}

/**
 * @return array{status:string,success:bool,message:string,data?:array}
 */
function theme_preferences_save(PDO $pdo, int $userId, string $userType, string $theme): array
{
    if (!theme_preferences_validate($theme)) {
        return [
            'status' => 'error',
            'success' => false,
            'message' => 'Invalid theme preference.',
        ];
    }

    theme_preferences_ensure_defaults($pdo, $userId, $userType);

    $stmt = $pdo->prepare('
        UPDATE user_preferences
        SET theme_preference = ?, updated_at = NOW()
        WHERE user_id = ? AND user_type = ?
    ');
    $stmt->execute([$theme, $userId, $userType]);

    if ($userType === 'provider') {
        theme_preferences_sync_provider_table($pdo, $userId, $theme);
    }

    theme_preferences_apply_to_session($theme);

    return [
        'status' => 'success',
        'success' => true,
        'message' => 'Theme updated successfully.',
        'data' => [
            'theme_preference' => $theme,
            'theme' => $theme,
        ],
    ];
}

function theme_preferences_require_auth(): array
{
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $role = (string) ($_SESSION['user_role'] ?? '');
    $userType = theme_preferences_normalize_role($role);
    if (!$userType) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Unauthorized role.']);
        exit;
    }

    return [
        'user_id' => (int) $_SESSION['user_id'],
        'user_type' => $userType,
        'role' => $role,
    ];
}

function theme_preferences_verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Invalid request token.']);
        exit;
    }
}
