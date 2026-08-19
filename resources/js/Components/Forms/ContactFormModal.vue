<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import { routes } from '@/routes';
import type { Contact, SelectOption } from '@/types/models';

const { open, contact = null, companies, lockedCompanyId = null } = defineProps<{
    open: boolean;
    contact?: Contact | null;
    companies: SelectOption[];
    /** Pre-selects a company, e.g. when adding from a company detail page. */
    lockedCompanyId?: number | null;
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    company_id: '' as string | number,
    first_name: '',
    last_name: '',
    job_title: '',
    department: '',
    email: '',
    phone: '',
    linkedin_url: '',
    country: '',
    city: '',
    notes: '',
});

watch(
    () => open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        form.clearErrors();
        form.defaults({
            company_id: contact?.company_id ?? lockedCompanyId ?? '',
            first_name: contact?.first_name ?? '',
            last_name: contact?.last_name ?? '',
            job_title: contact?.job_title ?? '',
            department: contact?.department ?? '',
            email: contact?.email ?? '',
            phone: contact?.phone ?? '',
            linkedin_url: contact?.linkedin_url ?? '',
            country: contact?.country ?? '',
            city: contact?.city ?? '',
            notes: contact?.notes ?? '',
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

    if (contact) {
        form.put(routes.contacts.update(contact.id), options);
    } else {
        form.post(routes.contacts.store(), options);
    }
}
</script>

<template>
    <Modal
        :open="open"
        :title="contact ? `Edit ${contact.full_name}` : 'New contact'"
        size="xl"
        @close="emit('close')"
    >
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id }" label="First name" :error="form.errors.first_name" required>
                    <input :id="id" v-model="form.first_name" type="text" class="input" autocomplete="off" />
                </FormField>

                <FormField v-slot="{ id }" label="Last name" :error="form.errors.last_name">
                    <input :id="id" v-model="form.last_name" type="text" class="input" autocomplete="off" />
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="Company"
                    :error="form.errors.company_id"
                    hint="Leave blank for an unaffiliated contact."
                >
                    <select :id="id" v-model="form.company_id" class="input">
                        <option value="">No company</option>
                        <option v-for="company in companies" :key="company.value" :value="company.value">
                            {{ company.label }}
                        </option>
                    </select>
                </FormField>

                <FormField v-slot="{ id }" label="Job title" :error="form.errors.job_title">
                    <input :id="id" v-model="form.job_title" type="text" class="input" placeholder="Head of R&D" />
                </FormField>

                <FormField v-slot="{ id }" label="Department" :error="form.errors.department">
                    <input :id="id" v-model="form.department" type="text" class="input" placeholder="Engineering" />
                </FormField>

                <FormField v-slot="{ id }" label="Email" :error="form.errors.email">
                    <input :id="id" v-model="form.email" type="email" class="input" autocomplete="off" />
                </FormField>

                <FormField v-slot="{ id }" label="Phone" :error="form.errors.phone">
                    <input :id="id" v-model="form.phone" type="text" class="input" />
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="LinkedIn"
                    :error="form.errors.linkedin_url"
                    hint="https:// is added automatically."
                >
                    <input
                        :id="id"
                        v-model="form.linkedin_url"
                        type="text"
                        class="input"
                        placeholder="linkedin.com/in/…"
                    />
                </FormField>

                <FormField v-slot="{ id }" label="Country" :error="form.errors.country">
                    <input :id="id" v-model="form.country" type="text" class="input" />
                </FormField>

                <FormField v-slot="{ id }" label="City" :error="form.errors.city">
                    <input :id="id" v-model="form.city" type="text" class="input" />
                </FormField>
            </div>

            <FormField v-slot="{ id }" label="Notes" :error="form.errors.notes">
                <textarea :id="id" v-model="form.notes" rows="3" class="input" />
            </FormField>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
            <button type="button" class="btn-primary" :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Saving…' : contact ? 'Save changes' : 'Create contact' }}
            </button>
        </template>
    </Modal>
</template>
