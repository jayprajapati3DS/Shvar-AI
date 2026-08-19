<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Local AI configuration
|--------------------------------------------------------------------------
|
| Shvar AI Copilot runs AI inference locally through Ollama. There is no cloud
| provider and no API key anywhere in this file by design.
|
| The endpoint lives here (server configuration) and NOT in the database,
| specifically so it cannot be changed from the browser. See the `url` note
| below.
|
*/

return [

    /*
    | Which provider implementation to bind to AIServiceInterface.
    | Only 'ollama' exists. The key is here so a different LOCAL runtime
    | (llama.cpp server, LM Studio) can be added later without touching callers.
    */
    'provider' => env('AI_PROVIDER', 'ollama'),

    'ollama' => [

        /*
        | Ollama's local HTTP endpoint.
        |
        | SECURITY: this is env-only and is never writable from the UI. The
        | settings screen renders it read-only. Allowing the browser to set this
        | would let a request be pointed at an arbitrary remote host, which is
        | exactly the data leak this application is built to avoid.
        |
        | Validated on boot by App\Services\AI\LocalEndpointGuard.
        */
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),

        /*
        | The model to use. Deliberately has no hard-coded default beyond a
        | common small one - the app detects whether the configured model is
        | actually installed and says so rather than assuming.
        |
        | Overridable at runtime from Settings -> AI (stored in ai_settings).
        */
        'model' => env('OLLAMA_MODEL', 'qwen3:8b'),
    ],

    /*
    | Generation defaults. Each is overridable per request, and the first three
    | are overridable from Settings -> AI.
    */
    'defaults' => [
        'temperature' => (float) env('AI_TEMPERATURE', 0.7),

        // Seconds to wait for a completion. Local models on CPU are slow; a
        // first request also pays for loading the model into RAM.
        'timeout' => (int) env('AI_TIMEOUT', 300),

        // Ollama calls this num_predict. Null = let the model decide.
        'max_tokens' => env('AI_MAX_TOKENS') !== null ? (int) env('AI_MAX_TOKENS') : null,

        // Short timeout for availability/model-list probes, so a dead Ollama
        // fails fast instead of hanging the settings page.
        'probe_timeout' => (int) env('AI_PROBE_TIMEOUT', 5),
    ],

    /*
    | Guard rails. See App\Services\AI\OllamaAIService.
    */
    'limits' => [
        // Characters, not tokens - a cheap pre-flight check so an accidental
        // paste of a 5 MB document is rejected before it reaches the model.
        'max_prompt_chars' => (int) env('AI_MAX_PROMPT_CHARS', 20000),

        // Responses longer than this are truncated rather than stored whole.
        'max_response_chars' => (int) env('AI_MAX_RESPONSE_CHARS', 100000),

        // Temperature bounds accepted from the UI.
        'min_temperature' => 0.0,
        'max_temperature' => 2.0,

        // Timeout bounds accepted from the UI, in seconds.
        'min_timeout' => 5,
        'max_timeout' => 600,
    ],

    /*
    | Local request log (ai_requests table). Never transmitted anywhere.
    */
    'logging' => [
        'enabled' => (bool) env('AI_LOGGING_ENABLED', true),

        // Stored prompt/response are capped so the local SQLite file cannot be
        // grown without bound by a runaway loop.
        'max_stored_chars' => (int) env('AI_LOG_MAX_STORED_CHARS', 20000),
    ],

    /*
    | Hosts the local endpoint is permitted to point at. Anything else fails
    | fast at boot with a clear message, rather than silently shipping your
    | CRM data to a third party because of a typo in .env.
    */
    'allowed_hosts' => ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'host.docker.internal'],
];
