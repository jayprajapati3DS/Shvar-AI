<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailGeneration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailGeneration
 */
class EmailGenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,

            'provider' => $this->provider,
            'model' => $this->model,

            'tone' => $this->tone->value,
            'length' => $this->length->value,
            'extra_instructions' => $this->extra_instructions,

            // The AI's own accounting, and what the validator made of it.
            'personalization_points' => $this->personalization_points ?? [],
            'claims_used' => $this->claims_used ?? [],
            'missing_information' => $this->missing_information ?? [],
            'warnings' => $this->warnings ?? [],

            'execution_time_ms' => $this->execution_time_ms,
            'seconds' => $this->seconds(),
            'ai_request_id' => $this->ai_request_id,
            'regenerated_from_id' => $this->regenerated_from_id,

            'created_at' => $this->created_at?->toIso8601String(),

            'product' => $this->whenLoaded('product', fn () => $this->product === null ? null : [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),

            'recommendation' => $this->whenLoaded('recommendation', fn () => $this->recommendation === null
                ? null
                : [
                    'id' => $this->recommendation->id,
                    'sales_angle' => $this->recommendation->sales_angle,
                    'reason' => $this->recommendation->reason,
                    'confidence' => $this->recommendation->confidencePercent(),
                ]),

            'drafts' => EmailDraftResource::collection($this->whenLoaded('drafts')),

            'drafts_count' => $this->whenCounted('drafts'),
        ];
    }
}
