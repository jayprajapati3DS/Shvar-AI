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
            'contacts_count' => $this->whenCounted('contacts'),
            'leads_count' => $this->whenCounted('leads'),

            'contacts' => ContactResource::collection($this->whenLoaded('contacts')),
            'leads' => LeadResource::collection($this->whenLoaded('leads')),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
