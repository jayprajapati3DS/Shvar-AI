<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import PhaseTwoNotice from '@/Components/PhaseTwoNotice.vue';
import StatCard from '@/Components/StatCard.vue';
import { routes } from '@/routes';

const { context } = defineProps<{
    context: { leadsAwaitingOutreach: number };
}>();
</script>

<template>
    <Head title="Email Drafts" />

    <PageHeader
        title="Email Drafts"
        subtitle="Personalised outreach, generated locally and reviewed by you before anything is sent."
    />

    <div class="mb-6 grid grid-cols-1 sm:max-w-xs">
        <StatCard
            label="Leads awaiting outreach"
            :value="context.leadsAwaitingOutreach"
            accent="indigo"
            hint="Qualified or Approved"
            :href="routes.leads.index({ status: 'Qualified' })"
        />
    </div>

    <PhaseTwoNotice
        blocked-on="The local AI layer is live and product recommendation works, but no email module exists yet: nothing here can draft, store or send a message."
        :planned="[
            'Draft an email per lead from the company profile and matched products',
            'Generation through a local model only — no cloud API',
            'Your review and approval before anything leaves the machine',
            'Full email history stored against the lead',
            'Reusable tone and template presets',
        ]"
    >
        <p class="text-sm text-slate-600">
            The data this will run on already exists. Keep filling in
            <Link :href="routes.companies.index()" class="font-medium text-indigo-600 hover:underline">companies</Link>
            and attaching
            <Link :href="routes.products.index()" class="font-medium text-indigo-600 hover:underline">products</Link>
            to leads — the better that context, the better the drafts will be.
        </p>
    </PhaseTwoNotice>
</template>
