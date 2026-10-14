<?php

declare(strict_types=1);

/*
 * Decision Engine configuration.
 *
 * Framework-agnostic: read it with `Config::fromArray(require 'config/decision-engine.php')`.
 * In Laravel the service provider merges this file and it can be published with
 * `php artisan vendor:publish --tag=decision-engine-config`.
 */

if (! function_exists('env')) {
    /**
     * Minimal env() shim for non-Laravel usage.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (is_string($value) ? strtolower($value) : $value) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

return [
    // Name of the engine used when a decision does not call ->using().
    'default' => env('DECISION_ENGINE', 'jev'),

    // Named engines. `driver` selects the implementation (jev | openai | openai_responses | anthropic |
    // custom via EngineManager::extend()); every other key is handed to that driver.
    // `openai` is OpenAI's Decisions API; `openai_responses` emulates it with structured output.
    'engines' => [
        'jev' => [
            'driver' => 'jev',
            'api_key' => env('TYPESAFE_API_KEY'),
            'base_url' => env('TYPESAFE_BASE_URL', 'https://api.typesafe.ai'),
            'model' => env('TYPESAFE_DEFAULT_MODEL', 'jev-latest'),
        ],
        'luna' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'model' => 'gpt-6-luna',
        ],
        'luna-responses' => [
            'driver' => 'openai_responses',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'model' => 'gpt-5.6-luna',
            'options' => ['reasoning' => ['effort' => 'none']],
        ],
        'haiku' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'model' => 'claude-haiku-4-5',
            'options' => ['anthropic_version' => '2023-06-01'],
        ],
    ],

    // HTTP transport. `curl` (default, supports parallel decisions) or `laravel` (Http facade, sequential only).
    'transport' => [
        'driver' => env('DECISION_ENGINE_TRANSPORT', 'curl'),
        'timeout' => 10.0,
        'connect_timeout' => 5.0,
    ],

    // Retry policy; defaults mirror the official TypeSafe SDKs.
    'retry' => [
        'max_retries' => 2,
        'statuses' => [408, 429, '5xx'],
        'backoff_initial_ms' => 500,
        'backoff_max_ms' => 5000,
        'jitter' => 0.25,
        'max_retry_after_ms' => 60000,
        'connection_errors' => true,
        'timeouts' => true,
        // Re-ask (no backoff) when a 2xx response is invalid, e.g. an answer is missing.
        'invalid_responses' => 1,
    ],

    // Maximum in-flight HTTP requests for Decision::parallel() / forEach().
    'concurrency' => [
        'default' => 10,
    ],

    // Certainty bands: high > 0.9, medium 0.5–0.9, low < 0.5. Stakes decide the boundaries; tune per project.
    'thresholds' => [
        'high' => 0.9,
        'medium' => 0.5,
    ],

    // PSR-3 logging. In Laravel `channel` selects a log channel; null uses the default logger.
    'logging' => [
        'channel' => null,
        'level' => 'info',
    ],
];
