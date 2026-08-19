<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import { routes } from '@/routes';
import type { Company } from '@/types/models';

/**
 * Create/edit company. One component for both: passing `company` switches it to
 * a PUT against that record.
 */
const { open, company = null } = defineProps<{
    open: boolean;
    company?: Company | null;
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    name: '',
    website: '',
    country: '',
    state: '',
    city: '',
    industry: '',
    company_type: '',
    description: '',
    specialties: '',
    products_services: '',
    notes: '',
});

/** Reload the form each time the modal opens so stale input never carries over. */
watch(
    () => open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        form.clearErrors();
        form.defaults({
            name: company?.name ?? '',
            website: company?.website ?? '',
            country: company?.country ?? '',
            state: company?.state ?? '',
            city: company?.city ?? '',
            industry: company?.industry ?? '',
            company_type: company?.company_type ?? '',
            description: company?.description ?? '',
            specialties: company?.specialties ?? '',
            products_services: company?.products_services ?? '',
            notes: company?.notes ?? '',
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

    if (company) {
        form.put(routes.companies.update(company.id), options);
    } else {
        form.post(routes.companies.store(), options);
    }
}
</script>

<template>
    <Modal
        :open="open"
        :title="company ? `Edit ${company.name}` : 'New company'"
        :description="company ? undefined : 'Only the name is required - fill in the rest as you learn it.'"
        size="xl"
        @close="emit('close')"
    >
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id }" label="Company name" :error="form.errors.name" required>
                    <input :id="id" v-model="form.name" type="text" class="input" autocomplete="off" />
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="Website"
                    :error="form.errors.website"
                    hint="https:// is added automatically."
                >
                    <input :id="id" v-model="form.website" type="text" class="input" placeholder="example.com" />
                </FormField>

                <FormField v-slot="{ id }" label="Industry" :error="form.errors.industry">
                    <input :id="id" v-model="form.industry" type="text" class="input" placeholder="Medical Devices" />
                </FormField>

                <FormField v-slot="{ id }" label="Company type" :error="form.errors.company_type">
                    <input :id="id" v-model="form.company_type" type="text" class="input" placeholder="PSI Manufacturer" />
                </FormField>

                <FormField v-slot="{ id }" label="Country" :error="form.errors.country">
                    <input :id="id" v-model="form.country" type="text" class="input" />
                </FormField>

                <FormField v-slot="{ id }" label="State / Region" :error="form.errors.state">
                    <input :id="id" v-model="form.state" type="text" class="input" />
                </FormField>

                <FormField v-slot="{ id }" label="City" :error="form.errors.city">
                    <input :id="id" v-model="form.city" type="text" class="input" />
                </FormField>
            </div>

            <FormField v-slot="{ id }" label="Description" :error="form.errors.description">
                <textarea :id="id" v-model="form.description" rows="3" class="input" />
            </FormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField
                    v-slot="{ id }"
                    label="Specialties"
                    :error="form.errors.specialties"
                    hint="Clinical areas they work in."
                >
                    <textarea :id="id" v-model="form.specialties" rows="3" class="input" />
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="Products / Services"
                    :error="form.errors.products_services"
                    hint="What they sell - useful when matching your products."
                >
                    <textarea :id="id" v-model="form.products_services" rows="3" class="input" />
                </FormField>
            </div>

            <FormField v-slot="{ id }" label="Notes" :error="form.errors.notes">
                <textarea :id="id" v-model="form.notes" rows="3" class="input" />
            </FormField>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
            <button type="button" class="btn-primary" :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Saving…' : company ? 'Save changes' : 'Create company' }}
            </button>
        </template>
    </Modal>
</template>
