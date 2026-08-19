import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { routes } from '@/routes';
import { useToasts } from '@/composables/useToasts';
import type { AiJob, AiJobsState, SharedProps } from '@/types/models';

/**
 * Background AI work, followed from wherever you happen to be.
 *
 * Module-level state, like useToasts, because there is one queue and one tray -
 * and because the state has to survive navigation. That is the whole point: you
 * start a five-minute analysis, go and edit three other leads, and the progress
 * is still there when you look down.
 *
 * Two sources feed it, and they complement each other:
 *
 *   - every Inertia page load carries the current state as a shared prop, so a
 *     freshly opened page is right immediately rather than blank for a second;
 *   - a poll keeps it moving between navigations.
 *
 * The poll only runs while something is actually going. An idle tray costs
 * nothing, which matters on a laptop where the other CPU cores are busy running
 * the model.
 */

const EMPTY: AiJobsState = { jobs: [], active: 0, stalled: false, start_command: '' };

const state = ref<AiJobsState>(EMPTY);

/** Fast enough to feel live, slow enough to be invisible next to a 5-minute job. */
const POLL_MS = 2000;

let timer: number | null = null;
let inFlight = false;

/**
 * Jobs already announced, so a finished job is toasted once rather than on every
 * poll and every page load for the next twelve hours.
 */
const announced = new Set<number>();

let started = false;

async function fetchOnce(): Promise<void> {
    // Skip if the last request has not come back. A slow response should not
    // stack up behind itself.
    if (inFlight) {
        return;
    }

    inFlight = true;

    try {
        const response = await fetch(routes.aiJobs.index(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            apply((await response.json()) as AiJobsState);
        }
    } catch {
        // A failed poll is not worth reporting. The next one is two seconds
        // away, and the page load after it carries the truth regardless.
    } finally {
        inFlight = false;
    }
}

function apply(next: AiJobsState): void {
    state.value = next;
    announce(next.jobs);
    schedule();
}

/**
 * Tell the user about work that finished while they were looking elsewhere.
 *
 * The tray shows it too, but a toast is what makes "go and do something else"
 * an honest instruction - otherwise finishing quietly means checking the corner
 * of the screen every minute, which is the waiting we just removed.
 */
function announce(jobs: AiJob[]): void {
    const { push } = useToasts();

    for (const job of jobs) {
        if (job.active || announced.has(job.id)) {
            continue;
        }

        announced.add(job.id);

        if (job.status === 'Failed') {
            push(`${job.label}: ${job.error ?? 'failed'}`, 'error');
        } else if (job.status === 'Done') {
            push(`${job.label} - ${job.result_summary ?? 'done'}`, job.result_level === 'info' ? 'info' : 'success');
        }
    }
}

function schedule(): void {
    if (timer !== null) {
        window.clearTimeout(timer);
        timer = null;
    }

    // Nothing running, nothing to watch for. The next page load will restart it
    // if work appears, and so will queueing something from this page.
    if (state.value.active === 0) {
        return;
    }

    timer = window.setTimeout(() => void fetchOnce(), POLL_MS);
}

export function useAiJobs() {
    const page = usePage<SharedProps>();

    // Seed from the current page and follow every subsequent one. Inertia visits
    // bring fresh state for free, which keeps the poll rate low.
    if (!started) {
        started = true;

        watch(
            () => page.props.ai_jobs,
            (shared) => {
                if (shared) {
                    apply(shared);
                }
            },
            { immediate: true, deep: true },
        );
    }

    const jobs = computed(() => state.value.jobs);
    const active = computed(() => state.value.jobs.filter((job) => job.active));
    const finished = computed(() => state.value.jobs.filter((job) => !job.active));

    return {
        jobs,
        active,
        finished,
        activeCount: computed(() => state.value.active),
        stalled: computed(() => state.value.stalled),
        startCommand: computed(() => state.value.start_command),

        /** Go to where the result of a job ended up. */
        open(job: AiJob) {
            if (job.result_url) {
                router.visit(job.result_url, { preserveScroll: false });
            }
        },

        dismiss(job: AiJob) {
            router.post(routes.aiJobs.dismiss(job.id), {}, { preserveScroll: true, preserveState: true });
        },

        dismissAll() {
            router.post(routes.aiJobs.dismissAll(), {}, { preserveScroll: true, preserveState: true });
        },

        cancel(job: AiJob) {
            router.post(routes.aiJobs.cancel(job.id), {}, { preserveScroll: true, preserveState: true });
        },

        /** Poll now - used right after queueing, so the tray reacts at once. */
        refresh: () => void fetchOnce(),
    };
}

/**
 * Is this record already being worked on?
 *
 * Lets a button on a lead page show "Analysing..." instead of offering to start
 * something that is already running - the check the server makes anyway, made
 * visible before the click rather than after it.
 *
 * Matched on the subject rather than the label, so two leads with the same name
 * cannot be mistaken for each other.
 */
export function useAiJobFor(type: string, subjectType: string, subjectId: () => number | null | undefined) {
    const { active } = useAiJobs();

    return computed(
        () =>
            active.value.find(
                (job) => job.type === type && job.subject_type === subjectType && job.subject_id === subjectId(),
            ) ?? null,
    );
}
