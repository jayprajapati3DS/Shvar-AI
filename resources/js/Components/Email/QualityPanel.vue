<script setup lang="ts">
/**
 * The pre-approval checklist.
 *
 * Two severities, shown differently on purpose: a `fail` is something that must
 * not reach a real person and blocks approval, a `warn` is worth a glance and
 * never blocks. Mixing them would train the reader to click past both.
 */
import { computed } from 'vue';
import type { QualityResult } from '@/types/models';

const { quality } = defineProps<{ quality: QualityResult }>();

const failures = computed(() => quality.checks.filter((c) => c.status === 'fail'));
const warnings = computed(() => quality.checks.filter((c) => c.status === 'warn'));
const passes = computed(() => quality.checks.filter((c) => c.status === 'pass'));
</script>

<template>
    <section class="card p-5">
        <header class="mb-3 flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-slate-900">Email quality</h2>
            <span class="text-xs text-slate-500">{{ quality.word_count }} words</span>
        </header>

        <p
            v-if="quality.blocking"
            class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs font-medium text-red-700"
        >
            Approval is blocked until these are fixed.
        </p>

        <ul class="space-y-2 text-sm">
            <li v-for="check in failures" :key="check.key" class="flex gap-2">
                <span class="mt-0.5 shrink-0 font-semibold text-red-600" aria-hidden="true">✕</span>
                <div>
                    <p class="font-medium text-red-700">{{ check.label }}</p>
                    <p v-if="check.detail" class="text-xs text-red-600">{{ check.detail }}</p>
                </div>
            </li>

            <li v-for="check in warnings" :key="check.key" class="flex gap-2">
                <span class="mt-0.5 shrink-0 font-semibold text-amber-600" aria-hidden="true">⚠</span>
                <div>
                    <p class="font-medium text-amber-800">{{ check.label }}</p>
                    <p v-if="check.detail" class="text-xs text-amber-700">{{ check.detail }}</p>
                </div>
            </li>

            <li v-for="check in passes" :key="check.key" class="flex gap-2">
                <span class="mt-0.5 shrink-0 font-semibold text-emerald-600" aria-hidden="true">✓</span>
                <div>
                    <p class="text-slate-700">{{ check.label }}</p>
                    <p v-if="check.detail" class="text-xs text-slate-400">{{ check.detail }}</p>
                </div>
            </li>
        </ul>
    </section>
</template>
