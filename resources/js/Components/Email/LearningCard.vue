<script setup lang="ts">
/**
 * What the application has learned about how you write.
 *
 * Shown in full, including the exact block that goes into the prompt. A prompt
 * that silently rewrites itself is how a system quietly gets worse in ways
 * nobody can point at - if it has concluded something silly, you should be able
 * to read it and switch it off.
 */
import { ref } from 'vue';
import type { LearningProfile } from '@/types/models';

const { learning } = defineProps<{ learning: LearningProfile }>();

const emit = defineEmits<{ toggle: [boolean] }>();

const showPrompt = ref(false);
</script>

<template>
    <section class="card p-5">
        <header class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Learning from your edits</h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    Every email you approve says something about how you want these written. This
                    reads that back into the next prompt. It does not retrain the model — nothing
                    here touches its weights, and nothing leaves this machine.
                </p>
            </div>

            <label class="flex shrink-0 cursor-pointer items-center gap-2">
                <input
                    type="checkbox"
                    class="checkbox"
                    :checked="learning.enabled"
                    @change="emit('toggle', !learning.enabled)"
                />
                <span class="text-xs font-medium text-slate-600">
                    {{ learning.enabled ? 'On' : 'Off' }}
                </span>
            </label>
        </header>

        <!-- Not enough history yet. Say how much more is needed. -->
        <p
            v-if="!learning.active"
            class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"
        >
            <template v-if="!learning.enabled">
                Switched off. Generations start from the same blank slate every time.
            </template>
            <template v-else>
                Nothing learned yet — {{ learning.samples }} of
                {{ learning.min_samples }} approved emails. Approve a few and this fills in.
                Rejected and unreviewed drafts are deliberately ignored: a draft you rejected is
                not a preference, and one you have not looked at is just the model's own output.
            </template>
        </p>

        <template v-else>
            <dl class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-500">Learned from</dt>
                    <dd class="text-sm font-medium text-slate-900">
                        {{ learning.samples }} emails
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">You prefer</dt>
                    <dd class="text-sm font-medium text-slate-900">
                        {{ learning.preferred_variant_label ?? 'no clear favourite' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Your real length</dt>
                    <dd class="text-sm font-medium text-slate-900">
                        {{ learning.typical_word_count ?? '—' }} words
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">You rewrite</dt>
                    <dd class="text-sm font-medium text-slate-900">
                        {{ Math.round(learning.edit_rate * 100) }}% of drafts
                    </dd>
                </div>
            </dl>

            <div v-if="learning.rejected_phrases.length" class="mb-4">
                <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Sentences you keep deleting — now banned
                </h3>
                <ul class="space-y-1">
                    <li
                        v-for="phrase in learning.rejected_phrases"
                        :key="phrase"
                        class="rounded bg-slate-50 px-2 py-1 text-xs italic text-slate-600"
                    >
                        “{{ phrase }}”
                    </li>
                </ul>
                <p class="mt-1.5 text-xs text-slate-500">
                    The most useful thing here. “Stop writing this” is a far stronger instruction
                    than any amount of style guidance, because it is specific and it came from you.
                </p>
            </div>

            <p v-if="learning.example_count" class="mb-3 text-xs text-slate-600">
                {{ learning.example_count }} of your approved emails are included as worked
                examples, so the model matches your register rather than a description of it.
            </p>

            <button
                type="button"
                class="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                @click="showPrompt = !showPrompt"
            >
                {{ showPrompt ? 'Hide' : 'Show' }} exactly what gets added to the prompt
            </button>

            <pre
                v-if="showPrompt && learning.prompt_block"
                class="mt-3 max-h-80 overflow-y-auto whitespace-pre-wrap rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs leading-relaxed text-slate-700"
            >{{ learning.prompt_block }}</pre>
        </template>
    </section>
</template>
