<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useAiJobs } from '@/composables/useAiJobs';
import { bottomBarVisible } from '@/composables/useBottomBar';
import type { AiJob } from '@/types/models';

/**
 * What the local model is working on, in the corner of every page.
 *
 * The thing that makes it possible to walk away from a five-minute analysis:
 * whatever page you open next, this is still here, still counting, and clicking
 * a finished job takes you to where its output ended up.
 *
 * It is deliberately quiet. Collapsed to a single pill while work is running,
 * out of the way entirely when there is none, and never covering anything -
 * because a progress panel you have to close before you can carry on working
 * would defeat the point of not having to wait in the first place.
 */

const { active, finished, stalled, startCommand, open, dismiss, dismissAll, cancel } = useAiJobs();

const expanded = ref(false);

const jobs = computed(() => [...active.value, ...finished.value]);
const visible = computed(() => jobs.value.length > 0);

/** Open on its own the first time work finishes, so a result is never missed. */
watch(
    () => finished.value.length,
    (now, before) => {
        if (now > (before ?? 0)) {
            expanded.value = true;
        }
    },
);

/**
 * Open on its own when the queue turns out to be unattended.
 *
 * This is the one message here that the user has to act on, and leaving it
 * folded away was worse than not showing it: collapsed, a stalled job looks
 * exactly like a running one - a pulsing dot and a name - so the reasonable
 * thing to do is wait, which is precisely the wrong thing.
 */
watch(stalled, (isStalled) => {
    if (isStalled) {
        expanded.value = true;
    }
});

// Nothing left to show: fold away rather than leaving an empty panel.
watch(visible, (shown) => {
    if (!shown) {
        expanded.value = false;
    }
});

