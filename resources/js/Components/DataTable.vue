<script setup lang="ts" generic="T">
/**
 * Table shell: renders the header row and the scroll container, and delegates
 * each row's cells to the `row` slot. Keeps every list page consistent without
 * forcing a rigid column config.
 */
import type { Column } from '@/types/ui';

const { columns, rows, rowKey } = defineProps<{
    columns: Column[];
    rows: T[];
    rowKey: (row: T) => string | number;
}>();
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
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
                >
                    <slot name="row" :row="row" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
