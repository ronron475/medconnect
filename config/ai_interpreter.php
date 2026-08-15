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

require_once __DIR__ . '/app.php';
