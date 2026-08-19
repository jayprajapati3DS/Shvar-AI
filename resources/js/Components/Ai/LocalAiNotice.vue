<script setup lang="ts">
/**
 * The privacy indicator shown on every AI screen.
 *
 * Wording is deliberately factual — it describes where inference is configured
 * to run, and does not claim the app is "100% secure" or "guaranteed private",
 * because no application can promise that about the machine it runs on.
 */
const { endpoint, compact = false } = defineProps<{
    endpoint: string;
    compact?: boolean;
}>();
</script>

<template>
    <div
        class="flex items-start gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-900"
        :class="compact ? 'px-3 py-2' : 'px-4 py-3'"
    >
        <svg class="mt-0.5 size-4 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
                fill-rule="evenodd"
                d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z"
                clip-rule="evenodd"
            />
        </svg>

        <div class="min-w-0 text-sm">
            <p class="font-semibold">Local AI</p>
            <p :class="compact ? 'text-xs' : ''">
                Your prompt is processed through Ollama running on this computer
                (<code class="rounded bg-emerald-100 px-1 py-0.5 font-mono text-xs">{{ endpoint }}</code>).
                This application does not send AI requests to external cloud services.
            </p>
        </div>
    </div>
</template>
