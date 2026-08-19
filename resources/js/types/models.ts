import type { LeadProductMatch } from '@/types/ai';

/**
 * Shapes returned by the Laravel API Resources.
 *
 * These mirror app/Http/Resources/*.php. When a resource gains a field, add it
 * here too - the pages are typed against these interfaces, so the compiler will
 * point at every place that needs updating.
 */

export type LeadStatus =
    | 'New'
    | 'Researching'
    | 'Qualified'
    | 'Email Drafted'
    | 'Approved'
    | 'Contacted'
    | 'Follow-up'
    | 'Interested'
    | 'Meeting Scheduled'
    | 'Proposal'
    | 'Negotiation'
    | 'Won'
    | 'Lost'
    | 'Not Relevant';

export type Priority = 'Low' | 'Medium' | 'High';

export type RecommendationType = 'Manual' | 'AI Suggested' | 'AI Primary' | 'AI Secondary';

export type ActivityTypeName =
    | 'Note'
    | 'Email'
    | 'Call'
    | 'Meeting'
    | 'Follow-up'
    | 'Status Change';

/** Tailwind colour token emitted by the PHP enums. */
export type BadgeColor =
    | 'slate'
    | 'sky'
    | 'indigo'
    | 'amber'
    | 'violet'
    | 'orange'
    | 'emerald'
    | 'red';

export interface Company {
    id: number;
    name: string;

    /**
     * The pipeline lives on the company, not the lead.
     *
     * A lead's status says where one PERSON has got to; this says where the
     * ACCOUNT has got to - the thing actually being won.
     */
    status: string;
    status_color: BadgeColor;
    won_at: string | null;
    lost_at: string | null;
    outcome_reason: string | null;
    website: string | null;
    country: string | null;
    state: string | null;
    city: string | null;
    industry: string | null;
    company_type: string | null;
    description: string | null;
    specialties: string | null;
    products_services: string | null;
    notes: string | null;
    /** Leads at this company with an email address. */
    contactable_leads_count?: number;
    leads_count?: number;
    leads?: Lead[];
    activities?: Activity[];
    created_at: string | null;
    updated_at: string | null;
}


export interface Lead {
    id: number;
    company_id: number | null;

    /** The person. A lead IS the contact now - there is no separate record. */
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    job_title: string | null;
    department: string | null;
    email: string | null;
    phone: string | null;
    linkedin_url: string | null;
    country: string | null;
    city: string | null;
    /** Whether a person is actually named on this lead. */
    is_named: boolean;
    /** Whether there is an address to write to. */
    is_contactable: boolean;
    lead_source: string | null;
    lead_status: LeadStatus;
    status_color: BadgeColor;
    priority: Priority;
    priority_color: BadgeColor;
    assigned_to: string | null;
    notes: string | null;
    company?: Company;
    product_matches?: LeadProductMatch[];
    activities?: Activity[];
    products_count?: number;
    product_summary?: string[];
    created_at: string | null;
    updated_at: string | null;
    updated_for_humans: string | null;
}

export interface Product {
    id: number;
    name: string;
    category: string | null;
    short_description: string | null;
    detailed_description: string | null;
    target_customer: string | null;
    target_specialty: string | null;
    key_features: string | null;
    target_customer_list: string[];
    target_specialty_list: string[];
    key_features_list: string[];
    value_proposition: string | null;
    sales_notes: string | null;
    active: boolean;
    leads_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

/**
 * Re-exported from `@/types/ai`, where Phase 3 extended it with the AI
 * recommendation fields (priority, status, evidence, sales angle, module).
 * Kept exported here so existing imports from `@/types/models` still resolve.
 */
export type { LeadProductMatch } from '@/types/ai';

export interface Activity {
    id: number;
    type: ActivityTypeName;
    icon: string;
    color: BadgeColor;
    title: string;
    body: string | null;
    occurred_at: string | null;
    occurred_for_humans: string | null;
}

/* -------------------------------------------------------------------------- */
/* Framework wrappers                                                         */
/* -------------------------------------------------------------------------- */

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
    links: PaginationLink[];
}

/** What `ResourceCollection` of a paginator serialises to. */
export interface Paginated<T> {
    data: T[];
    meta: PaginationMeta;
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
}

export interface SelectOption {
    value: string | number;
    label: string;
    color?: BadgeColor;
}

export interface DashboardSummary {
    companies: number;
    contacts: number;
    leads: number;
    new_leads: number;
    qualified_leads: number;
    follow_ups: number;
    opportunities: number;
    won: number;
}

export interface PipelineStage {
    status: LeadStatus;
    label: string;
    count: number;
    color: BadgeColor;
    percentage: number;
}

/* -------------------------------------------------------------------------- */
/* CSV import                                                                 */
/* -------------------------------------------------------------------------- */

export interface ImportRow {
    line: number;
    data: Record<string, string>;
    errors: string[];
    duplicate: boolean;
    duplicate_reasons: string[];
    importable: boolean;
}

export interface ImportPreview {
    headers: string[];
    mapped: Record<string, string | null>;
    unmapped: string[];
    rows: ImportRow[];
    total: number;
    valid: number;
    invalid: number;
    duplicates: number;
    truncated: boolean;
}

/** Props shared with every page by HandleInertiaRequests::share(). */
export interface SharedProps {
    app: { name: string };
    flash: {
        success: string | null;
        error: string | null;
        info: string | null;
    };
    /** Background AI work, shared with every page so the tray is never stale. */
    ai_jobs: AiJobsState;
    [key: string]: unknown;
}

/* -------------------------------------------------------------------------- */
/* Background AI work                                                          */
/* -------------------------------------------------------------------------- */

