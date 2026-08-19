<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

const { title, subtitle, backHref, backLabel } = defineProps<{
    title: string;
    subtitle?: string;
    backHref?: string;
    backLabel?: string;
}>();
</script>

<template>
    <header class="mb-6">
        <Link
            v-if="backHref"
            :href="backHref"
            class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition-colors hover:text-slate-800"
        >
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.612l4.158 3.96a.75.75 0 1 1-1.04 1.08l-5.5-5.25a.75.75 0 0 1 0-1.08l5.5-5.25a.75.75 0 1 1 1.04 1.08L5.612 9.25H16.25A.75.75 0 0 1 17 10Z" clip-rule="evenodd" />
            </svg>
            {{ backLabel ?? 'Back' }}
        </Link>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold text-slate-900 sm:text-2xl">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-sm text-slate-500">{{ subtitle }}</p>
                <slot name="meta" />
            </div>

            <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
