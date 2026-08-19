<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import PhaseTwoNotice from '@/Components/PhaseTwoNotice.vue';
import StatCard from '@/Components/StatCard.vue';
import { routes } from '@/routes';

const { context } = defineProps<{
    context: { products: number; activeProducts: number };
}>();
</script>

<template>
    <Head title="Knowledge Base" />

    <PageHeader
        title="Knowledge Base"
        subtitle="The reference material a local model will retrieve from when it drafts and recommends."
    />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:max-w-lg">
        <StatCard label="Products in catalogue" :value="context.products" accent="indigo" :href="routes.products.index()" />
        <StatCard label="Active" :value="context.activeProducts" accent="emerald" :href="routes.products.index({ active: '1' })" />
    </div>

    <PhaseTwoNotice
        blocked-on="Needs local embeddings and a local vector store for retrieval. The model is running; the retrieval layer is not built."
        :planned="[
            'Upload reference documents — spec sheets, clinical papers, case studies',
            'Local embeddings and a local vector store; nothing is uploaded anywhere',
            'Retrieval-augmented answers grounded in your own material',
            'Product knowledge cited when a recommendation is made',
            'Objection handling and competitor notes',
        ]"
    >
        <p class="text-sm text-slate-600">
            Your
            <Link :href="routes.products.index()" class="font-medium text-indigo-600 hover:underline">product portfolio</Link>
            is the first knowledge source and is already populated — descriptions, features, target customers and value
            propositions are all in the database.
        </p>
    </PhaseTwoNotice>
</template>
