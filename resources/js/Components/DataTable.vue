<script setup lang="ts" generic="T extends { id: number }">
/**
 * Table shell: renders the header row and the scroll container, and delegates
 * each row's cells to the `row` slot. Keeps every list page consistent without
 * forcing a rigid column config.
 *
 * Selection is opt-in. Pass `selectable` and the checkbox column appears at the
 * front of every row, so a page that has no bulk actions is unchanged and no
 * index page needs to hand-roll a checkbox cell.
 */
import { computed } from 'vue';
import type { Column } from '@/types/ui';

const {
    columns,
    rows,
    rowKey,
    selectable = false,
    isSelected = () => false,
    allSelected = false,
    someSelected = false,
    selectLabel = 'row',
} = defineProps<{
    columns: Column[];
    rows: T[];
    rowKey: (row: T) => string | number;

    /** Show the leading checkbox column. */
    selectable?: boolean;
    isSelected?: (id: number) => boolean;
    /** Every row on this page is selected - drives the header checkbox. */
    allSelected?: boolean;
    /** Some but not all - drives the indeterminate state. */
    someSelected?: boolean;
    /** Noun used in the checkbox labels, e.g. "lead". */
    selectLabel?: string;
}>();

const emit = defineEmits<{ toggle: [id: number]; toggleAll: [] }>();

const headerLabel = computed(() =>
    allSelected ? `Deselect all ${selectLabel}s on this page` : `Select all ${selectLabel}s on this page`,
);
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th v-if="selectable" scope="col" class="th w-10 pr-0">
                        <input
                            type="checkbox"
                            class="checkbox"
                            :checked="allSelected"
                            :indeterminate="someSelected"
                            :aria-label="headerLabel"
                            :title="headerLabel"
                            @change="emit('toggleAll')"
                        />
                    </th>

                    <th
                        v-for="column in columns"
                        :key="column.label"
                        scope="col"
                        class="th"
                        :class="column.class"
                    >
                        <span :class="column.hidden ? 'sr-only' : ''">{{ column.label }}</span>
                    </th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100 bg-white">
                <tr
                    v-for="row in rows"
                    :key="rowKey(row)"
                    class="transition-colors hover:bg-slate-50/75"
                    :class="selectable && isSelected(row.id) ? 'bg-indigo-50/60 hover:bg-indigo-50' : ''"
                >
                    <td v-if="selectable" class="td w-10 pr-0">
                        <input
                            type="checkbox"
                            class="checkbox"
                            :checked="isSelected(row.id)"
                            :aria-label="`Select this ${selectLabel}`"
                            @change="emit('toggle', row.id)"
                        />
                    </td>

                    <slot name="row" :row="row" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
