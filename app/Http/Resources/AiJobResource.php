<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiJob */
class AiJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'color' => $this->status->color(),
            'active' => $this->status->isActive(),

            'label' => $this->label,

            // What it is about, so a button on that record can show its own
            // state without matching on the display text.
            'subject_type' => $this->subject_type === null ? null : class_basename($this->subject_type),
            'subject_id' => $this->subject_id,

            // Where the output of this ended up. Present even on a failure, so
            // there is always a way back to the page it was started from.
            'result_url' => $this->result_url,
            'result_level' => $this->result_level,
            'result_summary' => $this->result_summary,
            'error' => $this->error,

            // Elapsed rather than a start timestamp: the browser's clock and the
            // server's need not agree, and a duration is immune to that.
            'elapsed_seconds' => (int) round($this->elapsedSeconds()),

            // An estimate, and the tray says so. See AiJob::estimatedProgress().
            'progress' => $this->estimatedProgress(),

            'queued_at' => $this->queued_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
