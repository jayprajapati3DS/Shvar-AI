<script setup lang="ts">
/**
 * A single status light with a label — "● Connected" / "● Not Connected".
 *
 * Three states rather than two: `unknown` covers "we have not probed yet",
 * which is honestly different from "we probed and it is down".
 */
const { state, on, off, unknown = 'Unknown' } = defineProps<{
    state: boolean | null;
    on: string;
    off: string;
    unknown?: string;
}>();
</script>

<template>
    <span class="inline-flex items-center gap-1.5 text-sm font-medium">
        <span
            class="size-2 shrink-0 rounded-full"
            :class="state === null ? 'bg-slate-300' : state ? 'bg-emerald-500' : 'bg-red-500'"
            aria-hidden="true"
        />
        <span :class="state === null ? 'text-slate-500' : state ? 'text-emerald-700' : 'text-red-700'">
            {{ state === null ? unknown : state ? on : off }}
        </span>
    </span>
</template>
