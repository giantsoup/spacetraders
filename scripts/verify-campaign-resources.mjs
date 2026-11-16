import assert from 'node:assert/strict';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

const compiler = await createServer({
    configFile: false, envDir: false,
    plugins: [vue(), {
        name: 'campaign-test-shell',
        enforce: 'pre',
        transform(code, id) {
            if (id.endsWith('/Pages/Campaign/Status.vue')) return code.replace("from '@inertiajs/vue3'", "from 'virtual:campaign-inertia'");
        },
        resolveId(source) {
            if (source === 'virtual:campaign-inertia') return '\0campaign-inertia';
            if (source.endsWith('/Layouts/AuthenticatedLayout.vue')) return '\0campaign-layout';
        },
        load(id) {
            if (id === '\0campaign-inertia') return 'export const Head = { render: () => null }; export const usePoll = () => {}; export const useForm = () => ({ errors: {} });';
            if (id === '\0campaign-layout') return 'export default { render() { return this.$slots.default(); } };';
        },
    }],
    resolve: { alias: { '@': process.cwd() + '/resources/js' } },
    server: { middlewareMode: true, watch: null, ws: false }, appType: 'custom',
});
try {
    const { default: Status } = await compiler.ssrLoadModule('/resources/js/Pages/Campaign/Status.vue');
    const snapshots = [];
    const render = async (fuel, cargo) => {
        const html = await renderToString(createSSRApp(Status, {
        grant: { active: true, revision: { name: 'Test', version: 1, hash: 'test' } }, health: { engine: { state: 'stopped' }, problems: [] },
        updatedAt: '2026-11-15T20:00:00Z',
        ships: [{ symbol: 'LOCAL-2', role: 'PROBE', fuel, cargo, locations: [] }],
        }));
        snapshots.push(html);
        return html.replace(/<!--[\s\S]*?-->/g, '').replace(/ data-v-[\w]+(?:="")?/g, '');
    };
    const probe = await render({ current: 0, capacity: 0 }, { units: 0, capacity: 0 });
    assert.match(probe, /<dt>Fuel<\/dt><dd>No fuel tank<\/dd>/);
    assert.match(probe, /<dt>Cargo<\/dt><dd>No cargo hold<\/dd>/);
    assert.doesNotMatch(probe, /0 \/ 0/);
    const command = await render({ current: 356, capacity: 400 }, { units: 18, capacity: 40 });
    assert.match(command, /<dt>Fuel<\/dt><dd>356 \/ 400<\/dd>/);
    assert.match(command, /<dt>Cargo<\/dt><dd>18 \/ 40<\/dd>/);
    const empty = await render({ current: 0, capacity: 400 }, { units: 0, capacity: 40 });
    assert.match(empty, /<dt>Fuel<\/dt><dd>0 \/ 400<\/dd>/);
    assert.match(empty, /<dt>Cargo<\/dt><dd>0 \/ 40<\/dd>/);
    const unknown = await render({ current: null, capacity: null }, { units: null, capacity: null });
    assert.match(unknown, /<dt>Fuel<\/dt><dd>Unknown \/ Unknown<\/dd>/);
    assert.match(unknown, /<dt>Cargo<\/dt><dd>Unknown \/ Unknown<\/dd>/);
    if (process.env.QA_ARTIFACT_DIR) {
        const fs = await import('node:fs/promises');
        const path = await import('node:path');
        const { chromium } = await import('playwright');
        const directory = path.resolve(process.env.QA_ARTIFACT_DIR);
        await fs.mkdir(directory, { recursive: true });
        const manifest = JSON.parse(await fs.readFile('public/build/manifest.json', 'utf8'));
        const styles = [...new Set(Object.values(manifest).flatMap(entry => entry.css || []))];
        const css = (await Promise.all(styles.map(file => fs.readFile(path.join('public/build', file), 'utf8')))).join('\n').replace(/\[data-v-[^\]]+\]/g, '');
        const browser = await chromium.launch({ headless: true });
        try {
            const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
            await page.setContent('<html class="dark"><head><style>' + css + '</style></head><body><main style="padding:16px">' + snapshots[0] + '</main></body></html>');
            const fleet = page.locator('section').filter({ has: page.locator('#ships-heading') });
            assert.equal(await fleet.getByText('No fuel tank', { exact: true }).count(), 1);
            assert.equal(await fleet.getByText('No cargo hold', { exact: true }).count(), 1);
            assert.equal(await fleet.evaluate(element => element.scrollWidth <= element.clientWidth), true);
            await fleet.screenshot({ path: path.join(directory, 'campaign-probe-mobile.png') });
            console.log('PASS 390px fleet rendering without horizontal overflow; screenshot: ' + path.join(directory, 'campaign-probe-mobile.png'));
        } finally {
            await browser.close();
        }
    }
    console.log('PASS campaign resource labels: zero capacity, normal usage, empty resources and unknown observations.');
} finally {
    await compiler.close();
}
