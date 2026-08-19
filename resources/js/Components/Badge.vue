<script setup lang="ts">
import type { BadgeColor } from '@/types/models';

const { color = 'slate', size = 'md' } = defineProps<{
    color?: BadgeColor;
    size?: 'sm' | 'md';
}>();

/**
 * Written out rather than composed as `bg-${color}-50`, because Tailwind's
 * scanner only keeps classes it can see literally in the source.
 */
const palette: Record<BadgeColor, string> = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    sky: 'bg-sky-50 text-sky-700 ring-sky-200',
    indigo: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-200',
    violet: 'bg-violet-50 text-violet-700 ring-violet-200',
    orange: 'bg-orange-50 text-orange-800 ring-orange-200',
    emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full font-medium whitespace-nowrap ring-1 ring-inset"
        :class="[palette[color], size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-xs']"
    >
        <slot />
    </span>
</template>