export type AiJobStatus = 'Queued' | 'Running' | 'Done' | 'Failed' | 'Cancelled';

/** One piece of AI work, from the moment it was asked for. */
export interface AiJob {
    id: number;
    type: string;
    type_label: string;
    status: AiJobStatus;
    status_label: string;
    color: string;
    /** Queued or Running - still expected to produce something. */
    active: boolean;
    label: string;
    /** Short class name of what it is about - "Lead", "Company", "EmailReply". */
    subject_type: string | null;
    subject_id: number | null;
    result_url: string | null;
    result_level: string | null;
    result_summary: string | null;
    error: string | null;
    elapsed_seconds: number;
    /**
     * 0 to 1, and an ESTIMATE. A local model returns one response at the end, so
     * there is nothing real to measure - this is elapsed time against a typical
     * run. The tray says as much rather than passing it off as measured.
     */
    progress: number;
    queued_at: string | null;
    finished_at: string | null;
}

export interface AiJobsState {
    jobs: AiJob[];
    active: number;
    /** Work is waiting and no worker is coming for it. */
    stalled: boolean;
    start_command: string;
}

/* -------------------------------------------------------------------------- */
/* Phase 4 - AI personalized sales email                                       */
/* -------------------------------------------------------------------------- */

/** One rule-based check from the pre-approval panel. */
export interface QualityCheck {
    key: string;
    label: string;
    /** `fail` blocks approval; `warn` never does. */
    status: 'pass' | 'warn' | 'fail';
    detail: string | null;
}

export interface QualityResult {
    passed: boolean;
    /** True when at least one check failed - approval is refused. */
    blocking: boolean;
    word_count: number;
    checks: QualityCheck[];
}

/** One email the user could send: a single variant of a single generation. */
export interface EmailDraft {
    id: number;
    email_generation_id: number | null;
    lead_id: number;

    variant: string;
    variant_label: string;
    variant_short_label: string;
    variant_color: BadgeColor;

    status: string;
    status_label: string;
    status_color: BadgeColor;

    subject: string;
    body: string;

    /** What the model wrote, before any edit. Never updated. */
    ai_subject: string | null;
    ai_body: string | null;
    was_edited: boolean;

    recipient_email: string | null;
    recipient_name: string | null;

    personalization_points: string[];
    quality: QualityResult | null;
    word_count: number | null;

    ai_model: string | null;
    ai_request_id: number | null;
    created_by: string | null;
    version: number;

    approved_at: string | null;
    sent_at: string | null;
    delivery_mode: string | null;
    delivery_error: string | null;

    /** Resolved server-side so the UI never reimplements "can this be sent". */
    is_editable: boolean;
    is_approvable: boolean;
    is_sendable: boolean;

    created_at: string | null;
    updated_at: string | null;

    product?: { id: number; name: string; category: string | null } | null;
    contact?: { id: number; name: string; email: string | null; job_title: string | null } | null;
    lead?: {
        id: number;
        status: string;
        company: { id: number; name: string } | null;
    };
    versions?: EmailDraftVersion[];
}

export interface EmailDraftVersion {
    version: number;
    subject: string;
    body: string;
    source: 'ai' | 'user';
    word_count: number | null;
    created_at: string | null;
}

/** One AI generation run: the three variants plus the model's own accounting. */
export interface EmailGeneration {
    id: number;
    model: string;
    tone: string;
    length: string;
    extra_instructions: string | null;
    personalization_points: string[];
    claims_used: string[];
    missing_information: string[];
    /** What the validator removed or doubted. */
    warnings: string[];
    /** Set when this run replaced an earlier one via Regenerate. */
    regenerated_from_id: number | null;
    /** Every product this email pitches, primary first. */
    products?: { name: string; is_primary: boolean }[];
    created_at: string | null;
}

/** What the recipient would actually see - nothing internal. */
export interface EmailPreview {
    from: string | null;
    from_name: string | null;
    to: string | null;
    to_name: string | null;
    subject: string;
    body: string;
    signature: string;
    body_without_signature: string;
}

/** How this build delivers: simulated, or really over SMTP. */
export interface SendingMode {
    /** True when nothing actually leaves the machine. */
    simulated: boolean;
    mode: string;
    description: string;
    /** Whether the send button is offered at all. */
    allowed: boolean;
    /** The configured EMAIL_DRIVER. */
    driver?: string;
}

/** SMTP connection settings. The password is never included - only whether one is set. */
export interface SmtpSettings {
    host: string | null;
    port: number;
    encryption: string;
    username: string | null;
    /** Whether a password is stored. The value itself never reaches the browser. */
    password_set: boolean;
    from_address: string | null;
    from_name: string | null;
    configured: boolean;
    gaps: string[];
    encryption_modes: string[];
}

/** The recipient allowlist - the safety rail on real sending. Read-only, from .env. */
export interface RecipientAllowlist {
    restricting: boolean;
    entries: string[];
    summary: string;
    read_only: boolean;
    env_key: string;
}

/**
 * What the application learned about how you write.
 *
 * Derived from approved and sent drafts only. Not training - the model's
 * weights are untouched; this is fed back through the prompt.
 */
export interface LearningProfile {
    enabled: boolean;
    /** Approved/sent emails learned from. */
    samples: number;
    min_samples: number;
    /** True when enabled AND there is enough history to say anything. */
    active: boolean;
    preferred_variant: string | null;
    preferred_variant_label: string | null;
    typical_word_count: number | null;
    /** 0.0-1.0 - how often you rewrite the model's wording. */
    edit_rate: number;
    /** Sentences you consistently delete, now banned in the prompt. */
    rejected_phrases: string[];
    example_count: number;
    /** The exact text added to the prompt, shown so it is not a black box. */
    prompt_block: string | null;
}
