<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ConfidenceMeter from '@/Components/Ai/ConfidenceMeter.vue';
import Badge from '@/Components/Badge.vue';
import { routes } from '@/routes';
import type { LeadProductMatch } from '@/types/ai';

/**
 * One recommendation, with the reasoning behind it and the review actions.
 *
 * The actions are the only path from Suggested to Accepted or Rejected -
 * nothing accepts on the user's behalf.
 */
const { match, primary = false, processing = false } = defineProps<{
    match: LeadProductMatch;
    primary?: boolean;
    processing?: boolean;
}>();

const emit = defineEmits<{
    accept: [match: LeadProductMatch];
    reject: [match: LeadProductMatch];
}>();
</script>

<template>
    <article
        class="rounded-lg border bg-white p-4 transition-shadow"
        :class="primary ? 'border-indigo-300 shadow-sm ring-1 ring-indigo-100' : 'border-slate-200'"
    >
        <header class="mb-3 flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="match.product"
                        :href="routes.products.show(match.product.id)"
                        class="text-sm font-semibold text-slate-900 hover:text-indigo-600"
                    >
                        {{ match.product.name }}
                    </Link>
                    <span v-else class="text-sm font-semibold text-slate-900">Product</span>

                    <!-- A capability inside the product, validated against its
                         own key_features - never an invented module. -->
                    <Badge v-if="match.module" color="violet" size="sm">{{ match.module }}</Badge>
                </div>

                <p v-if="match.product?.category" class="mt-0.5 text-xs text-slate-500">
                    {{ match.product.category }}
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                <Badge v-if="match.priority" :color="match.priority_color ?? 'slate'" size="sm">
                    {{ match.priority }}
                </Badge>
                <Badge :color="match.status_color" size="sm">{{ match.status_label }}</Badge>
                <Badge :color="match.is_ai_generated ? 'indigo' : 'slate'" size="sm">
                    {{ match.source }}
                </Badge>
            </div>
        </header>

        <ConfidenceMeter
            v-if="match.confidence_percent !== null"
            class="mb-3"
            :percent="match.confidence_percent"
            :band="match.confidence_band"
            :raw-percent="match.raw_confidence_percent"
            :was-calibrated="match.was_calibrated"
        />

        <div v-if="match.reason" class="mb-3">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Why</p>
            <p class="whitespace-pre-line text-sm text-slate-700">{{ match.reason }}</p>
        </div>

        <!-- Evidence is quoted from the stored record, so it can be checked. -->
        <div v-if="match.evidence.length" class="mb-3">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                Evidence from the record
            </p>
            <ul class="space-y-1">
                <li
                    v-for="item in match.evidence"
                    :key="item"
                    class="flex items-start gap-1.5 text-sm text-slate-600"
                >
                    <span class="mt-1.5 size-1 shrink-0 rounded-full bg-slate-400" aria-hidden="true" />
                    <span class="italic">{{ item }}</span>
                </li>
            </ul>
        </div>

        <div v-if="match.sales_angle" class="mb-3 rounded-md bg-slate-50 px-3 py-2">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Sales angle</p>
            <p class="text-sm text-slate-700">{{ match.sales_angle }}</p>
        </div>

        <div v-if="match.suggested_use_case" class="mb-3">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Suggested use case</p>
            <p class="text-sm text-slate-600">{{ match.suggested_use_case }}</p>
        </div>

        <p v-if="match.notes" class="mb-3 text-xs text-amber-700">{{ match.notes }}</p>

        <footer
            v-if="match.status === 'Suggested'"
            class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3"
        >
            <button
                type="button"
                class="btn-primary px-3 py-1.5 text-xs"
                :disabled="processing"
                @click="emit('accept', match)"
            >
                Accept
            </button>
            <button
                type="button"
                class="btn-secondary px-3 py-1.5 text-xs"
                :disabled="processing"
                @click="emit('reject', match)"
            >
                Reject
            </button>
            <span class="text-xs text-slate-400">Nothing is added to the lead until you accept.</span>
        </footer>

        <footer v-else class="border-t border-slate-100 pt-3 text-xs text-slate-400">
            {{ match.status_label }}<span v-if="match.reviewed_at"> · reviewed {{ match.created_for_humans }}</span>
        </footer>
    </article>
</template>
