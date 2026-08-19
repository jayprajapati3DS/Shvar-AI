<script setup lang="ts">
import { useToasts, type ToastKind } from '@/composables/useToasts';

const { toasts, dismiss } = useToasts();

const styles: Record<ToastKind, string> = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    error: 'border-red-200 bg-red-50 text-red-900',
    info: 'border-sky-200 bg-sky-50 text-sky-900',
};

const iconColor: Record<ToastKind, string> = {
    success: 'text-emerald-600',
    error: 'text-red-600',
    info: 'text-sky-600',
};
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="-translate-y-2 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                role="status"
                aria-live="polite"
                class="pointer-events-auto flex w-full max-w-md items-start gap-2.5 rounded-lg border px-4 py-3 shadow-lg"
                :class="styles[toast.kind]"
            >
                <svg
                    v-if="toast.kind === 'success'"
                    class="mt-0.5 size-4 shrink-0"
                    :class="iconColor[toast.kind]"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                </svg>

                <svg
                    v-else
                    class="mt-0.5 size-4 shrink-0"
                    :class="iconColor[toast.kind]"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" />
                </svg>

                <p class="min-w-0 flex-1 text-sm font-medium">{{ toast.message }}</p>

                <button
                    type="button"
                    class="-m-1 rounded p-1 opacity-60 transition-opacity hover:opacity-100"
                    aria-label="Dismiss"
                    @click="dismiss(toast.id)"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
