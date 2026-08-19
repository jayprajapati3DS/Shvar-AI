<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'short_description' => $this->short_description,
            'detailed_description' => $this->detailed_description,

            // Raw text is what the edit form posts back; the *_list variants are
            // the same content pre-split for bullet rendering.
            'target_customer' => $this->target_customer,
            'target_specialty' => $this->target_specialty,
            'key_features' => $this->key_features,
            'target_customer_list' => Product::toLines($this->target_customer),
            'target_specialty_list' => Product::toLines($this->target_specialty),
            'key_features_list' => Product::toLines($this->key_features),

            'value_proposition' => $this->value_proposition,
            'sales_notes' => $this->sales_notes,
            'active' => $this->active,

            'leads_count' => $this->whenCounted('leadMatches'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
