<script setup lang="ts">
import Modal from '@/Components/Modal.vue';

const {
    open,
    title = 'Are you sure?',
    message,
    confirmLabel = 'Delete',
    cancelLabel = 'Cancel',
    processing = false,
} = defineProps<{
    open: boolean;
    title?: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    processing?: boolean;
}>();

const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <Modal :open="open" :title="title" size="md" @close="emit('cancel')">
        <div class="flex gap-3">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-red-100">
                <svg class="size-5 text-red-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        fill-rule="evenodd"
                        d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.63-1.516 2.63H3.72c-1.347 0-2.19-1.463-1.516-2.63L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                        clip-rule="evenodd"
                    />
                </svg>
            </div>

            <p class="text-sm text-slate-600">{{ message }}</p>
        </div>

        <template #footer>
            <button type="button" class="btn-secondary" :disabled="processing" @click="emit('cancel')">
                {{ cancelLabel }}
            </button>

            <button type="button" class="btn-danger" :disabled="processing" @click="emit('confirm')">
                {{ processing ? 'Working…' : confirmLabel }}
            </button>
        </template>
    </Modal>
</template>
