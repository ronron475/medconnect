<?php
/**
 * Step-by-step NLP/CDS pipeline trace for QA and demo debugging.
 *
 * Enable via MEDCONNECT_NLP_DEBUG=1, define('MEDCONNECT_NLP_DEBUG', true),
 * or NlpPipelineDebug::enable(true) before analyze/assess.
 */

final class NlpPipelineDebug
{
    private static bool $enabled = false;

    /** @var list<array{step:string,ts:float,data:array<string,mixed>}> */
    private static array $trace = [];

    public static function enable(?bool $force = null): void
    {
        if ($force !== null) {
            self::$enabled = $force;

            return;
        }
        $env = getenv('MEDCONNECT_NLP_DEBUG');
        self::$enabled = $env === '1' || $env === 'true'
            || (defined('MEDCONNECT_NLP_DEBUG') && MEDCONNECT_NLP_DEBUG);
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /** @param array<string, mixed> $data */
    public static function step(string $stage, array $data = []): void
    {
        if (!self::$enabled) {
            return;
        }
        self::$trace[] = [
            'step' => $stage,
            'ts'   => microtime(true),
            'data' => $data,
        ];
    }

    /** @return list<array{step:string,ts:float,data:array<string,mixed>}> */
    public static function trace(): array
    {
        return self::$trace;
    }

    public static function reset(): void
    {
        self::$trace = [];
    }

    /** @param array<string, mixed> $result */
    public static function attach(array &$result): void
    {
        if (!self::$enabled || self::$trace === []) {
            return;
        }
        $result['pipeline_debug'] = [
            'enabled' => true,
            'step_count' => count(self::$trace),
            'steps' => self::$trace,
        ];
    }
}
