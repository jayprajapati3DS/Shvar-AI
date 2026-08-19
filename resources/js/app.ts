import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import '../css/app.css';

const appName = import.meta.env.VITE_APP_NAME ?? 'Shvar AI Copilot';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: async (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue');
        const page = await pages[`./Pages/${name}.vue`]();

        // Every page gets the sidebar shell unless it opts out with `layout = null`.
        page.default.layout ??= AppLayout;

        return page;
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        color: '#4f46e5',
        showSpinner: false,
    },
});
