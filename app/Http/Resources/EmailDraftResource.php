<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailDraft
 */
class EmailDraftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email_generation_id' => $this->email_generation_id,
            'lead_id' => $this->lead_id,

            'variant' => $this->variant->value,
            'variant_label' => $this->variant->label(),
            'variant_short_label' => $this->variant->shortLabel(),
            'variant_color' => $this->variant->color(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),

            'subject' => $this->subject,
            'body' => $this->body,

            // What the model originally wrote, so an edited draft can be
            // compared against its origin.
            'ai_subject' => $this->ai_subject,
            'ai_body' => $this->ai_body,
            'was_edited' => $this->wasEdited(),

            'recipient_email' => $this->recipient_email,
            'recipient_name' => $this->recipient_name,

            'personalization_points' => $this->personalization_points ?? [],
            'quality' => $this->quality,
            'word_count' => $this->word_count,

            'ai_model' => $this->ai_model,
            'ai_request_id' => $this->ai_request_id,
            'created_by' => $this->created_by,
            'version' => $this->version,

            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivery_mode' => $this->delivery_mode,
            'delivery_error' => $this->delivery_error,

            // The status rules, resolved server-side so the UI never has to
            // reimplement "can this be sent".
            'is_editable' => $this->isEditable(),
            'is_approvable' => $this->isApprovable(),
            'is_sendable' => $this->isSendable(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'category' => $this->product->category,
            ]),

            // The lead carries the person now, so this one relation answers
            // both "who is this to" and "which account is it for".
            'lead' => $this->whenLoaded('lead', fn () => [
                'id' => $this->lead->id,
                'name' => $this->lead->full_name,
                'email' => $this->lead->email,
                'job_title' => $this->lead->job_title,
                'status' => $this->lead->lead_status->value,
                'company' => $this->lead->relationLoaded('company') && $this->lead->company !== null
                    ? ['id' => $this->lead->company->id, 'name' => $this->lead->company->name]
                    : null,
            ]),

            'versions' => $this->whenLoaded('versions', fn () => $this->versions
                ->map(fn ($v) => [
                    'version' => $v->version,
                    'subject' => $v->subject,
                    'body' => $v->body,
                    'source' => $v->source,
                    'word_count' => $v->word_count,
                    'created_at' => $v->created_at?->toIso8601String(),
                ])
                ->all()),
        ];
    }
}
