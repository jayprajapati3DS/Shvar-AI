<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Models\LeadProductMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Adding a product opportunity by hand.
 *
 * Manual selection is always available and is never blocked by AI output - §17.
 * The AI can suggest, but it cannot stop you attaching whatever you want.
 */
class StoreLeadProductMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Uniqueness is checked here rather than with a `unique` rule.
     *
     * The database no longer enforces unique(lead_id, product_id) - it cannot,
     * because analysis history means the same product legitimately appears
     * across several runs. So "already attached" has to mean "already an ACTIVE
     * opportunity": a previously rejected or archived suggestion should not stop
     * you adding the product deliberately.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $leadId = $this->route('lead')?->id;
            $productId = $this->input('product_id');

            if ($leadId === null || $productId === null) {
                return;
            }

            $exists = LeadProductMatch::query()
                ->where('lead_id', $leadId)
                ->where('product_id', $productId)
                ->active()
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'product_id',
                    'That product is already an active opportunity on this lead.',
                );
            }
        });
    }

    /**
     * A hand-picked product is Manual and starts Accepted - choosing it IS the
     * decision, so it does not sit in a review queue. Confidence stays null:
     * a human pick has no model score to report.
     *
     * @return array<string, mixed>
     */
    public function matchAttributes(): array
    {
        return [
            ...$this->safe()->only(['product_id', 'reason', 'notes']),
            'recommendation_type' => RecommendationType::Manual,
            'status' => RecommendationStatus::Accepted,
            'confidence_score' => null,
            'reviewed_at' => now(),
        ];
    }
}
