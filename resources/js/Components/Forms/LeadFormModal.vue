<script setup lang="ts">
/**
 * Create or edit a lead.
 *
 * A lead IS a person at a company, so this one form carries both. There used to
 * be a contact picker here, pointing at a separate record you had to create
 * first - which meant entering the same human twice and keeping two rows in
 * step. The person's details live on the lead now.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import { routes } from '@/routes';
import type { Lead, SelectOption } from '@/types/models';

const { open, lead = null, options } = defineProps<{
    open: boolean;
    lead?: Lead | null;
    options: {
        statuses: SelectOption[];
        priorities: SelectOption[];
        sources: SelectOption[];
        companies: SelectOption[];
    };
    /** Pre-select a company, when opened from a company page. */
    companyId?: number | null;
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

    lead_source: '',
    lead_status: '',
    priority: '',
    assigned_to: '',
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
            company_id: lead?.company_id ?? '',

            first_name: lead?.first_name ?? '',
            last_name: lead?.last_name ?? '',
            job_title: lead?.job_title ?? '',
            department: lead?.department ?? '',
            email: lead?.email ?? '',
            phone: lead?.phone ?? '',
            linkedin_url: lead?.linkedin_url ?? '',
            country: lead?.country ?? '',
            city: lead?.city ?? '',

            lead_source: lead?.lead_source ?? '',
            lead_status: String(lead?.lead_status ?? options.statuses[0]?.value ?? ''),
            priority: lead?.priority ?? 'Medium',
            assigned_to: lead?.assigned_to ?? '',
            notes: lead?.notes ?? '',
        });
        form.reset();
    },
    { immediate: true },
);

/**
 * A lead needs something to identify it by.
 *
 * Mirrors the server rule rather than duplicating a different one: a row with
 * no company, no name and no address sits in the list forever confusing
 * everybody.
 */
const hasIdentity = computed(
    () =>
        Boolean(form.company_id) ||
        form.first_name.trim() !== '' ||
        form.last_name.trim() !== '' ||
        form.email.trim() !== '',
);

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };

    if (lead) {
        form.put(routes.leads.update(lead.id), opts);
    } else {
        form.post(routes.leads.store(), opts);
    }
}
</script>

<template>
    <Modal
        :open="open"
        :title="lead ? `Edit ${lead.full_name}` : 'New lead'"
        description="A lead is a person at a company. Several people at the same company is normal — each is their own lead."
        size="xl"
        @close="emit('close')"
    >
        <form class="space-y-6" @submit.prevent="submit">
            <!-- ------------------------------------------------ company -->
            <FormField
                v-slot="{ id }"
                label="Company"
                :error="form.errors.company_id"
                hint="The account you are trying to win."
            >
                <select :id="id" v-model="form.company_id" class="input">
                    <option value="">— no company yet —</option>
                    <option v-for="c in options.companies" :key="c.value" :value="c.value">
                        {{ c.label }}
                    </option>
                </select>
            </FormField>

            <!-- ------------------------------------------------ person -->
            <div>
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    The person
                </h3>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ id }" label="First name" :error="form.errors.first_name">
                        <input :id="id" v-model="form.first_name" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Last name" :error="form.errors.last_name">
                        <input :id="id" v-model="form.last_name" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Job title" :error="form.errors.job_title">
                        <input :id="id" v-model="form.job_title" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Department" :error="form.errors.department">
                        <input :id="id" v-model="form.department" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField
                        v-slot="{ id }"
                        label="Email"
                        :error="form.errors.email"
                        hint="Without this, no outreach email can be generated."
                    >
                        <input :id="id" v-model="form.email" type="email" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Phone" :error="form.errors.phone">
                        <input :id="id" v-model="form.phone" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="LinkedIn" :error="form.errors.linkedin_url">
                        <input :id="id" v-model="form.linkedin_url" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <div class="grid grid-cols-2 gap-4">
                        <FormField v-slot="{ id }" label="Country" :error="form.errors.country">
                            <input :id="id" v-model="form.country" type="text" class="input" />
                        </FormField>

                        <FormField v-slot="{ id }" label="City" :error="form.errors.city">
                            <input :id="id" v-model="form.city" type="text" class="input" />
                        </FormField>
                    </div>
                </div>
            </div>

            <!-- ------------------------------------------------ pursuit -->
            <div>
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    The pursuit
                </h3>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ id }" label="Status" :error="form.errors.lead_status" required>
                        <select :id="id" v-model="form.lead_status" class="input">
                            <option v-for="s in options.statuses" :key="s.value" :value="s.value">
                                {{ s.label }}
                            </option>
                        </select>
                    </FormField>

                    <FormField v-slot="{ id }" label="Priority" :error="form.errors.priority" required>
                        <select :id="id" v-model="form.priority" class="input">
                            <option v-for="p in options.priorities" :key="p.value" :value="p.value">
                                {{ p.label }}
                            </option>
                        </select>
                    </FormField>

                    <FormField
                        v-slot="{ id }"
                        label="Source"
                        :error="form.errors.lead_source"
                        hint="How you came across them. Free text."
                    >
                        <input
                            :id="id"
                            v-model="form.lead_source"
                            type="text"
                            class="input"
                            list="lead-source-options"
                            autocomplete="off"
                        />
                        <datalist id="lead-source-options">
                            <option v-for="s in options.sources" :key="s.value" :value="s.value" />
                        </datalist>
                    </FormField>

                    <FormField v-slot="{ id }" label="Assigned to" :error="form.errors.assigned_to">
                        <input :id="id" v-model="form.assigned_to" type="text" class="input" autocomplete="off" />
                    </FormField>
                </div>
            </div>

            <FormField v-slot="{ id }" label="Notes" :error="form.errors.notes">
                <textarea :id="id" v-model="form.notes" rows="4" class="input" />
            </FormField>

            <p v-if="!hasIdentity" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Give this lead a company, a name or an email address — otherwise there is nothing to
                identify it by.
            </p>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
            <button
                type="button"
                class="btn-primary"
                :disabled="form.processing || !hasIdentity"
                @click="submit"
            >
                {{ form.processing ? 'Saving…' : lead ? 'Save changes' : 'Create lead' }}
            </button>
        </template>
    </Modal>
</template>
