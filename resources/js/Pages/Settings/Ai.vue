<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LocalAiNotice from '@/Components/Ai/LocalAiNotice.vue';
import SettingsTabs from '@/Components/Ai/SettingsTabs.vue';
import StatusDot from '@/Components/Ai/StatusDot.vue';
import Badge from '@/Components/Badge.vue';
import DetailList from '@/Components/DetailList.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import { routes } from '@/routes';
import type { AiLogStats, AiRequestTypeOption, AiSettings, AiStatus } from '@/types/ai';
import type { DetailItem } from '@/types/ui';

const { settings, status, defaults, requestTypes, stats, environment } = defineProps<{
    settings: AiSettings;
    status: AiStatus;
    defaults: {
        system_prompt: string;
        model: string;
        temperature: number;
        timeout: number;
    };
    requestTypes: AiRequestTypeOption[];
    stats: AiLogStats;
    environment: {
        appName: string;
        phpVersion: string;
        laravelVersion: string;
        database: string;
        databasePath: string | null;
    };
}>();

const testing = ref(false);
const refreshing = ref(false);
const showSystemPrompt = ref(false);

const form = useForm({
    model: settings.model,
    temperature: settings.temperature,
    timeout: settings.timeout,
    max_tokens: settings.max_tokens,
    system_prompt: settings.using_default_system_prompt ? '' : settings.system_prompt,
});

/**
 * The model dropdown offers what is installed. If the configured model is NOT
 * installed we still list it, flagged — otherwise the form would silently
 * rewrite the user's setting to something else just by rendering.
 */
const modelOptions = computed(() => {
    const installed = status.installed_models;

    return installed.includes(settings.model) ? installed : [settings.model, ...installed];
});

const environmentItems = computed<DetailItem[]>(() => [
    { label: 'Application', value: environment.appName },
    { label: 'Laravel', value: environment.laravelVersion },
    { label: 'PHP', value: environment.phpVersion },
    { label: 'Ollama version', value: status.version },
    { label: 'Database driver', value: environment.database },
    { label: 'Database file', value: environment.databasePath, wide: true },
]);

function save() {
    form.transform((data) => ({
        ...data,
        // An empty textarea means "use the shipped default", not "empty prompt".
        system_prompt: data.system_prompt === '' ? null : data.system_prompt,
    })).put(routes.settings.ai.update(), { preserveScroll: true });
}

function testConnection() {
    testing.value = true;

    router.post(routes.settings.ai.test(), {}, {
        preserveScroll: true,
        onFinish: () => (testing.value = false),
    });
}

function refreshStatus() {
    refreshing.value = true;

    router.post(routes.settings.ai.refresh(), {}, {
        preserveScroll: true,
        onFinish: () => (refreshing.value = false),
    });
}

function resetSystemPrompt() {
    router.delete(routes.settings.ai.resetSystemPrompt(), {
        preserveScroll: true,
        onSuccess: () => {
            form.system_prompt = '';
        },
    });
}
</script>

