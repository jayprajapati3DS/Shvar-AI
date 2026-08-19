<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfidenceMeter from '@/Components/Ai/ConfidenceMeter.vue';
import LocalAiNotice from '@/Components/Ai/LocalAiNotice.vue';
import RecommendationCard from '@/Components/Ai/RecommendationCard.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { useAiJobFor } from '@/composables/useAiJobs';
import { useToasts } from '@/composables/useToasts';
import { routes } from '@/routes';
import type { AiStatus, LeadAnalysis, LeadProductMatch } from '@/types/ai';

/**
 * "AI Sales Intelligence" on the lead page.
 *
 * Presents one analysis and its recommendations for human review. Every
 * recommendation arrives as Suggested; the accept/reject buttons here are the
 * only way that changes.
 */
const { leadId, analysis, history, status } = defineProps<{
    leadId: number;
    /** Null until the lead has been analysed at least once. */
    analysis: { data: LeadAnalysis } | null;
    /** Summaries of past runs, newest first. */
    history: { data: LeadAnalysis[] };
    status: AiStatus;
}>();

const { error } = useToasts();

/**
 * The analysis job for THIS lead, if one is going.
 *
 * Read from the shared job state rather than a local flag, so it survives
 * navigating away and back - which is the point of running in the background.
 */
const running = useAiJobFor('lead_analysis', 'Lead', () => leadId);

const reviewing = ref<number | null>(null);
const showHistory = ref(false);
const viewing = ref<LeadAnalysis | null>(null);
const loadingPast = ref(false);

const current = computed(() => analysis?.data ?? null);

const recommendations = computed(() => current.value?.recommendations ?? []);

const primary = computed(
    () => recommendations.value.find((r) => r.product_id === current.value?.primary_product_id) ?? null,
);

const others = computed(() => recommendations.value.filter((r) => r !== primary.value));

const pendingCount = computed(() => recommendations.value.filter((r) => r.status === 'Suggested').length);

/**
 * Hand the analysis to a background worker.
 *
 * The request returns in milliseconds now, so there is nothing to wait for here
 * - `running` comes from the job itself, which means the button still reads
 * "Analysing" after you navigate away and come back, and the previous analysis
 * stays on screen and usable the whole time it runs.
 *
 * The server refuses a second run for the same lead regardless. This is the same
 * check made visible before the click rather than after it.
 */
function analyze() {
    if (running.value || !status.ready) {
        return;
    }

    router.post(routes.leads.analyze(leadId), {}, { preserveScroll: true });
}

function accept(match: LeadProductMatch) {
    reviewing.value = match.id;

    router.post(routes.leads.acceptRecommendation(leadId, match.id), {}, {
        preserveScroll: true,
        onFinish: () => (reviewing.value = null),
    });
}

function reject(match: LeadProductMatch) {
    reviewing.value = match.id;

    router.post(routes.leads.rejectRecommendation(leadId, match.id), {}, {
        preserveScroll: true,
        onFinish: () => (reviewing.value = null),
    });
}

