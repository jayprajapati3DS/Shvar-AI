<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import { routes } from '@/routes';
import type { Activity, BadgeColor, SelectOption } from '@/types/models';

/**
 * The activity timeline. The structure is complete and ready for the Phase 2
 * entry types (Email, Follow-up) - Phase 1 lets you log Note / Call / Meeting
 * by hand, and records status changes automatically.
 */
const { activities, subject, subjectId, types } = defineProps<{
    activities: Activity[];
    subject: 'leads' | 'companies' | 'contacts';
    subjectId: number;
    /** Manual entry types; omit to render the timeline read-only. */
    types?: SelectOption[];
}>();

const showForm = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const form = ref({ type: 'Note', title: '', body: '' });

const dots: Record<BadgeColor, string> = {
    slate: 'bg-slate-400',
    sky: 'bg-sky-500',
    indigo: 'bg-indigo-500',
    amber: 'bg-amber-500',
    violet: 'bg-violet-500',
    orange: 'bg-orange-500',
    emerald: 'bg-emerald-500',
    red: 'bg-red-500',
};

function open() {
    form.value = { type: types?.[0]?.value?.toString() ?? 'Note', title: '', body: '' };
    errors.value = {};
    showForm.value = true;
}

function submit() {
    processing.value = true;

    router.post(routes.activities.store(subject, subjectId), form.value, {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
        },
        onError: (received) => {
            errors.value = received as Record<string, string>;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function remove(activityId: number) {
    router.delete(routes.activities.destroy(subject, subjectId, activityId), {
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="card">
        <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Activity Timeline</h2>
                <p class="text-xs text-slate-500">
                    Notes, calls and meetings. Emails and follow-ups arrive in Phase 2.
                </p>
            </div>

            <button v-if="types?.length" type="button" class="btn-secondary" @click="open">
                Log activity
            </button>
        </header>

        <EmptyState
            v-if="activities.length === 0"
            icon="clock"
            title="No activity yet"
            message="Nothing has been logged against this record."
        />

        <ol v-else class="divide-y divide-slate-100">
            <li v-for="activity in activities" :key="activity.id" class="group flex gap-3 px-5 py-4">
                <div class="mt-1.5 flex flex-col items-center gap-1">
                    <span class="size-2 rounded-full" :class="dots[activity.color]" aria-hidden="true" />
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :color="activity.color" size="sm">{{ activity.type }}</Badge>
                        <p class="text-sm font-medium text-slate-900">{{ activity.title }}</p>
                    </div>

                    <p v-if="activity.body" class="mt-1 whitespace-pre-line text-sm text-slate-600">
                        {{ activity.body }}
                    </p>

                    <p class="mt-1 text-xs text-slate-400">{{ activity.occurred_for_humans }}</p>
                </div>

                <button
                    type="button"
                    class="shrink-0 self-start rounded p-1 text-slate-300 opacity-0 transition hover:bg-slate-100 hover:text-red-600 focus:opacity-100 group-hover:opacity-100"
                    aria-label="Delete activity"
                    @click="remove(activity.id)"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325A41.4 41.4 0 0 1 10 4Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </li>
        </ol>
    </section>

    <Modal
        :open="showForm"
        title="Log activity"
        description="Recorded locally against this record."
        size="lg"
        @close="showForm = false"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <FormField v-slot="{ id }" label="Type" :error="errors.type" required>
                <select :id="id" v-model="form.type" class="input">
                    <option v-for="type in types" :key="type.value" :value="type.value">
                        {{ type.label }}
                    </option>
                </select>
            </FormField>

            <FormField v-slot="{ id }" label="Title" :error="errors.title" required>
                <input :id="id" v-model="form.title" type="text" class="input" placeholder="Intro call with Dr. Shah" />
            </FormField>

            <FormField v-slot="{ id }" label="Details" :error="errors.body">
                <textarea :id="id" v-model="form.body" rows="4" class="input" />
            </FormField>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" @click="showForm = false">Cancel</button>
            <button type="button" class="btn-primary" :disabled="processing" @click="submit">
                {{ processing ? 'Saving…' : 'Save activity' }}
            </button>
        </template>
    </Modal>
</template>
