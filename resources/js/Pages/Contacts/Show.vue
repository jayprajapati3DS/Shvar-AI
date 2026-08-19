<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import DetailList from '@/Components/DetailList.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ContactFormModal from '@/Components/Forms/ContactFormModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { routes } from '@/routes';
import type { Contact, SelectOption } from '@/types/models';
import type { DetailItem } from '@/types/ui';

const { contact, companies } = defineProps<{
    contact: { data: Contact };
    companies: SelectOption[];
}>();

const showForm = ref(false);
const confirmingDelete = ref(false);
const processing = ref(false);

const details: DetailItem[] = [
    { label: 'Job title', value: contact.data.job_title },
    { label: 'Department', value: contact.data.department },
    {
        label: 'Email',
        value: contact.data.email,
        href: contact.data.email ? `mailto:${contact.data.email}` : null,
    },
    { label: 'Phone', value: contact.data.phone },
    { label: 'LinkedIn', value: contact.data.linkedin_url, href: contact.data.linkedin_url },
    { label: 'Country', value: contact.data.country },
    { label: 'City', value: contact.data.city },
];

function destroy() {
    processing.value = true;
    router.delete(routes.contacts.destroy(contact.data.id), {
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <Head :title="contact.data.full_name" />

    <PageHeader
        :title="contact.data.full_name"
        :subtitle="contact.data.job_title ?? undefined"
        :back-href="routes.contacts.index()"
        back-label="All contacts"
    >
        <template #meta>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <Badge v-if="contact.data.department" color="sky">{{ contact.data.department }}</Badge>
                <Badge color="slate">{{ contact.data.leads?.length ?? 0 }} lead(s)</Badge>
            </div>
        </template>

        <template #actions>
            <button type="button" class="btn-secondary" @click="showForm = true">Edit</button>
            <button type="button" class="btn-danger" @click="confirmingDelete = true">Delete</button>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Contact Information</h2>
                </header>
                <div class="px-5 py-4">
                    <DetailList :items="details" />
                </div>
            </section>

            <!-- Associated leads -->
            <section class="card">
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Associated Leads</h2>
                    <Link :href="routes.leads.index({ search: contact.data.email ?? contact.data.full_name })" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                        Manage
                    </Link>
                </header>

                <EmptyState
                    v-if="!contact.data.leads?.length"
                    icon="inbox"
                    title="No leads"
                    message="No opportunity has been opened with this contact."
                />

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="lead in contact.data.leads" :key="lead.id">
                        <Link
                            :href="routes.leads.show(lead.id)"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 transition-colors hover:bg-slate-50"
                        >
                            <span class="min-w-0 flex-1 truncate text-sm text-slate-700">
                                {{ lead.company?.name ?? 'No company' }}
                            </span>
                            <Badge :color="lead.status_color" size="sm">{{ lead.lead_status }}</Badge>
                            <Badge :color="lead.priority_color" size="sm">{{ lead.priority }}</Badge>
                            <span class="w-24 shrink-0 text-right text-xs text-slate-400">{{ lead.updated_for_humans }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <div class="space-y-6">
            <!-- Company -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Company</h2>
                </header>

                <div v-if="contact.data.company" class="px-5 py-4">
                    <Link
                        :href="routes.companies.show(contact.data.company.id)"
                        class="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        {{ contact.data.company.name }}
                    </Link>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ [contact.data.company.industry, contact.data.company.country].filter(Boolean).join(' · ') || 'No further detail' }}
                    </p>
                </div>

                <EmptyState
                    v-else
                    icon="building"
                    title="No company"
                    message="This contact is not linked to a company."
                />
            </section>

            <!-- Notes -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Notes</h2>
                </header>
                <div class="px-5 py-4">
                    <p v-if="contact.data.notes" class="whitespace-pre-line text-sm text-slate-700">
                        {{ contact.data.notes }}
                    </p>
                    <p v-else class="text-sm text-slate-400">
                        No notes yet.
                        <button type="button" class="font-medium text-indigo-600 hover:underline" @click="showForm = true">
                            Add some
                        </button>
                    </p>
                </div>
            </section>

            <ActivityTimeline
                :activities="contact.data.activities ?? []"
                subject="contacts"
                :subject-id="contact.data.id"
                :types="[
                    { value: 'Note', label: 'Note' },
                    { value: 'Call', label: 'Call' },
                    { value: 'Meeting', label: 'Meeting' },
                ]"
            />
        </div>
    </div>

    <ContactFormModal
        :open="showForm"
        :contact="contact.data"
        :companies="companies"
        @close="showForm = false"
    />

    <ConfirmDialog
        :open="confirmingDelete"
        :title="`Delete ${contact.data.full_name}?`"
        message="The contact is removed. Any leads referencing them are kept, but will no longer have a contact."
        :processing="processing"
        @cancel="confirmingDelete = false"
        @confirm="destroy"
    />
</template>
