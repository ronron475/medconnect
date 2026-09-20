<?php
/**
 * Load key=value pairs from project .env into getenv/$_ENV (does not override existing env).
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
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
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

    medconnect_load_env_file(dirname(__DIR__) . '/.env');

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
