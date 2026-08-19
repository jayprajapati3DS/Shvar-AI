<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ToastHub from '@/Components/ToastHub.vue';
import { useToasts } from '@/composables/useToasts';
import { routes } from '@/routes';
import type { SharedProps } from '@/types/models';

const page = usePage<SharedProps>();
const { push } = useToasts();

const sidebarOpen = ref(false);

const appName = computed(() => page.props.app?.name ?? 'Shvar AI Copilot');

/** First letter of each word, capped at three - "Shvar AI Copilot" -> "SAC". */
const initials = computed(() =>
    appName.value
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 3)
        .map((word) => word[0]!.toUpperCase())
        .join(''),
);

interface NavItem {
    label: string;
    href: string;
    icon: string;
    /** Phase 1 stub - shown with a muted "Soon" chip. */
    placeholder?: boolean;
}

/**
 * Heroicons outline paths, inlined. No icon package and no CDN, so the app makes
 * zero external requests.
 */
const nav: { section: string; items: NavItem[] }[] = [
    {
        section: 'Sales',
        items: [
            {
                label: 'Dashboard',
                href: routes.dashboard(),
                icon: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
            },
            {
                label: 'Leads',
                href: routes.leads.index(),
                icon: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
            },
            {
                label: 'Companies',
                href: routes.companies.index(),
                icon: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
            },
            {
                label: 'Contacts',
                href: routes.contacts.index(),
                icon: 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
            },
            {
                label: 'Product Portfolio',
                href: routes.products.index(),
                icon: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
            },
        ],
    },
    {
        section: 'Outreach',
        items: [
            {
                label: 'Email Drafts',
                href: routes.emailDrafts.index(),
                icon: 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
            },
            {
                label: 'Follow-ups',
                href: routes.followUps(),
                icon: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                placeholder: true,
            },
        ],
    },
    {
        section: 'System',
        items: [
            {
                label: 'Knowledge Base',
                href: routes.knowledgeBase(),
                icon: 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
                placeholder: true,
            },
            {
                label: 'Settings',
                href: routes.settings.index(),
                icon: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.03 7.03 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z',
            },
        ],
    },
];

/**
 * A nav item is current when the URL matches it exactly, or is nested under it.
 * The dashboard is exact-only, otherwise every route would light it up.
 */
function isCurrent(href: string): boolean {
    const path = page.url.split('?')[0];

    return href === '/' ? path === '/' : path === href || path.startsWith(`${href}/`);
}

// Surface Laravel's flash messages as toasts.
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) {
            return;
        }

        if (flash.success) push(flash.success, 'success');
        if (flash.error) push(flash.error, 'error');
        if (flash.info) push(flash.info, 'info');
    },
    { immediate: true, deep: true },
);

// Close the mobile drawer whenever the page changes.
watch(() => page.url, () => (sidebarOpen.value = false));
</script>

<template>
    <div class="min-h-screen lg:flex">
        <ToastHub />

        <!-- Mobile drawer backdrop -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-slate-800 bg-slate-900 transition-transform lg:static lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center gap-2.5 border-b border-slate-800 px-5">
                <!-- Initials derived from APP_NAME, so renaming the app in .env
                     updates the badge too rather than leaving stale letters. -->
                <span class="flex size-8 items-center justify-center rounded-md bg-indigo-600 text-sm font-bold text-white">
                    {{ initials }}
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-white">{{ appName }}</p>
                    <p class="text-[11px] text-slate-400">Local · Private</p>
                </div>
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
                <div v-for="group in nav" :key="group.section">
                    <p class="mb-1.5 px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        {{ group.section }}
                    </p>

                    <ul class="space-y-0.5">
                        <li v-for="item in group.items" :key="item.href">
                            <Link
                                :href="item.href"
                                class="flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm font-medium transition-colors"
                                :class="
                                    isCurrent(item.href)
                                        ? 'bg-indigo-600 text-white'
                                        : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                                "
                            >
                                <svg
                                    class="size-4 shrink-0"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.6"
                                    stroke="currentColor"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                                </svg>

                                <span class="flex-1 truncate">{{ item.label }}</span>

                                <span
                                    v-if="item.placeholder"
                                    class="rounded bg-slate-700 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-300"
                                >
                                    Soon
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="border-t border-slate-800 px-4 py-3">
                <p class="text-[11px] leading-relaxed text-slate-500">
                    Phase 2 · Local AI<br />
                    Ollama on this machine. No cloud, no telemetry.
                </p>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6"
            >
                <button
                    type="button"
                    class="btn-ghost -ml-1.5 p-2 lg:hidden"
                    aria-label="Open navigation"
                    @click="sidebarOpen = true"
                >
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>

                <div class="min-w-0 flex-1">
                    <slot name="header" />
                </div>

                <span
                    class="hidden items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200 sm:inline-flex"
                    title="All data stays on this machine"
                >
                    <span class="size-1.5 rounded-full bg-emerald-500" aria-hidden="true" />
                    Local only
                </span>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>
    </div>
</template>
