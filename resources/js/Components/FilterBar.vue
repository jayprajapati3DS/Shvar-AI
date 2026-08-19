<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { SelectOption } from '@/types/models';
import type { FilterDefinition } from '@/types/ui';

/**
 * Search box plus dropdown filters that push their state into the URL.
 *
 * Filters go through the query string rather than component state so a filtered
 * view is bookmarkable and survives a refresh or a back-navigation.
 */
const { basePath, filters, definitions, searchPlaceholder = 'Search…' } = defineProps<{
    basePath: string;
    filters: Record<string, string | undefined>;
    definitions: FilterDefinition[];
    searchPlaceholder?: string;
}>();

const search = ref(filters.search ?? '');
const selections = ref<Record<string, string>>(
    Object.fromEntries(definitions.map((d) => [d.key, filters[d.key] ?? ''])),
);

const activeCount = computed(
    () => Object.values(selections.value).filter((v) => v !== '').length + (search.value ? 1 : 0),
);

/** Normalise the two accepted option shapes into one. */
function normalise(options: SelectOption[] | string[]): SelectOption[] {
    return options.map((option) =>
        typeof option === 'string' ? { value: option, label: option } : option,
    );
}

function apply() {
    router.get(
        basePath,
        { search: search.value || undefined, ...selections.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function reset() {
    search.value = '';
    selections.value = Object.fromEntries(definitions.map((d) => [d.key, '']));
    apply();
}

// Debounce typing so each keystroke does not fire a request.
let timer: number | undefined;

watch(search, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(apply, 300);
});
</script>

<template>
    <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 lg:flex-row lg:items-center">
        <div class="relative flex-1 lg:max-w-xs">
            <svg
                class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd" />
            </svg>

            <input
                v-model="search"
                type="search"
                class="input pl-9"
                :placeholder="searchPlaceholder"
                aria-label="Search"
            />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select
                v-for="definition in definitions"
                :key="definition.key"
                v-model="selections[definition.key]"
                class="input w-auto min-w-[9rem] py-1.5 text-sm"
                :aria-label="definition.label"
                @change="apply"
            >
                <option value="">All {{ definition.label }}</option>
                <option
                    v-for="option in normalise(definition.options)"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>

            <button v-if="activeCount > 0" type="button" class="btn-ghost text-sm" @click="reset">
                Clear ({{ activeCount }})
            </button>
        </div>
    </div>
</template>
