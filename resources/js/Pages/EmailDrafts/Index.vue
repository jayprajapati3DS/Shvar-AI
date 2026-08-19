<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Badge.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { routes } from '@/routes';
import type { EmailDraft, Paginated, SelectOption } from '@/types/models';
import type { Column } from '@/types/ui';

const { drafts, filters, filterOptions } = defineProps<{
    drafts: Paginated<EmailDraft>;
    filters: Record<string, string | undefined>;
    filterOptions: {
        statuses: SelectOption[];
        variants: SelectOption[];
        products: SelectOption[];
        companies: SelectOption[];
    };
}>();

const columns: Column[] = [
    { label: 'Recipient' },
    { label: 'Company' },
    { label: 'Subject' },
    { label: 'Product' },
    { label: 'Variant' },
    { label: 'Status' },
    { label: 'Words', class: 'text-right' },
    { label: 'Updated' },
    { label: 'Actions', class: 'text-right', hidden: true },
];

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : '—';
}
</script>

<template>
    <Head title="Email Drafts" />

    <PageHeader title="Email Drafts" :subtitle="`${drafts.meta.total} draft(s)`">
        <template #actions>
            <Link :href="routes.settings.email.index()" class="btn-secondary">Signature &amp; tone</Link>
        </template>
    </PageHeader>

    <!--
        The standing reminder. Nothing on this screen contacts anyone: drafts are
        written locally, approval is a separate human action, and even a sent
        draft was only ever simulated in this phase.
    -->
    <div class="card mb-6 border-indigo-100 bg-indigo-50/60 p-4">
        <p class="text-sm text-indigo-900">
            <strong>Nothing here is sent automatically.</strong>
            Every email is written by the local model as a draft, stays unsendable until you approve
            it, and sending is simulated — the message is written to the local log and never leaves
            this machine.
        </p>
    </div>

    <div class="card overflow-hidden">
        <FilterBar
            :base-path="routes.emailDrafts.index()"
            :filters="filters"
            search-placeholder="Search subject, recipient, company…"
            :definitions="[
                { key: 'status', label: 'statuses', options: filterOptions.statuses },
                { key: 'variant', label: 'variants', options: filterOptions.variants },
                { key: 'product_id', label: 'products', options: filterOptions.products },
                { key: 'company_id', label: 'companies', options: filterOptions.companies },
            ]"
        />

        <EmptyState
            v-if="drafts.data.length === 0"
            icon="mail"
            title="No email drafts yet"
            :message="
                Object.values(filters).some(Boolean)
                    ? 'No draft matches those filters. Try clearing them.'
                    : 'Open a lead with an accepted product recommendation and use Generate personalized email.'
            "
        >
            <Link :href="routes.leads.index()" class="btn-primary">Go to leads</Link>
        </EmptyState>

        <template v-else>
            <DataTable :columns="columns" :rows="drafts.data" :row-key="(row) => row.id">
                <template #row="{ row }">
                    <td class="td">
                        <Link
                            :href="routes.emailDrafts.show(row.id)"
                            class="font-medium text-slate-900 hover:text-indigo-600"
                        >
                            {{ row.recipient_name ?? '—' }}
                        </Link>
                        <p v-if="row.recipient_email" class="truncate text-xs text-slate-400">
                            {{ row.recipient_email }}
                        </p>
                    </td>

                    <td class="td">{{ row.lead?.company?.name ?? '—' }}</td>

                    <td class="td max-w-xs">
                        <p class="truncate">{{ row.subject }}</p>
                        <p v-if="row.was_edited" class="text-xs text-amber-600">edited</p>
                    </td>

                    <td class="td">{{ row.product?.name ?? '—' }}</td>

                    <td class="td">
                        <Badge :color="row.variant_color" size="sm">{{ row.variant_short_label }}</Badge>
                    </td>

                    <td class="td">
                        <Badge :color="row.status_color" size="sm">{{ row.status_label }}</Badge>
                        <p v-if="row.delivery_mode === 'simulated'" class="mt-0.5 text-xs text-slate-400">
                            simulated
                        </p>
                    </td>

                    <td class="td text-right tabular-nums">{{ row.word_count ?? '—' }}</td>

                    <td class="td whitespace-nowrap text-slate-500">{{ formatDate(row.updated_at) }}</td>

                    <td class="td text-right whitespace-nowrap">
                        <Link :href="routes.emailDrafts.show(row.id)" class="btn-ghost px-2 py-1 text-xs">
                            Open
                        </Link>
                    </td>
                </template>
            </DataTable>

            <Pagination :meta="drafts.meta" />
        </template>
    </div>
</template>
