<script setup lang="ts">
/**
 * The SMTP connection form.
 *
 * The password field is write-only. The page is never sent the stored password
 * - only whether one exists - so the field starts blank with a "already set"
 * note, and submits a sentinel when it was not retyped. Leaving it blank on a
 * save must not wipe the credential.
 */
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FormField from '@/Components/FormField.vue';
import { routes } from '@/routes';
import type { SmtpSettings } from '@/types/models';

/** What SmtpSettings::UNCHANGED expects. */
const UNCHANGED = '__unchanged__';

const { smtp, live } = defineProps<{
    smtp: SmtpSettings;
    /** True when EMAIL_DRIVER is 'smtp' - i.e. these settings are in use. */
    live: boolean;
}>();

const form = useForm({
    smtp_host: smtp.host ?? '',
    smtp_port: String(smtp.port ?? 587),
    smtp_encryption: smtp.encryption ?? 'tls',
    smtp_username: smtp.username ?? '',
    smtp_password: '',
    smtp_from_address: smtp.from_address ?? '',
    smtp_from_name: smtp.from_name ?? '',
});

const testing = ref(false);
const showPassword = ref(false);

const passwordPlaceholder = computed(() =>
    smtp.password_set ? 'Stored — leave blank to keep it' : 'Your SMTP password',
);

function submit() {
    form
        .transform((data) => ({
            ...data,
            // Blank means "I did not retype it", not "clear it".
            smtp_password: data.smtp_password === '' ? UNCHANGED : data.smtp_password,
        }))
        .put(routes.settings.email.updateSmtp(), {
            preserveScroll: true,
            onSuccess: () => {
                form.smtp_password = '';
            },
        });
}

function test() {
    testing.value = true;

    router.post(
        routes.settings.email.testSmtp(),
        {},
        { preserveScroll: true, onFinish: () => (testing.value = false) },
    );
}

function forget() {
    router.delete(routes.settings.email.forgetSmtp(), { preserveScroll: true });
}
</script>

<template>
    <section class="card p-5">
        <header class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Mail server (SMTP)</h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    For Microsoft 365: <code class="text-slate-600">smtp.office365.com</code>, port 587,
                    STARTTLS. Your username is your full email address.
                </p>
            </div>

            <button
                v-if="smtp.configured"
                type="button"
                class="btn-ghost shrink-0 px-2 py-1 text-xs text-red-600 hover:bg-red-50"
                @click="forget"
            >
                Clear
            </button>
        </header>

        <!--
            Whether these settings are actually in use. Saving them does nothing
            on its own - EMAIL_DRIVER decides, and it is config-only.
        -->
        <p
            v-if="live"
            class="mb-4 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900"
        >
            <strong>These settings are live.</strong>
            EMAIL_DRIVER is <code>smtp</code>, so an approved email really is sent.
        </p>
        <p v-else class="mb-4 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
            Saved but <strong>not in use</strong>. Sending is simulated until you set
            <code>EMAIL_DRIVER=smtp</code> in <code>.env</code>. Configure and test the connection
            first, then switch.
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id }" label="Host" :error="form.errors.smtp_host">
                    <input
                        :id="id"
                        v-model="form.smtp_host"
                        type="text"
                        class="input"
                        placeholder="smtp.office365.com"
                        autocomplete="off"
                    />
                </FormField>

                <div class="grid grid-cols-2 gap-4">
                    <FormField v-slot="{ id }" label="Port" :error="form.errors.smtp_port">
                        <input :id="id" v-model="form.smtp_port" type="number" class="input" />
                    </FormField>

                    <FormField v-slot="{ id }" label="Encryption" :error="form.errors.smtp_encryption">
                        <select :id="id" v-model="form.smtp_encryption" class="input">
                            <option value="tls">STARTTLS (587)</option>
                            <option value="ssl">SSL/TLS (465)</option>
                            <option value="none">None</option>
                        </select>
                    </FormField>
                </div>

                <FormField
                    v-slot="{ id }"
                    label="Username"
                    :error="form.errors.smtp_username"
                    hint="Usually your full email address."
                >
                    <input
                        :id="id"
                        v-model="form.smtp_username"
                        type="text"
                        class="input"
                        autocomplete="off"
                    />
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="Password"
                    :error="form.errors.smtp_password"
                    :hint="
                        smtp.password_set
                            ? 'Encrypted and stored. Leave blank to keep it.'
                            : 'Encrypted before it is stored, and never shown again.'
                    "
                >
                    <div class="relative">
                        <input
                            :id="id"
                            v-model="form.smtp_password"
                            :type="showPassword ? 'text' : 'password'"
                            class="input pr-16"
                            :placeholder="passwordPlaceholder"
                            autocomplete="new-password"
                        />
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-slate-500 hover:text-slate-700"
                            @click="showPassword = !showPassword"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="From address"
                    :error="form.errors.smtp_from_address"
                    hint="Leave blank to use your profile email address."
                >
                    <input :id="id" v-model="form.smtp_from_address" type="email" class="input" />
                </FormField>

                <FormField
                    v-slot="{ id }"
                    label="From name"
                    :error="form.errors.smtp_from_name"
                    hint="Leave blank to use your profile name."
                >
                    <input :id="id" v-model="form.smtp_from_name" type="text" class="input" />
                </FormField>
            </div>

            <p v-if="smtp.gaps.length" class="text-xs text-amber-700">
                Still missing: {{ smtp.gaps.join(', ') }}.
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="btn-primary" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save connection' }}
                </button>

                <button
                    type="button"
                    class="btn-secondary"
                    :disabled="!smtp.configured || testing"
                    :title="smtp.configured ? undefined : 'Save the host, username and password first.'"
                    @click="test"
                >
                    {{ testing ? 'Connecting…' : 'Test connection' }}
                </button>

                <span class="text-xs text-slate-500">
                    Testing connects and authenticates. It sends nothing.
                </span>
            </div>
        </form>
    </section>
</template>
