<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * A named, reusable prompt with a system message and placeholder substitution.
 *
 * Exists so prompts are never string-concatenated inside a controller. Phase 3
 * will add specialised templates (company analysis, product recommendation,
 * email generation) by registering them in PromptLibrary - callers keep passing
 * a PromptTemplate to AIServiceInterface and nothing else changes.
 *
 * Immutable: every with*() returns a new instance, so a library template can be
 * safely reused across requests.
 */
final readonly class PromptTemplate
{
    /**
     * @param  array<string, string|int|float>  $variables
     */
    public function __construct(
        public string $name,
        public string $template,
        public ?string $system = null,
        public array $variables = [],
    ) {}

    /** A template with no placeholders - used for the Playground's raw input. */
    public static function raw(string $text, ?string $system = null, string $name = 'raw'): self
    {
        return new self(name: $name, template: $text, system: $system);
    }

    /** @param array<string, string|int|float> $variables */
    public function with(array $variables): self
    {
        return new self(
            name: $this->name,
            template: $this->template,
            system: $this->system,
            variables: [...$this->variables, ...$variables],
        );
    }

    public function withSystem(?string $system): self
    {
        return new self(
            name: $this->name,
            template: $this->template,
            system: $system,
            variables: $this->variables,
        );
    }

    /**
     * The final user-facing prompt, with {{ placeholders }} substituted.
     *
     * Unfilled placeholders are left verbatim rather than blanked, so a missing
     * variable is visible in the local log instead of silently producing a
     * prompt with a hole in it.
     */
    public function render(): string
    {
        $rendered = $this->template;

        foreach ($this->variables as $key => $value) {
            $rendered = str_replace(
                ['{{'.$key.'}}', '{{ '.$key.' }}'],
                (string) $value,
                $rendered,
            );
        }

        return trim($rendered);
    }

    /** Placeholder names still unsubstituted after render(). */
    public function missingVariables(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $this->render(), $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
