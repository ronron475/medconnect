<?php
/**
 * Load key=value pairs from .env into getenv/$_ENV (does not override existing env).
 *
 * Preferred Hostinger layout (secrets outside the web root):
 *   /home/USER/domains/example.com/public_html/   ← app
 *   /home/USER/domains/example.com/.env           ← secrets (parent of public_html)
 * Or set MEDCONNECT_ENV_FILE to an absolute path outside public_html.
 */
if (!function_exists('medconnect_load_env_file')) {
    function medconnect_load_env_file(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim(ltrim($line, "\xEF\xBB\xBF"));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '') {
                continue;
            }
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            if (getenv($name) === false && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
    }

    /**
     * Ordered candidate .env paths (first readable wins for initial load).
     * MEDCONNECT_ENV_FILE may be set by the process environment / Hostinger panel.
     *
     * @return list<string>
     */
    function medconnect_env_candidate_paths(): array
    {
        $paths = [];
        $custom = getenv('MEDCONNECT_ENV_FILE');
        if ($custom === false || trim((string) $custom) === '') {
            $custom = $_ENV['MEDCONNECT_ENV_FILE'] ?? ($_SERVER['MEDCONNECT_ENV_FILE'] ?? '');
        }
        $custom = trim((string) $custom);
        if ($custom !== '') {
            $paths[] = $custom;
        }

        $root = dirname(__DIR__);
        $paths[] = $root . DIRECTORY_SEPARATOR . '.env';
        $paths[] = dirname($root) . DIRECTORY_SEPARATOR . '.env';
        // Common Hostinger private folder next to (or above) public_html.
        $paths[] = dirname($root) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'medconnect.env';
        $paths[] = dirname($root, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'medconnect.env';

        $unique = [];
        foreach ($paths as $path) {
            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            if ($normalized === '' || isset($unique[$normalized])) {
                continue;
            }
            $unique[$normalized] = true;
        }

        return array_keys($unique);
    }

    // Load every readable candidate so a private secrets file and an optional
    // project .env can coexist. Existing keys are never overridden.
    foreach (medconnect_env_candidate_paths() as $envPath) {
        medconnect_load_env_file($envPath);
    }
    // If a loaded file defined MEDCONNECT_ENV_FILE, load that path too.
    $customAfter = trim((string) (getenv('MEDCONNECT_ENV_FILE') ?: ($_ENV['MEDCONNECT_ENV_FILE'] ?? '')));
    if ($customAfter !== '') {
        medconnect_load_env_file($customAfter);
    }

    // Admin AI provider toggles/models (non-secret). Loaded after .env so UI saves take effect.
    $aiRuntime = dirname(__DIR__) . '/storage/ai_provider_runtime.env';
    if (is_readable($aiRuntime)) {
        $aiAllow = [
            'MEDCONNECT_AI_SERVICE_ENABLED' => true,
            'MEDCONNECT_AI_INTERPRETER' => true,
            'AI_ENABLED' => true,
            'AI_PROVIDER' => true,
            'AI_MODEL' => true,
            'MEDCONNECT_GROQ_MODEL' => true,
            'GROQ_MODEL' => true,
        ];
        $aiLines = file($aiRuntime, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($aiLines)) {
            foreach ($aiLines as $aiLine) {
                $aiLine = trim($aiLine);
                if ($aiLine === '' || str_starts_with($aiLine, '#') || !str_contains($aiLine, '=')) {
                    continue;
                }
                [$aiName, $aiValue] = explode('=', $aiLine, 2);
                $aiName = trim($aiName);
                $aiValue = trim($aiValue);
                if ($aiName === '' || empty($aiAllow[$aiName])) {
                    continue;
                }
                if (
                    (str_starts_with($aiValue, '"') && str_ends_with($aiValue, '"'))
                    || (str_starts_with($aiValue, "'") && str_ends_with($aiValue, "'"))
                ) {
                    $aiValue = substr($aiValue, 1, -1);
                }
                putenv("{$aiName}={$aiValue}");
                $_ENV[$aiName] = $aiValue;
            }
        }
    }
}
