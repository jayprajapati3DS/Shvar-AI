<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'icon' => $this->type->icon(),
            'color' => $this->type->color(),
            'title' => $this->title,
            'body' => $this->body,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'occurred_for_humans' => $this->occurred_at?->diffForHumans(),
        ];
    }
}
