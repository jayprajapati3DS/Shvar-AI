<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import CompanyFormModal from '@/Components/Forms/CompanyFormModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { routes } from '@/routes';
import type { Company, Paginated } from '@/types/models';
import type { Column } from '@/types/ui';

const { companies, filters, filterOptions } = defineProps<{
    companies: Paginated<Company>;
    filters: Record<string, string | undefined>;
    filterOptions: {
        countries: string[];
        industries: string[];
        companyTypes: string[];
    };
}>();

const columns: Column[] = [
    { label: 'Company' },
    { label: 'Country' },
    { label: 'City' },
    { label: 'Industry' },
    { label: 'Company Type' },
    { label: 'Contacts', class: 'text-right' },
    { label: 'Leads', class: 'text-right' },
    { label: 'Created' },
    { label: 'Actions', class: 'text-right', hidden: true },
];

const showForm = ref(false);
const editing = ref<Company | null>(null);
const deleting = ref<Company | null>(null);
const processing = ref(false);

function create() {
    editing.value = null;
    showForm.value = true;
}

function edit(company: Company) {
    editing.value = company;
    showForm.value = true;
}

function confirmDelete() {
    if (!deleting.value) {
        return;
    }

    processing.value = true;

    router.delete(routes.companies.destroy(deleting.value.id), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            deleting.value = null;
        },
    });
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : '—';
}
</script>

<template>
    <Head title="Companies" />

    <PageHeader title="Companies" :subtitle="`${companies.meta.total} record(s)`">
        <template #actions>
            <Link :href="routes.import.create()" class="btn-secondary">Import CSV</Link>
            <button type="button" class="btn-primary" @click="create">+ New company</button>
        </template>
    </PageHeader>

    <div class="card overflow-hidden">
        <FilterBar
            :base-path="routes.companies.index()"
            :filters="filters"
            search-placeholder="Search name, website, industry…"
            :definitions="[
                { key: 'country', label: 'countries', options: filterOptions.countries },
                { key: 'industry', label: 'industries', options: filterOptions.industries },
                { key: 'company_type', label: 'types', options: filterOptions.companyTypes },
            ]"
        />

        <EmptyState
            v-if="companies.data.length === 0"
            icon="building"
            title="No companies found"
            :message="
                Object.values(filters).some(Boolean)
                    ? 'No company matches those filters. Try clearing them.'
                    : 'Add your first company, or import a CSV to create several at once.'
            "
        >
            <button type="button" class="btn-primary" @click="create">+ New company</button>
        </EmptyState>

        <template v-else>
            <DataTable :columns="columns" :rows="companies.data" :row-key="(row) => row.id">
                <template #row="{ row }">
                    <td class="td">
                        <Link :href="routes.companies.show(row.id)" class="font-medium text-slate-900 hover:text-indigo-600">
                            {{ row.name }}
                        </Link>
                        <p v-if="row.website" class="truncate text-xs text-slate-400">{{ row.website }}</p>
                    </td>
                    <td class="td">{{ row.country ?? '—' }}</td>
                    <td class="td">{{ row.city ?? '—' }}</td>
                    <td class="td">{{ row.industry ?? '—' }}</td>
                    <td class="td">{{ row.company_type ?? '—' }}</td>
                    <td class="td text-right tabular-nums">{{ row.contacts_count ?? 0 }}</td>
                    <td class="td text-right tabular-nums">{{ row.leads_count ?? 0 }}</td>
                    <td class="td whitespace-nowrap text-slate-500">{{ formatDate(row.created_at) }}</td>
                    <td class="td text-right whitespace-nowrap">
                        <Link :href="routes.companies.show(row.id)" class="btn-ghost px-2 py-1 text-xs">View</Link>
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

            <Pagination :meta="companies.meta" />
        </template>
    </div>

    <CompanyFormModal :open="showForm" :company="editing" @close="showForm = false" />

    <ConfirmDialog
        :open="deleting !== null"
        :title="`Delete ${deleting?.name}?`"
        message="The company record is removed. Its contacts and leads are kept, but will no longer be linked to a company."
        :processing="processing"
        @cancel="deleting = null"
        @confirm="confirmDelete"
    />
</template>
