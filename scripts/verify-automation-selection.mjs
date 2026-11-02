import assert from 'node:assert/strict';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

// Compile the real Vue component without listening on a port or loading live credentials.
const compiler = await createServer({ configFile: false, envDir: false, plugins: [vue()], resolve: { alias: { '@': `${process.cwd()}/resources/js` } }, server: { middlewareMode: true, watch: null, ws: false }, appType: 'custom' });
try {
    const { default: Console } = await compiler.ssrLoadModule('/resources/js/Components/AutomationConsole.vue');
    const candidate = (id, classification, preparation) => ({ id, strategy: 'mining', ship_symbol: 'LOCAL-1', site: 'X1-TEST-ROCK', market: 'X1-TEST-PORT', classification, preparation, required_equipment: [], reasons: [], trial_required: true, duration_seconds: null });
    const render = async (candidates, strategy = 'mining', contracts = []) => (await renderToString(createSSRApp(Console, {
        agent: { id: 1 }, ships: [], engine: {}, operator: false, enabled: true, drafts: [], runs: [], accounting: {},
        investigations: [{ id: 1, strategy, status: 'partial', system_symbol: 'X1-TEST',
            observations: { contracts, observed_at: '2026-11-02T04:31:53Z' }, candidates,
            coverage: { requests: 33, request_limit: 40, seconds_limit: 45, limits_reached: ['Market observation cap reached; coverage is partial.'] } }],
    }))).replace(/<!--[\s\S]*?-->/g, '');
    const ready = await render([candidate('ready', 'ready')]);
    assert.match(ready, /1 mining route ready to stage/);
    assert.match(ready, /Routes with verified prerequisites can still be selected/);
    assert.match(ready, /<button(?![^>]*disabled)[^>]*>Select LOCAL-1/);
    const task = { kind: 'observe_market', ship_symbol: 'LOCAL-1', market: 'X1-TEST-PORT', at_market: false };
    const unknown = await render([candidate('unknown', 'needs_information', task), candidate('duplicate', 'needs_information', task)]);
    assert.match(unknown, /No mining route is ready yet/);
    assert.equal(unknown.match(/Prepare visit to X1-TEST-PORT with LOCAL-1/g)?.length, 1);
    assert.match(unknown, /<button[^>]*disabled[^>]*>Select LOCAL-1/);
    const local = await render([candidate('local', 'needs_information', { ...task, at_market: true })]);
    assert.match(local, /Refresh prices at X1-TEST-PORT/);
    const completed = await render([{ ...candidate('completed', 'needs_information'), contract_id: 'done' }], 'contracts', [{ id: 'done', fulfilled: true }]);
    assert.match(completed, /No open contracts to automate/);
    assert.doesNotMatch(completed, /class="candidate"/);
    console.log('PASS partial ready selection, blocked price selection, deduplicated scouting controls, local refresh and fulfilled-contract guidance.');
} finally {
    await compiler.close();
}
