<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import { routes } from '@/routes';
import type { BadgeColor, DashboardSummary, Lead, PipelineStage, Priority } from '@/types/models';

const { summary, pipeline, byPriority, recentLeads } = defineProps<{
    summary: DashboardSummary;
    pipeline: PipelineStage[];
    byPriority: Partial<Record<Priority, number>>;
    recentLeads: { data: Lead[] };
}>();

/** True when nothing has been entered yet - drives the empty state. */
const isEmpty = computed(() => summary.leads === 0 && summary.companies === 0 && summary.contacts === 0);

const pipelineTotal = computed(() => pipeline.reduce((total, stage) => total + stage.count, 0));

const bars: Record<BadgeColor, string> = {
    slate: 'bg-slate-400',
    sky: 'bg-sky-500',
    indigo: 'bg-indigo-500',
    amber: 'bg-amber-500',
    violet: 'bg-violet-500',
    orange: 'bg-orange-500',
    emerald: 'bg-emerald-500',
    red: 'bg-red-500',
};

const priorityRows: { key: Priority; color: BadgeColor }[] = [
    { key: 'High', color: 'red' },
    { key: 'Medium', color: 'amber' },
    { key: 'Low', color: 'slate' },
];
</script>

<template>
    <Head title="Dashboard" />

    <PageHeader
        title="Dashboard"
        subtitle="Live figures from your local database. Nothing here is estimated."
    >
        <template #actions>
            <Link :href="routes.import.create()" class="btn-secondary">Import CSV</Link>
            <Link :href="routes.leads.index()" class="btn-primary">View leads</Link>
        </template>
    </PageHeader>

    <!-- First-run state -->
    <div v-if="isEmpty" class="card">
        <EmptyState
            icon="inbox"
            title="No data yet"
            message="Add your first company, contact and lead - or import a CSV to populate everything at once. Your product portfolio is already seeded."
        >
            <div class="flex flex-wrap justify-center gap-2">
                <Link :href="routes.companies.index()" class="btn-primary">Add a company</Link>
                <Link :href="routes.import.create()" class="btn-secondary">Import CSV</Link>
                <Link :href="routes.products.index()" class="btn-secondary">View products</Link>
            </div>
        </EmptyState>
    </div>

    <template v-else>
        <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard label="Companies" :value="summary.companies" accent="slate" :href="routes.companies.index()" />
            <StatCard label="Contacts" :value="summary.contacts" accent="slate" :href="routes.contacts.index()" />
            <StatCard label="Total Leads" :value="summary.leads" accent="indigo" :href="routes.leads.index()" />
            <StatCard label="New Leads" :value="summary.new_leads" accent="sky" :href="routes.leads.index({ status: 'New' })" />
            <StatCard label="Qualified" :value="summary.qualified_leads" accent="indigo" :href="routes.leads.index({ status: 'Qualified' })" />
            <StatCard label="Follow-ups" :value="summary.follow_ups" accent="amber" :href="routes.leads.index({ status: 'Follow-up' })" />
            <StatCard
                label="Opportunities"
                :value="summary.opportunities"
                accent="violet"
                hint="Interested → Negotiation"
            />
            <StatCard label="Won" :value="summary.won" accent="emerald" :href="routes.leads.index({ status: 'Won' })" />
        </section>

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
            <!-- Pipeline -->
            <section class="card xl:col-span-2">
                <header class="flex items-baseline justify-between border-b border-slate-200 px-5 py-3.5">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Lead Pipeline</h2>
                        <p class="text-xs text-slate-500">
                            Bars are scaled to the busiest stage.
                        </p>
                    </div>
                    <p class="text-xs text-slate-500">
                        <span class="font-semibold text-slate-700">{{ pipelineTotal }}</span> in pipeline
                    </p>
                </header>

                <div v-if="pipelineTotal === 0" class="px-5">
                    <EmptyState
                        icon="search"
                        title="No leads in the pipeline"
                        message="Leads appear here as soon as you create one."
                    />
                </div>

                <ol v-else class="space-y-2.5 px-5 py-4">
                    <li v-for="stage in pipeline" :key="stage.status">
                        <Link
                            :href="routes.leads.index({ status: stage.status })"
                            class="group grid grid-cols-[9.5rem_1fr_2.5rem] items-center gap-3"
                        >
                            <span class="truncate text-sm text-slate-600 group-hover:text-slate-900">
                                {{ stage.label }}
                            </span>

                            <span class="h-5 overflow-hidden rounded bg-slate-100">
                                <span
                                    class="block h-full rounded transition-all"
                                    :class="bars[stage.color]"
                                    :style="{ width: `${Math.max(stage.percentage, stage.count > 0 ? 3 : 0)}%` }"
                                />
                            </span>

                            <span class="text-right text-sm font-semibold tabular-nums text-slate-700">
                                {{ stage.count }}
                            </span>
                        </Link>
                    </li>
                </ol>
            </section>

            <!-- Priority split -->
            <section class="card">
                <header class="border-b border-slate-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-slate-900">By Priority</h2>
                    <p class="text-xs text-slate-500">Across all open and closed leads.</p>
                </header>

                <ul class="divide-y divide-slate-100">
                    <li
                        v-for="row in priorityRows"
                        :key="row.key"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <Badge :color="row.color">{{ row.key }}</Badge>
                        <Link
                            :href="routes.leads.index({ priority: row.key })"
                            class="text-sm font-semibold tabular-nums text-slate-700 hover:text-indigo-600"
                        >
                            {{ byPriority[row.key] ?? 0 }}
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Recently updated -->
        <section class="card mt-6">
            <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-900">Recently Updated Leads</h2>
                <Link :href="routes.leads.index()" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                    View all
                </Link>
            </header>

            <EmptyState
                v-if="recentLeads.data.length === 0"
                icon="inbox"
                title="Nothing updated yet"
            />

            <ul v-else class="divide-y divide-slate-100">
                <li v-for="lead in recentLeads.data" :key="lead.id">
                    <Link
                        :href="routes.leads.show(lead.id)"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 transition-colors hover:bg-slate-50"
                    >
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">
                            {{ lead.company?.name ?? 'No company' }}
                        </span>

                        <span class="min-w-0 flex-1 truncate text-sm text-slate-500">
                            {{ lead.contact?.full_name ?? 'No contact' }}
                        </span>

                        <Badge :color="lead.status_color" size="sm">{{ lead.lead_status }}</Badge>
                        <Badge :color="lead.priority_color" size="sm">{{ lead.priority }}</Badge>

                        <span class="w-24 shrink-0 text-right text-xs text-slate-400">
                            {{ lead.updated_for_humans }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>
    </template>
</template>
