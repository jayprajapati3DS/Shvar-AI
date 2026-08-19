<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import SettingsTabs from '@/Components/Ai/SettingsTabs.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { useToasts } from '@/composables/useToasts';
import { routes } from '@/routes';
import type { AiRequestLog, AiRequestTypeOption } from '@/types/ai';
import type { Paginated, SelectOption } from '@/types/models';
import type { Column } from '@/types/ui';

const { requests, filters, filterOptions } = defineProps<{
    requests: Paginated<AiRequestLog>;
    filters: Record<string, string | undefined>;
    filterOptions: {
        statuses: SelectOption[];
        types: AiRequestTypeOption[];
        models: string[];
    };
}>();

const { error } = useToasts();

const columns: Column[] = [
    { label: 'Date / time' },
    { label: 'Request Type' },
    { label: 'Model' },
    { label: 'Status' },
    { label: 'Execution', class: 'text-right' },
    { label: 'Actions', class: 'text-right', hidden: true },
];

const detail = ref<AiRequestLog | null>(null);
const loadingDetail = ref(false);
const confirmingClear = ref(false);
const processing = ref(false);

/**
 * Prompt and response are fetched per row rather than shipped with the list:
 * a page of 20 completions would otherwise be megabytes of props.
 */
async function open(row: AiRequestLog) {
    loadingDetail.value = true;
    detail.value = row;

    try {
        const response = await fetch(routes.settings.ai.log(row.id), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = (await response.json()) as { request: AiRequestLog };
        detail.value = payload.request;
    } catch {
        detail.value = null;
        error('Could not load that log entry.');
    } finally {
        loadingDetail.value = false;
    }
}

function remove(row: AiRequestLog) {
    router.delete(routes.settings.ai.log(row.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (detail.value?.id === row.id) {
                detail.value = null;
            }
        },
    });
}

