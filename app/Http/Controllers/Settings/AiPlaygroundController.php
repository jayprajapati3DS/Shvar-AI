<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AiRequestType;
use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\RunAiPromptRequest;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\AiSettings;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\PromptLibrary;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings -> AI Playground.
 *
 * A developer/testing screen for confirming the local AI path works end to end.
 * Deliberately the ONLY place in Phase 2 that runs a prompt, and it only ever
 * uses AiRequestType::General - company analysis, product recommendation and
 * email generation are Phase 3.
 */
class AiPlaygroundController extends Controller
{
    use RedirectsToOrigin;

    public function __construct(
        private readonly AIServiceInterface $ai,
        private readonly AiSettings $settings,
        private readonly PromptLibrary $prompts,
    ) {}

    public function index(): Response
    {
        $status = $this->ai->status();

        return Inertia::render('Settings/AiPlayground', [
            'settings' => $this->settings->toArray(),
            'status' => $status->toArray(),
            'result' => null,
            'systemPrompt' => $this->prompts->systemPrompt(),
            'examplePrompts' => $this->examplePrompts(),
        ]);
    }

    public function run(RunAiPromptRequest $request): RedirectResponse|Response
    {
        $prompt = (string) $request->validated('prompt');
        $structured = (bool) $request->boolean('structured');

        $options = array_filter([
            'model' => $request->validated('model'),
            'temperature' => $request->validated('temperature'),
            'max_tokens' => $request->validated('max_tokens'),
        ], fn ($value) => $value !== null && $value !== '');

        // A per-run model override must still be something installed locally.
        // Without this, a typo would surface as a confusing 404 from Ollama.
        if (isset($options['model']) && ! $this->ai->hasModel((string) $options['model'])) {
            return $this->backTo('settings.ai.playground')
                ->withInput()
                ->withErrors(['model' => 'That model is not installed locally.']);
        }

        $template = $this->prompts->general($prompt);

        try {
            $result = $structured
                ? $this->ai->generateStructured($template, $this->probeSchema(), AiRequestType::General, $options)
                : $this->ai->generate($template, AiRequestType::General, $options);
        } catch (AiException $e) {
            // The attempt is already in the local log; the user sees plain language.
            return $this->backTo('settings.ai.playground')
                ->withInput()
                ->with('error', trim($e->userMessage().' '.($e->hint() ?? '')));
        }

        // Re-render with the result rather than flashing it: a completion can be
        // tens of kilobytes and has no business in a session store.
        return Inertia::render('Settings/AiPlayground', [
            'settings' => $this->settings->toArray(),
            'status' => $this->ai->status()->toArray(),
            'systemPrompt' => $this->prompts->systemPrompt(),
            'examplePrompts' => $this->examplePrompts(),
            'result' => [
                ...$result->toArray(),
                'prompt' => $prompt,
            ],
        ]);
    }

    /**
     * A permissive schema for the Playground's JSON mode.
     *
     * No `required` keys: this screen exists to see what the model returns, so
     * enforcing a shape here would just produce failures that say nothing about
     * the connection. Phase 3 features will pass strict schemas.
     *
     * @return array<string, mixed>
     */
    private function probeSchema(): array
    {
        return ['type' => 'object'];
    }

    /** @return list<array{label: string, prompt: string}> */
    private function examplePrompts(): array
    {
        return [
            [
                'label' => 'Connection check',
                'prompt' => 'Reply with exactly the word: OK',
            ],
            [
                'label' => 'Summarise a note',
                'prompt' => 'Summarise the following sales note in two sentences. '
                    ."Do not add anything that is not stated.\n\n"
                    .'Met the R&D lead at a device company in Minneapolis. They outsource '
                    .'all segmentation today, roughly 40 cases a month, and want it in-house. '
                    .'No budget confirmed yet.',
            ],
            [
                'label' => 'Structured output test',
                'prompt' => 'Return a JSON object with keys "status" (string) and "ok" (boolean). '
                    .'Set status to "ready" and ok to true.',
            ],
            [
                'label' => 'Hallucination guard',
                'prompt' => 'What regulatory clearance does the product "Vision Anatomy Table" hold? '
                    .'If you do not know, say so plainly rather than guessing.',
            ],
        ];
    }
}
