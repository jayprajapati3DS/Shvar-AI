<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the local AI log.
 *
 * The list view needs only the summary fields; prompt, response and
 * error_message are heavy and are sent only when a row is expanded, via
 * `$this->whenAppended`-style gating on the `detail` flag the controller sets.
 *
 * @mixin AiRequest
 */
class AiRequestResource extends JsonResource
{
    public function __construct($resource, private readonly bool $withDetail = false)
    {
        parent::__construct($resource);
    }

    /** Collection of summary rows, without the bulky text columns. */
    public static function summaries(mixed $resource): AnonymousResourceCollection
    {
        return self::collection($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,

            'request_type' => $this->request_type->value,
            'request_type_label' => $this->request_type->label(),
            'request_type_color' => $this->request_type->color(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'succeeded' => $this->status->isSuccess(),

            'execution_time_ms' => $this->execution_time_ms,
            'seconds' => $this->seconds(),

            'structured' => $this->structured,
            'template' => $this->template,
            'prompt_tokens' => $this->prompt_tokens,
            'response_tokens' => $this->response_tokens,
            'temperature' => $this->temperature,

            'created_at' => $this->created_at?->toIso8601String(),
            'created_for_humans' => $this->created_at?->diffForHumans(),

            // Only present on the detail request, so the list payload stays small.
            ...$this->withDetail ? [
                'prompt' => $this->prompt,
                'response' => $this->response,
                'error_message' => $this->error_message,
            ] : [],
        ];
    }
}
