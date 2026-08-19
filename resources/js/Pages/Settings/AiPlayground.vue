<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LocalAiNotice from '@/Components/Ai/LocalAiNotice.vue';
import SettingsTabs from '@/Components/Ai/SettingsTabs.vue';
import StatusDot from '@/Components/Ai/StatusDot.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { routes } from '@/routes';
import type { AiResult, AiSettings, AiStatus } from '@/types/ai';

const { settings, status, result, systemPrompt, examplePrompts } = defineProps<{
    settings: AiSettings;
    status: AiStatus;
    /** Null until a prompt has been run. */
    result: AiResult | null;
    systemPrompt: string;
    examplePrompts: { label: string; prompt: string }[];
}>();

const showSystemPrompt = ref(false);

const form = useForm({
    prompt: result?.prompt ?? '',
    model: settings.model,
    temperature: settings.temperature,
    max_tokens: settings.max_tokens,
    structured: result?.structured ?? false,
});

const promptLength = computed(() => form.prompt.length);
const overLimit = computed(() => promptLength.value > settings.limits.max_prompt_chars);

/** `form.processing` alone drives the disabled state, so a double-click cannot double-submit. */
const canRun = computed(
    () => !form.processing && form.prompt.trim().length > 0 && !overLimit.value && status.ready,
);

const prettyJson = computed(() => {
    if (!result?.data) {
        return null;
    }

    return JSON.stringify(result.data, null, 2);
});

function run() {
    if (!canRun.value) {
        return;
    }

    form.post(routes.settings.ai.run(), { preserveScroll: true, preserveState: false });
}

function useExample(prompt: string) {
    form.prompt = prompt;
}
</script>

