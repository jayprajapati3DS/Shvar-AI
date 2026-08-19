<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import PhaseTwoNotice from '@/Components/PhaseTwoNotice.vue';
import StatCard from '@/Components/StatCard.vue';
import { routes } from '@/routes';

const { context } = defineProps<{
    context: { leadsInFollowUp: number; contactedLeads: number };
}>();
</script>

<template>
    <Head title="Follow-ups" />

    <PageHeader
        title="Follow-ups"
        subtitle="Scheduling and reminders so a lead never goes quiet by accident."
    />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:max-w-lg">
        <StatCard
            label="In follow-up"
            :value="context.leadsInFollowUp"
            accent="amber"
            :href="routes.leads.index({ status: 'Follow-up' })"
        />
        <StatCard
            label="Contacted"
            :value="context.contactedLeads"
            accent="sky"
            hint="Awaiting a reply"
            :href="routes.leads.index({ status: 'Contacted' })"
        />
    </div>

    <PhaseTwoNotice
        blocked-on="Needs the email module for send and reply tracking before follow-ups mean anything. The local AI layer it will draft with is already in place."
        :planned="[
            'Follow-up scheduling per lead, with a due date',
            'Overdue and due-today queues',
            'AI-drafted follow-up copy aware of the previous message',
            'Automatic status transitions as follow-ups complete',
            'Local desktop reminders — no external notification service',
        ]"
    >
        <p class="text-sm text-slate-600">
            Until this ships, the
            <Link :href="routes.leads.index({ status: 'Follow-up' })" class="font-medium text-indigo-600 hover:underline">
                Follow-up status
            </Link>
            and each lead's activity timeline track this by hand.
        </p>
    </PhaseTwoNotice>
</template>
