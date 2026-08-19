<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAiSettingsRequest;
use App\Models\AiRequest;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\AiSettings;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\PromptLibrary;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings -> AI: configuration, live status, and the connection test.
 *
 * No Ollama call is made here directly - everything goes through
 * AIServiceInterface, so this controller has no idea which runtime is behind it.
 */
class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AIServiceInterface $ai,
        private readonly AiSettings $settings,
    ) {}

    public function index(): Response
    {
        // status() never throws: a stopped Ollama renders as "Not Connected"
        // rather than an error page.
        $status = $this->ai->status();

        return Inertia::render('Settings/Ai', [
            'settings' => $this->settings->toArray(),
            'status' => $status->toArray(),
            'defaults' => [
                'system_prompt' => PromptLibrary::BASE_SYSTEM_PROMPT,
                'model' => config('ai.ollama.model'),
                'temperature' => (float) config('ai.defaults.temperature'),
                'timeout' => (int) config('ai.defaults.timeout'),
            ],
            'requestTypes' => AiRequestType::options(),
            'stats' => $this->stats(),
            'environment' => $this->environment(),
        ]);
    }

    public function update(UpdateAiSettingsRequest $request): RedirectResponse
    {
        // save() ignores anything outside AiSettings::WRITABLE_KEYS, so the
        // endpoint cannot be changed here even if it were posted.
        $this->settings->save($request->settings());

        return back()->with('success', 'AI settings saved locally.');
    }

    /** Restore the shipped system prompt. */
    public function resetSystemPrompt(): RedirectResponse
    {
        $this->settings->forget('system_prompt');

        return back()->with('success', 'System prompt reset to the default.');
    }

    /**
     * The "Test AI Connection" button.
     *
     * Performs a real but minimal round trip so a green light means the model
     * actually responded, not merely that the port is open.
     */
    public function test(): RedirectResponse
    {
        try {
            $result = $this->ai->ping();
        } catch (AiException $e) {
            // userMessage() only - never the exception message or a trace.
            return back()->with('error', trim($e->userMessage().' '.($e->hint() ?? '')));
        }

        return back()->with('success', sprintf(
            'Connection successful. Model %s responded in %ss.',
            $result->model,
            number_format($result->seconds(), 2),
        ));
    }

    /**
     * Re-probe the runtime without a full page reload.
     *
     * Returns a redirect so the Inertia page props refresh with fresh status.
     */
    public function refresh(): RedirectResponse
    {
        $status = $this->ai->status();

        if (! $status->connected) {
            return back()->with('error', $status->message ?? 'Ollama is not reachable.');
        }

        if (! $status->modelInstalled) {
            return back()->with('error', $status->message ?? 'The configured model is not installed.');
        }

        return back()->with('success', sprintf(
            'Ollama reachable (%d model(s) installed).',
            count($status->installedModels),
        ));
    }

    /**
     * Local log counters shown on the settings page.
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $byStatus = AiRequest::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return [
            'total' => array_sum($byStatus),
            'success' => $byStatus[AiRequestStatus::Success->value] ?? 0,
            'failed' => array_sum($byStatus) - ($byStatus[AiRequestStatus::Success->value] ?? 0),
            'average_ms' => (int) round(
                (float) AiRequest::where('status', AiRequestStatus::Success->value)
                    ->avg('execution_time_ms')
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function environment(): array
    {
        return [
            'appName' => config('app.name'),
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
            'database' => config('database.default'),
            'databasePath' => config('database.default') === 'sqlite'
                ? config('database.connections.sqlite.database')
                : null,
        ];
    }
}
