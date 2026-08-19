<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';

const { open, title, description, size = 'lg' } = defineProps<{
    open: boolean;
    title: string;
    description?: string;
    size?: 'md' | 'lg' | 'xl' | '2xl';
}>();

const emit = defineEmits<{ close: [] }>();

const widths: Record<string, string> = {
    md: 'max-w-md',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
    '2xl': 'max-w-6xl',
};

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && open) {
        emit('close');
    }
}

// Lock body scroll while a modal is up, so a long form does not scroll the
// page behind it.
watch(
    () => open,
    (isOpen) => {
        document.body.style.overflow = isOpen ? 'hidden' : '';
    },
);

onMounted(() => document.addEventListener('keydown', onKeydown));

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="fixed inset-0 bg-slate-900/40" @click="emit('close')" />

                <div class="flex min-h-full items-start justify-center p-4 sm:p-6">
                    <div
                        role="dialog"
                        aria-modal="true"
                        :aria-label="title"
                        class="relative w-full rounded-lg bg-white shadow-xl ring-1 ring-slate-900/5"
                        :class="widths[size]"
                    >
                        <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
                                <p v-if="description" class="mt-0.5 text-sm text-slate-500">
                                    {{ description }}
                                </p>
                            </div>

                            <button
                                type="button"
                                class="-m-1.5 rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                aria-label="Close"
                                @click="emit('close')"
                            >
                                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                                </svg>
                            </button>
                        </header>

                        <div class="px-6 py-5">
                            <slot />
                        </div>

                        <footer
                            v-if="$slots.footer"
                            class="flex items-center justify-end gap-2 rounded-b-lg border-t border-slate-200 bg-slate-50 px-6 py-4"
                        >
                            <slot name="footer" />
                        </footer>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
