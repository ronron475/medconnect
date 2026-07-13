<?php
/**
 * Detect, start, and diagnose the local Python AI service (port 8765).
 */

final class AiServiceLauncher
{
    private const DEFAULT_PORT = 8765;
    private const START_COOLDOWN_SEC = 15;
    private const HEALTH_WAIT_ITERATIONS = 12;
    private const HEALTH_WAIT_SLEEP_US = 500000;
    private const LOG_FILE = 'storage/logs/ai_service.log';
    private const DIAGNOSTICS_CACHE_TTL_SEC = 10;

    /** @var array<string, mixed>|null */
    private static ?array $diagnosticsCache = null;
    private static int $diagnosticsCacheAt = 0;
    private static ?string $resolvedPython = null;
    private static bool $pythonResolved = false;
    private static ?bool $venvRapidfuzz = null;

    public static function invalidateDiagnosticsCache(): void
    {
        self::$diagnosticsCache = null;
        self::$diagnosticsCacheAt = 0;
    }

    /** @return array<string, mixed> */
    public static function diagnostics(): array
    {
        if (
            self::$diagnosticsCache !== null
            && (time() - self::$diagnosticsCacheAt) < self::DIAGNOSTICS_CACHE_TTL_SEC
        ) {
            return self::$diagnosticsCache;
        }

        $result = self::buildDiagnostics();
        self::$diagnosticsCache = $result;
        self::$diagnosticsCacheAt = time();

        return $result;
    }

    /** @return array<string, mixed> */
    private static function buildDiagnostics(): array
    {
        if (!AI_SERVICE_ENABLED) {
            return [
                'online'            => false,
                'status'            => 'disabled',
                'service'           => 'php-validation-workflow',
                'url'               => AI_SERVICE_BASE_URL,
                'port'              => self::parsePort(AI_SERVICE_BASE_URL),
                'port_open'         => false,
                'reason'            => 'Python AI service disabled (MEDCONNECT_AI_SERVICE_ENABLED=false)',
                'python_executable' => null,
                'venv_valid'          => false,
                'venv_broken'         => false,
                'dependencies_ok'     => false,
                'groq_configured'     => (bool) (MedicalAiInterpreter::providerStatus()['groq_configured'] ?? false),
                'groq'                => 'configured',
                'groq_connected'      => false,
                'groq_error'          => '',
                'model'               => GROQ_MODEL,
                'engine'              => 'php-validation-workflow',
                'health'              => null,
                'log_file'            => BASE_PATH . '/' . self::LOG_FILE,
                'start_script'        => BASE_PATH . '/ai_service/start_ai_service.bat',
                'install_script'      => BASE_PATH . '/ai_service/install_ai_dependencies.bat',
            ];
        }

        $url = AI_SERVICE_BASE_URL;
        $port = self::parsePort($url);
        $python = self::resolvePythonExecutable();
        $venvPython = BASE_PATH . '/ai_service/.venv/Scripts/python.exe';
        $venvValid = is_file($venvPython) && self::pythonExecutableWorks($venvPython);
        $portOpen = self::isPortOpen('127.0.0.1', $port);
        $health = self::fetchHealthPayload(AI_SERVICE_TIMEOUT_HEALTH);
        $online = is_array($health) && !empty($health['success']);

        $aiStatus = MedicalAiInterpreter::providerStatus();
        $groqConfigured = (bool) ($aiStatus['groq_configured'] ?? false);
        $groqLive = $online && in_array((string) ($health['groq'] ?? ''), ['connected', 'online'], true);

        $reason = self::offlineReason($portOpen, $online, $health, $python, $venvValid);

        return [
            'online'              => $online,
            'status'              => $online ? 'online' : 'offline',
            'service'             => $online
                ? (string) ($health['service'] ?? 'python-medical-profile-nlp')
                : 'php-validation-workflow',
            'url'                 => $url,
            'port'                => $port,
            'port_open'           => $portOpen,
            'reason'              => $reason,
            'python_executable'   => $python,
            'venv_python'         => $venvPython,
            'venv_valid'          => $venvValid,
            'venv_broken'         => is_file($venvPython) && !$venvValid,
            'dependencies_ok'     => $venvValid && self::venvHasRapidfuzz($python),
            'groq_configured'     => $groqConfigured,
            'groq'                => $online
                ? (string) ($health['groq'] ?? ($groqConfigured ? 'configured' : 'missing'))
                : ($groqConfigured ? 'configured' : 'missing'),
            'groq_connected'      => $groqLive,
            'groq_error'          => (string) ($health['groq_error'] ?? ''),
            'model'               => (string) ($health['model'] ?? GROQ_MODEL),
            'engine'              => $online ? 'fastapi' : 'php-validation-workflow',
            'health'              => $health,
            'log_file'            => BASE_PATH . '/' . self::LOG_FILE,
            'start_script'        => BASE_PATH . '/ai_service/start_ai_service.bat',
            'install_script'      => BASE_PATH . '/ai_service/install_ai_dependencies.bat',
        ];
    }

    /**
     * Attempt to start the Python service if offline; wait briefly for health.
     */
    public static function ensureRunning(bool $waitForHealth = true): bool
    {
        if (!AI_SERVICE_ENABLED || !AI_SERVICE_AUTO_START) {
            return false;
        }

        if (AiServiceClient::isHealthy()) {
            return true;
        }

        $lockFile = BASE_PATH . '/storage/logs/ai_service_start.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        if (is_file($lockFile)) {
            $age = time() - (int) @filemtime($lockFile);
            if ($age < self::START_COOLDOWN_SEC) {
                self::log('Start skipped — cooldown active (' . $age . 's)');
                return false;
            }
        }

