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
import ContactFormModal from '@/Components/Forms/ContactFormModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { useBulkSelection } from '@/composables/useBulkSelection';
import { routes } from '@/routes';
import type { Contact, Paginated, SelectOption } from '@/types/models';
import type { BulkField, Column } from '@/types/ui';

const { contacts, filters, filterOptions, bulkFields } = defineProps<{
    contacts: Paginated<Contact>;
    filters: Record<string, string | undefined>;
    filterOptions: {
        companies: SelectOption[];
        departments: string[];
        countries: string[];
    };
    bulkFields: BulkField[];
}>();

const columns: Column[] = [
    { label: 'Name' },
    { label: 'Company' },
    { label: 'Job Title' },
    { label: 'Department' },
    { label: 'Email' },
    { label: 'LinkedIn' },
    { label: 'Lead Status' },
    { label: 'Actions', class: 'text-right', hidden: true },
];

const showForm = ref(false);
const editing = ref<Contact | null>(null);
const deleting = ref<Contact | null>(null);
const processing = ref(false);

// Bulk selection. Reads the rows through a getter so it re-derives after every
// Inertia visit rather than holding a stale array.
const selection = useBulkSelection(() => contacts.data);
const showBulkEdit = ref(false);
const confirmingBulkDelete = ref(false);

function bulkDelete() {
    const ids = selection.selectedIds.value;
    processing.value = true;

    router.post(
        routes.contacts.bulkDestroy(),
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

function edit(contact: Contact) {
    editing.value = contact;
    showForm.value = true;
}

function confirmDelete() {
    if (!deleting.value) {
        return;
    }

    processing.value = true;

    router.delete(routes.contacts.destroy(deleting.value.id), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            deleting.value = null;
        },
    });
}

/** The controller eager-loads only the most recently updated lead. */
function latestLead(contact: Contact) {
    return contact.leads?.[0] ?? null;
}
</script>

<template>
    <Head title="Contacts" />

    <PageHeader title="Contacts" :subtitle="`${contacts.meta.total} record(s)`">
        <template #actions>
            <Link :href="routes.import.create()" class="btn-secondary">Import CSV</Link>
            <button type="button" class="btn-primary" @click="create">+ New contact</button>
        </template>
    </PageHeader>

    <div class="card overflow-hidden">
        <FilterBar
            :base-path="routes.contacts.index()"
            :filters="filters"
            search-placeholder="Search name, email, title, company…"
            :definitions="[
                { key: 'company_id', label: 'companies', options: filterOptions.companies },
                { key: 'department', label: 'departments', options: filterOptions.departments },
                { key: 'country', label: 'countries', options: filterOptions.countries },
            ]"
        />

        <EmptyState
            v-if="contacts.data.length === 0"
            icon="user"
            title="No contacts found"
            :message="
                Object.values(filters).some(Boolean)
                    ? 'No contact matches those filters. Try clearing them.'
                    : 'Add your first contact, or import a CSV to create several at once.'
            "
        >
            <button type="button" class="btn-primary" @click="create">+ New contact</button>
        </EmptyState>

        <template v-else>
            <DataTable
                :columns="columns"
                :rows="contacts.data"
                :row-key="(row) => row.id"
                selectable
                select-label="contact"
                :is-selected="selection.isSelected"
                :all-selected="selection.allVisibleSelected.value"
                :some-selected="selection.someVisibleSelected.value"
                @toggle="selection.toggle"
                @toggle-all="selection.toggleAllVisible"
            >
                <template #row="{ row }">
                    <td class="td">
                        <Link :href="routes.contacts.show(row.id)" class="font-medium text-slate-900 hover:text-indigo-600">
                            {{ row.full_name }}
                        </Link>
                        <p v-if="row.phone" class="text-xs text-slate-400">{{ row.phone }}</p>
                    </td>

                    <td class="td">
                        <Link
                            v-if="row.company"
                            :href="routes.companies.show(row.company.id)"
                            class="text-slate-700 hover:text-indigo-600"
                        >
                            {{ row.company.name }}
                        </Link>
                        <span v-else class="text-slate-400">—</span>
                    </td>

                    <td class="td">{{ row.job_title ?? '—' }}</td>
                    <td class="td">{{ row.department ?? '—' }}</td>

                    <td class="td">
                        <a
                            v-if="row.email"
                            :href="`mailto:${row.email}`"
                            class="text-indigo-600 hover:text-indigo-800 hover:underline"
                        >
                            {{ row.email }}
                        </a>
                        <span v-else class="text-slate-400">—</span>
                    </td>

                    <td class="td">
                        <a
                            v-if="row.linkedin_url"
                            :href="row.linkedin_url"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="text-indigo-600 hover:text-indigo-800 hover:underline"
                        >
                            Profile
                        </a>
                        <span v-else class="text-slate-400">—</span>
                    </td>

                    <td class="td">
                        <Badge v-if="latestLead(row)" :color="latestLead(row)!.status_color" size="sm">
                            {{ latestLead(row)!.lead_status }}
                        </Badge>
                        <span v-else class="text-xs text-slate-400">No lead</span>
                    </td>

                    <td class="td text-right whitespace-nowrap">
                        <Link :href="routes.contacts.show(row.id)" class="btn-ghost px-2 py-1 text-xs">View</Link>
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

            <Pagination :meta="contacts.meta" />
        </template>
    </div>

    <ContactFormModal
        :open="showForm"
        :contact="editing"
        :companies="filterOptions.companies"
        @close="showForm = false"
    />

    <BulkActionBar
        :count="selection.count.value"
        :off-page-count="selection.offPageCount.value"
        label="contact"
        :processing="processing"
        @edit="showBulkEdit = true"
        @delete="confirmingBulkDelete = true"
        @clear="selection.clear"
    />

    <BulkEditModal
        :open="showBulkEdit"
        :fields="bulkFields"
        :ids="selection.selectedIds.value"
        label="contact"
        :url="routes.contacts.bulkUpdate()"
        @close="showBulkEdit = false"
        @saved="showBulkEdit = false"
    />

    <ConfirmDialog
        :open="confirmingBulkDelete"
        :title="`Delete ${selection.count.value} contact(s)?`"
        message="The contacts are removed. Any leads referencing them are kept, but will no longer have a contact. This cannot be undone."
        :confirm-label="`Delete ${selection.count.value}`"
        :processing="processing"
        @cancel="confirmingBulkDelete = false"
        @confirm="bulkDelete"
    />

    <ConfirmDialog
        :open="deleting !== null"
        :title="`Delete ${deleting?.full_name}?`"
        message="The contact is removed. Any leads referencing them are kept, but will no longer have a contact."
        :processing="processing"
        @cancel="deleting = null"
        @confirm="confirmDelete"
    />
</template>
