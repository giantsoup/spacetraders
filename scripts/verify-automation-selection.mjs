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
    const render = async (candidates, strategy = 'mining', contracts = [], extra = {}, { sort, focus } = {}) => (await renderToString(createSSRApp({
        ...Console,
        setup(props, context) {
            const state = Console.setup(props, context);
            if (sort) state.sort.value = sort;
            return state;
        },
    }, {
        agent: { id: 1 }, ships: [], engine: {}, operator: false, enabled: true, drafts: [], runs: [], accounting: {},
        investigations: [{ id: 1, strategy, status: 'partial', system_symbol: 'X1-TEST',
            observations: { contracts, observed_at: '2026-11-02T04:31:53Z' }, candidates,
            coverage: { focus, requests: 33, request_limit: 40, seconds_limit: 45, limits_reached: ['Market observation cap reached; coverage is partial.'] } }], ...extra,
    }))).replace(/<!--[\s\S]*?-->/g, '');
    const ready = await render([candidate('ready', 'ready')]);
    assert.match(ready, /1 mining route ready to stage/);
    assert.match(ready, /Routes with verified prerequisites can still be selected/);
    assert.match(ready, /<button(?![^>]*disabled)[^>]*>Select LOCAL-1/);
    const higherRate = { ...candidate('higher-rate', 'ready'), market: 'X1-TEST-RATE', estimated_per_hour: 100, estimated_net: 10 };
    const higherNet = { ...candidate('higher-net', 'ready'), market: 'X1-TEST-NET', estimated_per_hour: 50, estimated_net: 20 };
    const focusedPartial = { ...candidate('partial', 'ready'), survey_required_for_departure: true, estimated_per_hour: 1000, estimated_net: 1000 };
    const stale = { ...candidate('stale', 'needs_information'), market: 'X1-TEST-STALE', survey_required_for_departure: true, estimated_net: 1000 };
    const needsPreparation = { ...candidate('preparation', 'needs_preparation'), market: 'X1-TEST-PREP', estimated_net: 500 };
    const focus = { buyer: focusedPartial.market, sites: [focusedPartial.site] };
    for (const [sort, expected] of [['rate', [higherRate, higherNet]], ['net', [higherNet, higherRate]]]) {
        const html = await render([higherRate, higherNet, focusedPartial, stale, needsPreparation], 'mining', [], {}, { sort, focus });
        const markets = Array.from(html.matchAll(/<h3\b[^>]*>LOCAL-1 · X1-TEST-ROCK → ([A-Z0-9-]+)<\/h3>/g), match => match[1]);
        assert.deepEqual(markets, [...expected, focusedPartial, stale, needsPreparation].map(route => route.market), `Departure-ready routes lead in ${sort} order`);
    }
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
    const otherRun = { id: 9, ship_symbol: 'LOCAL-2', active_agent_id: 1, strategy: 'mining', state: 'running', plan: {}, totals: {} };
    const separateShip = await render([], 'mining', [], { selected: 'LOCAL-1', runs: [otherRun] });
    assert.match(separateShip, /<fieldset(?![^>]*disabled)[^>]*><legend[^>]*>Choose a strategy/);
    assert.doesNotMatch(separateShip, /Run #9/);
    const sameShip = await render([], 'mining', [], { selected: 'LOCAL-2', runs: [otherRun] });
    assert.match(sameShip, /<fieldset[^>]*disabled[^>]*><legend[^>]*>Choose a strategy/);
    assert.match(sameShip, /Run #9/);
    console.log('PASS departure readiness in both sort modes, partial ready selection, blocked price selection, deduplicated scouting controls, local refresh and fulfilled-contract guidance.');
} finally {
    await compiler.close();
}