<template>
    <Head title="AI Playground" />

    <PageHeader
        title="Local AI Playground"
        subtitle="A developer screen for confirming the local AI path works. Nothing here touches your CRM data."
    >
        <template #actions>
            <Link :href="routes.settings.ai.logs()" class="btn-secondary">View logs</Link>
        </template>
    </PageHeader>

    <SettingsTabs />

    <div class="mb-6">
        <LocalAiNotice :endpoint="settings.endpoint" />
    </div>

    <!-- Blocked state: no point rendering a form that cannot submit -->
    <div v-if="!status.ready" class="card mb-6 border-amber-200 bg-amber-50">
        <div class="flex items-start gap-3 px-5 py-4">
            <svg class="mt-0.5 size-5 shrink-0 text-amber-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.63-1.516 2.63H3.72c-1.347 0-2.19-1.463-1.516-2.63L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                    clip-rule="evenodd"
                />
            </svg>
            <div>
                <p class="text-sm font-semibold text-amber-900">
                    {{ status.message ?? 'Local AI is not ready.' }}
                </p>
                <p v-if="status.hint" class="mt-1 text-sm text-amber-800">{{ status.hint }}</p>
                <Link :href="routes.settings.ai.index()" class="mt-3 inline-block btn-secondary">
                    Go to AI Configuration
                </Link>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <!-- Prompt -->
        <div class="space-y-6 xl:col-span-2">
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Prompt</h2>
                </header>

                <form class="space-y-4 px-5 py-4" @submit.prevent="run">
                    <div>
                        <label for="playground-prompt" class="label">Prompt</label>
                        <textarea
                            id="playground-prompt"
                            v-model="form.prompt"
                            rows="9"
                            class="input font-mono text-sm"
                            placeholder="Ask the local model something…"
                            :disabled="form.processing"
                        />
                        <div class="mt-1 flex items-center justify-between gap-2">
                            <p v-if="form.errors.prompt" class="text-xs font-medium text-red-600">
                                {{ form.errors.prompt }}
                            </p>
                            <p v-else class="text-xs text-slate-400">
                                Sent to the local model with the system prompt below.
                            </p>
                            <p
                                class="shrink-0 text-xs tabular-nums"
                                :class="overLimit ? 'font-semibold text-red-600' : 'text-slate-400'"
                            >
                                {{ promptLength.toLocaleString() }} /
                                {{ settings.limits.max_prompt_chars.toLocaleString() }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <FormField v-slot="{ id }" label="Model" :error="form.errors.model">
                            <select
                                v-if="status.installed_models.length"
                                :id="id"
                                v-model="form.model"
                                class="input font-mono text-sm"
                            >
                                <option v-for="name in status.installed_models" :key="name" :value="name">
                                    {{ name }}
                                </option>
                            </select>
                            <input v-else :id="id" v-model="form.model" type="text" class="input font-mono text-sm" />
                        </FormField>

                        <FormField v-slot="{ id }" label="Temperature" :error="form.errors.temperature">
                            <input
                                :id="id"
                                v-model.number="form.temperature"
                                type="number"
                                step="0.1"
                                :min="settings.limits.min_temperature"
                                :max="settings.limits.max_temperature"
                                class="input"
                            />
                        </FormField>

                        <FormField
                            v-slot="{ id }"
                            label="Max tokens"
                            :error="form.errors.max_tokens"
                            hint="Blank = model default."
                        >
                            <input :id="id" v-model.number="form.max_tokens" type="number" min="1" class="input" />
                        </FormField>
                    </div>

                    <label class="flex items-start gap-2.5">
                        <input
                            v-model="form.structured"
                            type="checkbox"
                            class="mt-0.5 size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <span class="text-sm text-slate-700">
                            Structured output (JSON)
                            <span class="block text-xs text-slate-500">
                                Asks Ollama to constrain generation to JSON and validates the result. A response that
                                is not usable JSON is reported as a failure rather than guessed at.
                            </span>
                        </span>
                    </label>

                    <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                        <button type="submit" class="btn-primary" :disabled="!canRun">
                            <svg
                                v-if="form.processing"
                                class="size-4 animate-spin"
                                viewBox="0 0 24 24"
                                fill="none"
                                aria-hidden="true"
                            >
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"
                                />
                            </svg>
                            {{ form.processing ? 'Running locally…' : 'Run Local AI' }}
                        </button>

                        <p v-if="form.processing" class="text-xs text-slate-500">
                            A first request is slower — the model has to load into memory.
                        </p>
                        <p v-else-if="!status.ready" class="text-xs text-amber-700">
                            Disabled until Ollama and the model are available.
                        </p>
                    </div>
                </form>
            </section>

            <!-- Response -->
            <section class="card">
                <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Response</h2>
                    <div v-if="result" class="flex items-center gap-2">
                        <Badge v-if="result.structured" color="indigo" size="sm">JSON</Badge>
                        <Badge v-if="result.truncated" color="amber" size="sm">Truncated</Badge>
                    </div>
                </header>

                <EmptyState
                    v-if="!result"
                    icon="inbox"
                    title="No response yet"
                    message="Run a prompt to see the local model's output here."
                />

                <div v-else class="space-y-4 px-5 py-4">
                    <pre
                        class="max-h-[32rem] overflow-auto whitespace-pre-wrap rounded-md bg-slate-50 px-4 py-3 font-mono text-sm text-slate-800"
                        >{{ result.text }}</pre
                    >

                    <!-- Parsed structure, when JSON was requested -->
                    <div v-if="prettyJson">
                        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Parsed JSON
                        </p>
                        <pre
                            class="max-h-80 overflow-auto rounded-md bg-slate-900 px-4 py-3 font-mono text-xs text-slate-100"
                            >{{ prettyJson }}</pre
                        >
                    </div>
                </div>
            </section>

            <!-- The prompt that produced the response -->
            <section v-if="result" class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Prompt sent</h2>
                </header>
                <div class="px-5 py-4">
                    <pre
                        class="max-h-48 overflow-auto whitespace-pre-wrap rounded-md bg-slate-50 px-4 py-3 font-mono text-xs text-slate-700"
                        >{{ result.prompt }}</pre
                    >
                </div>
            </section>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Run stats -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Status</h2>
                </header>

                <dl class="divide-y divide-slate-100">
                    <div class="flex items-center justify-between gap-2 px-5 py-2.5">
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Connection</dt>
                        <dd><StatusDot :state="status.connected" on="Connected" off="Not Connected" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 px-5 py-2.5">
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Provider</dt>
                        <dd class="text-sm font-medium capitalize text-slate-800">{{ settings.provider }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 px-5 py-2.5">
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Model</dt>
                        <dd class="truncate font-mono text-sm text-slate-800">
                            {{ result?.model ?? settings.model }}
                        </dd>
                    </div>
                    <div v-if="result" class="flex items-center justify-between gap-2 px-5 py-2.5">
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Execution</dt>
                        <dd class="text-sm font-semibold tabular-nums text-slate-800">
                            {{ result.seconds.toFixed(2) }} seconds
                        </dd>
                    </div>
                    <div v-if="result?.prompt_tokens !== null && result" class="flex items-center justify-between gap-2 px-5 py-2.5">
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Tokens</dt>
                        <dd class="text-sm tabular-nums text-slate-800">
                            {{ result.prompt_tokens }} in / {{ result.response_tokens ?? '—' }} out
                        </dd>
                    </div>
                    <div v-if="result?.log_id" class="flex items-center justify-between gap-2 px-5 py-2.5">
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Logged as</dt>
                        <dd>
                            <Link
                                :href="routes.settings.ai.logs()"
                                class="text-sm font-medium text-indigo-600 hover:underline"
                            >
                                #{{ result.log_id }}
                            </Link>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Examples -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Example prompts</h2>
                    <p class="text-xs text-slate-500">Click to load into the editor.</p>
                </header>

                <ul class="divide-y divide-slate-100">
                    <li v-for="example in examplePrompts" :key="example.label">
                        <button
                            type="button"
                            class="w-full px-5 py-3 text-left transition-colors hover:bg-slate-50"
                            @click="useExample(example.prompt)"
                        >
                            <span class="block text-sm font-medium text-slate-800">{{ example.label }}</span>
                            <span class="mt-0.5 block truncate text-xs text-slate-500">{{ example.prompt }}</span>
                        </button>
                    </li>
                </ul>
            </section>

            <!-- System prompt in effect -->
            <section class="card">
                <header class="flex items-center justify-between gap-2 border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">System prompt</h2>
                    <button
                        type="button"
                        class="text-xs font-medium text-indigo-600 hover:underline"
                        @click="showSystemPrompt = !showSystemPrompt"
                    >
                        {{ showSystemPrompt ? 'Hide' : 'Show' }}
                    </button>
                </header>

                <div v-if="showSystemPrompt" class="px-5 py-4">
                    <pre
                        class="max-h-64 overflow-auto whitespace-pre-wrap font-mono text-xs text-slate-600"
                        >{{ systemPrompt }}</pre
                    >
                </div>
                <p v-else class="px-5 py-3 text-xs text-slate-500">
                    Applied to every run. Edit it in AI Configuration.
                </p>
            </section>
        </div>
    </div>
</template>
