<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PaginationMeta } from '@/types/models';

const { meta } = defineProps<{ meta: PaginationMeta }>();
</script>

<template>
    <nav
        v-if="meta.last_page > 1"
        class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        aria-label="Pagination"
    >
        <p class="text-sm text-slate-500">
            Showing <span class="font-medium text-slate-700">{{ meta.from ?? 0 }}</span>
            to <span class="font-medium text-slate-700">{{ meta.to ?? 0 }}</span>
            of <span class="font-medium text-slate-700">{{ meta.total }}</span>
        </p>

        <div class="flex flex-wrap items-center gap-1">
            <template v-for="(link, index) in meta.links" :key="index">
                <!-- Laravel emits a "..." separator with a null url. -->
                <span v-if="link.url === null" class="px-2.5 py-1.5 text-sm text-slate-400">
                    {{ link.label }}
                </span>

                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        link.active
                            ? 'bg-indigo-600 text-white'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                    "
                    v-html="link.label"
                />
            </template>
        </div>
    </nav>
</template>
