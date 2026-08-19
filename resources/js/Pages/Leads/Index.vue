<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import BulkActionBar from '@/Components/Bulk/BulkActionBar.vue';
import BulkEditModal from '@/Components/Bulk/BulkEditModal.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import LeadFormModal from '@/Components/Forms/LeadFormModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { useBulkSelection } from '@/composables/useBulkSelection';
import { routes } from '@/routes';
import type { Lead, Paginated, SelectOption } from '@/types/models';
import type { BulkField, Column } from '@/types/ui';

const { leads, filters, filterOptions, options, bulkFields } = defineProps<{
    leads: Paginated<Lead>;
    filters: Record<string, string | undefined>;
    filterOptions: {
        statuses: SelectOption[];
        priorities: SelectOption[];
        companies: SelectOption[];
        countries: string[];
        sources: SelectOption[];
    };
    options: {
        statuses: SelectOption[];
        priorities: SelectOption[];
        sources: SelectOption[];
        companies: SelectOption[];
    };
    bulkFields: BulkField[];
}>();

const columns: Column[] = [
    { label: 'Person' },
    { label: 'Company' },
    { label: 'Job Title' },
    { label: 'Product Opportunity' },
    { label: 'Status' },
    { label: 'Priority' },
    { label: 'Source' },
    { label: 'Last Updated' },
    { label: 'Actions', class: 'text-right', hidden: true },
];

const showForm = ref(false);
const editing = ref<Lead | null>(null);
const deleting = ref<Lead | null>(null);
const processing = ref(false);

// Bulk selection. Reads the rows through a getter so it re-derives after every
// Inertia visit rather than holding a stale array.
const selection = useBulkSelection(() => leads.data);
const showBulkEdit = ref(false);
const confirmingBulkDelete = ref(false);

function bulkDelete() {
    const ids = selection.selectedIds.value;
    processing.value = true;

    router.post(
        routes.leads.bulkDestroy(),
        { ids },
        {
            preserveScroll: true,
            onSuccess: () => selection.forget(ids),
            onFinish: () => {
                processing.value = false;
                confirmingBulkDelete.value = false;
            },
        },
    );
}

function create() {
    editing.value = null;
    showForm.value = true;
}

function edit(lead: Lead) {
    editing.value = lead;
    showForm.value = true;
}

