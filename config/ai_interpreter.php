<?php
/**
 * AI interpreter configuration (Groq / OpenAI / local Llama).
 */

if (!defined('AI_INTERPRETER_ENABLED')) {
    $disabled = getenv('MEDCONNECT_AI_INTERPRETER');
    define('AI_INTERPRETER_ENABLED', !in_array(strtolower((string) $disabled), ['0', 'false', 'no', 'off'], true));
}

if (!defined('GROQ_API_KEY')) {
    define('GROQ_API_KEY', (string) (getenv('GROQ_API_KEY') ?: getenv('MEDCONNECT_GROQ_API_KEY') ?: ''));
}

if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', (string) (getenv('OPENAI_API_KEY') ?: getenv('MEDCONNECT_OPENAI_API_KEY') ?: ''));
}

if (!defined('LOCAL_LLAMA_URL')) {
    define('LOCAL_LLAMA_URL', rtrim((string) (getenv('MEDCONNECT_LOCAL_LLAMA_URL') ?: 'http://127.0.0.1:11434'), '/'));
}

if (!defined('LOCAL_LLAMA_MODEL')) {
    define('LOCAL_LLAMA_MODEL', (string) (getenv('MEDCONNECT_LOCAL_LLAMA_MODEL') ?: 'llama3'));
}

if (!defined('GROQ_MODEL')) {
    $groqModel = (string) (getenv('MEDCONNECT_GROQ_MODEL') ?: getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b');
    $deprecatedGroq = [
        'llama-3.3-70b-versatile' => 'openai/gpt-oss-120b',
        'llama-3.1-8b-instant'    => 'openai/gpt-oss-120b',
    ];
    define('GROQ_MODEL', $deprecatedGroq[$groqModel] ?? $groqModel);
}

if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', (string) (getenv('MEDCONNECT_OPENAI_MODEL') ?: 'gpt-4o-mini'));
}

if (!defined('AI_INTERPRETER_TIMEOUT')) {
    define('AI_INTERPRETER_TIMEOUT', max(5, (int) (getenv('MEDCONNECT_AI_INTERPRETER_TIMEOUT') ?: 25)));
}

if (!defined('BITS_SERVICE_URL')) {
    $bitsUrl = getenv('MEDCONNECT_BITS_SERVICE_URL');
    if ($bitsUrl === false || $bitsUrl === '') {
        $bitsUrl = getenv('BITS_SERVICE_URL') ?: 'https://bits-service.bagocitycollege.com';
    }
    define('BITS_SERVICE_URL', rtrim((string) $bitsUrl, '/'));
}

if (!defined('BITS_OLLAMA_MODEL')) {
    $bitsModel = getenv('MEDCONNECT_BITS_OLLAMA_MODEL');
    if ($bitsModel === false || $bitsModel === '') {
        $bitsModel = 'phi3:mini';
    }
    define('BITS_OLLAMA_MODEL', (string) $bitsModel);
}

if (!defined('BITS_SERVICE_ENABLED')) {
    $bitsOn = getenv('MEDCONNECT_BITS_SERVICE');
    if ($bitsOn === false || $bitsOn === '') {
        $bitsOn = '1';
    }
    define('BITS_SERVICE_ENABLED', !in_array(strtolower(trim((string) $bitsOn)), ['0', 'false', 'no', 'off'], true));
}

if (!defined('BITS_SERVICE_TIMEOUT')) {
    define('BITS_SERVICE_TIMEOUT', max(15, (int) (getenv('MEDCONNECT_BITS_SERVICE_TIMEOUT') ?: 60)));
}

require_once __DIR__ . '/app.php';
