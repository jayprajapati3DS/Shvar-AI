<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
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
            'notes' => $this->notes,

            'company' => new CompanyResource($this->whenLoaded('company')),
            'leads' => LeadResource::collection($this->whenLoaded('leads')),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
            'leads_count' => $this->whenCounted('leads'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
