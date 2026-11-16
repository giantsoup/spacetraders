import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { createPinia } from 'pinia';
import piniaPluginPersistedState from 'pinia-plugin-persistedstate';

// A deploy deletes the previous build's hashed chunks, so a tab restored from cache can request
// files that no longer exist. Reload once to fetch the current build; the timestamp in
// sessionStorage stops a second reload within a minute, so it can never loop.
const RELOAD_KEY = 'asset-reload-at';
function reloadOnce() {
    try {
        if (Date.now() - Number(window.sessionStorage.getItem(RELOAD_KEY) || 0) < 60000) {
            return;
        }
        window.sessionStorage.setItem(RELOAD_KEY, String(Date.now()));
    } catch {
        return; // Without storage there is no loop guard, so leave the error visible.
    }
    window.location.reload();
}
window.addEventListener('vite:preloadError', reloadOnce);

const appName = window.document.getElementsByTagName('title')[0]?.innerText || 'Laravel';
const pinia = createPinia();
pinia.use(piniaPluginPersistedState);

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')).catch((error) => {
        reloadOnce();
        throw error;
    }),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .use(ZiggyVue, Ziggy)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
