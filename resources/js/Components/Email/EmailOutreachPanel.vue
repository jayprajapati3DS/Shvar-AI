<script setup lang="ts">
/**
 * Email outreach on the lead page.
 *
 * Generating requires an ACCEPTED product recommendation, not merely a
 * suggested one - the email follows the sales direction the user approved, and
 * the picker only offers those. When nothing has been accepted, the panel says
 * what to do rather than offering a button that fails on click.
 *
 * Regenerating never replaces anything: a second run produces its own drafts
 * alongside the first, and the user picks.
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import { routes } from '@/routes';
import type { AiStatus } from '@/types/ai';
import type { EmailDraft, EmailGeneration } from '@/types/models';

const { leadId, drafts, generations, acceptedProducts, status, canGenerate, blockers, thin } =
    defineProps<{
        leadId: number;
        drafts: EmailDraft[];
        generations: EmailGeneration[];
        /** Accepted recommendations, the only ones an email may be written from. */
        acceptedProducts: { id: number; product: string; sales_angle: string | null }[];
        status: AiStatus;
        canGenerate: boolean;
        /** Why generation is unavailable, if it is. */
        blockers: string[];
        /** Fields that are blank and would improve personalisation. */
        thin: string[];
    }>();

const showGenerate = ref(false);

const form = useForm({
    recommendation_id: '' as string | number,
    extra_instructions: '',
    regenerate_from: '' as string | number,
});

const openDrafts = computed(() =>
    drafts.filter((d) => !['Archived', 'Rejected'].includes(d.status)),
);

/** The most recent run, and the one it replaced, for the compare prompt. */
const pendingComparison = computed(() => {
    const latest = generations[0];

    if (!latest?.id || !generations.some((g) => g.id === latest.id)) {
        return null;
    }

    return latest;
});

function open(recommendationId?: number, regenerateFrom?: number) {
    form.reset();
    form.clearErrors();
    form.recommendation_id = recommendationId ?? acceptedProducts[0]?.id ?? '';
    form.regenerate_from = regenerateFrom ?? '';
    showGenerate.value = true;
}

function generate() {
    form.post(routes.leads.generateEmail(leadId), {
        preserveScroll: true,
        onSuccess: () => (showGenerate.value = false),
    });
}

function resolve(generationId: number, choice: 'previous' | 'new') {
    router.post(
        routes.leads.resolveGeneration(leadId, generationId, choice),
        {},
        { preserveScroll: true },
    );
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : '—';
}
</script>

