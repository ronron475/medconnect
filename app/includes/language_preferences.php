<?php
/**
 * Account language preference — patients, providers, admins, and BHW.
 * Stored on user_preferences. Interface translation is intentionally out of scope.
 */

require_once __DIR__ . '/theme_preferences.php';

const LANGUAGE_PREFERENCE_VALUES = ['en', 'fil', 'hil'];

function language_preferences_normalize(string $language): string
{
    $language = strtolower(trim($language));
    return in_array($language, LANGUAGE_PREFERENCE_VALUES, true) ? $language : 'en';
}

function language_preferences_validate(string $language): bool
{
    return in_array(strtolower(trim($language)), LANGUAGE_PREFERENCE_VALUES, true);
}

function language_preferences_html_lang(): string
{
    return language_preferences_normalize((string) ($_SESSION['ui_language'] ?? 'en'));
}

/**
 * @return array{language:string,theme_preference:string}|null
 */
function language_preferences_row(PDO $pdo, int $userId, string $userType): ?array
{
    $stmt = $pdo->prepare('
        SELECT language, theme_preference
        FROM user_preferences
        WHERE user_id = ? AND user_type = ?
        LIMIT 1
    ');
    $stmt->execute([$userId, $userType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'language' => (string) ($row['language'] ?? ''),
        'theme_preference' => (string) ($row['theme_preference'] ?? ''),
    ];
}

function language_preferences_store(PDO $pdo, int $userId, string $userType, string $language): void
{
    $stmt = $pdo->prepare('
        UPDATE user_preferences
        SET language = ?
        WHERE user_id = ? AND user_type = ?
    ');
    $stmt->execute([$language, $userId, $userType]);
}

function language_preferences_get(PDO $pdo, int $userId, string $userType): string
{
    theme_preferences_ensure_defaults($pdo, $userId, $userType);
    $row = language_preferences_row($pdo, $userId, $userType);

    return language_preferences_normalize((string) ($row['language'] ?? ''));
}

function language_preferences_apply_to_session(string $language): void
{
    $language = language_preferences_normalize($language);
    $_SESSION['ui_language'] = $language;
    if (($_SESSION['user_role'] ?? '') === 'provider') {
        $_SESSION['provider_language'] = $language;
        return;
    }
    unset($_SESSION['provider_language']);
}

function language_preferences_sync_session(PDO $pdo, int $userId, string $role): void
{
    $userType = theme_preferences_normalize_role($role);
    if (!$userType) {
        $_SESSION['ui_language'] = 'en';
        return;
    }
    language_preferences_apply_to_session(language_preferences_get($pdo, $userId, $userType));
}

function language_preferences_sync_provider_table(PDO $pdo, int $providerId, string $language): void
{
    if (!language_preferences_validate($language)) {
        return;
    }
    $language = strtolower(trim($language));
    try {
        if ($pdo->query("SHOW TABLES LIKE 'provider_system_preferences'")->rowCount() === 0) {
            return;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM provider_system_preferences')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('language', $cols, true)) {
            return;
        }
        $idCol = in_array('provider_id', $cols, true) ? 'provider_id' : (in_array('user_id', $cols, true) ? 'user_id' : '');
        if ($idCol === '') {
            return;
        }
        $pdo->prepare("UPDATE provider_system_preferences SET language = ? WHERE {$idCol} = ?")
            ->execute([$language, $providerId]);
    } catch (PDOException $e) {
        /* non-fatal */
    }
}

/**
 * Copy an existing provider language once, when that provider's preference row is first created.
 */
function language_preferences_adopt_from_provider(PDO $pdo, int $providerId): void
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'provider_system_preferences'")->rowCount() === 0) {
            return;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM provider_system_preferences')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('language', $cols, true)) {
            return;
        }
        $idCol = in_array('provider_id', $cols, true) ? 'provider_id' : (in_array('user_id', $cols, true) ? 'user_id' : '');
        if ($idCol === '') {
            return;
        }
        $stmt = $pdo->prepare("SELECT language FROM provider_system_preferences WHERE {$idCol} = ? LIMIT 1");
        $stmt->execute([$providerId]);
        $stored = $stmt->fetchColumn();
        if (!is_string($stored) || !language_preferences_validate($stored)) {
            return;
        }
        language_preferences_store($pdo, $providerId, 'provider', strtolower(trim($stored)));
    } catch (PDOException $e) {
        /* non-fatal */
    }
}

/**
 * One-time copy used only when the language column is added to an existing table.
 */
function language_preferences_backfill_from_provider(PDO $pdo): void
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'provider_system_preferences'")->rowCount() === 0) {
            return;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM provider_system_preferences')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('language', $cols, true)) {
            return;
        }
        $idCol = in_array('provider_id', $cols, true) ? 'provider_id' : (in_array('user_id', $cols, true) ? 'user_id' : '');
        if ($idCol === '') {
            return;
        }
        $pdo->exec("
            UPDATE user_preferences up
            INNER JOIN provider_system_preferences psp ON psp.{$idCol} = up.user_id
            SET up.language = psp.language
            WHERE up.user_type = 'provider'
              AND psp.language IN ('en', 'fil', 'hil')
        ");
    } catch (PDOException $e) {
        /* non-fatal */
    }
}

/**
 * @return array{status:string,success:bool,message:string,data?:array}
 */
function language_preferences_save(PDO $pdo, int $userId, string $userType, string $language): array
{
    $language = strtolower(trim($language));
    if (!language_preferences_validate($language)) {
        return [
            'status' => 'error',
            'success' => false,
            'message' => 'Invalid language selection.',
        ];
    }

    $userType = theme_preferences_normalize_role($userType) ?? '';
    if (!in_array($userType, ['patient', 'provider', 'admin', 'bhw'], true)) {
        return [
            'status' => 'error',
            'success' => false,
            'message' => 'Invalid account type.',
        ];
    }

    theme_preferences_ensure_defaults($pdo, $userId, $userType);
    language_preferences_store($pdo, $userId, $userType, $language);

    if ($userType === 'provider') {
        language_preferences_sync_provider_table($pdo, $userId, $language);
    }

    language_preferences_apply_to_session($language);

    return [
        'status' => 'success',
        'success' => true,
        'message' => 'Language saved.',
        'data' => [
            'language' => $language,
        ],
    ];
}
