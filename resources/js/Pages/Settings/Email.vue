<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { routes } from '@/routes';
import type { SelectOption, SendingMode } from '@/types/models';

interface EmailSettingsPayload {
    sender_name: string | null;
    sender_job_title: string | null;
    sender_company: string | null;
    sender_email: string | null;
    sender_phone: string | null;
    sender_website: string | null;
    sender_linkedin: string | null;
    signature: string;
    custom_signature: string | null;
    using_composed_signature: boolean;
    configured: boolean;
    gaps: string[];
    tone: string;
    length: string;
}

const { settings, options, preview, sending } = defineProps<{
    settings: EmailSettingsPayload;
    options: { tones: SelectOption[]; lengths: SelectOption[] };
    preview: string;
    sending: SendingMode;
}>();

const form = useForm({
    sender_name: settings.sender_name ?? '',
    sender_job_title: settings.sender_job_title ?? '',
    sender_company: settings.sender_company ?? '',
    sender_email: settings.sender_email ?? '',
    sender_phone: settings.sender_phone ?? '',
    sender_website: settings.sender_website ?? '',
    sender_linkedin: settings.sender_linkedin ?? '',
    signature: settings.custom_signature ?? '',
    tone: settings.tone,
    length: settings.length,
});

/**
 * The composed signature, previewed live.
 *
 * Mirrors EmailSettings::signature() - a hand-written override wins outright,
 * otherwise the non-blank profile fields are joined one per line.
 */
const composed = computed(() => {
    if (form.signature.trim() !== '') {
        return form.signature.trim();
    }

    return [
        form.sender_name,
        form.sender_job_title,
        form.sender_company,
        form.sender_email,
        form.sender_phone,
        form.sender_website,
    ]
        .map((v) => v.trim())
        .filter(Boolean)
        .join('\n');
});

function submit() {
    form.put(routes.settings.email.update(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Email settings" />

    <PageHeader
        title="Email"
        subtitle="Your signature and how the local model should write for you"
    >
        <template #actions>
            <Link :href="routes.settings.ai.index()" class="btn-secondary">AI settings</Link>
            <Link :href="routes.emailDrafts.index()" class="btn-secondary">Email drafts</Link>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <form class="space-y-6 lg:col-span-2" @submit.prevent="submit">
            <!-- ------------------------------------------------ profile -->
            <section class="card p-5">
                <header class="mb-4">
                    <h2 class="text-sm font-semibold text-slate-900">Your details</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        These build the signature appended to every email. The AI never writes a
                        signature — a model asked to sign off invents job titles and phone numbers.
                    </p>
                </header>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ id }" label="Name" :error="form.errors.sender_name">
                        <input :id="id" v-model="form.sender_name" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Job title" :error="form.errors.sender_job_title">
                        <input :id="id" v-model="form.sender_job_title" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Company" :error="form.errors.sender_company">
                        <input :id="id" v-model="form.sender_company" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Email address" :error="form.errors.sender_email">
                        <input :id="id" v-model="form.sender_email" type="email" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Phone" :error="form.errors.sender_phone">
                        <input :id="id" v-model="form.sender_phone" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Website" :error="form.errors.sender_website">
                        <input :id="id" v-model="form.sender_website" type="text" class="input" autocomplete="off" />
                    </FormField>

                    <FormField
                        v-slot="{ id }"
                        label="LinkedIn"
                        :error="form.errors.sender_linkedin"
                        hint="Stored for reference. Not added to the signature automatically."
                    >
                        <input :id="id" v-model="form.sender_linkedin" type="text" class="input" autocomplete="off" />
                    </FormField>
                </div>
            </section>

            <!-- ------------------------------------------------ signature -->
            <section class="card p-5">
                <header class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Signature</h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Leave this blank to compose one from the fields above.
                        </p>
                    </div>

                    <button
                        v-if="!settings.using_composed_signature"
                        type="button"
                        class="btn-ghost px-2 py-1 text-xs"
                        @click="router.delete(routes.settings.email.resetSignature(), { preserveScroll: true })"
                    >
                        Reset to composed
                    </button>
                </header>

                <FormField
                    v-slot="{ id }"
                    label="Custom signature"
                    :error="form.errors.signature"
                    hint="Plain text, one line each. Overrides the composed signature entirely."
                >
                    <textarea :id="id" v-model="form.signature" rows="6" class="input font-mono text-xs" />
                </FormField>
            </section>

            <!-- ------------------------------------------------ writing -->
            <section class="card p-5">
                <header class="mb-4">
                    <h2 class="text-sm font-semibold text-slate-900">How the AI writes</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Passed into every generation prompt. The length also sets what the quality
                        check measures against.
                    </p>
                </header>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ id }" label="Tone" :error="form.errors.tone">
                        <select :id="id" v-model="form.tone" class="input">
                            <option v-for="tone in options.tones" :key="tone.value" :value="tone.value">
                                {{ tone.label }}
                            </option>
                        </select>
                    </FormField>

                    <FormField v-slot="{ id }" label="Length" :error="form.errors.length">
                        <select :id="id" v-model="form.length" class="input">
                            <option v-for="len in options.lengths" :key="len.value" :value="len.value">
                                {{ len.label }}
                            </option>
                        </select>
                    </FormField>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn-primary" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save settings' }}
                </button>
                <span v-if="form.recentlySuccessful" class="text-sm text-emerald-600">Saved.</span>
            </div>
        </form>

        <!-- ------------------------------------------------ sidebar -->
        <div class="space-y-6">
            <section class="card p-5">
                <h2 class="mb-3 text-sm font-semibold text-slate-900">Signature preview</h2>

                <pre
                    v-if="composed"
                    class="whitespace-pre-wrap rounded-md bg-slate-50 px-3 py-2 font-sans text-sm leading-relaxed text-slate-700"
                >{{ composed }}</pre>

                <p v-else class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Nothing configured yet — emails would go out unsigned.
                </p>

                <p v-if="settings.gaps.length" class="mt-3 text-xs text-slate-500">
                    Still blank: {{ settings.gaps.join(', ') }}.
                </p>
            </section>

            <section class="card p-5">
                <h2 class="mb-3 text-sm font-semibold text-slate-900">In an email</h2>
                <pre class="whitespace-pre-wrap rounded-md border border-slate-200 px-3 py-2 font-sans text-sm leading-relaxed text-slate-700">{{ preview }}</pre>
            </section>

            <section class="card border-indigo-100 bg-indigo-50/60 p-5">
                <h2 class="mb-2 text-sm font-semibold text-indigo-900">Sending</h2>
                <p class="text-xs text-indigo-900">{{ sending.description }}</p>
                <p class="mt-2 text-xs text-indigo-800">
                    There is no mail server, account or API key to configure — Phase 4 cannot send an
                    email at all.
                </p>
            </section>
        </div>
    </div>
</template>
