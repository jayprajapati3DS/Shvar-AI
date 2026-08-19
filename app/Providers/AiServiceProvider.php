<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Ai\ProductMatcher;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\AiRequestLogger;
use App\Services\AI\AiSettings;
use App\Services\AI\LocalEndpointGuard;
use App\Services\AI\OllamaAIService;
use App\Services\AI\PromptLibrary;
use App\Services\AI\Recommendation\AiProductMatcher;
use App\Services\AI\StructuredResponseParser;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wires the local AI layer.
 *
 * The whole point of this file: AIServiceInterface is resolved here and nowhere
 * else, so swapping the local runtime later is a change to one match arm.
 *
 * There is no cloud branch, and adding one would be a deliberate, visible edit
 * to this provider - not a configuration accident.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons: settings does one query per request and caches it, and the
        // stateless collaborators have nothing to gain from being rebuilt.
        $this->app->singleton(AiSettings::class);
        $this->app->singleton(LocalEndpointGuard::class);
        $this->app->singleton(StructuredResponseParser::class);
        $this->app->singleton(AiRequestLogger::class);
        $this->app->singleton(PromptLibrary::class);

        $this->app->singleton(AIServiceInterface::class, function ($app): AIServiceInterface {
            $provider = (string) config('ai.provider', 'ollama');

            return match ($provider) {
                'ollama' => new OllamaAIService(
                    $app->make(HttpFactory::class),
                    $app->make(AiSettings::class),
                    $app->make(AiRequestLogger::class),
                    $app->make(StructuredResponseParser::class),
                    $app->make(LocalEndpointGuard::class),
                    $app->make(PromptLibrary::class),
                ),

                // Fail loudly. A typo in AI_PROVIDER must not silently fall back
                // to something the user did not choose.
                default => throw new InvalidArgumentException(
                    "Unsupported AI provider [{$provider}]. Only 'ollama' is available. "
                    .'Shvar AI Copilot runs AI locally and has no cloud provider.'
                ),
            };
        });

        // PHASE 3: the Phase 1 ProductMatcher placeholder now has an
        // implementation. It depends on AIServiceInterface, not on Ollama, so it
        // follows whatever local runtime is bound above.
        $this->app->bind(ProductMatcher::class, AiProductMatcher::class);
    }

    public function boot(): void
    {
        $this->assertEndpointIsLocal();
    }

    /**
     * Surface a misconfigured endpoint at boot rather than mid-request.
     *
     * Only a warning here - LocalEndpointGuard is what actually blocks the call,
     * on every request. Throwing at boot would take the whole CRM down over an
     * AI setting, which is the wrong trade for a feature you might not be using.
     */
    private function assertEndpointIsLocal(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $url = (string) config('ai.ollama.url', '');

        if ($url === '') {
            return;
        }

        if (! $this->app->make(LocalEndpointGuard::class)->isLocal($url)) {
            logger()->warning(
                'OLLAMA_URL is not a local address. AI requests will be blocked until it is corrected.',
                ['url' => $url, 'allowed_hosts' => config('ai.allowed_hosts')],
            );
        }
    }
}