function clearAll() {
    processing.value = true;

    router.delete(routes.settings.ai.clearLogs(), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            confirmingClear.value = false;
        },
    });
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <Head title="AI Logs" />

    <PageHeader
        title="Local AI Activity"
        :subtitle="`${requests.meta.total} request(s) recorded on this machine`"
    >
        <template #actions>
            <button
                type="button"
                class="btn-danger"
                :disabled="requests.meta.total === 0"
                @click="confirmingClear = true"
            >
                Clear log
            </button>
        </template>
    </PageHeader>

    <SettingsTabs />

    <div class="mb-6 flex items-start gap-2.5 rounded-lg border border-slate-200 bg-white px-4 py-3">
        <svg class="mt-0.5 size-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
                fill-rule="evenodd"
                d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z"
                clip-rule="evenodd"
            />
        </svg>
        <p class="text-sm text-slate-600">
            Every AI request is recorded in your local database so you can see exactly what was sent to the model and
            what came back. These records are never transmitted anywhere.
        </p>
    </div>

    <div class="card overflow-hidden">
        <FilterBar
            :base-path="routes.settings.ai.logs()"
            :filters="filters"
            search-placeholder="Search prompts and responses…"
            :definitions="[
                { key: 'status', label: 'statuses', options: filterOptions.statuses },
                {
                    key: 'type',
                    label: 'types',
                    options: filterOptions.types.map((t) => ({ value: t.value, label: t.label })),
                },
                { key: 'model', label: 'models', options: filterOptions.models },
            ]"
        />

        <EmptyState
            v-if="requests.data.length === 0"
            icon="clock"
            title="No AI requests logged"
            :message="
                Object.values(filters).some(Boolean)
                    ? 'No entry matches those filters. Try clearing them.'
                    : 'Run a prompt in the AI Playground and it will appear here.'
            "
        />

        <template v-else>
            <DataTable :columns="columns" :rows="requests.data" :row-key="(row) => row.id">
                <template #row="{ row }">
                    <td class="td whitespace-nowrap">
                        <button type="button" class="font-medium text-slate-900 hover:text-indigo-600" @click="open(row)">
                            {{ formatDateTime(row.created_at) }}
                        </button>
                        <p class="text-xs text-slate-400">{{ row.created_for_humans }}</p>
                    </td>

                    <td class="td">
                        <Badge :color="row.request_type_color" size="sm">{{ row.request_type_label }}</Badge>
                        <Badge v-if="row.structured" color="indigo" size="sm" class="ml-1">JSON</Badge>
                    </td>

                    <td class="td font-mono text-xs">{{ row.model }}</td>

                    <td class="td">
                        <Badge :color="row.status_color" size="sm">{{ row.status_label }}</Badge>
                    </td>

                    <td class="td text-right tabular-nums">
                        {{ row.seconds !== null ? `${row.seconds.toFixed(2)}s` : '—' }}
                    </td>

                    <td class="td whitespace-nowrap text-right">
                        <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="open(row)">View</button>
                        <button
                            type="button"
                            class="btn-ghost px-2 py-1 text-xs text-red-600 hover:bg-red-50 hover:text-red-700"
                            @click="remove(row)"
                        >
                            Delete
                        </button>
                    </td>
                </template>
            </DataTable>

            <Pagination :meta="requests.meta" />
        </template>
    </div>

    <!-- Detail -->
    <Modal
        :open="detail !== null"
        :title="detail ? `AI request #${detail.id}` : 'AI request'"
        size="2xl"
        @close="detail = null"
    >
        <div v-if="detail" class="space-y-5">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Status</p>
                    <p class="mt-1"><Badge :color="detail.status_color" size="sm">{{ detail.status_label }}</Badge></p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Model</p>
                    <p class="mt-1 break-all font-mono text-xs text-slate-800">{{ detail.model }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Execution</p>
                    <p class="mt-1 text-sm tabular-nums text-slate-800">
                        {{ detail.seconds !== null ? `${detail.seconds.toFixed(2)}s` : '—' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Temperature</p>
                    <p class="mt-1 text-sm tabular-nums text-slate-800">{{ detail.temperature ?? '—' }}</p>
                </div>
            </div>

            <p v-if="loadingDetail" class="text-sm text-slate-500">Loading…</p>

            <template v-else>
                <div v-if="detail.error_message">
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-red-600">Error detail</p>
                    <pre
                        class="max-h-40 overflow-auto whitespace-pre-wrap rounded-md bg-red-50 px-3 py-2 font-mono text-xs text-red-800"
                        >{{ detail.error_message }}</pre
                    >
                </div>

                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Prompt</p>
                    <pre
                        class="max-h-64 overflow-auto whitespace-pre-wrap rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700"
                        >{{ detail.prompt ?? '—' }}</pre
                    >
                </div>

                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Response</p>
                    <pre
                        class="max-h-80 overflow-auto whitespace-pre-wrap rounded-md bg-slate-900 px-3 py-2 font-mono text-xs text-slate-100"
                        >{{ detail.response ?? '—' }}</pre
                    >
                </div>

                <dl class="grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-xs sm:grid-cols-4">
                    <div>
                        <dt class="text-slate-500">Provider</dt>
                        <dd class="mt-0.5 capitalize text-slate-800">{{ detail.provider }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Template</dt>
                        <dd class="mt-0.5 font-mono text-slate-800">{{ detail.template ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Prompt tokens</dt>
                        <dd class="mt-0.5 tabular-nums text-slate-800">{{ detail.prompt_tokens ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Response tokens</dt>
                        <dd class="mt-0.5 tabular-nums text-slate-800">{{ detail.response_tokens ?? '—' }}</dd>
                    </div>
                </dl>
            </template>
        </div>

        <template #footer>
            <button type="button" class="btn-secondary" @click="detail = null">Close</button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="confirmingClear"
        title="Clear the local AI log?"
        message="Every recorded prompt and response is permanently deleted from your local database. This cannot be undone."
        confirm-label="Clear log"
        :processing="processing"
        @cancel="confirmingClear = false"
        @confirm="clearAll"
    />
</template>
