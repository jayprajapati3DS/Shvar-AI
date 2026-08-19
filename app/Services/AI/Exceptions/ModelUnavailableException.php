<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use App\Enums\AiRequestStatus;

/** Ollama is running, but the configured model has not been pulled. */
class ModelUnavailableException extends AiException
{
    /** @param list<string> $installed */
    public function __construct(
        private readonly string $model,
        private readonly array $installed = [],
    ) {
        parent::__construct("Model [{$model}] is not installed on this machine");
    }

    public function status(): AiRequestStatus
    {
        return AiRequestStatus::ModelMissing;
    }

    public function userMessage(): string
    {
        return "Model \"{$this->model}\" is not installed. Please install the model using Ollama.";
    }

    public function hint(): string
    {
        // Told, not run: the app never executes shell commands on the user's behalf.
        // Kept ASCII-only so the message is readable in a Windows console as well
        // as in the browser.
        $hint = "Run: ollama pull {$this->model}";

        if ($this->installed !== []) {
            $hint .= ' | Installed models: '.implode(', ', array_slice($this->installed, 0, 8));
        }

        return $hint;
    }
}
