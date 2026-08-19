<script setup lang="ts">
/**
 * Edits the fields a set of records can share.
 *
 * Every field has three explicit states rather than the usual "blank means no
 * change" convention. That convention is the source of the classic bulk-edit
 * accident: you clear a box meaning to empty a column, and the form reads it as
 * "leave alone" - or worse, the reverse, and a hundred rows lose a value nobody
 * asked to remove. Here "leave unchanged" and "clear" are separate choices, and
 * only fields the model declares nullable offer the second one.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import type { BulkField } from '@/types/ui';

type Mode = 'keep' | 'set' | 'clear';

const { open, fields, ids, label, url } = defineProps<{
    open: boolean;
    fields: BulkField[];
    /** Ids of the selected records. */
    ids: number[];
    /** Singular noun for the records, e.g. "lead". */
    label: string;
    /** Endpoint to POST to. */
    url: string;
}>();

const emit = defineEmits<{ close: []; saved: [] }>();

/** Per-field: what the user chose to do with it. */
const modes = ref<Record<string, Mode>>({});

const form = useForm<{
    ids: number[];
    values: Record<string, string | number | boolean>;
    clear: string[];
}>({ ids: [], values: {}, clear: [] });

/** Local draft of each field's value, kept separate so switching modes back and
 *  forth does not lose what was typed. */
const drafts = ref<Record<string, string | number | boolean>>({});

function reset() {
    const nextModes: Record<string, Mode> = {};
    const nextDrafts: Record<string, string | number | boolean> = {};

    for (const field of fields) {
        nextModes[field.key] = 'keep';
        nextDrafts[field.key] = field.type === 'boolean' ? true : '';
    }

    modes.value = nextModes;
    drafts.value = nextDrafts;
    form.clearErrors();
}

watch(() => open, (isOpen) => isOpen && reset(), { immediate: true });

/** Fields the user has actually chosen to touch. */
const changing = computed(() => fields.filter((f) => modes.value[f.key] !== 'keep'));

const canSubmit = computed(() => changing.value.length > 0 && ids.length > 0);

function submit() {
    if (!canSubmit.value) {
        return;
    }

    const values: Record<string, string | number | boolean> = {};
    const clear: string[] = [];

    for (const field of fields) {
        const mode = modes.value[field.key];

        if (mode === 'set') {
            values[field.key] = drafts.value[field.key];
        } else if (mode === 'clear') {
            clear.push(field.key);
        }
    }

    form.defaults({ ids: [...ids], values, clear });
    form.reset();

    form.post(url, {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}

/** Server-side errors arrive keyed as `values.field_name`. */
function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`values.${key}`];
}

const summary = computed(() => `${ids.length} ${label}${ids.length === 1 ? '' : 's'}`);
</script>

<template>
    <Modal
        :open="open"
        :title="`Edit ${summary}`"
        description="Only the fields you switch to “Set” or “Clear” are written. Everything else is left exactly as it is."
        size="xl"
        @close="emit('close')"
    >
        <form class="space-y-5" @submit.prevent="submit">
            <p
                v-if="form.errors.ids"
                class="rounded-md bg-red-50 px-3 py-2 text-sm font-medium text-red-700"
            >
                {{ form.errors.ids }}
            </p>

            <div
                v-for="field in fields"
                :key="field.key"
                class="grid grid-cols-1 items-start gap-3 border-b border-slate-100 pb-4 last:border-0 last:pb-0 sm:grid-cols-[10rem_9rem_1fr]"
            >
                <p class="pt-2 text-sm font-medium text-slate-700">{{ field.label }}</p>

                <select
                    v-model="modes[field.key]"
                    class="input"
                    :aria-label="`What to do with ${field.label}`"
                >
                    <option value="keep">Leave unchanged</option>
                    <option value="set">Set to…</option>
                    <option v-if="field.nullable" value="clear">Clear</option>
                </select>

                <div>
                    <template v-if="modes[field.key] === 'set'">
                        <select
                            v-if="field.type === 'select'"
                            v-model="drafts[field.key]"
                            class="input"
                            :aria-label="`New ${field.label}`"
                        >
                            <option value="" disabled>Choose a value…</option>
                            <option
                                v-for="option in field.options ?? []"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <select
                            v-else-if="field.type === 'boolean'"
                            v-model="drafts[field.key]"
                            class="input"
                            :aria-label="`New ${field.label}`"
                        >
                            <option :value="true">Yes</option>
                            <option :value="false">No</option>
                        </select>

                        <input
                            v-else
                            v-model="drafts[field.key]"
                            type="text"
                            class="input"
                            autocomplete="off"
                            :aria-label="`New ${field.label}`"
                            :placeholder="`New ${field.label.toLowerCase()} for all ${summary}`"
                        />

                        <p v-if="errorFor(field.key)" class="mt-1 text-xs font-medium text-red-600">
                            {{ errorFor(field.key) }}
                        </p>
                        <p v-else-if="field.hint" class="mt-1 text-xs text-slate-500">{{ field.hint }}</p>
                    </template>

                    <p v-else-if="modes[field.key] === 'clear'" class="pt-2 text-sm text-amber-700">
                        Will be emptied on all {{ summary }}.
                    </p>

                    <p v-else class="pt-2 text-sm text-slate-400">
                        Keeps whatever each record already has.
                    </p>
                </div>
            </div>

            <p
                v-if="changing.length > 0"
                class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800"
            >
                This overwrites
                <strong>{{ changing.map((f) => f.label).join(', ') }}</strong>
                on all {{ summary }}, including any that already have a value. It cannot be undone.
            </p>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" :disabled="form.processing" @click="emit('close')">
                Cancel
            </button>

            <button
                type="button"
                class="btn-primary"
                :disabled="form.processing || !canSubmit"
                :title="canSubmit ? undefined : 'Switch at least one field to “Set” or “Clear” first.'"
                @click="submit"
            >
                {{ form.processing ? 'Applying…' : `Apply to ${summary}` }}
            </button>
        </template>
    </Modal>
</template>
