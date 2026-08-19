<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import LeadIntelligencePanel from '@/Components/Ai/LeadIntelligencePanel.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import DetailList from '@/Components/DetailList.vue';
import EmailOutreachPanel from '@/Components/Email/EmailOutreachPanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import LeadFormModal from '@/Components/Forms/LeadFormModal.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { routes } from '@/routes';
import type { AiStatus, LeadAnalysis } from '@/types/ai';
import type { Contact, EmailDraft, EmailGeneration, Lead, Product, SelectOption } from '@/types/models';
import type { DetailItem } from '@/types/ui';

const { lead, options, availableProducts, activityTypes, analyses, latestAnalysis, aiStatus, email } =
    defineProps<{
        lead: { data: Lead };
        options: {
            statuses: SelectOption[];
            priorities: SelectOption[];
            sources: SelectOption[];
            companies: SelectOption[];
            contacts: { data: Contact[] };
        };
        availableProducts: { data: Product[] };
        activityTypes: SelectOption[];

        // Phase 3 - AI Sales Intelligence.
        analyses: { data: LeadAnalysis[] };
        latestAnalysis: { data: LeadAnalysis } | null;
        aiStatus: AiStatus;

        // Phase 4 - email outreach.
        email: {
            drafts: EmailDraft[];
            generations: EmailGeneration[];
            acceptedProducts: { id: number; product: string; sales_angle: string | null }[];
            canGenerate: boolean;
            blockers: string[];
            thin: string[];
        };
    }>();

const showForm = ref(false);
const showProductPicker = ref(false);
const confirmingDelete = ref(false);
const processing = ref(false);

const productForm = useForm({
    product_id: '' as string | number,
    reason: '',
    notes: '',
});

const details: DetailItem[] = [
    { label: 'Lead source', value: lead.data.lead_source },
    { label: 'Assigned to', value: lead.data.assigned_to },
    { label: 'Created', value: lead.data.created_at ? new Date(lead.data.created_at).toLocaleString() : null },
    { label: 'Last updated', value: lead.data.updated_for_humans },
];

function attachProduct() {
    productForm.post(routes.leads.attachProduct(lead.data.id), {
        preserveScroll: true,
        onSuccess: () => {
            showProductPicker.value = false;
            productForm.reset();
        },
    });
}

function detachProduct(matchId: number) {
    router.delete(routes.leads.detachProduct(lead.data.id, matchId), { preserveScroll: true });
}

/** Move the lead to a new status without opening the full edit form. */
function setStatus(status: string) {
    router.put(
        routes.leads.update(lead.data.id),
        {
            company_id: lead.data.company_id,
            contact_id: lead.data.contact_id,
            lead_source: lead.data.lead_source,
            lead_status: status,
            priority: lead.data.priority,
            assigned_to: lead.data.assigned_to,
            notes: lead.data.notes,
        },
        { preserveScroll: true },
    );
}

