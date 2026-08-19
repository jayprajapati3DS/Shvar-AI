<script setup lang="ts">
/**
 * Label + control + error, so every form field in the app lines up and reports
 * server-side validation errors the same way.
 *
 * The control itself comes through the default slot and receives the generated
 * id so the label stays associated with it.
 */
import { useId } from 'vue';

const { label, error, hint, required = false } = defineProps<{
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
}>();

const id = useId();
</script>

<template>
    <div>
        <label :for="id" class="label">
            {{ label }}
            <span v-if="required" class="text-red-500" aria-hidden="true">*</span>
        </label>

        <slot :id="id" :invalid="Boolean(error)" />

        <p v-if="error" class="mt-1 text-xs font-medium text-red-600">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>
