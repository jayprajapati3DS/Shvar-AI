<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { routes } from '@/routes';
import type { ImportPreview } from '@/types/models';

/**
 * `preview` is null until POST /import/preview re-renders this page with the
 * analysis. The file itself is held only in this component, and re-sent on
 * commit so the server re-parses rather than trusting the browser.
 */
const { template, preview } = defineProps<{
    template: {
        columns: string[];
        statuses: string[];
        priorities: string[];
        maxRows: number;
    };
    preview: ImportPreview | null;
}>();

const file = ref<File | null>(null);
const importDuplicates = ref(false);
const showAllRows = ref(false);

const previewForm = useForm<{ file: File | null }>({ file: null });

const visibleRows = computed(() => {
    const rows = preview?.rows ?? [];

    return showAllRows.value ? rows : rows.slice(0, 25);
});

/** Rows that will actually be written, given the current duplicate setting. */
const willImport = computed(() => {
    if (!preview) {
        return 0;
    }

    return importDuplicates.value ? preview.valid + preview.duplicates : preview.valid;
});

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
}

function analyse() {
    if (!file.value) {
        return;
    }

    previewForm.file = file.value;
    previewForm.post(routes.import.preview(), { preserveScroll: true, forceFormData: true });
}

function commit() {
    if (!file.value) {
        return;
    }

    // Sent as a fresh request with the same file: the server re-parses rather
    // than trusting anything the browser hands back from the preview.
    router.post(
        routes.import.store(),
        { file: file.value, import_duplicates: importDuplicates.value },
        { forceFormData: true },
    );
}

