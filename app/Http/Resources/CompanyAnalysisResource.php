<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CompanyAnalysis;
use App\Services\AI\Research\CompanyResearchSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyAnalysis */
class CompanyAnalysisResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $applied = (array) $this->applied_fields;

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,

            'requested_url' => $this->requested_url,
            'fetched_urls' => $this->fetched_urls ?? [],

            'provider' => $this->provider,
            'model' => $this->model,

            // Each finding carries a quote already verified against the fetched
            // page, plus whether it has been applied to the company record.
            'findings' => array_map(
                fn (array $finding) => [
                    ...$finding,
                    'applied' => in_array($finding['field'] ?? '', $applied, true),
                    'confidence_percent' => (int) round(((float) ($finding['confidence'] ?? 0)) * 100),
                ],
                (array) $this->findings,
            ),

            // Fields the page did not establish, labelled for display.
            'not_found' => array_map(
                fn (string $field) => [
                    'field' => $field,
                    'label' => CompanyResearchSchema::label($field),
                ],
                (array) $this->not_found,
            ),

            // What the validator discarded, and why.
            'warnings' => $this->warnings ?? [],

            'page_summary' => $this->page_summary,
            'applied_fields' => $applied,
            'has_unapplied' => count($this->findings ?? []) > count($applied),

            'fetch_time_ms' => $this->fetch_time_ms,
            'execution_time_ms' => $this->execution_time_ms,
            'seconds' => $this->seconds(),
            'source_chars' => $this->source_chars,
            'ai_request_id' => $this->ai_request_id,

            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_for_humans' => $this->created_at?->diffForHumans(),
        ];
    }
}
