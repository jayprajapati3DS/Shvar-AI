<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeadAnalysis;
use App\Services\AI\Recommendation\ConfidenceCalibrator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeadAnalysis */
class LeadAnalysisResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,

            'provider' => $this->provider,
            'model' => $this->model,

            'company_summary' => $this->company_summary,
            'company_type' => $this->company_type,
            'business_opportunity' => $this->business_opportunity,

            'products_to_avoid' => $this->products_to_avoid ?? [],
            'missing_information' => $this->missing_information ?? [],
            'recommended_next_action' => $this->recommended_next_action,

            // What the validator discarded from the model's output. Shown so
            // the filtering is visible rather than silent.
            'warnings' => $this->warnings ?? [],

            'primary_product_id' => $this->primary_product_id,
            'primary_product_name' => $this->whenLoaded('primaryProduct', fn () => $this->primaryProduct?->name),
            'primary_confidence' => $this->primary_confidence,
            'primary_confidence_percent' => $this->primary_confidence === null
                ? null
                : (int) round($this->primary_confidence * 100),
            'primary_confidence_band' => ConfidenceCalibrator::describe($this->primary_confidence),

            'execution_time_ms' => $this->execution_time_ms,
            'seconds' => $this->seconds(),
            'ai_request_id' => $this->ai_request_id,

            'recommendations' => LeadProductMatchResource::collection($this->whenLoaded('recommendations')),
            'recommendations_count' => $this->whenCounted('recommendations'),

            'created_at' => $this->created_at?->toIso8601String(),
            'created_for_humans' => $this->created_at?->diffForHumans(),
        ];
    }
}