function elapsed(job: AiJob): string {
    const seconds = job.elapsed_seconds;

    if (seconds < 60) {
        return `${seconds}s`;
    }

    return `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
}

const ring: Record<string, string> = {
    indigo: 'border-indigo-200 bg-indigo-50',
    slate: 'border-slate-200 bg-slate-50',
    emerald: 'border-emerald-200 bg-emerald-50',
    red: 'border-red-200 bg-red-50',
};

const dot: Record<string, string> = {
    indigo: 'bg-indigo-500',
    slate: 'bg-slate-400',
    emerald: 'bg-emerald-500',
    red: 'bg-red-500',
};
</script>

<template>
    <!-- Lifts clear of the bulk-action bar when a list selection is active,
         rather than landing on top of its Delete button. -->
    <div
        v-if="visible"
        class="fixed right-4 z-50 w-[min(24rem,calc(100vw-2rem))] transition-[bottom] duration-200"
        :class="bottomBarVisible ? 'bottom-24' : 'bottom-4'"
    >
        <!-- Collapsed: one line, always the same place. -->
        <button
            v-if="!expanded"
            type="button"
            class="flex w-full items-center gap-2.5 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-left shadow-lg transition-shadow hover:shadow-xl"
            @click="expanded = true"
        >
            <!-- Amber and still when nothing is coming for it. A pulsing dot
                 says "working", and saying that would be untrue. -->
            <span
                v-if="stalled"
                class="size-2 shrink-0 rounded-full bg-amber-500"
                aria-hidden="true"
            />
            <span
                v-else-if="active.length"
                class="size-2 shrink-0 animate-pulse rounded-full bg-indigo-500"
                aria-hidden="true"
            />
            <span v-else class="size-2 shrink-0 rounded-full bg-emerald-500" aria-hidden="true" />

            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800">
                <template v-if="stalled">Waiting - no worker running</template>
                <template v-else-if="active.length === 1">{{ active[0]!.label }}</template>
                <template v-else-if="active.length">{{ active.length }} AI jobs running</template>
                <template v-else>{{ finished.length }} finished</template>
            </span>

            <span v-if="active.length === 1" class="shrink-0 font-mono text-xs text-slate-500">
                {{ elapsed(active[0]!) }}
            </span>

            <svg class="size-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 0 1-1.06-.02L10 8.832 6.29 12.77a.75.75 0 1 1-1.08-1.04l4.25-4.5a.75.75 0 0 1 1.08 0l4.25 4.5a.75.75 0 0 1-.02 1.06Z" clip-rule="evenodd" />
            </svg>
        </button>

        <!-- Expanded -->
        <div v-else class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
            <div class="flex items-center gap-2 border-b border-slate-200 px-4 py-2.5">
                <p class="flex-1 text-sm font-semibold text-slate-800">AI activity</p>

                <button
                    v-if="finished.length"
                    type="button"
                    class="rounded px-1.5 py-0.5 text-xs font-medium text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700"
                    @click="dismissAll()"
                >
                    Clear finished
                </button>

                <button
                    type="button"
                    class="-mr-1 rounded p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                    aria-label="Collapse"
                    @click="expanded = false"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>

            <!--
                The one situation worth interrupting for: work is waiting and
                nothing is coming to collect it. The fix is a command, so give
                them the command.
            -->
            <div v-if="stalled" class="border-b border-amber-200 bg-amber-50 px-4 py-3">
                <p class="text-xs font-semibold text-amber-900">No background worker is running.</p>
                <p class="mt-1 text-xs leading-relaxed text-amber-800">
                    Queued work will sit there until one starts. Run this in a second terminal, or start the app
                    with <code class="font-mono">composer dev</code>, which includes one.
                </p>
                <code
                    class="mt-2 block overflow-x-auto rounded border border-amber-200 bg-white px-2 py-1.5 font-mono text-[11px] text-amber-900"
                >{{ startCommand }}</code>
            </div>

            <ul class="max-h-[26rem] divide-y divide-slate-100 overflow-y-auto">
                <li v-for="job in jobs" :key="job.id" class="px-4 py-3">
                    <div class="flex items-start gap-2.5">
                        <span
                            class="mt-1.5 size-2 shrink-0 rounded-full"
                            :class="[dot[job.color] ?? 'bg-slate-400', job.active ? 'animate-pulse' : '']"
                            aria-hidden="true"
                        />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-800">{{ job.label }}</p>
                            <p class="text-xs text-slate-500">
                                {{ job.type_label }} · {{ job.status_label }} · {{ elapsed(job) }}
                            </p>

                            <!--
                                An estimate, and labelled as one. A local model
                                returns one response at the end, so there is no
                                real progress to report - this is elapsed time
                                against a typical run, and it never reaches the
                                end while the work is still going.
                            -->
                            <div v-if="job.active" class="mt-2">
                                <div class="h-1 overflow-hidden rounded-full bg-slate-200">
                                    <div
                                        class="h-full rounded-full bg-indigo-500 transition-all duration-1000 ease-linear"
                                        :style="{ width: `${Math.round(job.progress * 100)}%` }"
                                    />
                                </div>
                                <p class="mt-1 text-[11px] text-slate-400">
                                    {{ job.status === 'Queued' ? 'Waiting for a worker' : 'Estimated - the model reports no progress' }}
                                </p>
                            </div>

                            <p
                                v-if="job.result_summary"
                                class="mt-1.5 rounded border px-2 py-1.5 text-xs leading-relaxed"
                                :class="ring[job.color] ?? 'border-slate-200 bg-slate-50'"
                            >
                                {{ job.result_summary }}
                            </p>

                            <p
                                v-if="job.error"
                                class="mt-1.5 rounded border border-red-200 bg-red-50 px-2 py-1.5 text-xs leading-relaxed text-red-800"
                            >
                                {{ job.error }}
                            </p>

                            <div class="mt-2 flex items-center gap-3">
                                <button
                                    v-if="!job.active && job.result_url"
                                    type="button"
                                    class="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800"
                                    @click="open(job)"
                                >
                                    View result
                                </button>

                                <button
                                    v-if="job.status === 'Queued'"
                                    type="button"
                                    class="text-xs font-medium text-slate-500 transition-colors hover:text-slate-700"
                                    @click="cancel(job)"
                                >
                                    Cancel
                                </button>

                                <button
                                    v-if="!job.active"
                                    type="button"
                                    class="text-xs font-medium text-slate-500 transition-colors hover:text-slate-700"
                                    @click="dismiss(job)"
                                >
                                    Dismiss
                                </button>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>

            <p class="border-t border-slate-100 px-4 py-2 text-[11px] leading-relaxed text-slate-400">
                Runs on this machine. Nothing here sends anything - results wait for you to approve them.
            </p>
        </div>
    </div>
</template>
