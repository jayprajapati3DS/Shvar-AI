<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import { routes } from '@/routes';
import type { Contact, Lead, SelectOption } from '@/types/models';

const { open, lead = null, options } = defineProps<{
    open: boolean;
    lead?: Lead | null;
    options: {
        statuses: SelectOption[];
        priorities: SelectOption[];
        sources: SelectOption[];
        companies: SelectOption[];
        contacts: { data: Contact[] };
    };
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    company_id: '' as string | number,
    contact_id: '' as string | number,
    lead_source: '',
    lead_status: 'New',
    priority: 'Medium',
    assigned_to: '',
    notes: '',
});

/**
 * Narrow the contact list to the chosen company, so you cannot pair a contact
 * with the wrong company. Contacts with no company stay selectable.
 */
const selectableContacts = computed(() => {
    if (!form.company_id) {
        return options.contacts.data;
    }

    return options.contacts.data.filter(
        (contact) => contact.company_id === Number(form.company_id) || contact.company_id === null,
    );
});

watch(
    () => open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        form.clearErrors();
        form.defaults({
            company_id: lead?.company_id ?? '',
            contact_id: lead?.contact_id ?? '',
            lead_source: lead?.lead_source ?? '',
            lead_status: lead?.lead_status ?? 'New',
            priority: lead?.priority ?? 'Medium',
            assigned_to: lead?.assigned_to ?? '',
            notes: lead?.notes ?? '',
        });
        form.reset();
    },
    { immediate: true },
);

// Drop a contact that no longer belongs to the newly selected company.
watch(
    () => form.company_id,
    () => {
        if (!form.contact_id) {
            return;
        }

        const stillValid = selectableContacts.value.some(
            (contact) => contact.id === Number(form.contact_id),
        );

        if (!stillValid) {
            form.contact_id = '';
        }
    },
);

function submit() {
    const requestOptions = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    if (lead) {
        form.put(routes.leads.update(lead.id), requestOptions);
    } else {
        form.post(routes.leads.store(), requestOptions);
    }
}
</script>

<template>
    <Modal
        :open="open"
        :title="lead ? 'Edit lead' : 'New lead'"
        description="A lead needs at least a company or a contact."
        size="xl"
        @close="emit('close')"
    >
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id }" label="Company" :error="form.errors.company_id">
                    <select :id="id" v-model="form.company_id" class="input">
                        <option value="">No company</option>
                        <option v-for="company in options.companies" :key="company.value" :value="company.value">
                            {{ company.label }}
                        </option>
                    </select>
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="Contact"
                    :error="form.errors.contact_id"
                    :hint="form.company_id ? 'Filtered to this company.' : undefined"
                >
                    <select :id="id" v-model="form.contact_id" class="input">
                        <option value="">No contact</option>
                        <option v-for="contact in selectableContacts" :key="contact.id" :value="contact.id">
                            {{ contact.full_name }}{{ contact.job_title ? ` — ${contact.job_title}` : '' }}
                        </option>
                    </select>
                </FormField>

                <FormField v-slot="{ id }" label="Status" :error="form.errors.lead_status" required>
                    <select :id="id" v-model="form.lead_status" class="input">
                        <option v-for="status in options.statuses" :key="status.value" :value="status.value">
                            {{ status.label }}
                        </option>
                    </select>
                </FormField>

                <FormField v-slot="{ id }" label="Priority" :error="form.errors.priority" required>
                    <select :id="id" v-model="form.priority" class="input">
                        <option v-for="priority in options.priorities" :key="priority.value" :value="priority.value">
                            {{ priority.label }}
                        </option>
                    </select>
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="Lead source"
                    :error="form.errors.lead_source"
                    hint="Pick one or type your own."
                >
                    <input
                        :id="id"
                        v-model="form.lead_source"
                        type="text"
                        class="input"
                        list="lead-source-options"
                        placeholder="LinkedIn"
                    />
                    <datalist id="lead-source-options">
                        <option v-for="source in options.sources" :key="source.value" :value="source.value" />
                    </datalist>
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="Assigned to"
                    :error="form.errors.assigned_to"
                    hint="Free text - there are no user accounts in Phase 1."
                >
                    <input :id="id" v-model="form.assigned_to" type="text" class="input" />
                </FormField>
            </div>

            <FormField v-slot="{ id }" label="Notes" :error="form.errors.notes">
                <textarea :id="id" v-model="form.notes" rows="4" class="input" />
            </FormField>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
            <button type="button" class="btn-primary" :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Saving…' : lead ? 'Save changes' : 'Create lead' }}
            </button>
        </template>
    </Modal>
</template>