<template>
    <section class="card">
        <header class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Email Outreach</h2>
                <p class="text-xs text-slate-500">
                    Written locally, as a draft. Nothing is sent without your approval.
                </p>
            </div>

            <button
                type="button"
                class="btn-primary shrink-0"
                :disabled="!canGenerate || !status.ready"
                :title="
                    !status.ready
                        ? 'The local model is not available.'
                        : !canGenerate
                          ? blockers.join(' ')
                          : undefined
                "
                @click="open()"
            >
                Generate personalized email
            </button>
        </header>

        <div class="px-5 py-4">
            <!-- Why the button is disabled, said plainly. -->
            <div v-if="!status.ready" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                {{ status.message ?? 'The local model is not reachable.' }}
                <Link :href="routes.settings.ai.index()" class="font-medium underline">
                    Check AI settings
                </Link>
            </div>

            <ul v-else-if="blockers.length" class="space-y-1.5">
                <li v-for="blocker in blockers" :key="blocker" class="flex gap-2 text-sm text-slate-600">
                    <span class="text-slate-400" aria-hidden="true">•</span>
                    {{ blocker }}
                </li>
            </ul>

            <!-- A nudge, not a block. -->
            <p v-else-if="thin.length" class="mb-4 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                Additional information may improve personalization — still blank:
                {{ thin.join(', ') }}.
            </p>

            <!-- Regeneration comparison. -->
            <div
                v-if="pendingComparison?.regenerated_from_id"
                class="mb-4 rounded-md border border-indigo-200 bg-indigo-50/60 px-3 py-3"
            >
                <p class="mb-2 text-sm font-medium text-indigo-900">
                    You regenerated this email. Both versions are kept — choose which to keep working on.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="btn-secondary px-3 py-1.5 text-xs"
                        @click="resolve(pendingComparison.id, 'previous')"
                    >
                        Keep previous
                    </button>
                    <button
                        type="button"
                        class="btn-primary px-3 py-1.5 text-xs"
                        @click="resolve(pendingComparison.id, 'new')"
                    >
                        Use new version
                    </button>
                </div>
            </div>

            <!-- The drafts themselves. -->
            <ul v-if="openDrafts.length" class="divide-y divide-slate-100">
                <li v-for="draft in openDrafts" :key="draft.id" class="flex items-start gap-3 py-3">
                    <div class="min-w-0 grow">
                        <Link
                            :href="routes.emailDrafts.show(draft.id)"
                            class="text-sm font-medium text-slate-900 hover:text-indigo-600"
                        >
                            {{ draft.subject }}
                        </Link>

                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <Badge :color="draft.variant_color" size="sm">
                                {{ draft.variant_short_label }}
                            </Badge>
                            <Badge :color="draft.status_color" size="sm">
                                {{ draft.status_label }}
                            </Badge>
                            <span class="text-xs text-slate-400">
                                {{ draft.product?.name }} · {{ formatDate(draft.created_at) }}
                                <span v-if="draft.word_count"> · {{ draft.word_count }} words</span>
                            </span>
                        </div>
                    </div>

                    <Link
                        :href="routes.emailDrafts.show(draft.id)"
                        class="btn-ghost shrink-0 px-2 py-1 text-xs"
                    >
                        Open
                    </Link>
                </li>
            </ul>

            <p v-else-if="canGenerate && status.ready && !thin.length" class="text-sm text-slate-400">
                No drafts yet.
            </p>
        </div>

        <footer
            v-if="drafts.length > openDrafts.length"
            class="border-t border-slate-200 px-5 py-2.5"
        >
            <Link
                :href="routes.emailDrafts.index({ company_id: undefined })"
                class="text-xs font-medium text-indigo-600 hover:underline"
            >
                {{ drafts.length - openDrafts.length }} archived or rejected draft(s) in the history
            </Link>
        </footer>
    </section>

    <!-- ------------------------------------------------ generate -->
    <Modal
        :open="showGenerate"
        :title="form.regenerate_from ? 'Regenerate this email' : 'Generate personalized email'"
        description="The local model writes three versions from the product recommendation you accepted. Nothing is sent."
        size="lg"
        @close="showGenerate = false"
    >
        <form class="space-y-4" @submit.prevent="generate">
            <div>
                <label for="recommendation" class="label">Product recommendation</label>
                <select id="recommendation" v-model="form.recommendation_id" class="input">
                    <option value="" disabled>Choose an accepted recommendation…</option>
                    <option v-for="item in acceptedProducts" :key="item.id" :value="item.id">
                        {{ item.product }}
                    </option>
                </select>
                <p v-if="form.errors.recommendation_id" class="mt-1 text-xs font-medium text-red-600">
                    {{ form.errors.recommendation_id }}
                </p>
                <p v-else class="mt-1 text-xs text-slate-500">
                    Only accepted recommendations are offered — the email follows the direction you approved.
                </p>
            </div>

            <div>
                <label for="instructions" class="label">Additional AI instructions</label>
                <textarea
                    id="instructions"
                    v-model="form.extra_instructions"
                    rows="3"
                    class="input"
                    placeholder="Make it shorter. Focus on segmentation. Target the product development team."
                />
                <p v-if="form.errors.extra_instructions" class="mt-1 text-xs font-medium text-red-600">
                    {{ form.errors.extra_instructions }}
                </p>
                <p v-else class="mt-1 text-xs text-slate-500">
                    Optional. Sent to the local model along with the company and product data.
                </p>
            </div>

            <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                Generation can take a minute or two on a local model. Three drafts are created and
                left unsent — you review, edit and approve each one yourself.
            </p>
        </form>

        <template #footer>
            <button type="button" class="btn-secondary" :disabled="form.processing" @click="showGenerate = false">
                Cancel
            </button>
            <button
                type="button"
                class="btn-primary"
                :disabled="form.processing || !form.recommendation_id"
                @click="generate"
            >
                {{ form.processing ? 'Writing…' : 'Generate' }}
            </button>
        </template>
    </Modal>
</template>
