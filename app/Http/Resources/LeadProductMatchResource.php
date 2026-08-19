<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeadProductMatch;
use App\Services\AI\Recommendation\ConfidenceCalibrator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeadProductMatch */
class LeadProductMatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'product_id' => $this->product_id,
            'lead_analysis_id' => $this->lead_analysis_id,

            // Provenance: Manual, AI Primary, AI Secondary.
            'recommendation_type' => $this->recommendation_type->value,
            'recommendation_color' => $this->recommendation_type->color(),
            'is_ai_generated' => $this->recommendation_type->isAiGenerated(),
            'source' => $this->recommendation_type->isAiGenerated() ? 'AI' : 'Manual',

            // Human decision: Suggested, Accepted, Rejected, Archived.
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'is_active' => $this->status->isActive(),

            'priority' => $this->priority?->value,
            'priority_color' => $this->priority?->color(),

            'confidence_score' => $this->confidence_score,
            'confidence_percent' => $this->confidencePercent(),
            'confidence_band' => ConfidenceCalibrator::describe($this->confidence_score),

            // Set only when the calibrator lowered the model's own score; the UI
            // shows both so the displayed number is never silently different
            // from what the model said.
            'raw_confidence_score' => $this->raw_confidence_score,
            'raw_confidence_percent' => $this->raw_confidence_score === null
                ? null
                : (int) round($this->raw_confidence_score * 100),
            'was_calibrated' => $this->wasCalibrated(),

            'reason' => $this->reason,
            'evidence' => $this->evidence ?? [],
            'sales_angle' => $this->sales_angle,
            'suggested_use_case' => $this->suggested_use_case,

            // A capability within the product, validated against its own
            // key_features - e.g. "Knee Planning" inside the 3dsurgical Platform.
            'module' => $this->module,

            'notes' => $this->notes,

            'product' => new ProductResource($this->whenLoaded('product')),

            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_for_humans' => $this->created_at?->diffForHumans(),
        ];
    }
}
