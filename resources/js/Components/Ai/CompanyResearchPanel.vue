<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { routes } from '@/routes';
import type { CompanyAnalysis, CompanyResearchFinding } from '@/types/ai';

/**
 * "Research from website" on the company page.
 *
 * Findings are proposals, never saved automatically. You tick what you want and
 * press Save; anything you leave unticked is discarded.
 *
 * Each finding shows the quote it came from, already verified against the
 * fetched page - a claim the page did not make never reaches this list.
 */
const { companyId, website, analysis, history, enabled } = defineProps<{
    companyId: number;
    website: string | null;
    analysis: { data: CompanyAnalysis } | null;
    history: { data: CompanyAnalysis[] };
    enabled: boolean;
}>();

const researching = ref(false);
const showHistory = ref(false);
const showUrlOverride = ref(false);

const current = computed(() => analysis?.data ?? null);

const findings = computed(() => current.value?.findings ?? []);

/** Only the ones not already written to the company record. */
const pending = computed(() => findings.value.filter((f) => !f.applied));

const form = useForm<{ url: string }>({ url: '' });
const selected = ref<Record<string, boolean>>({});

// Tick everything unapplied by default - the common case is accepting the lot,
// and unticking two is less work than ticking five.
watch(
    () => current.value?.id,
    () => {
        selected.value = Object.fromEntries(pending.value.map((f) => [f.field, true]));
    },
    { immediate: true },
);

const selectedFields = computed(() =>
    Object.entries(selected.value)
        .filter(([, checked]) => checked)
        .map(([field]) => field),
);

function research() {
    if (researching.value || !enabled) {
        return;
    }

    researching.value = true;

    router.post(
        routes.companies.research(companyId),
        form.url ? { url: form.url } : {},
        {
            preserveScroll: true,
            onFinish: () => {
                researching.value = false;
                showUrlOverride.value = false;
            },
        },
    );
}

function apply() {
    if (!current.value || selectedFields.value.length === 0) {
        return;
    }

    router.post(
        routes.companies.applyResearch(companyId, current.value.id),
        { fields: selectedFields.value },
        { preserveScroll: true },
    );
}

function preview(finding: CompanyResearchFinding): string {
    return finding.is_list ? finding.value.split('\n').join(' · ') : finding.value;
}
</script>

