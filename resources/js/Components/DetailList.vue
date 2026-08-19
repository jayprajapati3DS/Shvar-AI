<script setup lang="ts">
/**
 * Definition list for detail pages. A null/blank value renders as "Not set" so
 * a sparse record still reads as deliberate rather than broken.
 */
import type { DetailItem } from '@/types/ui';

const { items, columns = 2 } = defineProps<{
    items: DetailItem[];
    columns?: 1 | 2 | 3;
}>();

const grids: Record<number, string> = {
    1: 'sm:grid-cols-1',
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-3',
};
</script>

<template>
    <dl class="grid grid-cols-1 gap-x-6 gap-y-4" :class="grids[columns]">
        <div v-for="item in items" :key="item.label" :class="item.wide ? 'sm:col-span-full' : ''">
            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ item.label }}</dt>

            <dd class="mt-1 text-sm text-slate-800">
                <a
                    v-if="item.href && item.value"
                    :href="item.href"
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    class="break-all font-medium text-indigo-600 hover:text-indigo-800 hover:underline"
                >
                    {{ item.value }}
                </a>

                <span v-else-if="item.value !== null && item.value !== undefined && item.value !== ''" class="whitespace-pre-line">
                    {{ item.value }}
                </span>

                <span v-else class="text-slate-400">Not set</span>
            </dd>
        </div>
    </dl>
</template>
