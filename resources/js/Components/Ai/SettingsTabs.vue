<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { routes } from '@/routes';

/**
 * Tab bar across the Settings area. Kept as its own component so all three AI
 * screens share one definition of what the tabs are.
 */
const page = usePage();

const tabs = [
    { label: 'AI Configuration', href: routes.settings.ai.index() },
    { label: 'AI Playground', href: routes.settings.ai.playground() },
    { label: 'AI Logs', href: routes.settings.ai.logs() },
];

const currentPath = computed(() => page.url.split('?')[0]);

/**
 * Exact match only. `/settings/ai` is a prefix of the other two, so a
 * startsWith check would light up the first tab on every screen.
 */
function isCurrent(href: string): boolean {
    return currentPath.value === href;
}
</script>

<template>
    <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Settings sections">
        <Link
            v-for="tab in tabs"
            :key="tab.href"
            :href="tab.href"
            class="-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
            :class="
                isCurrent(tab.href)
                    ? 'border-indigo-600 text-indigo-700'
                    : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'
            "
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
