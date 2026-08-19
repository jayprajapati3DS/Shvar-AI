<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Badge from '@/Components/Badge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import QualityPanel from '@/Components/Email/QualityPanel.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { routes } from '@/routes';
import type {
    EmailDraft,
    EmailGeneration,
    EmailPreview,
    QualityResult,
    SendingMode,
} from '@/types/models';

const { draft, quality, preview, signatureConfigured, generation, sending } = defineProps<{
    draft: { data: EmailDraft };
    quality: QualityResult;
    /** What the recipient would see - carries nothing internal. */
    preview: EmailPreview;
    signatureConfigured: boolean;
    generation: EmailGeneration | null;
    sending: SendingMode;
}>();

const record = computed(() => draft.data);

const form = useForm({ subject: '', body: '' });

// Reset from the server whenever the draft changes underneath us - after a save,
// an approval, or a back-navigation.
watch(
    () => record.value.id + ':' + record.value.updated_at,
    () => {
        form.defaults({ subject: record.value.subject, body: record.value.body });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const dirty = computed(
    () => form.subject !== record.value.subject || form.body !== record.value.body,
);

const wordCount = computed(
    () => form.body.trim().split(/\s+/).filter(Boolean).length,
);

const confirmingApprove = ref(false);
const confirmingSend = ref(false);
const confirmingDelete = ref(false);
const showOriginal = ref(false);
const showVersions = ref(false);

function save() {
    form.put(routes.emailDrafts.update(record.value.id), { preserveScroll: true });
}

function approve() {
    router.post(
        routes.emailDrafts.approve(record.value.id),
        {},
        { preserveScroll: true, onFinish: () => (confirmingApprove.value = false) },
    );
}

function send() {
    router.post(
        routes.emailDrafts.send(record.value.id),
        {},
        { preserveScroll: true, onFinish: () => (confirmingSend.value = false) },
    );
}

function act(url: string) {
    router.post(url, {}, { preserveScroll: true });
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <Head :title="record.subject" />

    <PageHeader
        :title="record.recipient_name ? `Email to ${record.recipient_name}` : 'Email draft'"
        :subtitle="record.lead?.company?.name ?? undefined"
    >
        <template #actions>
            <Link :href="routes.emailDrafts.index()" class="btn-secondary">All drafts</Link>
            <Link
                v-if="record.lead"
                :href="routes.leads.show(record.lead.id)"
                class="btn-secondary"
            >
                Open lead
            </Link>
        </template>
    </PageHeader>

    <!-- Status strip. What this draft is, and what it is allowed to do. -->
    <div class="card mb-6 flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
        <div class="flex items-center gap-2">
            <Badge :color="record.status_color">{{ record.status_label }}</Badge>
            <Badge :color="record.variant_color" size="sm">{{ record.variant_label }}</Badge>
        </div>

        <dl class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
            <div>
                <dt class="inline font-medium text-slate-600">To:</dt>
                <dd class="inline"> {{ record.recipient_email ?? 'no address' }}</dd>
            </div>
            <div v-if="record.product">
                <dt class="inline font-medium text-slate-600">Product:</dt>
                <dd class="inline"> {{ record.product.name }}</dd>
            </div>
            <div v-if="record.ai_model">
                <dt class="inline font-medium text-slate-600">Written by:</dt>
                <dd class="inline"> {{ record.ai_model }} (local)</dd>
            </div>
            <div>
                <dt class="inline font-medium text-slate-600">Version:</dt>
                <dd class="inline"> {{ record.version }}</dd>
            </div>
            <div v-if="record.approved_at">
                <dt class="inline font-medium text-slate-600">Approved:</dt>
                <dd class="inline"> {{ formatDateTime(record.approved_at) }}</dd>
            </div>
            <div v-if="record.sent_at">
                <dt class="inline font-medium text-slate-600">Sent:</dt>
                <dd class="inline">
                    {{ formatDateTime(record.sent_at) }}
                    <span v-if="record.delivery_mode === 'simulated'">(simulated)</span>
                </dd>
            </div>
        </dl>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <!-- ------------------------------------------------ editor -->
        <div class="space-y-6 xl:col-span-2">
            <section class="card p-5">
                <header class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900">Edit</h2>

                    <div class="flex items-center gap-3 text-xs text-slate-500">
                        <span>{{ wordCount }} words</span>
                        <button
                            v-if="record.ai_body && record.was_edited"
                            type="button"
                            class="font-medium text-indigo-600 hover:text-indigo-700"
                            @click="showOriginal = true"
                        >
                            Compare with the AI original
                        </button>
                        <button
                            v-if="(record.versions?.length ?? 0) > 1"
                            type="button"
                            class="font-medium text-indigo-600 hover:text-indigo-700"
                            @click="showVersions = true"
                        >
                            {{ record.versions?.length }} versions
                        </button>
                    </div>
                </header>

                <p
                    v-if="!record.is_editable"
                    class="mb-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600"
                >
                    This draft is <strong>{{ record.status_label }}</strong> and can no longer be
                    edited.
                    <span v-if="record.status === 'Approved'">
                        Editing it would revoke the approval, so reject it first if you need changes.
                    </span>
                </p>

                <form class="space-y-4" @submit.prevent="save">
                    <div>
                        <label for="subject" class="label">Subject</label>
                        <input
                            id="subject"
                            v-model="form.subject"
                            type="text"
                            class="input"
                            :disabled="!record.is_editable"
                            autocomplete="off"
                        />
                        <p v-if="form.errors.subject" class="mt-1 text-xs font-medium text-red-600">
                            {{ form.errors.subject }}
                        </p>
                    </div>

                    <div>
                        <label for="body" class="label">Email</label>
                        <textarea
                            id="body"
                            v-model="form.body"
                            rows="16"
                            class="input leading-relaxed"
                            :disabled="!record.is_editable"
                        />
                        <p v-if="form.errors.body" class="mt-1 text-xs font-medium text-red-600">
                            {{ form.errors.body }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            Plain text. Your signature is appended automatically — do not type it here.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="submit"
                            class="btn-primary"
                            :disabled="!record.is_editable || form.processing || !dirty"
                        >
                            {{ form.processing ? 'Saving…' : 'Save draft' }}
                        </button>

                        <button
                            v-if="record.is_approvable"
                            type="button"
                            class="btn-secondary"
                            :disabled="dirty"
                            :title="dirty ? 'Save your changes first.' : undefined"
                            @click="confirmingApprove = true"
                        >
                            Approve
                        </button>

                        <button
                            v-if="record.is_sendable && sending.allowed"
                            type="button"
                            class="btn-primary"
                            @click="confirmingSend = true"
                        >
                            {{ sending.simulated ? 'Test send (simulated)' : 'Send' }}
                        </button>

                        <span class="grow" />

                        <button
                            v-if="record.is_approvable"
                            type="button"
                            class="btn-ghost text-xs text-red-600 hover:bg-red-50"
                            @click="act(routes.emailDrafts.reject(record.id))"
                        >
                            Reject
                        </button>

                        <button
                            v-if="!['Archived', 'Sent'].includes(record.status)"
                            type="button"
                            class="btn-ghost text-xs"
                            @click="act(routes.emailDrafts.archive(record.id))"
                        >
                            Archive
                        </button>

                        <button
                            type="button"
                            class="btn-ghost text-xs text-red-600 hover:bg-red-50"
                            @click="confirmingDelete = true"
                        >
                            Delete
                        </button>
                    </div>
                </form>
            </section>

            <!--
                What the recipient would see. Deliberately carries nothing
                internal - no model name, no confidence, no reasoning, no notes.
            -->
            <section class="card p-5">
                <header class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900">Preview</h2>
                    <span class="text-xs text-slate-500">What the recipient would see</span>
                </header>

                <div class="rounded-md border border-slate-200 bg-slate-50/60 p-4">
                    <dl class="mb-3 space-y-1 border-b border-slate-200 pb-3 text-xs text-slate-600">
                        <div>
                            <dt class="inline font-medium">From:</dt>
                            <dd class="inline">
                                {{ preview.from_name ?? '—' }}
                                <span v-if="preview.from">&lt;{{ preview.from }}&gt;</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="inline font-medium">To:</dt>
                            <dd class="inline">
                                {{ preview.to_name ?? '—' }}
                                <span v-if="preview.to">&lt;{{ preview.to }}&gt;</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="inline font-medium">Subject:</dt>
                            <dd class="inline"> {{ preview.subject }}</dd>
                        </div>
                    </dl>

                    <pre class="whitespace-pre-wrap font-sans text-sm leading-relaxed text-slate-800">{{ preview.body }}</pre>
                </div>

                <p v-if="!signatureConfigured" class="mt-3 text-xs text-amber-700">
                    No signature configured — this email would go out unsigned.
                    <Link :href="routes.settings.email.index()" class="font-medium underline">
                        Set one up
                    </Link>
                </p>
            </section>
        </div>

        <!-- ------------------------------------------------ sidebar -->
        <div class="space-y-6">
            <QualityPanel :quality="quality" />

            <section v-if="generation" class="card p-5">
                <h2 class="mb-3 text-sm font-semibold text-slate-900">How this was written</h2>

                <dl class="mb-4 space-y-1 text-xs text-slate-500">
                    <div>
                        <dt class="inline font-medium text-slate-600">Model:</dt>
                        <dd class="inline"> {{ generation.model }} (local)</dd>
                    </div>
                    <div>
                        <dt class="inline font-medium text-slate-600">Tone:</dt>
                        <dd class="inline"> {{ generation.tone }}, {{ generation.length }}</dd>
                    </div>
                    <div v-if="generation.extra_instructions">
                        <dt class="font-medium text-slate-600">Your instructions:</dt>
                        <dd class="italic">"{{ generation.extra_instructions }}"</dd>
                    </div>
                </dl>

                <div v-if="generation.personalization_points.length" class="mb-4">
                    <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Personalised on
                    </h3>
                    <ul class="space-y-1 text-xs text-slate-600">
                        <li v-for="point in generation.personalization_points" :key="point">
                            • {{ point }}
                        </li>
                    </ul>
                </div>

                <div v-if="generation.claims_used.length" class="mb-4">
                    <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Product claims made
                    </h3>
                    <ul class="space-y-1 text-xs text-slate-600">
                        <li v-for="claim in generation.claims_used" :key="claim">• {{ claim }}</li>
                    </ul>
                    <p class="mt-1.5 text-xs text-slate-400">
                        Each should be traceable to the product record. Check anything that is not.
                    </p>
                </div>

                <div v-if="generation.warnings.length" class="mb-4">
                    <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-amber-700">
                        The output was filtered
                    </h3>
                    <ul class="space-y-1 text-xs text-amber-700">
                        <li v-for="warning in generation.warnings" :key="warning">• {{ warning }}</li>
                    </ul>
                </div>

                <div v-if="generation.missing_information.length">
                    <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Would have helped
                    </h3>
                    <ul class="space-y-1 text-xs text-slate-600">
                        <li v-for="item in generation.missing_information" :key="item">• {{ item }}</li>
                    </ul>
                </div>
            </section>

            <section class="card p-5">
                <h2 class="mb-2 text-sm font-semibold text-slate-900">Sending</h2>
                <p class="text-xs text-slate-600">{{ sending.description }}</p>
                <p v-if="!sending.allowed" class="mt-2 text-xs text-amber-700">
                    Sending is disabled in this environment.
                </p>
            </section>
        </div>
    </div>

    <!-- ------------------------------------------------ dialogs -->

    <Modal
        :open="showOriginal"
        title="The AI original"
        description="What the model wrote before you edited it. This is never overwritten."
        size="xl"
        @close="showOriginal = false"
    >
        <div class="space-y-4">
            <div>
                <p class="label">Subject</p>
                <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    {{ record.ai_subject }}
                </p>
            </div>
            <div>
                <p class="label">Email</p>
                <pre class="whitespace-pre-wrap rounded-md bg-slate-50 px-3 py-2 font-sans text-sm leading-relaxed text-slate-700">{{ record.ai_body }}</pre>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn-secondary" @click="showOriginal = false">Close</button>
        </template>
    </Modal>

    <Modal
        :open="showVersions"
        title="Version history"
        description="Every saved state of this draft. Nothing here is ever overwritten or deleted."
        size="xl"
        @close="showVersions = false"
    >
        <ol class="space-y-4">
            <li
                v-for="version in record.versions ?? []"
                :key="version.version"
                class="rounded-md border border-slate-200 p-3"
            >
                <header class="mb-2 flex items-center gap-2 text-xs">
                    <Badge :color="version.source === 'ai' ? 'sky' : 'amber'" size="sm">
                        v{{ version.version }} — {{ version.source === 'ai' ? 'AI' : 'you' }}
                    </Badge>
                    <span class="text-slate-400">
                        {{ formatDateTime(version.created_at) }} · {{ version.word_count }} words
                    </span>
                </header>

                <p class="mb-1 text-sm font-medium text-slate-800">{{ version.subject }}</p>
                <pre class="whitespace-pre-wrap font-sans text-xs leading-relaxed text-slate-600">{{ version.body }}</pre>
            </li>
        </ol>

        <template #footer>
            <button type="button" class="btn-secondary" @click="showVersions = false">Close</button>
        </template>
    </Modal>

    <!--
        Approval confirmation. Shows the recipient, subject, product and the full
        preview, because approving is the moment the user takes responsibility
        for what the model wrote.
    -->
    <Modal
        :open="confirmingApprove"
        title="Approve this email for sending?"
        size="xl"
        @close="confirmingApprove = false"
    >
        <div class="space-y-4">
            <dl class="grid grid-cols-1 gap-2 rounded-md bg-slate-50 p-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium text-slate-500">Recipient</dt>
                    <dd class="text-slate-800">
                        {{ preview.to_name }} &lt;{{ preview.to }}&gt;
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Product</dt>
                    <dd class="text-slate-800">{{ record.product?.name ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Subject</dt>
                    <dd class="text-slate-800">{{ preview.subject }}</dd>
                </div>
            </dl>

            <div>
                <p class="label">Email preview</p>
                <pre class="max-h-72 overflow-y-auto whitespace-pre-wrap rounded-md border border-slate-200 px-3 py-2 font-sans text-sm leading-relaxed text-slate-700">{{ preview.body }}</pre>
            </div>

            <p class="text-xs text-slate-500">
                Approving marks this email ready to send. It does not send it.
            </p>
        </div>

        <template #footer>
            <button type="button" class="btn-secondary" @click="confirmingApprove = false">
                Cancel
            </button>
            <button type="button" class="btn-primary" @click="approve">Approve</button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="confirmingSend"
        :title="sending.simulated ? 'Simulate sending this email?' : 'Send this email?'"
        :message="
            sending.simulated
                ? `Nothing will actually be sent. The message to ${preview.to} will be written to the local log and the draft marked Sent (simulated).`
                : `This will send the email to ${preview.to}.`
        "
        :confirm-label="sending.simulated ? 'Simulate send' : 'Send'"
        @cancel="confirmingSend = false"
        @confirm="send"
    />

    <ConfirmDialog
        :open="confirmingDelete"
        title="Delete this draft?"
        message="The draft and its entire version history are removed. This cannot be undone."
        @cancel="confirmingDelete = false"
        @confirm="router.delete(routes.emailDrafts.destroy(record.id))"
    />
</template>
