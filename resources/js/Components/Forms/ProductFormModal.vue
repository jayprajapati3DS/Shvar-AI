<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import { routes } from '@/routes';
import type { Product } from '@/types/models';

const { open, product = null } = defineProps<{
    open: boolean;
    product?: Product | null;
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    name: '',
    category: '',
    short_description: '',
    detailed_description: '',
    target_customer: '',
    target_specialty: '',
    key_features: '',
    value_proposition: '',
    sales_notes: '',
    active: true,
});

watch(
    () => open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        form.clearErrors();
        form.defaults({
            name: product?.name ?? '',
            category: product?.category ?? '',
            short_description: product?.short_description ?? '',
            detailed_description: product?.detailed_description ?? '',
            // The raw newline-separated text, not the pre-split *_list arrays.
            target_customer: product?.target_customer ?? '',
            target_specialty: product?.target_specialty ?? '',
            key_features: product?.key_features ?? '',
            value_proposition: product?.value_proposition ?? '',
            sales_notes: product?.sales_notes ?? '',
            active: product?.active ?? true,
        });
        form.reset();
    },
    { immediate: true },
);

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    if (product) {
        form.put(routes.products.update(product.id), options);
    } else {
        form.post(routes.products.store(), options);
    }
}
</script>

<template>
    <Modal
        :open="open"
        :title="product ? `Edit ${product.name}` : 'New product'"
        description="One item per line in the list fields."
        size="xl"
        @close="emit('close')"
    >
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id }" label="Product name" :error="form.errors.name" required>
                    <input :id="id" v-model="form.name" type="text" class="input" autocomplete="off" />
                </FormField>

                <FormField v-slot="{ id }" label="Category" :error="form.errors.category">
                    <input
                        :id="id"
                        v-model="form.category"
                        type="text"
                        class="input"
                        placeholder="Medical Software / Surgical Planning"
                    />
                </FormField>
            </div>

            <FormField
                v-slot="{ id }"
                label="Short description"
                :error="form.errors.short_description"
                hint="One or two lines. Shown on the portfolio card."
            >
                <textarea :id="id" v-model="form.short_description" rows="2" class="input" />
            </FormField>

            <FormField v-slot="{ id }" label="Detailed description" :error="form.errors.detailed_description">
                <textarea :id="id" v-model="form.detailed_description" rows="6" class="input" />
            </FormField>

            <FormField
                v-slot="{ id }"
                label="Key features"
                :error="form.errors.key_features"
                hint="One feature per line."
            >
                <textarea :id="id" v-model="form.key_features" rows="6" class="input font-mono text-xs" />
            </FormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField
                    v-slot="{ id }"
                    label="Target customers"
                    :error="form.errors.target_customer"
                    hint="One per line."
                >
                    <textarea :id="id" v-model="form.target_customer" rows="6" class="input font-mono text-xs" />
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="Target specialties"
                    :error="form.errors.target_specialty"
                    hint="One per line."
                >
                    <textarea :id="id" v-model="form.target_specialty" rows="6" class="input font-mono text-xs" />
                </FormField>
            </div>

            <FormField v-slot="{ id }" label="Value proposition" :error="form.errors.value_proposition">
                <textarea :id="id" v-model="form.value_proposition" rows="3" class="input" />
            </FormField>

            <FormField
                v-slot="{ id }"
                label="Sales notes"
                :error="form.errors.sales_notes"
                hint="Private reminders - positioning, objections, pricing cues."
            >
                <textarea :id="id" v-model="form.sales_notes" rows="3" class="input" />
            </FormField>

            <label class="flex items-center gap-2.5">
                <input
                    v-model="form.active"
                    type="checkbox"
                    class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                />
                <span class="text-sm text-slate-700">
                    Active
                    <span class="text-slate-400">— inactive products are hidden from lead product pickers</span>
                </span>
            </label>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
            <button type="button" class="btn-primary" :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Saving…' : product ? 'Save changes' : 'Create product' }}
            </button>
        </template>
    </Modal>
</template>