<template>
    <Head title="AI Configuration" />

    <PageHeader
        title="Settings"
        subtitle="Local AI configuration. Inference runs on this machine through Ollama."
    >
        <template #actions>
            <button type="button" class="btn-secondary" :disabled="refreshing" @click="refreshStatus">
                {{ refreshing ? 'Checking…' : 'Re-check' }}
            </button>
            <button type="button" class="btn-primary" :disabled="testing" @click="testConnection">
                {{ testing ? 'Testing…' : 'Test AI Connection' }}
            </button>
        </template>
    </PageHeader>

    <SettingsTabs />

    <div class="mb-6">
        <LocalAiNotice :endpoint="settings.endpoint" />
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <!-- Live status -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Status</h2>
                    <p class="text-xs text-slate-500">
                        Probed when this page loaded<span v-if="status.probe_ms !== null">
                            · {{ status.probe_ms }} ms</span
                        >.
                    </p>
                </header>

                <dl class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-y-0 sm:divide-x">
                    <div class="px-5 py-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Connection</dt>
                        <dd class="mt-1.5">
                            <StatusDot :state="status.connected" on="Connected" off="Not Connected" />
                        </dd>
                    </div>

                    <div class="px-5 py-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Model</dt>
                        <dd class="mt-1.5">
                            <StatusDot
                                :state="status.connected ? status.model_installed : null"
                                on="Installed"
                                off="Not Installed"
                                unknown="Unknown — Ollama unreachable"
                            />
                        </dd>
                    </div>
                </dl>

                <!-- Problem explanation, when there is one -->
                <div v-if="status.message" class="border-t border-slate-200 bg-amber-50 px-5 py-3.5">
                    <p class="text-sm font-medium text-amber-900">{{ status.message }}</p>
                    <p v-if="status.hint" class="mt-1 text-xs text-amber-800">{{ status.hint }}</p>
                </div>

                <div class="grid grid-cols-1 gap-x-6 gap-y-3 border-t border-slate-200 px-5 py-4 sm:grid-cols-3">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">AI Provider</p>
                        <p class="mt-1 text-sm font-medium capitalize text-slate-800">{{ settings.provider }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Ollama URL</p>
                        <p class="mt-1 break-all font-mono text-sm text-slate-800">{{ settings.endpoint }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Configured Model</p>
                        <p class="mt-1 break-all font-mono text-sm text-slate-800">{{ settings.model }}</p>
                    </div>
                </div>
            </section>

            <!-- Configuration form -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">AI Configuration</h2>
                    <p class="text-xs text-slate-500">Saved locally to your database.</p>
                </header>

                <form class="space-y-5 px-5 py-4" @submit.prevent="save">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField v-slot="{ id }" label="Provider">
                            <input
                                :id="id"
                                :value="settings.provider"
                                type="text"
                                class="input font-mono"
                                disabled
                                readonly
                            />
                        </FormField>

                        <FormField
                            v-slot="{ id }"
                            label="Ollama URL"
                            hint="Set by OLLAMA_URL in .env. Not editable here — see below."
                        >
                            <input
                                :id="id"
                                :value="settings.endpoint"
                                type="text"
                                class="input font-mono"
                                disabled
                                readonly
                            />
                        </FormField>
                    </div>

                    <!-- Why the URL is locked -->
                    <p class="flex items-start gap-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        <svg class="mt-0.5 size-3.5 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" />
                        </svg>
                        <span>
                            The endpoint is server configuration on purpose. Allowing it to be changed from the browser
                            would make it possible to point AI traffic — and your CRM data — at a remote host. Only
                            local addresses are accepted.
                        </span>
                    </p>

                    <FormField
                        v-slot="{ id }"
                        label="Model"
                        :error="form.errors.model"
                        :hint="
                            status.connected
                                ? `${status.installed_models.length} model(s) installed locally.`
                                : 'Ollama is unreachable, so the installed list is unavailable.'
                        "
                        required
                    >
                        <select v-if="modelOptions.length" :id="id" v-model="form.model" class="input font-mono">
                            <option v-for="name in modelOptions" :key="name" :value="name">
                                {{ name }}{{ status.installed_models.includes(name) ? '' : '  (not installed)' }}
                            </option>
                        </select>

                        <input v-else :id="id" v-model="form.model" type="text" class="input font-mono" />
                    </FormField>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <FormField
                            v-slot="{ id }"
                            label="Temperature"
                            :error="form.errors.temperature"
                            :hint="`${settings.limits.min_temperature} – ${settings.limits.max_temperature}`"
                            required
                        >
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
                            label="Timeout (seconds)"
                            :error="form.errors.timeout"
                            :hint="`${settings.limits.min_timeout} – ${settings.limits.max_timeout}`"
                            required
                        >
                            <input
                                :id="id"
                                v-model.number="form.timeout"
                                type="number"
                                :min="settings.limits.min_timeout"
                                :max="settings.limits.max_timeout"
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

                    <!-- System prompt -->
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-slate-700">System prompt</span>
                            <div class="flex items-center gap-2">
                                <Badge :color="settings.using_default_system_prompt ? 'slate' : 'indigo'" size="sm">
                                    {{ settings.using_default_system_prompt ? 'Default' : 'Customised' }}
                                </Badge>
                                <button
                                    type="button"
                                    class="text-xs font-medium text-indigo-600 hover:underline"
                                    @click="showSystemPrompt = !showSystemPrompt"
                                >
                                    {{ showSystemPrompt ? 'Hide' : 'Edit' }}
                                </button>
                            </div>
                        </div>

                        <template v-if="showSystemPrompt">
                            <textarea
                                v-model="form.system_prompt"
                                rows="10"
                                class="input font-mono text-xs"
                                :placeholder="defaults.system_prompt"
                            />
                            <p v-if="form.errors.system_prompt" class="mt-1 text-xs font-medium text-red-600">
                                {{ form.errors.system_prompt }}
                            </p>
                            <div class="mt-2 flex items-center gap-3">
                                <p class="text-xs text-slate-500">
                                    Leave blank to use the shipped default. This is the Phase 2 base prompt —
                                    specialised sales prompts arrive in Phase 3.
                                </p>
                                <button
                                    v-if="!settings.using_default_system_prompt"
                                    type="button"
                                    class="shrink-0 text-xs font-medium text-red-600 hover:underline"
                                    @click="resetSystemPrompt"
                                >
                                    Reset to default
                                </button>
                            </div>
                        </template>

                        <pre
                            v-else
                            class="max-h-32 overflow-y-auto whitespace-pre-wrap rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600"
                            >{{ settings.system_prompt }}</pre
                        >
                    </div>

                    <div class="flex items-center gap-2 border-t border-slate-100 pt-4">
                        <button type="submit" class="btn-primary" :disabled="form.processing">
                            {{ form.processing ? 'Saving…' : 'Save settings' }}
                        </button>
                        <Link :href="routes.settings.ai.playground()" class="btn-secondary">Open Playground</Link>
                    </div>
                </form>
            </section>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Installed models -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Installed Models</h2>
                    <p class="text-xs text-slate-500">Read from Ollama. Never downloaded automatically.</p>
                </header>

                <ul v-if="status.installed_models.length" class="divide-y divide-slate-100">
                    <li
                        v-for="name in status.installed_models"
                        :key="name"
                        class="flex items-center justify-between gap-2 px-5 py-2.5"
                    >
                        <span class="truncate font-mono text-sm text-slate-800">{{ name }}</span>
                        <Badge v-if="name === settings.model" color="indigo" size="sm">Active</Badge>
                    </li>
                </ul>

                <div v-else class="px-5 py-4">
                    <p class="text-sm text-slate-500">
                        {{
                            status.connected
                                ? 'Ollama is running but no models are installed.'
                                : 'Cannot list models — Ollama is not reachable.'
                        }}
                    </p>
                    <p class="mt-2 rounded bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600">
                        ollama pull {{ settings.model }}
                    </p>
                    <p class="mt-1.5 text-xs text-slate-400">
                        Run that in your own terminal. This app never executes commands for you.
                    </p>
                </div>
            </section>

            <!-- Local log summary -->
            <section class="card">
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Local AI Log</h2>
                    <Link
                        :href="routes.settings.ai.logs()"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        View all
                    </Link>
                </header>

                <div class="grid grid-cols-2 gap-3 px-5 py-4">
                    <StatCard label="Requests" :value="stats.total" accent="slate" />
                    <StatCard label="Successful" :value="stats.success" accent="emerald" />
                    <StatCard label="Failed" :value="stats.failed" accent="red" />
                    <StatCard label="Avg ms" :value="stats.average_ms" accent="sky" />
                </div>

                <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                    Stored in your local database only. Never transmitted.
                </p>
            </section>

            <!-- Request types -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">AI Request Types</h2>
                    <p class="text-xs text-slate-500">Phase 2 implements General only.</p>
                </header>

                <ul class="divide-y divide-slate-100">
                    <li
                        v-for="type in requestTypes"
                        :key="type.value"
                        class="flex items-center justify-between gap-2 px-5 py-2.5"
                    >
                        <span class="text-sm text-slate-700">{{ type.label }}</span>
                        <Badge :color="type.implemented ? 'emerald' : 'slate'" size="sm">
                            {{ type.implemented ? 'Available' : 'Phase 3' }}
                        </Badge>
                    </li>
                </ul>
            </section>

            <!-- Environment -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">Environment</h2>
                </header>
                <div class="px-5 py-4">
                    <DetailList :items="environmentItems" :columns="1" />
                </div>
            </section>
        </div>
    </div>
</template>
