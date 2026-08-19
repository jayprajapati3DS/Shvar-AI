<script setup lang="ts">
/**
 * Replies received in Outlook from people in the CRM.
 *
 * The sync is a button, not a timer. Reading someone's mailbox should be
 * something they asked for and can see happening - a background poll quietly
 * opening mail is a different thing from a button that reports what it did.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { routes } from '@/routes';
import type { BadgeColor, Paginated, SelectOption } from '@/types/models';

interface Reply {
    id: number;
    from_name: string | null;
    from_address: string;
    subject: string | null;
    excerpt: string;
    body: string | null;
    received_at: string | null;
    classification: string | null;
    classification_color: BadgeColor | null;
    summary: string | null;
    signals: { quotes?: string[]; asks?: string[]; mentioned_dates?: string[] };
    needs_attention: boolean;
    reviewed_at: string | null;
    lead_id: number | null;
    company: string | null;
    job_title: string | null;
    answers_draft: { id: number; subject: string } | null;
    tasks: { id: number; title: string; status: string; due_on: string | null }[];
}

const { replies, filters, filterOptions, counts } = defineProps<{
    replies: Paginated<Reply>;
    filters: Record<string, string | undefined>;
    filterOptions: { classifications: SelectOption[] };
    counts: { unreviewed: number; needing_attention: number };
}>();

const syncing = ref(false);
const busy = ref<number | null>(null);
const expanded = ref<number | null>(null);

function sync() {
    syncing.value = true;
    router.post(
        routes.replies.sync(),
        {},
        { preserveScroll: true, onFinish: () => (syncing.value = false) },
    );
}

function act(id: number, url: string) {
    busy.value = id;
    router.post(url, {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <Head title="Replies" />

    <PageHeader
        title="Replies"
        :subtitle="`${counts.needing_attention} needing attention · ${counts.unreviewed} unreviewed`"
    >
        <template #actions>
            <Link :href="routes.followUps.index()" class="btn-secondary">Follow-ups</Link>
            <button type="button" class="btn-primary" :disabled="syncing" @click="sync">
                {{ syncing ? 'Reading Outlook…' : 'Check Outlook for replies' }}
            </button>
        </template>
    </PageHeader>

    <!-- What this does and does not read. Worth saying plainly, on the page. -->
    <div class="card mb-6 border-indigo-100 bg-indigo-50/60 p-4">
        <p class="text-sm text-indigo-900">
            <strong>Only mail from your CRM contacts is read.</strong>
            Checking connects to the Outlook desktop app on this machine and looks for messages whose
            sender matches a contact record. Everything else in your mailbox is left alone — not
            opened, not stored, not shown to the AI. Nothing is read on a timer; it happens when you
            press the button.
        </p>
    </div>

    <div class="card overflow-hidden">
        <FilterBar
            :base-path="routes.replies.index()"
            :filters="filters"
            :definitions="[
                { key: 'classification', label: 'readings', options: filterOptions.classifications },
                {
                    key: 'reviewed',
                    label: 'states',
                    options: [
                        { value: 'no', label: 'Not reviewed' },
                        { value: 'yes', label: 'Reviewed' },
                    ],
                },
            ]"
        />

        <EmptyState
            v-if="replies.data.length === 0"
            icon="inbox"
            title="No replies yet"
            :message="
                Object.values(filters).some(Boolean)
                    ? 'No reply matches those filters.'
                    : 'Press Check Outlook for replies. Only messages from people already in your CRM are imported.'
            "
        >
            <button type="button" class="btn-primary" :disabled="syncing" @click="sync">
                {{ syncing ? 'Reading Outlook…' : 'Check Outlook for replies' }}
            </button>
        </EmptyState>

        <ul v-else class="divide-y divide-slate-100">
            <li
                v-for="reply in replies.data"
                :key="reply.id"
                class="px-5 py-4"
                :class="reply.needs_attention ? '' : 'bg-slate-50/50'"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 grow">
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium text-slate-900">
                                {{ reply.from_name ?? reply.from_address }}
                            </span>

                            <Badge
                                v-if="reply.classification"
                                :color="reply.classification_color ?? 'slate'"
                                size="sm"
                            >
                                {{ reply.classification }}
                            </Badge>
                            <Badge v-else color="slate" size="sm">Not read yet</Badge>

                            <span v-if="reply.reviewed_at" class="text-xs text-slate-400">reviewed</span>
                        </div>

                        <p class="text-sm text-slate-700">{{ reply.subject ?? '(no subject)' }}</p>

                        <!-- The model's reading, clearly marked as a reading. -->
                        <p v-if="reply.summary" class="mt-1 text-sm text-slate-600">
                            <span class="font-medium text-slate-500">Read as:</span>
                            {{ reply.summary }}
                        </p>

                        <p class="mt-1 text-xs text-slate-500">
                            {{ reply.company ?? 'no company' }}
                            <span v-if="reply.job_title"> · {{ reply.job_title }}</span>
                            · {{ formatDate(reply.received_at) }}
                        </p>

                        <p v-if="reply.answers_draft" class="mt-1 text-xs text-slate-400">
                            Replying to your email
                            <Link
                                :href="routes.emailDrafts.show(reply.answers_draft.id)"
                                class="font-medium text-indigo-600 hover:underline"
                            >
                                “{{ reply.answers_draft.subject }}”
                            </Link>
                        </p>

                        <div v-if="reply.tasks.length" class="mt-2 flex flex-wrap gap-1.5">
                            <Badge v-for="task in reply.tasks" :key="task.id" color="violet" size="sm">
                                {{ task.title }}<span v-if="task.due_on"> · {{ task.due_on }}</span>
                            </Badge>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <button
                            v-if="!reply.classification"
                            type="button"
                            class="btn-secondary px-2 py-1 text-xs"
                            :disabled="busy === reply.id"
                            @click="act(reply.id, routes.replies.classify(reply.id))"
                        >
                            {{ busy === reply.id ? 'Reading…' : 'Read with AI' }}
                        </button>

                        <button
                            v-else
                            type="button"
                            class="btn-ghost px-2 py-1 text-xs"
                            :disabled="busy === reply.id"
                            @click="act(reply.id, routes.replies.classify(reply.id))"
                        >
                            Re-read
                        </button>

                        <button
                            type="button"
                            class="btn-ghost px-2 py-1 text-xs"
                            @click="expanded = expanded === reply.id ? null : reply.id"
                        >
                            {{ expanded === reply.id ? 'Hide' : 'Read it' }}
                        </button>

                        <button
                            v-if="!reply.reviewed_at"
                            type="button"
                            class="btn-ghost px-2 py-1 text-xs"
                            @click="act(reply.id, routes.replies.review(reply.id))"
                        >
                            Mark reviewed
                        </button>

                        <Link
                            v-if="reply.lead_id"
                            :href="routes.leads.show(reply.lead_id)"
                            class="btn-ghost px-2 py-1 text-xs"
                        >
                            Open lead
                        </Link>
                    </div>
                </div>

                <!-- The actual message, so the reading can always be checked. -->
                <div v-if="expanded === reply.id" class="mt-3 space-y-3">
                    <pre class="max-h-72 overflow-y-auto whitespace-pre-wrap rounded-md border border-slate-200 bg-white px-3 py-2 font-sans text-sm leading-relaxed text-slate-700">{{ reply.body }}</pre>

                    <div v-if="reply.signals.quotes?.length" class="text-xs text-slate-500">
                        <span class="font-medium">Based on:</span>
                        <span v-for="q in reply.signals.quotes" :key="q" class="italic"> “{{ q }}” </span>
                    </div>

                    <div v-if="reply.signals.asks?.length" class="text-xs text-slate-600">
                        <span class="font-medium">They asked for:</span>
                        {{ reply.signals.asks.join('; ') }}
                    </div>

                    <div v-if="reply.signals.mentioned_dates?.length" class="text-xs text-slate-600">
                        <span class="font-medium">Dates mentioned:</span>
                        {{ reply.signals.mentioned_dates.join('; ') }}
                    </div>
                </div>
            </li>
        </ul>

        <Pagination v-if="replies.data.length" :meta="replies.meta" />
    </div>
</template>
