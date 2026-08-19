/**
 * Typed URL builders.
 *
 * Kept hand-written rather than pulling in Ziggy: the route table is small,
 * and this keeps the dependency list short while still giving one place to
 * change a path. Mirrors routes/web.php.
 */

type Query = Record<string, string | number | boolean | null | undefined>;

/** Appends a query string, dropping empty values so filter URLs stay clean. */
function withQuery(path: string, query?: Query): string {
    if (!query) {
        return path;
    }

    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        params.append(key, String(value));
    }

    const qs = params.toString();

    return qs ? `${path}?${qs}` : path;
}

export const routes = {
    dashboard: () => '/',

    companies: {
        index: (q?: Query) => withQuery('/companies', q),
        show: (id: number) => `/companies/${id}`,
        store: () => '/companies',
        update: (id: number) => `/companies/${id}`,
        destroy: (id: number) => `/companies/${id}`,

        // Bulk actions - both POST, since the selection is a list of ids.
        bulkUpdate: () => '/companies/bulk/update',
        bulkDestroy: () => '/companies/bulk/delete',

        // Website research - the one feature that fetches an external page.
        research: (id: number) => `/companies/${id}/research`,
        researchRun: (id: number, analysisId: number) => `/companies/${id}/research/${analysisId}`,
        applyResearch: (id: number, analysisId: number) =>
            `/companies/${id}/research/${analysisId}/apply`,
    },

    contacts: {
        index: (q?: Query) => withQuery('/contacts', q),
        show: (id: number) => `/contacts/${id}`,
        store: () => '/contacts',
        update: (id: number) => `/contacts/${id}`,
        destroy: (id: number) => `/contacts/${id}`,

        // Bulk actions - both POST, since the selection is a list of ids.
        bulkUpdate: () => '/contacts/bulk/update',
        bulkDestroy: () => '/contacts/bulk/delete',
    },

    leads: {
        index: (q?: Query) => withQuery('/leads', q),
        show: (id: number) => `/leads/${id}`,
        store: () => '/leads',
        update: (id: number) => `/leads/${id}`,
        destroy: (id: number) => `/leads/${id}`,

        // Bulk actions - both POST, since the selection is a list of ids.
        bulkUpdate: () => '/leads/bulk/update',
        bulkDestroy: () => '/leads/bulk/delete',
        attachProduct: (id: number) => `/leads/${id}/products`,
        detachProduct: (id: number, matchId: number) => `/leads/${id}/products/${matchId}`,

        // Phase 3 - AI Sales Intelligence.
        analyze: (id: number) => `/leads/${id}/analyze`,
        analysis: (id: number, analysisId: number) => `/leads/${id}/analyses/${analysisId}`,
        acceptRecommendation: (id: number, matchId: number) =>
            `/leads/${id}/recommendations/${matchId}/accept`,
        rejectRecommendation: (id: number, matchId: number) =>
            `/leads/${id}/recommendations/${matchId}/reject`,
        archiveRecommendation: (id: number, matchId: number) =>
            `/leads/${id}/recommendations/${matchId}/archive`,

        // Phase 4 - email generation. Produces drafts only; nothing is sent.
        generateEmail: (id: number) => `/leads/${id}/emails`,
        resolveGeneration: (id: number, generationId: number, choice: 'previous' | 'new') =>
            `/leads/${id}/emails/${generationId}/resolve/${choice}`,
    },

    products: {
        index: (q?: Query) => withQuery('/products', q),
        show: (id: number) => `/products/${id}`,
        store: () => '/products',
        update: (id: number) => `/products/${id}`,
        destroy: (id: number) => `/products/${id}`,

        // Bulk actions - both POST, since the selection is a list of ids.
        bulkUpdate: () => '/products/bulk/update',
        bulkDestroy: () => '/products/bulk/delete',
    },

    activities: {
        store: (subject: 'leads' | 'companies' | 'contacts', id: number) =>
            `/${subject}/${id}/activities`,
        destroy: (subject: 'leads' | 'companies' | 'contacts', id: number, activityId: number) =>
            `/${subject}/${id}/activities/${activityId}`,
    },

    import: {
        create: () => '/import',
        preview: () => '/import/preview',
        store: () => '/import',
        template: () => '/import/template',
    },

    emailDrafts: {
        index: (q?: Query) => withQuery('/email-drafts', q),
        show: (id: number) => `/email-drafts/${id}`,
        update: (id: number) => `/email-drafts/${id}`,
        destroy: (id: number) => `/email-drafts/${id}`,

        // The approval gate. Only `approve` makes a draft sendable.
        approve: (id: number) => `/email-drafts/${id}/approve`,
        reject: (id: number) => `/email-drafts/${id}/reject`,
        archive: (id: number) => `/email-drafts/${id}/archive`,
        send: (id: number) => `/email-drafts/${id}/send`,
    },

    followUps: () => '/follow-ups',
    knowledgeBase: () => '/knowledge-base',

    settings: {
        index: () => '/settings/ai',

        ai: {
            index: () => '/settings/ai',
            update: () => '/settings/ai',
            test: () => '/settings/ai/test',
            refresh: () => '/settings/ai/refresh',
            resetSystemPrompt: () => '/settings/ai/system-prompt',

            playground: () => '/settings/ai/playground',
            run: () => '/settings/ai/playground',

            logs: (q?: Query) => withQuery('/settings/ai/logs', q),
            log: (id: number) => `/settings/ai/logs/${id}`,
            clearLogs: () => '/settings/ai/logs',
        },

        email: {
            index: () => '/settings/email',
            update: () => '/settings/email',
            resetSignature: () => '/settings/email/signature',
        },
    },
} as const;
