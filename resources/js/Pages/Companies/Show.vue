<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import CompanyResearchPanel from '@/Components/Ai/CompanyResearchPanel.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import DetailList from '@/Components/DetailList.vue';
import EmptyState from '@/Components/EmptyState.vue';
import CompanyFormModal from '@/Components/Forms/CompanyFormModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { routes } from '@/routes';
import type { CompanyAnalysis } from '@/types/ai';
import type { Company, Product } from '@/types/models';
import type { DetailItem } from '@/types/ui';

const { company, products, analyses, latestAnalysis, research } = defineProps<{
    company: { data: Company };
    products: { data: Product[] };

    // Website research.
    analyses: { data: CompanyAnalysis[] };
    latestAnalysis: { data: CompanyAnalysis } | null;
    research: { enabled: boolean; fields: string[] };
}>();

const showForm = ref(false);
const confirmingDelete = ref(false);
const processing = ref(false);

const details: DetailItem[] = [
    { label: 'Website', value: company.data.website, href: company.data.website },
    { label: 'Industry', value: company.data.industry },
    { label: 'Company type', value: company.data.company_type },
    { label: 'Country', value: company.data.country },
    { label: 'State / Region', value: company.data.state },
    { label: 'City', value: company.data.city },
    { label: 'Description', value: company.data.description, wide: true },
    { label: 'Specialties', value: company.data.specialties, wide: true },
    { label: 'Products / Services', value: company.data.products_services, wide: true },
];

function destroy() {
    processing.value = true;
    router.delete(routes.companies.destroy(company.data.id), {
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <Head :title="company.data.name" />

    <PageHeader
        :title="company.data.name"
        :subtitle="[company.data.city, company.data.country].filter(Boolean).join(', ') || undefined"
        :back-href="routes.companies.index()"
        back-label="All companies"
    >
        <template #meta>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <Badge v-if="company.data.industry" color="sky">{{ company.data.industry }}</Badge>
                <Badge v-if="company.data.company_type" color="indigo">{{ company.data.company_type }}</Badge>
                <Badge :color="company.data.status_color ?? 'slate'">{{ company.data.status ?? 'Prospect' }}</Badge>
                <Badge color="slate">{{ company.data.leads?.length ?? 0 }} person/people</Badge>
            </div>
        </template>

        <template #actions>
            <button type="button" class="btn-secondary" @click="showForm = true">Edit</button>
            <button type="button" class="btn-danger" @click="confirmingDelete = true">Delete</button>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <!-- Company information -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Company Information</h2>
                </header>
                <div class="px-5 py-4">
                    <DetailList :items="details" />
                </div>
            </section>

            <!-- Website research -->
            <CompanyResearchPanel
                :company-id="company.data.id"
                :website="company.data.website"
                :analysis="latestAnalysis"
                :history="analyses"
                :enabled="research.enabled"
            />

            <!-- Leads -->
            <section class="card">
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">People at this company</h2>
                    <Link :href="routes.leads.index({ company_id: company.data.id })" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                        Manage
                    </Link>
                </header>

                <EmptyState
                    v-if="!company.data.leads?.length"
                    icon="inbox"
                    title="Nobody here yet"
                    message="Add the people you are working at this company. Each one is a lead."
                />

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="lead in company.data.leads" :key="lead.id">
                        <Link
                            :href="routes.leads.show(lead.id)"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 transition-colors hover:bg-slate-50"
                        >
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">
                                {{ lead.full_name }}
                            </span>
                            <span class="min-w-0 flex-1 truncate text-xs text-slate-500">
                                {{ lead.job_title ?? '—' }}
                            </span>
                            <span
                                class="min-w-0 flex-1 truncate text-xs"
                                :class="lead.is_contactable ? 'text-slate-400' : 'text-amber-600'"
                            >
                                {{ lead.email ?? 'no email' }}
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
            <!-- Products reached through this company's leads -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Associated Products</h2>
                    <p class="text-xs text-slate-500">Attached to this company's leads.</p>
                </header>

                <EmptyState
                    v-if="products.data.length === 0"
                    icon="box"
                    title="No products attached"
                    message="Open a lead to record which products fit this company."
                />

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="product in products.data" :key="product.id">
                        <Link :href="routes.products.show(product.id)" class="block px-5 py-3 transition-colors hover:bg-slate-50">
                            <p class="text-sm font-medium text-slate-900">{{ product.name }}</p>
                            <p class="text-xs text-slate-500">{{ product.category }}</p>
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- Notes -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Notes</h2>
                </header>
                <div class="px-5 py-4">
                    <p v-if="company.data.notes" class="whitespace-pre-line text-sm text-slate-700">
                        {{ company.data.notes }}
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
                :activities="company.data.activities ?? []"
                subject="companies"
                :subject-id="company.data.id"
                :types="[
                    { value: 'Note', label: 'Note' },
                    { value: 'Call', label: 'Call' },
                    { value: 'Meeting', label: 'Meeting' },
                ]"
            />
        </div>
    </div>

    <CompanyFormModal :open="showForm" :company="company.data" @close="showForm = false" />

    <ConfirmDialog
        :open="confirmingDelete"
        :title="`Delete ${company.data.name}?`"
        message="The company record is removed. The people you were working there are kept as leads, but will no longer be linked to a company."
        :processing="processing"
        @cancel="confirmingDelete = false"
        @confirm="destroy"
    />
</template>