<template>
    <section class="card">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-slate-900">Research from website</h2>
                <p class="text-xs text-slate-500">
                    Reads the company's site and extracts what it actually says. Nothing is saved until you accept it.
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button
                    v-if="history.data.length"
                    type="button"
                    class="btn-ghost px-2.5 py-1.5 text-xs"
                    @click="showHistory = true"
                >
                    History ({{ history.data.length }})
                </button>

                <button
                    type="button"
                    class="btn-primary"
                    :disabled="researching || !enabled || (!website && !form.url)"
                    @click="research"
                >
                    <svg v-if="researching" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
                    </svg>
                    {{ researching ? 'Reading…' : current ? 'Research again' : 'Research Company' }}
                </button>
            </div>
        </header>

        <!-- Loading -->
        <div v-if="researching" class="px-5 py-8 text-center">
            <p class="text-sm font-medium text-slate-700">Reading the website…</p>
            <p class="mt-1 text-xs text-slate-500">
                Fetching the page, then reading it with the local model — in the background, so this page stays yours.
            </p>
        </div>

        <template v-else>
            <!-- Disabled -->
            <div v-if="!enabled" class="border-b border-slate-200 bg-amber-50 px-5 py-4">
                <p class="text-sm font-semibold text-amber-900">Website research is switched off.</p>
                <p class="mt-1 text-sm text-amber-800">
                    Set <code class="font-mono text-xs">RESEARCH_FETCH_ENABLED=true</code> in
                    <code class="font-mono text-xs">.env</code> to enable it. With it off, the application makes
                    no outbound requests at all.
                </p>
            </div>

            <!-- No website recorded -->
            <div v-else-if="!website" class="border-b border-slate-200 bg-slate-50 px-5 py-4">
                <p class="text-sm text-slate-700">
                    Add a website address for this company, or enter one below for a one-off lookup.
                </p>
                <div class="mt-2 flex gap-2">
                    <input
                        v-model="form.url"
                        type="text"
                        class="input text-sm"
                        placeholder="acme-medical.com"
                    />
                    <button type="button" class="btn-secondary shrink-0" :disabled="!form.url" @click="research">
                        Read it
                    </button>
                </div>
            </div>

            <!-- Never researched -->
            <EmptyState
                v-else-if="!current"
                icon="search"
                title="Not researched yet"
                :message="`Read ${website} and pull out the industry, company type, description, specialties and what they sell — each backed by a quote from the page.`"
            />

            <!-- Results -->
            <div v-else class="divide-y divide-slate-100">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 bg-slate-50 px-5 py-2.5 text-xs text-slate-500">
                    <span>Model <span class="font-mono text-slate-700">{{ current.model }}</span></span>
                    <span v-if="current.seconds !== null">{{ current.seconds.toFixed(0) }}s</span>
                    <span>{{ current.created_for_humans }}</span>
                    <span v-if="current.source_chars">
                        {{ current.source_chars.toLocaleString() }} chars read
                    </span>
                </div>

                <!-- Which pages were actually read -->
                <div v-if="current.fetched_urls.length" class="px-5 py-2.5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pages read</p>
                    <ul class="mt-1 space-y-0.5">
                        <li v-for="url in current.fetched_urls" :key="url">
                            <a
                                :href="url"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="break-all font-mono text-xs text-indigo-600 hover:underline"
                            >
                                {{ url }}
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Findings -->
                <div v-if="findings.length" class="px-5 py-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Found on the page
                        </p>
                        <p class="text-xs text-slate-400">Tick what to save</p>
                    </div>

                    <ul class="space-y-3">
                        <li
                            v-for="finding in findings"
                            :key="finding.field"
                            class="rounded-lg border border-slate-200 p-3"
                            :class="finding.applied ? 'bg-emerald-50/50' : ''"
                        >
                            <div class="flex items-start gap-2.5">
                                <input
                                    v-if="!finding.applied"
                                    :id="`f-${finding.field}`"
                                    v-model="selected[finding.field]"
                                    type="checkbox"
                                    class="mt-1 size-4 shrink-0 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <svg
                                    v-else
                                    class="mt-1 size-4 shrink-0 text-emerald-600"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                                </svg>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <label
                                            :for="`f-${finding.field}`"
                                            class="text-xs font-semibold uppercase tracking-wide text-slate-500"
                                        >
                                            {{ finding.label }}
                                        </label>
                                        <Badge :color="finding.confidence_percent >= 80 ? 'emerald' : 'amber'" size="sm">
                                            {{ finding.confidence_percent }}%
                                        </Badge>
                                        <Badge v-if="finding.applied" color="emerald" size="sm">Saved</Badge>
                                    </div>

                                    <p class="mt-1 whitespace-pre-line text-sm text-slate-800">
                                        {{ preview(finding) }}
                                    </p>

                                    <!-- The quote, already verified against the page -->
                                    <p class="mt-1.5 border-l-2 border-slate-200 pl-2 text-xs italic text-slate-500">
                                        “{{ finding.evidence }}”
                                    </p>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <div v-if="pending.length" class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                        <button
                            type="button"
                            class="btn-primary"
                            :disabled="selectedFields.length === 0"
                            @click="apply"
                        >
                            Save {{ selectedFields.length }} field(s) to this company
                        </button>
                        <span class="text-xs text-slate-400">Unticked findings are discarded.</span>
                    </div>

                    <p v-else class="mt-4 border-t border-slate-100 pt-3 text-xs text-emerald-700">
                        All findings from this run have been saved.
                    </p>
                </div>

                <div v-else class="px-5 py-6 text-center">
                    <p class="text-sm font-medium text-slate-700">Nothing could be verified</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                        The page was read, but nothing on it could be tied to a quote. Sites built entirely in
                        JavaScript often look empty to a simple fetch.
                    </p>
                </div>

                <!-- Not stated -->
                <div v-if="current.not_found.length" class="px-5 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Not stated on the page
                    </p>
                    <div class="mt-1.5 flex flex-wrap gap-1">
                        <Badge v-for="item in current.not_found" :key="item.field" color="slate" size="sm">
                            {{ item.label }}
                        </Badge>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-400">
                        These were left blank rather than guessed. Fill them in by hand if you know them.
                    </p>
                </div>

                <!-- What the validator threw away -->
                <div v-if="current.warnings.length" class="bg-amber-50 px-5 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                        Discarded as unsupported
                    </p>
                    <ul class="mt-1 space-y-0.5">
                        <li v-for="warning in current.warnings" :key="warning" class="text-xs text-amber-800">
                            {{ warning }}
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </section>

    <!-- History -->
    <Modal :open="showHistory" title="Research history" size="lg" @close="showHistory = false">
        <p class="mb-3 text-sm text-slate-500">
            Every run is kept, so what the site said before a redesign stays readable.
        </p>

        <ol class="divide-y divide-slate-100">
            <li v-for="entry in history.data" :key="entry.id" class="py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-mono text-xs text-slate-600">{{ entry.requested_url }}</span>
                    <span class="text-xs text-slate-400">{{ entry.created_for_humans }}</span>
                </div>
                <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-slate-500">
                    <span>{{ entry.findings.length }} finding(s)</span>
                    <span>{{ entry.applied_fields.length }} saved</span>
                    <span class="font-mono">{{ entry.model }}</span>
                </div>
            </li>
        </ol>

        <template #footer>
            <button type="button" class="btn-secondary" @click="showHistory = false">Close</button>
        </template>
    </Modal>
</template>
