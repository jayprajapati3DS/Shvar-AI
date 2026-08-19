import { ref } from 'vue';

export type ToastKind = 'success' | 'error' | 'info';

export interface Toast {
    id: number;
    kind: ToastKind;
    message: string;
}

/**
 * Module-level state so any component can raise a toast without prop drilling.
 * There is exactly one ToastHub mounted, in AppLayout.
 */
const toasts = ref<Toast[]>([]);
let nextId = 1;

const DISMISS_AFTER = 4500;

export function useToasts() {
    function push(message: string, kind: ToastKind = 'success') {
        const id = nextId++;

        toasts.value.push({ id, kind, message });

        // Errors stay until dismissed - they usually need reading.
        if (kind !== 'error') {
            window.setTimeout(() => dismiss(id), DISMISS_AFTER);
        }
    }

    function dismiss(id: number) {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }

    return {
        toasts,
        push,
        dismiss,
        success: (message: string) => push(message, 'success'),
        error: (message: string) => push(message, 'error'),
        info: (message: string) => push(message, 'info'),
    };
}
