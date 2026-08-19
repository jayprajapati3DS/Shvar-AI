<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { BadgeColor } from '@/types/models';

const { label, value, href, accent = 'slate', hint } = defineProps<{
    label: string;
    value: number;
    href?: string;
    accent?: BadgeColor;
    hint?: string;
}>();

const accents: Record<BadgeColor, string> = {
    slate: 'text-slate-900',
    sky: 'text-sky-700',
    indigo: 'text-indigo-700',
    amber: 'text-amber-700',
    violet: 'text-violet-700',
    orange: 'text-orange-700',
    emerald: 'text-emerald-700',
    red: 'text-red-700',
};

const bars: Record<BadgeColor, string> = {
    slate: 'bg-slate-300',
    sky: 'bg-sky-500',
    indigo: 'bg-indigo-500',
    amber: 'bg-amber-500',
    violet: 'bg-violet-500',
    orange: 'bg-orange-500',
    emerald: 'bg-emerald-500',
    red: 'bg-red-500',
};
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        class="card relative overflow-hidden p-4 transition-shadow"
        :class="href ? 'hover:shadow-md' : ''"
    >
        <span class="absolute inset-y-0 left-0 w-1" :class="bars[accent]" aria-hidden="true" />

        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ label }}</p>
        <p class="mt-1.5 text-2xl font-semibold tabular-nums" :class="accents[accent]">{{ value }}</p>
        <p v-if="hint" class="mt-0.5 text-xs text-slate-400">{{ hint }}</p>
    </component>
</template>