/** Past analyses are fetched on demand rather than shipped with the page. */
async function viewPast(entry: LeadAnalysis) {
    loadingPast.value = true;
    viewing.value = entry;

    try {
        const response = await fetch(routes.leads.analysis(leadId, entry.id), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = (await response.json()) as { analysis: LeadAnalysis };
        viewing.value = payload.analysis;
    } catch {
        viewing.value = null;
        error('Could not load that analysis.');
    } finally {
        loadingPast.value = false;
    }
}
</script>

<template>
    <section class="card">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-slate-900">AI Sales Intelligence</h2>
                <p class="text-xs text-slate-500">
                    Suggestions only. Nothing is added to this lead until you accept it.
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
                    :disabled="!!running || !status.ready"
                    @click="analyze"
                >
                    <svg
                        v-if="running"
                        class="size-4 animate-spin"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
                    </svg>
                    {{ running ? 'Analysing…' : current ? 'Regenerate Analysis' : 'Analyze Lead' }}
                </button>
            </div>
        </header>

        <!--
            Running. A strip, not a screen: the analysis already on this page
            stays readable and its recommendations stay actionable while a new
            one is written. Replacing the panel with a spinner would take the
            page away for five minutes, which is what this change was for.
        -->
        <div v-if="running" class="flex items-center gap-3 border-b border-indigo-100 bg-indigo-50 px-5 py-3">
            <span class="size-2 shrink-0 animate-pulse rounded-full bg-indigo-500" aria-hidden="true" />
            <p class="min-w-0 flex-1 text-xs text-indigo-900">
                Analysing with {{ status.model }} in the background. Carry on working — the AI activity tray
                will tell you when it is done.
            </p>
            <span class="shrink-0 font-mono text-xs text-indigo-700">{{ running.elapsed_seconds }}s</span>
        </div>

        <!-- AI unavailable -->
        <div v-if="!status.ready" class="border-b border-slate-200 bg-amber-50 px-5 py-4">
            <p class="text-sm font-semibold text-amber-900">
                {{ status.message ?? 'Local AI is not available.' }}
            </p>
            <p v-if="status.hint" class="mt-1 text-sm text-amber-800">{{ status.hint }}</p>
        </div>

        <!-- Never analysed -->
        <EmptyState
            v-else-if="!current"
            icon="search"
            title="No analysis yet"
            message="Run the local model over this lead's stored company information to see which of your products fit, and why."
        />

        <!-- Results -->
        <div v-else class="divide-y divide-slate-100">
            <!-- Run metadata -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 bg-slate-50 px-5 py-2.5 text-xs text-slate-500">
                <span>Model <span class="font-mono text-slate-700">{{ current.model }}</span></span>
                <span v-if="current.seconds !== null">{{ current.seconds.toFixed(1) }}s</span>
                <span>{{ current.created_for_humans }}</span>
                <span v-if="pendingCount > 0" class="font-medium text-sky-700">
                    {{ pendingCount }} awaiting your review
                </span>
            </div>

            <!-- What the model discarded, so filtering is visible -->
            <div v-if="current.warnings.length" class="bg-amber-50 px-5 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                    Filtered from the model's output
                </p>
                <ul class="mt-1 space-y-0.5">
                    <li v-for="warning in current.warnings" :key="warning" class="text-xs text-amber-800">
                        {{ warning }}
                    </li>
                </ul>
            </div>

            <!-- Company reading -->
            <div class="space-y-3 px-5 py-4">
                <div v-if="current.company_summary">
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Company summary
                    </p>
                    <p class="text-sm text-slate-700">{{ current.company_summary }}</p>
                    <Badge v-if="current.company_type" color="sky" size="sm" class="mt-1.5">
                        {{ current.company_type }}
                    </Badge>
                </div>

                <div v-if="current.business_opportunity">
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Business opportunity
                    </p>
                    <p class="text-sm text-slate-700">{{ current.business_opportunity }}</p>
                </div>
            </div>

            <!-- Primary -->
            <div v-if="primary" class="px-5 py-4">
                <div class="mb-2 flex items-center gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">
                        Primary recommendation
                    </p>
                </div>
                <RecommendationCard
                    :match="primary"
                    primary
                    :processing="reviewing === primary.id"
                    @accept="accept"
                    @reject="reject"
                />
            </div>

            <!-- Others -->
            <div v-if="others.length" class="px-5 py-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Other recommendations
                </p>
                <div class="space-y-3">
                    <RecommendationCard
                        v-for="match in others"
                        :key="match.id"
                        :match="match"
                        :processing="reviewing === match.id"
                        @accept="accept"
                        @reject="reject"
                    />
                </div>
            </div>

            <!-- Nothing fit: a legitimate outcome, stated plainly -->
            <div v-if="!recommendations.length" class="px-5 py-6 text-center">
                <p class="text-sm font-medium text-slate-700">No product recommended</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    The model found nothing in your portfolio that the stored information supports
                    recommending. That is a valid result, not a failure.
                </p>
            </div>

            <!-- Avoid -->
            <div v-if="current.products_to_avoid.length" class="px-5 py-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Products to avoid
                </p>
                <ul class="space-y-1.5">
                    <li
                        v-for="item in current.products_to_avoid"
                        :key="item.product_name"
                        class="text-sm text-slate-600"
                    >
                        <span class="font-medium text-slate-800">{{ item.product_name }}</span>
                        <span v-if="item.reason"> — {{ item.reason }}</span>
                    </li>
                </ul>
            </div>

            <!-- Missing info -->
            <div v-if="current.missing_information.length" class="px-5 py-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Missing information
                </p>
                <ul class="space-y-1">
                    <li
                        v-for="item in current.missing_information"
                        :key="item"
                        class="flex items-start gap-1.5 text-sm text-slate-600"
                    >
                        <span class="mt-1.5 size-1 shrink-0 rounded-full bg-amber-400" aria-hidden="true" />
                        {{ item }}
                    </li>
                </ul>
                <p class="mt-2 text-xs text-slate-400">
                    Filling these in on the company record and re-running gives a better-supported score.
                </p>
            </div>

            <!-- Next action -->
            <div v-if="current.recommended_next_action" class="bg-indigo-50 px-5 py-4">
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-indigo-700">
                    Recommended next action
                </p>
                <p class="text-sm text-indigo-900">{{ current.recommended_next_action }}</p>
            </div>

            <div class="px-5 py-3">
                <LocalAiNotice :endpoint="status.endpoint" compact />
            </div>
        </div>
    </section>

    <!-- History -->
    <Modal :open="showHistory" title="Analysis history" size="lg" @close="showHistory = false">
        <p class="mb-3 text-sm text-slate-500">
            Every analysis is kept. Re-running adds a new one rather than replacing the last.
        </p>

        <ol class="divide-y divide-slate-100">
            <li v-for="entry in history.data" :key="entry.id">
                <button
                    type="button"
                    class="w-full py-3 text-left transition-colors hover:bg-slate-50"
                    @click="viewPast(entry)"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-medium text-slate-900">
                            {{ entry.primary_product_name ?? 'No recommendation' }}
                        </span>
                        <span class="font-mono text-xs text-slate-400">{{ entry.model }}</span>
                    </div>
                    <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-slate-500">
                        <span>{{ entry.created_for_humans }}</span>
                        <span v-if="entry.primary_confidence_percent !== null">
                            {{ entry.primary_confidence_percent }}% confidence
                        </span>
                        <span v-if="entry.recommendations_count !== undefined">
                            {{ entry.recommendations_count }} recommendation(s)
                        </span>
                    </div>
                </button>
            </li>
        </ol>

        <template #footer>
            <button type="button" class="btn-secondary" @click="showHistory = false">Close</button>
        </template>
    </Modal>

    <!-- A past analysis, read-only -->
    <Modal
        :open="viewing !== null"
        :title="viewing ? `Analysis from ${viewing.created_for_humans}` : 'Analysis'"
        size="xl"
        @close="viewing = null"
    >
        <div v-if="viewing" class="space-y-4">
            <p v-if="loadingPast" class="text-sm text-slate-500">Loading…</p>

            <template v-else>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
                    <span>Model <span class="font-mono text-slate-700">{{ viewing.model }}</span></span>
                    <span v-if="viewing.seconds !== null">{{ viewing.seconds.toFixed(1) }}s</span>
                </div>

                <div v-if="viewing.company_summary">
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Company summary</p>
                    <p class="text-sm text-slate-700">{{ viewing.company_summary }}</p>
                </div>

                <ConfidenceMeter
                    v-if="viewing.primary_confidence_percent !== null"
                    :percent="viewing.primary_confidence_percent"
                    :band="viewing.primary_confidence_band"
                />

                <div v-if="viewing.recommendations?.length" class="space-y-3">
                    <RecommendationCard
                        v-for="match in viewing.recommendations"
                        :key="match.id"
                        :match="match"
                    />
                </div>

                <p v-else class="text-sm text-slate-500">This analysis recommended no products.</p>
            </template>
        </div>

        <template #footer>
            <button type="button" class="btn-secondary" @click="viewing = null">Close</button>
        </template>
    </Modal>
</template>
