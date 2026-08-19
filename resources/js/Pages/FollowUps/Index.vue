<script setup lang="ts">
/**
 * Follow-up tasks.
 *
 * A task is a reminder with a date. Nothing on this page sends, schedules or
 * generates anything - completing one does not trigger an email and an overdue
 * one does not chase anybody.
 *
 * AI-suggested tasks are labelled. Knowing which items came from a machine is
 * the difference between a to-do list you trust and one you stop reading.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { routes } from '@/routes';
import type { BadgeColor, Paginated, SelectOption } from '@/types/models';

interface Task {
    id: number;
    title: string;
    notes: string | null;
    status: string;
    status_color: BadgeColor;
    priority: string | null;
    source: string;
    from_ai: boolean;
    due_on: string | null;
    overdue: boolean;
    completed_at: string | null;
    lead_id: number;
    company: string | null;
    contact: string | null;
    reply: {
        id: number;
        subject: string | null;
        classification: string | null;
        received_at: string | null;
    } | null;
}

const { tasks, filters, filterOptions, counts, leads } = defineProps<{
    tasks: Paginated<Task>;
    filters: Record<string, string | undefined>;
    filterOptions: {
        statuses: SelectOption[];
        priorities: SelectOption[];
        sources: SelectOption[];
    };
    counts: { open: number; overdue: number; today: number; suggested: number };
    leads: SelectOption[];
}>();

const showAdd = ref(false);

const form = useForm({
    lead_id: '' as string | number,
    title: '',
    notes: '',
    priority: '',
    due_on: '',
});

function add() {
    form.post(routes.followUps.store(), {
        preserveScroll: true,
        onSuccess: () => {
            showAdd.value = false;
            form.reset();
        },
    });
}

function act(url: string) {
    router.post(url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Follow-ups" />

    <PageHeader title="Follow-ups" :subtitle="`${counts.open} open`">
        <template #actions>
            <Link :href="routes.replies.index()" class="btn-secondary">Replies</Link>
            <button type="button" class="btn-primary" @click="showAdd = true">+ New follow-up</button>
        </template>
    </PageHeader>

    <!-- Counts as filters, since they are the reason you opened this page. -->
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Link
            :href="routes.followUps.index({ status: 'Open' })"
            class="card px-4 py-3 transition-shadow hover:shadow-md"
        >
            <p class="text-xs text-slate-500">Open</p>
            <p class="text-xl font-semibold text-slate-900">{{ counts.open }}</p>
        </Link>
        <Link
            :href="routes.followUps.index({ due: 'overdue' })"
            class="card px-4 py-3 transition-shadow hover:shadow-md"
            :class="counts.overdue > 0 ? 'border-red-200 bg-red-50/60' : ''"
        >
            <p class="text-xs text-slate-500">Overdue</p>
            <p class="text-xl font-semibold" :class="counts.overdue > 0 ? 'text-red-700' : 'text-slate-900'">
                {{ counts.overdue }}
            </p>
        </Link>
        <Link
            :href="routes.followUps.index({ due: 'today' })"
            class="card px-4 py-3 transition-shadow hover:shadow-md"
        >
            <p class="text-xs text-slate-500">Due today</p>
            <p class="text-xl font-semibold text-slate-900">{{ counts.today }}</p>
        </Link>
        <Link
            :href="routes.followUps.index({ source: 'ai', status: 'Open' })"
            class="card px-4 py-3 transition-shadow hover:shadow-md"
        >
            <p class="text-xs text-slate-500">Suggested by AI</p>
            <p class="text-xl font-semibold text-slate-900">{{ counts.suggested }}</p>
        </Link>
    </div>

    <div class="card overflow-hidden">
        <FilterBar
            :base-path="routes.followUps.index()"
            :filters="filters"
            :definitions="[
                { key: 'status', label: 'states', options: filterOptions.statuses },
                { key: 'source', label: 'sources', options: filterOptions.sources },
                {
                    key: 'due',
                    label: 'dates',
                    options: [
                        { value: 'overdue', label: 'Overdue' },
                        { value: 'today', label: 'Due today' },
                    ],
                },
            ]"
        />

        <EmptyState
            v-if="tasks.data.length === 0"
            icon="clock"
            title="Nothing to follow up"
            message="Follow-ups appear here when you add one, or when the local model suggests one after reading a reply."
        >
            <button type="button" class="btn-primary" @click="showAdd = true">+ New follow-up</button>
        </EmptyState>

        <ul v-else class="divide-y divide-slate-100">
            <li v-for="task in tasks.data" :key="task.id" class="flex items-start gap-3 px-5 py-4">
                <button
                    v-if="task.status === 'Open'"
                    type="button"
                    class="mt-0.5 size-4 shrink-0 rounded border border-slate-300 transition-colors hover:border-emerald-500 hover:bg-emerald-50"
                    :aria-label="`Mark ${task.title} done`"
                    @click="act(routes.followUps.complete(task.id))"
                />
                <span v-else class="mt-0.5 shrink-0 text-emerald-600" aria-hidden="true">✓</span>

                <div class="min-w-0 grow">
                    <div class="flex flex-wrap items-center gap-2">
                        <p
                            class="text-sm font-medium"
                            :class="task.status === 'Open' ? 'text-slate-900' : 'text-slate-400 line-through'"
                        >
                            {{ task.title }}
                        </p>

                        <Badge v-if="task.status !== 'Open'" :color="task.status_color" size="sm">
                            {{ task.status }}
                        </Badge>

                        <!-- Labelled, always. -->
                        <Badge v-if="task.from_ai" color="violet" size="sm">AI suggested</Badge>

                        <span
                            v-if="task.due_on"
                            class="text-xs"
                            :class="task.overdue ? 'font-medium text-red-600' : 'text-slate-500'"
                        >
                            {{ task.overdue ? 'overdue · ' : '' }}{{ task.due_on }}
                        </span>
                    </div>

                    <p v-if="task.notes" class="mt-0.5 text-xs text-slate-600">{{ task.notes }}</p>

                    <p class="mt-1 text-xs text-slate-500">
                        <Link
                            :href="routes.leads.show(task.lead_id)"
                            class="font-medium text-indigo-600 hover:underline"
                        >
                            {{ task.company ?? `Lead #${task.lead_id}` }}
                        </Link>
                        <span v-if="task.contact"> · {{ task.contact }}</span>
                    </p>

                    <p v-if="task.reply" class="mt-1 text-xs text-slate-400">
                        After
                        <Link :href="routes.replies.index()" class="text-indigo-600 hover:underline">
                            their reply
                        </Link>
                        <span v-if="task.reply.classification"> ({{ task.reply.classification }})</span>
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <button
                        v-if="task.status === 'Open'"
                        type="button"
                        class="btn-ghost px-2 py-1 text-xs"
                        title="Not worth doing - kept separate from done"
                        @click="act(routes.followUps.dismiss(task.id))"
                    >
                        Dismiss
                    </button>
                    <button
                        v-else
                        type="button"
                        class="btn-ghost px-2 py-1 text-xs"
                        @click="act(routes.followUps.reopen(task.id))"
                    >
                        Reopen
                    </button>
                </div>
            </li>
        </ul>

        <Pagination v-if="tasks.data.length" :meta="tasks.meta" />
    </div>

    <Modal
        :open="showAdd"
        title="New follow-up"
        description="A reminder with a date. Nothing here sends or schedules anything."
        size="lg"
        @close="showAdd = false"
    >
        <form class="space-y-4" @submit.prevent="add">
            <FormField v-slot="{ id }" label="Lead" :error="form.errors.lead_id" required>
                <select :id="id" v-model="form.lead_id" class="input">
                    <option value="" disabled>Choose a lead…</option>
                    <option v-for="lead in leads" :key="lead.value" :value="lead.value">
                        {{ lead.label }}
                    </option>
                </select>
            </FormField>

            <FormField
                v-slot="{ id }"
                label="What needs doing"
                :error="form.errors.title"
                hint="An action, not a sentiment. 'Send the workflow overview she asked for' beats 'nurture'."
                required
            >
                <input :id="id" v-model="form.title" type="text" class="input" autocomplete="off" />
            </FormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id }" label="Due" :error="form.errors.due_on">
                    <input :id="id" v-model="form.due_on" type="date" class="input" />
                </FormField>

                <FormField v-slot="{ id }" label="Priority" :error="form.errors.priority">
                    <select :id="id" v-model="form.priority" class="input">
                        <option value="">—</option>
                        <option v-for="p in filterOptions.priorities" :key="p.value" :value="p.value">
                            {{ p.label }}
                        </option>
                    </select>
                </FormField>
            </div>

            <FormField v-slot="{ id }" label="Notes" :error="form.errors.notes">
                <textarea :id="id" v-model="form.notes" rows="3" class="input" />
            </FormField>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" @click="showAdd = false">Cancel</button>
            <button type="button" class="btn-primary" :disabled="form.processing" @click="add">
                {{ form.processing ? 'Adding…' : 'Add follow-up' }}
            </button>
        </template>
    </Modal>
</template>
