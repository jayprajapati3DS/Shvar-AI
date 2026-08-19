/**
 * Types for the shared UI components.
 *
 * Kept out of the .vue files because `<script setup>` blocks are not modules
 * that can export - importing `{ type Column }` from a component would break.
 */

import type { SelectOption } from '@/types/models';

/** A DataTable header cell. */
export interface Column {
    label: string;
    /** Extra classes for this column's <th> - alignment, width, hiding at small widths. */
    class?: string;
    /** Render the header text screen-reader-only, for an actions column. */
    hidden?: boolean;
}

/** A row in a DetailList definition list. */
export interface DetailItem {
    label: string;
    value: string | number | null | undefined;
    /** Render the value as an external link. */
    href?: string | null;
    /** Span the full grid width - for long text such as notes. */
    wide?: boolean;
}

/** One dropdown in a FilterBar. */
export interface FilterDefinition {
    /** Query-string key this filter writes to. */
    key: string;
    label: string;
    options: SelectOption[] | string[];
}

/**
 * One editable field in the bulk-edit modal.
 *
 * Mirrors what a model declares in bulkEditableFields(). The server strips the
 * validation rules before sending this, so the shape here is presentation only
 * - every value is re-validated server-side regardless of what the modal does.
 */
export interface BulkField {
    key: string;
    label: string;
    type: 'select' | 'text' | 'boolean';
    /** Whether this field offers a "clear" option as well as a new value. */
    nullable: boolean;
    options?: SelectOption[];
    hint?: string;
}

/** What a bulk edit sends: values to set, and fields to empty. */
export interface BulkEditPayload {
    ids: number[];
    values: Record<string, string | number | boolean>;
    clear: string[];
}
