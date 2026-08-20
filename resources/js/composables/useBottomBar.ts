import { computed, readonly, ref } from 'vue';

/**
 * Is a bar currently pinned across the bottom of the screen?
 *
 * Two things now want that corner: the bulk-action bar that appears once rows
 * are selected, and the AI activity tray. On a wide screen they miss each other;
 * on anything narrower the tray lands on top of the Delete button, which is a
 * poor place for an overlay to sit.
 *
 * Rather than have either guess, the bar announces itself and the tray steps up
 * out of the way. A module-level flag because there is exactly one of each, and
 * because threading state between two independent fixed overlays would mean
 * passing props through every page that has a list on it.
 *
 * Counted rather than a boolean: an unmount racing a mount - which is what a
 * page navigation does - would otherwise leave the flag stuck on.
 */
const claims = ref(0);

export const bottomBarVisible = readonly(computed(() => claims.value > 0));

export function claimBottomBar(): void {
    claims.value += 1;
}

export function releaseBottomBar(): void {
    claims.value = Math.max(0, claims.value - 1);
}