function destroy() {
    processing.value = true;
    router.delete(routes.leads.destroy(lead.data.id), {
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <Head :title="`Lead — ${lead.data.company?.name ?? lead.data.contact?.full_name ?? lead.data.id}`" />

    <PageHeader
        :title="lead.data.company?.name ?? lead.data.contact?.full_name ?? `Lead #${lead.data.id}`"
        :subtitle="lead.data.contact ? `${lead.data.contact.full_name}${lead.data.contact.job_title ? ` · ${lead.data.contact.job_title}` : ''}` : undefined"
        :back-href="routes.leads.index()"
        back-label="All leads"
    >
        <template #meta>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <Badge :color="lead.data.status_color">{{ lead.data.lead_status }}</Badge>
                <Badge :color="lead.data.priority_color">{{ lead.data.priority }} priority</Badge>
                <Badge v-if="lead.data.lead_source" color="slate">{{ lead.data.lead_source }}</Badge>
            </div>
        </template>

        <template #actions>
            <button type="button" class="btn-secondary" @click="showForm = true">Edit</button>
            <button type="button" class="btn-danger" @click="confirmingDelete = true">Delete</button>
        </template>
    </PageHeader>

    <!-- Quick status move -->
    <section class="card mb-6 px-5 py-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="mr-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Move to</span>
            <button
                v-for="status in options.statuses"
                :key="status.value"
                type="button"
                class="rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition-colors"
                :class="
                    status.value === lead.data.lead_status
                        ? 'bg-indigo-600 text-white ring-indigo-600'
                        : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50 hover:text-slate-900'
                "
                :disabled="status.value === lead.data.lead_status"
                @click="setStatus(String(status.value))"
            >
                {{ status.label }}
            </button>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <!-- Lead detail -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Lead Detail</h2>
                </header>

                <div class="space-y-5 px-5 py-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Company</p>
                            <Link
                                v-if="lead.data.company"
                                :href="routes.companies.show(lead.data.company.id)"
                                class="mt-1 block text-sm font-medium text-indigo-600 hover:text-indigo-800"
                            >
                                {{ lead.data.company.name }}
                            </Link>
                            <p v-else class="mt-1 text-sm text-slate-400">Not set</p>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Contact</p>
                            <Link
                                v-if="lead.data.contact"
                                :href="routes.contacts.show(lead.data.contact.id)"
                                class="mt-1 block text-sm font-medium text-indigo-600 hover:text-indigo-800"
                            >
                                {{ lead.data.contact.full_name }}
                            </Link>
                            <p v-else class="mt-1 text-sm text-slate-400">Not set</p>
                        </div>
                    </div>

                    <DetailList :items="details" />
                </div>
            </section>

            <!-- AI Sales Intelligence (Phase 3) -->
            <LeadIntelligencePanel
                :lead-id="lead.data.id"
                :analysis="latestAnalysis"
                :history="analyses"
                :status="aiStatus"
            />

            <!-- Email outreach (Phase 4) -->
            <EmailOutreachPanel
                :lead-id="lead.data.id"
                :drafts="email.drafts"
                :generations="email.generations"
                :accepted-products="email.acceptedProducts"
                :status="aiStatus"
                :can-generate="email.canGenerate"
                :blockers="email.blockers"
                :thin="email.thin"
            />

            <!-- Product opportunities: manual picks plus accepted AI ones -->
            <section class="card">
                <header class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Potential Products</h2>
                        <p class="text-xs text-slate-500">
                            Chosen by hand. AI recommendations arrive in Phase 2.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="btn-secondary"
                        :disabled="availableProducts.data.length === 0"
                        @click="showProductPicker = true"
                    >
                        + Add product
                    </button>
                </header>

                <EmptyState
                    v-if="!lead.data.product_matches?.length"
                    icon="box"
                    title="No products attached"
                    message="Record which of your products fit this lead."
                >
                    <button
                        type="button"
                        class="btn-primary"
                        :disabled="availableProducts.data.length === 0"
                        @click="showProductPicker = true"
                    >
                        + Add product
                    </button>
                </EmptyState>

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="match in lead.data.product_matches" :key="match.id" class="group px-5 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Link
                                        v-if="match.product"
                                        :href="routes.products.show(match.product.id)"
                                        class="text-sm font-medium text-slate-900 hover:text-indigo-600"
                                    >
                                        {{ match.product.name }}
                                    </Link>

                                    <Badge :color="match.recommendation_color" size="sm">
                                        {{ match.recommendation_type }}
                                    </Badge>

                                    <!-- Null for every Phase 1 record. -->
                                    <Badge v-if="match.confidence_score !== null" color="violet" size="sm">
                                        {{ Math.round(match.confidence_score * 100) }}% confidence
                                    </Badge>
                                </div>

                                <p v-if="match.product?.category" class="mt-0.5 text-xs text-slate-500">
                                    {{ match.product.category }}
                                </p>
                                <p v-if="match.reason" class="mt-1.5 text-sm text-slate-600">{{ match.reason }}</p>
                                <p v-if="match.notes" class="mt-1 text-xs text-slate-500">{{ match.notes }}</p>
                            </div>

                            <button
                                type="button"
                                class="btn-ghost shrink-0 px-2 py-1 text-xs text-red-600 opacity-0 transition hover:bg-red-50 focus:opacity-100 group-hover:opacity-100"
                                @click="detachProduct(match.id)"
                            >
                                Remove
                            </button>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Notes -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Notes</h2>
                </header>
                <div class="px-5 py-4">
                    <p v-if="lead.data.notes" class="whitespace-pre-line text-sm text-slate-700">
                        {{ lead.data.notes }}
                    </p>
                    <p v-else class="text-sm text-slate-400">
                        No notes yet.
                        <button type="button" class="font-medium text-indigo-600 hover:underline" @click="showForm = true">
                            Add some
                        </button>
                    </p>
                </div>
            </section>
        </div>

        <div class="space-y-6">
            <ActivityTimeline
                :activities="lead.data.activities ?? []"
                subject="leads"
                :subject-id="lead.data.id"
                :types="activityTypes"
            />
        </div>
    </div>

    <LeadFormModal :open="showForm" :lead="lead.data" :options="options" @close="showForm = false" />

    <!-- Product picker -->
    <Modal
        :open="showProductPicker"
        title="Attach a product"
        description="Manual match. Phase 2 will suggest these automatically with a confidence score."
        size="lg"
        @close="showProductPicker = false"
    >
        <form class="space-y-4" @submit.prevent="attachProduct">
            <FormField v-slot="{ id }" label="Product" :error="productForm.errors.product_id" required>
                <select :id="id" v-model="productForm.product_id" class="input">
                    <option value="">Select a product…</option>
                    <option v-for="product in availableProducts.data" :key="product.id" :value="product.id">
                        {{ product.name }}{{ product.category ? ` — ${product.category}` : '' }}
                    </option>
                </select>
            </FormField>

            <FormField
                v-slot="{ id }"
                label="Why this product?"
                :error="productForm.errors.reason"
                hint="Your reasoning. Phase 2 will fill this in automatically."
            >
                <textarea :id="id" v-model="productForm.reason" rows="3" class="input" />
            </FormField>

            <FormField v-slot="{ id }" label="Notes" :error="productForm.errors.notes">
                <textarea :id="id" v-model="productForm.notes" rows="2" class="input" />
            </FormField>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" @click="showProductPicker = false">Cancel</button>
            <button type="button" class="btn-primary" :disabled="productForm.processing" @click="attachProduct">
                {{ productForm.processing ? 'Adding…' : 'Attach product' }}
            </button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="confirmingDelete"
        title="Delete this lead?"
        message="The lead, its product matches and its activity timeline are removed. The company and contact are kept."
        :processing="processing"
        @cancel="confirmingDelete = false"
        @confirm="destroy"
    />
</template>
