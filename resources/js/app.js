import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import PrimeVue from 'primevue/config';
import ToastService from 'primevue/toastservice';
import Tooltip from 'primevue/tooltip';
import Aura from '@primeuix/themes/aura';
import { definePreset } from '@primeuix/themes';
import 'primeicons/primeicons.css';
import vUppercase from './directives/uppercase';

const appName = import.meta.env.VITE_APP_NAME || 'CLASSLY';

// Rounder buttons across the whole system — bumped from Aura's default
// {form.field.border.radius} (6px, shared with inputs/selects) to a
// dedicated, larger radius so every PrimeVue <Button> (Save Schedule,
// Auto Generate Schedule, Print, Send via Email, modal actions, etc.)
// picks up the same softer corners as this app's already-rounded
// neu-card/rounded-xl surfaces, without also rounding text inputs.
const ClasslyPreset = definePreset(Aura, {
    components: {
        button: {
            borderRadius: '0.75rem',
        },
    },
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(PrimeVue, {
                theme: {
                    preset: ClasslyPreset,
                    options: {
                        darkModeSelector: false,
                    },
                },
            })
            .use(ToastService)
            .directive('tooltip', Tooltip)
            .directive('uppercase', vUppercase)
            .mount(el);
    },
    progress: {
        color: '#2563EB',
    },
});