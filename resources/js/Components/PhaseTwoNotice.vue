<script setup lang="ts">
/**
 * Shared body for the remaining placeholder pages.
 *
 * Deliberately explicit about what does NOT exist yet: no AI, no email sending,
 * no network calls. A stub that looks half-built is worse than one that states
 * its scope.
 */
const { planned, blockedOn } = defineProps<{
    /** What this module will do once built. */
    planned: string[];
    /** What it is waiting on. */
    blockedOn: string;
}>();
</script>

<template>
    <div class="card overflow-hidden">
        <div class="border-b border-slate-200 bg-slate-50 px-5 py-4">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-100">
                    <svg class="size-4 text-amber-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-11.25a.75.75 0 0 0-1.5 0v4.5a.75.75 0 0 0 1.5 0v-4.5Zm0 7.25a.75.75 0 1 0-1.5 0 .75.75 0 0 0 1.5 0Z" clip-rule="evenodd" />
                    </svg>
                </span>

                <div>
                    <p class="text-sm font-semibold text-slate-900">Not built yet — Phase 4</p>
                    <p class="mt-0.5 text-sm text-slate-600">{{ blockedOn }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 px-5 py-5 lg:grid-cols-2">
            <div>
                <h3 class="mb-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Planned for this module
                </h3>
                <ul class="space-y-2">
                    <li v-for="item in planned" :key="item" class="flex items-start gap-2 text-sm text-slate-700">
                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-slate-300" aria-hidden="true" />
                        {{ item }}
                    </li>
                </ul>
            </div>

            <div>
                <h3 class="mb-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Not present anywhere in the application
                </h3>
                <ul class="space-y-2 text-sm text-slate-600">
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 shrink-0 text-red-500" aria-hidden="true">✕</span>
                        No cloud AI, ever. The local model does not power this module
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 shrink-0 text-red-500" aria-hidden="true">✕</span>
                        No email sending, and no mailbox connected
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 shrink-0 text-red-500" aria-hidden="true">✕</span>
                        No web scraping or lead enrichment
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 shrink-0 text-red-500" aria-hidden="true">✕</span>
                        No outbound network requests at all
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="$slots.default" class="border-t border-slate-200 px-5 py-4">
            <slot />
        </div>
    </div>
</template>
