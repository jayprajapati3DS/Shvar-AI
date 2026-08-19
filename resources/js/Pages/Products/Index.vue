<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import BulkActionBar from '@/Components/Bulk/BulkActionBar.vue';
import BulkEditModal from '@/Components/Bulk/BulkEditModal.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import ProductFormModal from '@/Components/Forms/ProductFormModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useBulkSelection } from '@/composables/useBulkSelection';
import { routes } from '@/routes';
import type { Product } from '@/types/models';
import type { BulkField } from '@/types/ui';

const { products, filters, filterOptions, bulkFields } = defineProps<{
    products: { data: Product[] };
    filters: Record<string, string | undefined>;
    filterOptions: { categories: string[] };
    bulkFields: BulkField[];
}>();

const showForm = ref(false);
const editing = ref<Product | null>(null);
const processing = ref(false);

// The portfolio is a card grid rather than a table, so the checkbox sits in
// each card header and "select all" gets its own control above the grid.
const selection = useBulkSelection(() => products.data);
const showBulkEdit = ref(false);
const confirmingBulkDelete = ref(false);

function create() {
    editing.value = null;
    showForm.value = true;
}

function edit(product: Product) {
    editing.value = product;
    showForm.value = true;
}

function bulkDelete() {
    const ids = selection.selectedIds.value;
    processing.value = true;

    router.post(
        routes.products.bulkDestroy(),
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
</script>

<template>
    <Head title="Product Portfolio" />

    <PageHeader
        title="Product Portfolio"
        :subtitle="`${products.data.length} product(s) and service(s)`"
    >
        <template #actions>
            <button type="button" class="btn-primary" @click="create">+ New product</button>
        </template>
    </PageHeader>

    <div class="card mb-6 overflow-hidden">
        <FilterBar
            :base-path="routes.products.index()"
            :filters="filters"
            search-placeholder="Search name, category, target customer…"
            :definitions="[
                { key: 'category', label: 'categories', options: filterOptions.categories },
                {
                    key: 'active',
                    label: 'states',
                    options: [
                        { value: '1', label: 'Active' },
                        { value: '0', label: 'Inactive' },
                    ],
                },
            ]"
        />
    </div>

    <EmptyState
        v-if="products.data.length === 0"
        icon="box"
        title="No products found"
        :message="
            Object.values(filters).some(Boolean)
                ? 'No product matches those filters. Try clearing them.'
                : 'Your portfolio is empty. Run `php artisan db:seed` to load the seeded catalogue, or add one by hand.'
        "
    >
        <button type="button" class="btn-primary" @click="create">+ New product</button>
    </EmptyState>

    <div v-else>
        <label class="mb-3 inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600">
            <input
                type="checkbox"
                class="checkbox"
                :checked="selection.allVisibleSelected.value"
                :indeterminate="selection.someVisibleSelected.value"
                @change="selection.toggleAllVisible"
            />
            Select all {{ products.data.length }}
        </label>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        <article
            v-for="product in products.data"
            :key="product.id"
            class="card flex flex-col p-5 transition-shadow hover:shadow-md"
            :class="[
                product.active ? '' : 'opacity-70',
                selection.isSelected(product.id) ? 'ring-2 ring-indigo-500' : '',
            ]"
        >
            <header class="mb-3">
                <div class="mb-1.5 flex items-start justify-between gap-2">
                    <div class="flex items-start gap-2.5">
                        <input
                            type="checkbox"
                            class="checkbox mt-1"
                            :checked="selection.isSelected(product.id)"
                            :aria-label="`Select ${product.name}`"
                            @change="selection.toggle(product.id)"
                        />

                        <Link
                            :href="routes.products.show(product.id)"
                            class="text-base font-semibold leading-snug text-slate-900 hover:text-indigo-600"
                        >
                            {{ product.name }}
                        </Link>
                    </div>

                    <Badge :color="product.active ? 'emerald' : 'slate'" size="sm">
                        {{ product.active ? 'Active' : 'Inactive' }}
                    </Badge>
                </div>

                <p v-if="product.category" class="text-xs font-medium uppercase tracking-wide text-indigo-600">
                    {{ product.category }}
                </p>
            </header>

            <p v-if="product.short_description" class="mb-4 text-sm leading-relaxed text-slate-600">
                {{ product.short_description }}
            </p>

            <div v-if="product.target_customer_list.length" class="mb-3">
                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Target customers
                </p>
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="customer in product.target_customer_list.slice(0, 4)"
                        :key="customer"
                        color="sky"
                        size="sm"
                    >
                        {{ customer }}
                    </Badge>
                    <Badge v-if="product.target_customer_list.length > 4" color="slate" size="sm">
                        +{{ product.target_customer_list.length - 4 }} more
                    </Badge>
                </div>
            </div>

            <div v-if="product.target_specialty_list.length" class="mb-4">
                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Target specialties
                </p>
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="specialty in product.target_specialty_list.slice(0, 4)"
                        :key="specialty"
                        color="violet"
                        size="sm"
                    >
                        {{ specialty }}
                    </Badge>
                    <Badge v-if="product.target_specialty_list.length > 4" color="slate" size="sm">
                        +{{ product.target_specialty_list.length - 4 }} more
                    </Badge>
                </div>
            </div>

            <footer class="mt-auto flex items-center justify-between border-t border-slate-100 pt-3">
                <span class="text-xs text-slate-400">
                    {{ product.leads_count ?? 0 }} lead(s) attached
                </span>

                <div class="flex items-center gap-1">
                    <Link :href="routes.products.show(product.id)" class="btn-ghost px-2 py-1 text-xs">View</Link>
                    <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="edit(product)">Edit</button>
                </div>
            </footer>
        </article>
        </div>
    </div>

    <ProductFormModal :open="showForm" :product="editing" @close="showForm = false" />

    <BulkActionBar
        :count="selection.count.value"
        label="product"
        :processing="processing"
        @edit="showBulkEdit = true"
        @delete="confirmingBulkDelete = true"
        @clear="selection.clear"
    />

    <BulkEditModal
        :open="showBulkEdit"
        :fields="bulkFields"
        :ids="selection.selectedIds.value"
        label="product"
        :url="routes.products.bulkUpdate()"
        @close="showBulkEdit = false"
        @saved="showBulkEdit = false"
    />

    <ConfirmDialog
        :open="confirmingBulkDelete"
        :title="`Delete ${selection.count.value} product(s)?`"
        message="The products are removed, along with every lead opportunity that references them. Marking them inactive instead keeps the history and hides them from lead product pickers. This cannot be undone."
        :confirm-label="`Delete ${selection.count.value}`"
        :processing="processing"
        @cancel="confirmingBulkDelete = false"
        @confirm="bulkDelete"
    />
</template>
