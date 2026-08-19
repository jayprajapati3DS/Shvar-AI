<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // The pipeline. Lives on the company because the company is what
            // gets won; a lead's status tracks one person's progress.
            // Null-safe on purpose: a company is often loaded with a narrow
            // select (`lead.company:id,name`) to keep a list query cheap, and a
            // resource that 500s on that is a resource that dictates how every
            // caller must query.
            'status' => $this->status?->value,
            'status_color' => $this->status?->color(),
            'status_description' => $this->status?->describe(),
            'won_at' => $this->won_at?->toIso8601String(),
            'lost_at' => $this->lost_at?->toIso8601String(),
            'outcome_reason' => $this->outcome_reason,
            'is_won' => $this->status?->value === 'Won',
            'website' => $this->website,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'industry' => $this->industry,
            'company_type' => $this->company_type,
            'description' => $this->description,
            'specialties' => $this->specialties,
            'products_services' => $this->products_services,
            'notes' => $this->notes,

            // Only present when the controller eager-loaded counts, so list and
            // detail views can share this resource without extra queries.
            'contactable_leads_count' => $this->whenCounted('contactableLeads'),
            'leads_count' => $this->whenCounted('leads'),

            'leads' => LeadResource::collection($this->whenLoaded('leads')),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