function confirmDelete() {
    if (!deleting.value) {
        return;
    }

    processing.value = true;

    router.delete(routes.leads.destroy(deleting.value.id), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <Head title="Leads" />

    <PageHeader title="Leads" :subtitle="`${leads.meta.total} record(s)`">
        <template #actions>
            <Link :href="routes.import.create()" class="btn-secondary">Import CSV</Link>
            <button type="button" class="btn-primary" @click="create">+ New lead</button>
        </template>
    </PageHeader>

    <div class="card overflow-hidden">
        <FilterBar
            :base-path="routes.leads.index()"
            :filters="filters"
            search-placeholder="Search company, contact, email…"
            :definitions="[
                { key: 'status', label: 'statuses', options: filterOptions.statuses },
                { key: 'priority', label: 'priorities', options: filterOptions.priorities },
                { key: 'country', label: 'countries', options: filterOptions.countries },
                { key: 'company_id', label: 'companies', options: filterOptions.companies },
                { key: 'source', label: 'sources', options: filterOptions.sources },
            ]"
        />

        <EmptyState
            v-if="leads.data.length === 0"
            icon="inbox"
            title="No leads found"
            :message="
                Object.values(filters).some(Boolean)
                    ? 'No lead matches those filters. Try clearing them.'
                    : 'Create your first lead, or import a CSV to create companies, contacts and leads in one pass.'
            "
        >
            <button type="button" class="btn-primary" @click="create">+ New lead</button>
        </EmptyState>

        <template v-else>
            <DataTable
                :columns="columns"
                :rows="leads.data"
                :row-key="(row) => row.id"
                selectable
                select-label="lead"
                :is-selected="selection.isSelected"
                :all-selected="selection.allVisibleSelected.value"
                :some-selected="selection.someVisibleSelected.value"
                @toggle="selection.toggle"
                @toggle-all="selection.toggleAllVisible"
            >
                <template #row="{ row }">
                    <td class="td">
                        <Link :href="routes.leads.show(row.id)" class="font-medium text-slate-900 hover:text-indigo-600">
                            {{ row.full_name }}
                        </Link>
                        <p
                            class="truncate text-xs"
                            :class="row.is_contactable ? 'text-slate-400' : 'text-amber-600'"
                        >
                            {{ row.email ?? 'no email' }}
                        </p>
                    </td>

                    <td class="td">
                        <Link
                            v-if="row.company"
                            :href="routes.companies.show(row.company.id)"
                            class="text-slate-700 hover:text-indigo-600"
                        >
                            {{ row.company.name }}
                        </Link>
                        <span v-else class="text-amber-600">No company</span>
                    </td>

                    <td class="td">{{ row.job_title ?? '—' }}</td>

                    <td class="td">
                        <!-- Manually attached in Phase 1; AI-suggested from Phase 2. -->
                        <div v-if="row.product_summary?.length" class="flex flex-wrap gap-1">
                            <Badge v-for="name in row.product_summary.slice(0, 2)" :key="name" color="indigo" size="sm">
                                {{ name }}
                            </Badge>
                            <Badge v-if="row.product_summary.length > 2" color="slate" size="sm">
                                +{{ row.product_summary.length - 2 }}
                            </Badge>
                        </div>
                        <span v-else class="text-xs text-slate-400">None yet</span>
                    </td>

                    <td class="td"><Badge :color="row.status_color" size="sm">{{ row.lead_status }}</Badge></td>
                    <td class="td"><Badge :color="row.priority_color" size="sm">{{ row.priority }}</Badge></td>
                    <td class="td">{{ row.lead_source ?? '—' }}</td>
                    <td class="td whitespace-nowrap text-slate-500">{{ row.updated_for_humans }}</td>

                    <td class="td text-right whitespace-nowrap">
                        <Link :href="routes.leads.show(row.id)" class="btn-ghost px-2 py-1 text-xs">View</Link>
                        <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="edit(row)">Edit</button>
                        <button
                            type="button"
                            class="btn-ghost px-2 py-1 text-xs text-red-600 hover:bg-red-50 hover:text-red-700"
                            @click="deleting = row"
                        >
                            Delete
                        </button>
                    </td>
                </template>
            </DataTable>

            <Pagination :meta="leads.meta" />
        </template>
    </div>

    <LeadFormModal :open="showForm" :lead="editing" :options="options" @close="showForm = false" />

    <BulkActionBar
        :count="selection.count.value"
        :off-page-count="selection.offPageCount.value"
        label="lead"
        :processing="processing"
        @edit="showBulkEdit = true"
        @delete="confirmingBulkDelete = true"
        @clear="selection.clear"
    />

    <BulkEditModal
        :open="showBulkEdit"
        :fields="bulkFields"
        :ids="selection.selectedIds.value"
        label="lead"
        :url="routes.leads.bulkUpdate()"
        @close="showBulkEdit = false"
        @saved="showBulkEdit = false"
    />

    <ConfirmDialog
        :open="confirmingBulkDelete"
        :title="`Delete ${selection.count.value} lead(s)?`"
        message="The leads, their product opportunities, their AI analysis history and their activity timelines are removed. Companies and contacts are kept. This cannot be undone."
        :confirm-label="`Delete ${selection.count.value}`"
        :processing="processing"
        @cancel="confirmingBulkDelete = false"
        @confirm="bulkDelete"
    />

    <ConfirmDialog
        :open="deleting !== null"
        title="Delete this lead?"
        message="The lead, its product matches and its activity timeline are removed. The company and contact are kept."
        :processing="processing"
        @cancel="deleting = null"
        @confirm="confirmDelete"
    />
</template>
