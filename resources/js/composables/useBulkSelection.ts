/**
 * Selection state for a list page.
 *
 * Selection is held as a Set of ids rather than a flag on each row, so it
 * survives the row objects being replaced - which Inertia does on every filter
 * change, sort or pagination step.
 *
 * That raises the question of what should happen to a selection when the rows
 * underneath it change. This keeps ids that have scrolled out of view: you can
 * select five leads on page 1, page forward, select three more, and act on all
 * eight. `visibleSelected` is what the header checkbox reflects, so "select
 * all" still means "all of these", not "all of everything".
 */
import { computed, ref } from 'vue';
import type { Ref } from 'vue';

export interface Identifiable {
    id: number;
}

export function useBulkSelection<T extends Identifiable>(rows: Ref<T[]> | (() => T[])) {
    const selected = ref<Set<number>>(new Set());

    const visible = computed(() => (typeof rows === 'function' ? rows() : rows.value));
    const visibleIds = computed(() => visible.value.map((row) => row.id));

    const selectedIds = computed(() => [...selected.value]);
    const count = computed(() => selected.value.size);
    const hasSelection = computed(() => selected.value.size > 0);

    /** Selected ids that are on the current page. */
    const visibleSelected = computed(() =>
        visibleIds.value.filter((id) => selected.value.has(id)),
    );

    const allVisibleSelected = computed(
        () => visibleIds.value.length > 0 && visibleSelected.value.length === visibleIds.value.length,
    );

    /** True when some but not all rows on this page are selected. */
    const someVisibleSelected = computed(
        () => visibleSelected.value.length > 0 && !allVisibleSelected.value,
    );

    /** Selected ids that are NOT on the current page, so the UI can say so. */
    const offPageCount = computed(() => selected.value.size - visibleSelected.value.length);

    function isSelected(id: number): boolean {
        return selected.value.has(id);
    }

    function toggle(id: number) {
        // Reassigned rather than mutated: Vue's reactivity does not track Set
        // mutations, so `selected.value.add(id)` alone would update nothing.
        const next = new Set(selected.value);

        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        selected.value = next;
    }

    function toggleAllVisible() {
        const next = new Set(selected.value);

        if (allVisibleSelected.value) {
            visibleIds.value.forEach((id) => next.delete(id));
        } else {
            visibleIds.value.forEach((id) => next.add(id));
        }

        selected.value = next;
    }

    function clear() {
        selected.value = new Set();
    }

    /**
     * Drop ids the server no longer knows about.
     *
     * Called after a bulk delete: the rows are gone, and keeping their ids in
     * the selection would send a second request that fails validation.
     */
    function forget(ids: number[]) {
        const next = new Set(selected.value);
        ids.forEach((id) => next.delete(id));
        selected.value = next;
    }

    return {
        selected,
        selectedIds,
        count,
        hasSelection,
        allVisibleSelected,
        someVisibleSelected,
        offPageCount,
        isSelected,
        toggle,
        toggleAllVisible,
        clear,
        forget,
    };
}