        @touch($lockFile);

        self::invalidateDiagnosticsCache();

        $python = self::resolvePythonExecutable();
        if ($python === null) {
            self::log('Cannot start AI service — no working Python executable found');
            return false;
        }

        if (!self::venvHasRapidfuzz($python)) {
            self::log('Python found but rapidfuzz missing — run ai_service/install_ai_dependencies.bat');
        }

        $started = self::startBackgroundProcess($python);
        self::log($started ? 'Background start issued for ' . $python : 'Failed to issue background start');

        if (!$waitForHealth) {
            return $started;
        }

        for ($i = 0; $i < self::HEALTH_WAIT_ITERATIONS; $i++) {
            usleep(self::HEALTH_WAIT_SLEEP_US);
            if (AiServiceClient::isHealthy(1)) {
                self::log('AI service health check passed after ' . (($i + 1) * (self::HEALTH_WAIT_SLEEP_US / 1000)) . 'ms');
                return true;
            }
        }

        self::log('AI service did not become healthy within ~' . (int) ((self::HEALTH_WAIT_ITERATIONS * self::HEALTH_WAIT_SLEEP_US) / 1000000) . 's after start');
        return false;
    }

    public static function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        $path = BASE_PATH . '/' . self::LOG_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private static function parsePort(string $url): int
    {
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return self::DEFAULT_PORT;
    }

    private static function isPortOpen(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** @return array<string, mixed>|null */
    private static function fetchHealthPayload(int $timeout): ?array
    {
        $body = self::httpGet(AI_SERVICE_BASE_URL . '/health', $timeout);
        if ($body === null) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function httpGet(string $url, int $timeout): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }

        return (string) $body;
    }

  private static function offlineReason(
        bool $portOpen,
        bool $online,
        ?array $health,
        ?string $python,
        bool $venvValid
    ): string {
        if ($online) {
            return '';
        }
        if ($python === null) {
            return 'No working Python executable found';
        }
        if (is_file(BASE_PATH . '/ai_service/.venv/Scripts/python.exe') && !$venvValid) {
            return 'Virtual environment broken — run ai_service/install_ai_dependencies.bat';
        }
        if (!$portOpen) {
            return 'Connection refused — port ' . self::parsePort(AI_SERVICE_BASE_URL) . ' not reachable';
        }

        return 'Port open but health endpoint failed';
    }

    public static function resolvePythonExecutable(): ?string
    {
        if (self::$pythonResolved) {
            return self::$resolvedPython;
        }
        self::$pythonResolved = true;

        $candidates = [
            BASE_PATH . '/ai_service/.venv/Scripts/python.exe',
            'C:/Users/Lenovo/AppData/Local/Programs/Python/Python311/python.exe',
            'C:/Users/Lenovo/AppData/Local/Programs/Python/Python312/python.exe',
        ];

        foreach ($candidates as $path) {
            if (self::pythonExecutableWorks($path)) {
                self::$resolvedPython = $path;
                return self::$resolvedPython;
            }
        }

        $resolver = BASE_PATH . '/ai_service/resolve_python.bat';
        if (is_file($resolver)) {
            $out = [];
            $code = 0;
            @exec('cmd /c "' . str_replace('/', '\\', $resolver) . '" 2>nul', $out, $code);
            if ($code === 0 && isset($out[0]) && self::pythonExecutableWorks(trim($out[0]))) {
                self::$resolvedPython = trim($out[0]);
                return self::$resolvedPython;
            }
        }

        foreach (['py -3.11 -c "import sys;print(sys.executable)"', 'python -c "import sys;print(sys.executable)"'] as $cmd) {
            $out = [];
            $code = 0;
            @exec($cmd . ' 2>nul', $out, $code);
            if ($code === 0 && isset($out[0]) && self::pythonExecutableWorks(trim($out[0]))) {
                self::$resolvedPython = trim($out[0]);
                return self::$resolvedPython;
            }
        }

        self::$resolvedPython = null;
        return null;
    }

    private static function pythonExecutableWorks(string $path): bool
    {
        $path = trim(str_replace('/', '\\', $path));
        if ($path === '' || !is_file($path)) {
            return false;
        }
        $out = [];
        $code = 0;
        @exec('"' . $path . '" --version 2>nul', $out, $code);

        return $code === 0;
    }

    private static function venvHasRapidfuzz(?string $python): bool
    {
        if ($python === null) {
            return false;
        }
        if (self::$venvRapidfuzz !== null) {
            return self::$venvRapidfuzz;
        }
        $out = [];
        $code = 0;
        @exec('"' . str_replace('/', '\\', $python) . '" -c "import rapidfuzz" 2>nul', $out, $code);

        self::$venvRapidfuzz = ($code === 0);
        return self::$venvRapidfuzz;
    }

    private static function startBackgroundProcess(string $python): bool
    {
        $python = str_replace('/', '\\', $python);
        $root = str_replace('/', '\\', BASE_PATH);
        $logDir = dirname($bootLog);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $aiDir = $root . '\\ai_service';
        $bootLog = $root . '\\storage\\logs\\ai_service_boot.log';
        $cmd = 'cmd /c start "" /B /D "' . $aiDir . '" "' . $python . '" -u -m uvicorn app.main:app --host 127.0.0.1 --port 8765 >> "' . $bootLog . '" 2>&1';
        if (function_exists('popen')) {
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                pclose($handle);

                return true;
            }
        }

        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);

        return $code === 0;
    }
}
