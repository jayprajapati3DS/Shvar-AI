/**
 * Shapes returned by the Phase 2 AI layer.
 *
 * Mirrors:
 *   App\Services\AI\AiStatus, AiResult
 *   App\Services\AI\AiSettings::toArray()
 *   App\Http\Resources\AiRequestResource
 */

import type { BadgeColor, Product } from '@/types/models';

export type AiRequestTypeName =
    | 'general'
    | 'company_analysis'
    | 'product_recommendation'
    | 'email_generation'
    | 'follow_up_generation';

export type AiRequestStatusName =
    | 'success'
    | 'failed'
    | 'unavailable'
    | 'model_missing'
    | 'timeout'
    | 'invalid_response'
    | 'rejected';

/** A live snapshot of the local runtime. */
export interface AiStatus {
    connected: boolean;
    model_installed: boolean;
    ready: boolean;
    provider: string;
    endpoint: string;
    model: string;
    installed_models: string[];
    version: string | null;
    probe_ms: number | null;
    /** User-safe reason when something is wrong. Never a stack trace. */
    message: string | null;
    hint: string | null;
}

export interface AiLimits {
    max_prompt_chars: number;
    min_temperature: number;
    max_temperature: number;
    min_timeout: number;
    max_timeout: number;
}

export interface AiSettings {
    provider: string;
    /** Read-only in the UI: server configuration, never writable from the browser. */
    endpoint: string;
    model: string;
    temperature: number;
    timeout: number;
    max_tokens: number | null;
    system_prompt: string;
    using_default_system_prompt: boolean;
    logging_enabled: boolean;
    endpoint_is_read_only: true;
    limits: AiLimits;
}

/** One completion, as returned to the Playground. */
export interface AiResult {
    prompt: string;
    text: string;
    data: Record<string, unknown> | null;
    provider: string;
    model: string;
    request_type: AiRequestTypeName;
    execution_ms: number;
    seconds: number;
    structured: boolean;
    prompt_tokens: number | null;
    response_tokens: number | null;
    truncated: boolean;
    log_id: number | null;
}

/** A row in the local AI log. Summary fields always; text only on detail. */
export interface AiRequestLog {
    id: number;
    provider: string;
    model: string;
    request_type: AiRequestTypeName;
    request_type_label: string;
    request_type_color: BadgeColor;
    status: AiRequestStatusName;
    status_label: string;
    status_color: BadgeColor;
    succeeded: boolean;
    execution_time_ms: number | null;
    seconds: number | null;
    structured: boolean;
    template: string | null;
    prompt_tokens: number | null;
    response_tokens: number | null;
    temperature: number | null;
    created_at: string | null;
    created_for_humans: string | null;

    /** Present only from the detail endpoint. */
    prompt?: string | null;
    response?: string | null;
    error_message?: string | null;
}

export interface AiRequestTypeOption {
    value: AiRequestTypeName;
    label: string;
    color: BadgeColor;
    implemented: boolean;
}

export interface AiLogStats {
    total: number;
    success: number;
    failed: number;
    average_ms: number;
}

/* -------------------------------------------------------------------------- */
/* Phase 3 - product recommendation                                          */
/* -------------------------------------------------------------------------- */

export type RecommendationPriorityName = 'High' | 'Medium' | 'Low';

export type RecommendationStatusName = 'Suggested' | 'Accepted' | 'Rejected' | 'Archived';

export type RecommendationTypeName = 'Manual' | 'AI Suggested' | 'AI Primary' | 'AI Secondary';

/**
 * One recommended (or manually chosen) product on a lead.
 *
 * Mirrors App\Http\Resources\LeadProductMatchResource. Note the two separate
 * axes: `source`/`recommendation_type` is where it came from, `status` is what
 * the user decided about it.
 */
export interface LeadProductMatch {
    id: number;
    lead_id: number;
    product_id: number;
    lead_analysis_id: number | null;

    recommendation_type: RecommendationTypeName;
    recommendation_color: BadgeColor;
    is_ai_generated: boolean;
    source: 'AI' | 'Manual';

    status: RecommendationStatusName;
    status_label: string;
    status_color: BadgeColor;
    is_active: boolean;

    priority: RecommendationPriorityName | null;
    priority_color: BadgeColor | null;

    confidence_score: number | null;
    confidence_percent: number | null;
    confidence_band: string;

    /** Present only when the calibrator lowered the model's own score. */
    raw_confidence_score: number | null;
    raw_confidence_percent: number | null;
    was_calibrated: boolean;

    reason: string | null;
    evidence: string[];
    sales_angle: string | null;
    suggested_use_case: string | null;

    /** A capability inside the product, e.g. "Knee Planning". */
    module: string | null;

    notes: string | null;
    product?: Product;

    reviewed_at: string | null;
    created_at: string | null;
    created_for_humans: string | null;
}

/** One AI analysis run. Mirrors App\Http\Resources\LeadAnalysisResource. */
export interface LeadAnalysis {
    id: number;
    lead_id: number;

    provider: string;
    model: string;

    company_summary: string | null;
    company_type: string | null;
    business_opportunity: string | null;

    products_to_avoid: { product_name: string; reason: string }[];
    missing_information: string[];
    recommended_next_action: string | null;

    /** What the validator discarded from the model's raw output. */
    warnings: string[];

    primary_product_id: number | null;
    primary_product_name?: string | null;
    primary_confidence: number | null;
    primary_confidence_percent: number | null;
    primary_confidence_band: string;

    execution_time_ms: number | null;
    seconds: number | null;
    ai_request_id: number | null;

    /** Nested collections serialise as a plain array, not { data: [] }. */
    recommendations?: LeadProductMatch[];
    recommendations_count?: number;

    created_at: string | null;
    created_for_humans: string | null;
}
