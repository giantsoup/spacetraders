import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

const matrix = JSON.parse(await readFile(new URL('../tests/Fixtures/ship-information.json', import.meta.url), 'utf8'));
const { components: { schemas } } = JSON.parse(await readFile(new URL('../docs/api/spacetraders-openapi-2.3.0.json', import.meta.url), 'utf8'));
const resolve = schema => schema.$ref ? resolve(schemas[schema.$ref.split('/').at(-1)]) : schema;
function expectedProjection(value, contract) {
    const schema = resolve(contract);
    if (value === null || value === undefined) return value;
    if (schema.type === 'array') return value.map(item => expectedProjection(item, schema.items));
    if (schema.type !== 'object') return value;
    return Object.fromEntries(Object.entries(value).filter(([key]) => schema.properties[key]).map(([key, item]) => [key, expectedProjection(item, schema.properties[key])]));
}
const decode = value => value.replaceAll('&quot;', '"').replaceAll('&#39;', "'").replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&amp;', '&');
const plain = html => decode(html.replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();
const render = async (component, props) => await renderToString(createSSRApp(component, props));
const compiler = await createServer({
    configFile: false, envDir: false,
    plugins: [vue(), {
        name: 'ship-information-shell', enforce: 'pre',
        transform(code, id) {
            if (id.endsWith('/Pages/Campaign/Status.vue') || id.endsWith('/SystemMap/MapFlightTask.vue')) return code.replace("from '@inertiajs/vue3'", "from 'virtual:ship-information-inertia'");
        },
        resolveId(source) {
            if (source === 'virtual:ship-information-inertia') return '\0ship-information-inertia';
            if (source.endsWith('/Layouts/AuthenticatedLayout.vue')) return '\0ship-information-layout';
        },
        load(id) {
            if (id === '\0ship-information-inertia') return 'export const Head = { render: () => null }; export const Link = { render() { return this.$slots.default?.(); } }; export const usePoll = () => {}; export const useForm = () => ({ errors: {} });';
            if (id === '\0ship-information-layout') return 'export default { render() { return this.$slots.default(); } };';
        },
    }],
    resolve: { alias: { '@': process.cwd() + '/resources/js' } },
    server: { middlewareMode: true, watch: null, ws: false }, appType: 'custom',
});
try {
    const load = async name => (await compiler.ssrLoadModule('/resources/js/' + name + '.vue')).default;
    const Details = await load('Components/ShipDetails');
    const Report = await load('Components/ShipReport');
    const Campaign = await load('Pages/Campaign/Status');
    const Data = await load('Components/DataDetails');
    const Equipment = await load('Components/ShipEquipmentList');
    for (const row of matrix.scenarios) {
        const ship = expectedProjection(row.ship, schemas.Ship);
        if (row.ship.cooldown?.readyAt) ship.cooldown.readyAt = row.ship.cooldown.readyAt;
        const html = await render(Details, { ship, unlistedFields: ['frame.futureStat'] });
        const exact = html.match(/<pre[^>]*>([\s\S]*?)<\/pre>/);
        assert.ok(exact, row.id + ': exact values accessible');
        assert.deepEqual(JSON.parse(decode(exact[1])), ship, row.id + ': exact values unchanged');
        assert.match(plain(html), /frame.futureStat/);
        if (ship.nav?.waypointSymbol) assert.match(plain(html), /Current waypoint X1-QA-ORIGIN/);
        for (const kind of ['mounts', 'modules']) {
            const equipmentHtml = await render(Equipment, { equipment: ship[kind], kind });
            for (const part of ship[kind] || []) {
                assert.ok(plain(equipmentHtml).includes(part.symbol), row.id + ': equipment symbol visible');
                for (const number of Object.values(part.requirements || {})) assert.ok(plain(equipmentHtml).includes(String(number)));
            }
            assert.equal((equipmentHtml.match(/<summary/g) || []).length, ship[kind]?.length || 0, row.id + ': every instance has its own disclosure');
        }
        for (const section of ['fuel', 'cargo', 'systems']) {
            const report = await render(Report, { ship, section, observedAt: row.observed_at, readiness: 'Ready' });
            const text = plain(report);
            assert.ok(text.includes(ship.symbol));
            if (section === 'systems') {
                assert.ok(text.includes('Mining client-policy check'));
                assert.ok(text.includes('Current waypoint'));
                if (ship.frame?.quality === 3) assert.match(text, /Quality 3(?: |$)/);
            }
            if (section === 'cargo' && ship.cargo?.capacity === 0) {
                assert.ok(text.includes('No cargo hold'));
                assert.ok(!text.includes('The hold is empty'));
            }
        }
        const campaign = await render(Campaign, {
            grant: { active: true, revision: { name: 'QA', version: 1, hash: 'qa' } },
            health: { engine: { state: 'stopped' }, problems: [] }, updatedAt: '2026-11-15T21:00:00Z',
            ships: [{ symbol: ship.symbol, role: 'fleet', nav: ship.nav, fuel: ship.fuel || {}, cargo: ship.cargo || {}, locations: [], details: ship, observed_at: row.observed_at, unlisted_fields: ['frame.futureStat'] }],
        });
        const campaignText = plain(campaign);
        assert.ok(campaignText.includes('Campaign role') && campaignText.includes('Registration role'));
        assert.ok(campaignText.includes('Current waypoint'));
        assert.ok(campaignText.includes('Last known state'), 'Use ship observation age, not page timestamp');
        assert.ok(!campaign.includes('private-'), 'Unsafe fixture fields must not enter safe render');
    }
    const duplicate = matrix.scenarios.find(row => row.id === 'distinct-duplicates').ship;
    const duplicateText = plain(await render(Equipment, { equipment: duplicate.mounts, kind: 'mounts' }));
    assert.match(duplicateText, /Strength 2/);
    assert.match(duplicateText, /Strength 9/);
    assert.ok(duplicateText.includes('GOLD_ORE'));
    const ordinary = plain(await render(Data, { value: { waypointSymbol: 'X1-QA-ORIGIN' } }));
    assert.match(ordinary, /^Destination X1-QA-ORIGIN$/);
    console.log('PASS 24 safe exact-value/campaign renders, 72 ship reports, equipment instance fidelity, ship-scoped labels, raw quality and unchanged preview labels.');
} finally {
    await compiler.close();
}
