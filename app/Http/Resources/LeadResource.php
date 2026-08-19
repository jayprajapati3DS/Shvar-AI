<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            // The person. A lead IS the contact now.
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'job_title' => $this->job_title,
            'department' => $this->department,
            'email' => $this->email,
            'phone' => $this->phone,
            'linkedin_url' => $this->linkedin_url,
            'country' => $this->country,
            'city' => $this->city,
            'is_named' => $this->isNamed(),
            'is_contactable' => $this->isContactable(),

            'lead_source' => $this->lead_source,

            'lead_status' => $this->lead_status->value,
            'status_color' => $this->lead_status->color(),
            'priority' => $this->priority->value,
            'priority_color' => $this->priority->color(),

            'assigned_to' => $this->assigned_to,
            'notes' => $this->notes,

            'company' => new CompanyResource($this->whenLoaded('company')),
            'product_matches' => LeadProductMatchResource::collection($this->whenLoaded('productMatches')),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),

            // Powers the "Product Opportunity" column without loading the full
            // match records into the list view.
            'products_count' => $this->whenCounted('productMatches'),
            'product_summary' => $this->whenLoaded(
                'productMatches',
                fn () => $this->productMatches->pluck('product.name')->filter()->values()->all()
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_for_humans' => $this->updated_at?->diffForHumans(),
        ];
    }
}
