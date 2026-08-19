<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ProductFormModal from '@/Components/Forms/ProductFormModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { routes } from '@/routes';
import type { Lead, Product } from '@/types/models';

const { product, leads } = defineProps<{
    product: { data: Product };
    leads: { data: Lead[] };
}>();

const showForm = ref(false);
const confirmingDelete = ref(false);
const processing = ref(false);

function destroy() {
    processing.value = true;
    router.delete(routes.products.destroy(product.data.id), {
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <Head :title="product.data.name" />

    <PageHeader
        :title="product.data.name"
        :subtitle="product.data.category ?? undefined"
        :back-href="routes.products.index()"
        back-label="Product Portfolio"
    >
        <template #meta>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <Badge :color="product.data.active ? 'emerald' : 'slate'">
                    {{ product.data.active ? 'Active' : 'Inactive' }}
                </Badge>
                <Badge color="slate">{{ product.data.leads_count ?? 0 }} lead(s) attached</Badge>
            </div>
        </template>

        <template #actions>
            <button type="button" class="btn-secondary" @click="showForm = true">Edit</button>
            <button type="button" class="btn-danger" @click="confirmingDelete = true">Delete</button>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <!-- Description -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Description</h2>
                </header>
                <div class="space-y-4 px-5 py-4">
                    <p v-if="product.data.short_description" class="text-sm font-medium text-slate-800">
                        {{ product.data.short_description }}
                    </p>
                    <p
                        v-if="product.data.detailed_description"
                        class="whitespace-pre-line text-sm leading-relaxed text-slate-600"
                    >
                        {{ product.data.detailed_description }}
                    </p>
                    <p v-if="!product.data.short_description && !product.data.detailed_description" class="text-sm text-slate-400">
                        No description recorded.
                    </p>
                </div>
            </section>

            <!-- Features -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Features</h2>
                </header>

                <div class="px-5 py-4">
                    <ul v-if="product.data.key_features_list.length" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <li
                            v-for="feature in product.data.key_features_list"
                            :key="feature"
                            class="flex items-start gap-2 text-sm text-slate-700"
                        >
                            <svg class="mt-0.5 size-4 shrink-0 text-emerald-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                            </svg>
                            {{ feature }}
                        </li>
                    </ul>

                    <p v-else class="text-sm text-slate-400">No features listed.</p>
                </div>
            </section>

            <!-- Value proposition -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Value Proposition</h2>
                </header>
                <div class="px-5 py-4">
                    <p v-if="product.data.value_proposition" class="whitespace-pre-line text-sm leading-relaxed text-slate-700">
                        {{ product.data.value_proposition }}
                    </p>
                    <p v-else class="text-sm text-slate-400">Not recorded.</p>
                </div>
            </section>

            <!-- Attached leads -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Leads With This Product</h2>
                </header>

                <EmptyState
                    v-if="leads.data.length === 0"
                    icon="inbox"
                    title="Not attached to any lead"
                    message="Attach this product from a lead's detail page."
                />

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="lead in leads.data" :key="lead.id">
                        <Link
                            :href="routes.leads.show(lead.id)"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 transition-colors hover:bg-slate-50"
                        >
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">
                                {{ lead.company?.name ?? 'No company' }}
                            </span>
                            <span class="min-w-0 flex-1 truncate text-sm text-slate-500">
                                {{ lead.contact?.full_name ?? 'No contact' }}
                            </span>
                            <Badge :color="lead.status_color" size="sm">{{ lead.lead_status }}</Badge>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <div class="space-y-6">
            <!-- Target customers -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Target Customers</h2>
                </header>
                <div class="px-5 py-4">
                    <div v-if="product.data.target_customer_list.length" class="flex flex-wrap gap-1.5">
                        <Badge v-for="customer in product.data.target_customer_list" :key="customer" color="sky" size="sm">
                            {{ customer }}
                        </Badge>
                    </div>
                    <p v-else class="text-sm text-slate-400">Not recorded.</p>
                </div>
            </section>

            <!-- Target specialties -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Target Specialties</h2>
                </header>
                <div class="px-5 py-4">
                    <div v-if="product.data.target_specialty_list.length" class="flex flex-wrap gap-1.5">
                        <Badge v-for="specialty in product.data.target_specialty_list" :key="specialty" color="violet" size="sm">
                            {{ specialty }}
                        </Badge>
                    </div>
                    <p v-else class="text-sm text-slate-400">Not recorded.</p>
                </div>
            </section>

            <!-- Sales notes -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Sales Notes</h2>
                    <p class="text-xs text-slate-500">Private to you.</p>
                </header>
                <div class="px-5 py-4">
                    <p v-if="product.data.sales_notes" class="whitespace-pre-line text-sm text-slate-700">
                        {{ product.data.sales_notes }}
                    </p>
                    <p v-else class="text-sm text-slate-400">
                        None yet.
                        <button type="button" class="font-medium text-indigo-600 hover:underline" @click="showForm = true">
                            Add some
                        </button>
                    </p>
                </div>
            </section>
        </div>
    </div>

    <ProductFormModal :open="showForm" :product="product.data" @close="showForm = false" />

    <ConfirmDialog
        :open="confirmingDelete"
        :title="`Delete ${product.data.name}?`"
        :message="`This removes the product and unlinks it from ${product.data.leads_count ?? 0} lead(s). Marking it inactive instead keeps the history.`"
        :processing="processing"
        @cancel="confirmingDelete = false"
        @confirm="destroy"
    />
</template>
