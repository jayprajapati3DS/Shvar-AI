<script setup lang="ts">
import { onUnmounted, watch } from 'vue';
import { claimBottomBar, releaseBottomBar } from '@/composables/useBottomBar';

/**
 * The bar that appears once rows are selected.
 *
 * Pinned to the bottom of the viewport rather than sitting above the table, so
 * it stays reachable after scrolling down a long list - which is exactly when a
 * multi-page selection has been built up.
 */
const {
    count,
    offPageCount = 0,
    label,
    processing = false,
    canEdit = true,
} = defineProps<{
    /** Total selected, across pages. */
    count: number;
    /** How many of those are not on the current page. */
    offPageCount?: number;
    /** Singular noun for the records, e.g. "lead". */
    label: string;
    processing?: boolean;
    /** False when the model exposes nothing worth bulk-editing. */
    canEdit?: boolean;
}>();

const emit = defineEmits<{ edit: []; delete: []; clear: [] }>();

// Announce the bottom edge while this is visible, so the AI activity tray steps
// up rather than covering the Delete button on a narrower screen.
watch(
    () => count > 0,
    (shown, was) => {
        if (shown === was) {
            return;
        }

        if (shown) {
            claimBottomBar();
        } else {
            releaseBottomBar();
        }
    },
    { immediate: true },
);

// A page navigation unmounts this with the selection still non-empty, which
// would otherwise leave the claim standing for ever.
onUnmounted(() => {
    if (count > 0) {
        releaseBottomBar();
    }
});

function plural(n: number, noun: string): string {
    return `${n} ${noun}${n === 1 ? '' : 's'}`;
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="translate-y-4 opacity-0"
        leave-active-class="transition duration-100 ease-in"
        leave-to-class="translate-y-4 opacity-0"
    >
        <div
            v-if="count > 0"
            class="fixed inset-x-0 bottom-0 z-40 flex justify-center px-4 pb-5"
            role="region"
            aria-label="Bulk actions"
        >
            <div
                class="pointer-events-auto flex w-full max-w-3xl flex-wrap items-center gap-3 rounded-lg
                       bg-slate-900 px-4 py-3 text-sm text-white shadow-xl ring-1 ring-black/5"
            >
                <p class="font-medium">
                    {{ plural(count, label) }} selected
                    <span v-if="offPageCount > 0" class="font-normal text-slate-300">
                        ({{ offPageCount }} on other pages)
                    </span>
                </p>

                <div class="ml-auto flex items-center gap-2">
                    <button
                        v-if="canEdit"
                        type="button"
                        class="rounded-md bg-white/10 px-3 py-1.5 font-medium transition-colors
                               hover:bg-white/20 disabled:pointer-events-none disabled:opacity-50"
                        :disabled="processing"
                        @click="emit('edit')"
                    >
                        Edit fields
                    </button>

                    <button
                        type="button"
                        class="rounded-md bg-red-600 px-3 py-1.5 font-medium transition-colors
                               hover:bg-red-500 disabled:pointer-events-none disabled:opacity-50"
                        :disabled="processing"
                        @click="emit('delete')"
                    >
                        Delete
                    </button>

                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 font-medium text-slate-300 transition-colors
                               hover:bg-white/10 hover:text-white"
                        :disabled="processing"
                        @click="emit('clear')"
                    >
                        Clear
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>
