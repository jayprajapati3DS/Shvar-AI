<script setup lang="ts">
import { computed } from 'vue';

/**
 * A confidence score as a bar plus its documented band.
 *
 * When the calibrator lowered the model's own number, both are shown. Quietly
 * displaying a different figure than the model produced would be dishonest, and
 * the gap is itself useful information: it says the record is too thin to
 * support the model's optimism.
 */
const { percent, band, rawPercent = null, wasCalibrated = false, compact = false } = defineProps<{
    percent: number | null;
    band: string;
    rawPercent?: number | null;
    wasCalibrated?: boolean;
    compact?: boolean;
}>();

const value = computed(() => percent ?? 0);

// Bands match the documented scale, so the colour and the words never disagree.
const tone = computed(() => {
    if (percent === null) return { bar: 'bg-slate-300', text: 'text-slate-500' };
    if (percent >= 80) return { bar: 'bg-emerald-500', text: 'text-emerald-700' };
    if (percent >= 60) return { bar: 'bg-sky-500', text: 'text-sky-700' };
    if (percent >= 40) return { bar: 'bg-amber-500', text: 'text-amber-700' };
    return { bar: 'bg-red-500', text: 'text-red-700' };
});
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Confidence</span>
            <span class="text-sm font-semibold tabular-nums" :class="tone.text">
                {{ percent === null ? '—' : `${percent}%` }}
            </span>
        </div>

        <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full transition-all" :class="tone.bar" :style="{ width: `${value}%` }" />
        </div>

        <p v-if="!compact" class="mt-1 text-xs" :class="tone.text">{{ band }}</p>

        <p v-if="wasCalibrated && rawPercent !== null" class="mt-1 text-xs text-amber-700">
            Model said {{ rawPercent }}%; lowered because the stored record is thin.
        </p>
    </div>
</template>