function reset() {
    file.value = null;
    previewForm.reset();
    router.get(routes.import.create(), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Import CSV" />

    <PageHeader
        title="Import CSV"
        subtitle="Creates companies, contacts and leads from one file. Parsed locally — the file is never uploaded anywhere."
    >
        <template #actions>
            <a :href="routes.import.template()" class="btn-secondary">Download template</a>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <!-- Step 1: choose a file -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">1 · Choose a file</h2>
                    <p class="text-xs text-slate-500">
                        CSV, up to 10 MB and {{ template.maxRows }} rows.
                    </p>
                </header>

                <div class="space-y-4 px-5 py-4">
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
                        @change="onFileChange"
                    />

                    <p v-if="previewForm.errors.file" class="text-xs font-medium text-red-600">
                        {{ previewForm.errors.file }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="btn-primary"
                            :disabled="!file || previewForm.processing"
                            @click="analyse"
                        >
                            {{ previewForm.processing ? 'Checking…' : 'Preview import' }}
                        </button>

                        <button v-if="preview" type="button" class="btn-secondary" @click="reset">
                            Start over
                        </button>
                    </div>
                </div>
            </section>

            <!-- Step 2: preview -->
            <section v-if="preview" class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">2 · Review</h2>
                    <p class="text-xs text-slate-500">Nothing has been saved yet.</p>
                </header>

                <div class="grid grid-cols-2 gap-4 border-b border-slate-200 px-5 py-4 sm:grid-cols-4">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Rows</p>
                        <p class="text-lg font-semibold tabular-nums text-slate-900">{{ preview.total }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Ready</p>
                        <p class="text-lg font-semibold tabular-nums text-emerald-700">{{ preview.valid }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Duplicates</p>
                        <p class="text-lg font-semibold tabular-nums text-amber-700">{{ preview.duplicates }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Invalid</p>
                        <p class="text-lg font-semibold tabular-nums text-red-700">{{ preview.invalid }}</p>
                    </div>
                </div>

                <!-- Warnings -->
                <div v-if="preview.unmapped.length || preview.truncated" class="space-y-2 border-b border-slate-200 px-5 py-3">
                    <p v-if="preview.unmapped.length" class="text-xs text-amber-700">
                        <span class="font-semibold">Ignored columns:</span>
                        {{ preview.unmapped.join(', ') }} — these headers were not recognised and will be skipped.
                    </p>
                    <p v-if="preview.truncated" class="text-xs text-amber-700">
                        <span class="font-semibold">File truncated:</span>
                        only the first {{ template.maxRows }} rows were read.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="th">Row</th>
                                <th class="th">Company</th>
                                <th class="th">Contact</th>
                                <th class="th">Email</th>
                                <th class="th">Status</th>
                                <th class="th">Result</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in visibleRows"
                                :key="row.line"
                                :class="
                                    row.errors.length
                                        ? 'bg-red-50/50'
                                        : row.duplicate
                                          ? 'bg-amber-50/50'
                                          : ''
                                "
                            >
                                <td class="td tabular-nums text-slate-400">{{ row.line }}</td>
                                <td class="td">{{ row.data.company_name || '—' }}</td>
                                <td class="td">
                                    {{ [row.data.first_name, row.data.last_name].filter(Boolean).join(' ') || '—' }}
                                </td>
                                <td class="td">{{ row.data.email || '—' }}</td>
                                <td class="td">{{ row.data.lead_status || 'New' }}</td>
                                <td class="td">
                                    <div v-if="row.errors.length" class="space-y-1">
                                        <Badge color="red" size="sm">Invalid</Badge>
                                        <p v-for="error in row.errors" :key="error" class="text-xs text-red-700">
                                            {{ error }}
                                        </p>
                                    </div>

                                    <div v-else-if="row.duplicate" class="space-y-1">
                                        <Badge color="amber" size="sm">Duplicate</Badge>
                                        <p
                                            v-for="reason in row.duplicate_reasons"
                                            :key="reason"
                                            class="text-xs text-amber-700"
                                        >
                                            {{ reason }}
                                        </p>
                                    </div>

                                    <Badge v-else color="emerald" size="sm">Ready</Badge>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="preview.rows.length > 25" class="border-t border-slate-200 px-5 py-3">
                    <button type="button" class="btn-ghost text-sm" @click="showAllRows = !showAllRows">
                        {{ showAllRows ? 'Show first 25 only' : `Show all ${preview.rows.length} rows` }}
                    </button>
                </div>
            </section>

            <!-- Step 3: commit -->
            <section v-if="preview" class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">3 · Import</h2>
                </header>

                <div class="space-y-4 px-5 py-4">
                    <label class="flex items-start gap-2.5">
                        <input
                            v-model="importDuplicates"
                            type="checkbox"
                            class="mt-0.5 size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <span class="text-sm text-slate-700">
                            Import duplicate rows too
                            <span class="block text-xs text-slate-500">
                                Existing companies and contacts are reused rather than recreated — only a new lead is
                                added. Leave unchecked to skip them entirely.
                            </span>
                        </span>
                    </label>

                    <p class="text-sm text-slate-600">
                        <span class="font-semibold text-slate-900">{{ willImport }}</span> row(s) will be imported.
                        <span v-if="preview.invalid > 0" class="text-red-600">
                            {{ preview.invalid }} invalid row(s) are always skipped.
                        </span>
                    </p>

                    <button type="button" class="btn-primary" :disabled="willImport === 0" @click="commit">
                        Import {{ willImport }} row(s)
                    </button>
                </div>
            </section>
        </div>

        <!-- Reference -->
        <aside class="space-y-6">
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Recognised columns</h2>
                    <p class="text-xs text-slate-500">Header order does not matter. Extras are ignored.</p>
                </header>
                <ul class="divide-y divide-slate-100">
                    <li v-for="column in template.columns" :key="column" class="px-5 py-2 text-sm text-slate-700">
                        {{ column }}
                    </li>
                </ul>
            </section>

            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Accepted values</h2>
                </header>
                <div class="space-y-3 px-5 py-4">
                    <div>
                        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Lead Status</p>
                        <div class="flex flex-wrap gap-1">
                            <Badge v-for="status in template.statuses" :key="status" color="slate" size="sm">
                                {{ status }}
                            </Badge>
                        </div>
                    </div>

                    <div>
                        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Priority</p>
                        <div class="flex flex-wrap gap-1">
                            <Badge v-for="priority in template.priorities" :key="priority" color="slate" size="sm">
                                {{ priority }}
                            </Badge>
                        </div>
                    </div>

                    <p class="text-xs text-slate-500">
                        Blank status defaults to <strong>New</strong>, blank priority to <strong>Medium</strong>.
                        Each row needs at least a company name or an email.
                    </p>
                </div>
            </section>
        </aside>
    </div>
</template>
