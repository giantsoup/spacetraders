import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import { withBrowserVerification } from './browser-verification.mjs';
import { waitUntil } from './verification-lifecycle.mjs';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || 'php';
const evidence = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/browser-lifecycle'));
await fs.mkdir(evidence, { recursive: true });
const output = await fs.mkdtemp(path.join(evidence, 'run-'));
const exists = file => fs.lstat(file).then(() => true, error => { if (error.code === 'ENOENT') return false; throw error; });
const alive = pid => { try { process.kill(pid, 0); return true; } catch (error) { if (error.code === 'ESRCH') return false; throw error; } };
const result = name => fs.readFile(path.join(output, name, 'results.json'), 'utf8').then(JSON.parse);

async function proveStopped(name, enginePids = []) {
    const report = await result(name);
    for (const pid of [...Object.values(report.processes || {}), ...enginePids]) assert.equal(alive(pid), false, `Owned PID ${pid} exited`);
    for (const state of Object.values(report.shutdown)) assert.equal(state, 'verified');
    if (report.engineShutdown) {
        const target = `gui/${process.getuid()}/${report.engineShutdown.serviceLabel}`;
        assert.notEqual(spawnSync('/bin/launchctl', ['print', target]).status, 0, 'Owned launchd service is absent');
    }
    return report;
}

async function screenshot({ browser, origin, output }) {
    const context = await browser.newContext({ colorScheme: 'light', viewport: { width: 390, height: 844 } });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const page = await context.newPage();
    await page.goto(`${origin}/login`);
    assert.equal(await page.locator('html').evaluate(element => getComputedStyle(element).colorScheme), 'dark');
    await page.screenshot({ path: path.join(output, 'login.png') });
}

test('real browser/server success removes its owned fixture and preserves a dark screenshot', async () => {
    const destination = path.join(output, 'success');
    await withBrowserVerification({ root, php, output: destination }, screenshot);
    const report = await proveStopped('success');
    assert.equal(report.fixtureRemoved, true);
    assert.equal(await exists(report.fixture), false);
    assert.equal(await exists(path.join(destination, 'login.png')), true);
});

test('failed browser launch retains partial setup privately before any server or service starts', async () => {
    const previous = process.env.PLAYWRIGHT_EXECUTABLE;
    process.env.PLAYWRIGHT_EXECUTABLE = path.join(output, 'missing-browser');
    try {
        await assert.rejects(withBrowserVerification({ root, php, output: path.join(output, 'launch-failure') }, () => {}));
    } finally {
        if (previous === undefined) delete process.env.PLAYWRIGHT_EXECUTABLE;
        else process.env.PLAYWRIGHT_EXECUTABLE = previous;
    }
    const report = await result('launch-failure');
    assert.equal(report.passed, false);
    assert.equal(await exists(path.join(report.fixture, 'failure.log')), true);
    assert.deepEqual(report.shutdown, {});
});

for (const mode of ['assertion', 'SIGTERM']) {
    test(`real native engine ${mode} failure stops every owned process and removes only its service`, { skip: process.platform !== 'darwin' }, async () => {
        let enginePids = [];
        await assert.rejects(withBrowserVerification({ root, php, engine: true, output: path.join(output, mode) }, async run => {
            await screenshot(run);
            run.cli('echo json_encode(app(App\\Gameplay\\LocalEngineManager::class)->start());');
            await waitUntil(() => run.cli("echo json_encode(App\\Models\\LocalEngine::find(1)?->state === 'running');"));
            enginePids = Object.values(run.cli('echo json_encode(App\\Models\\LocalEngine::find(1)->processes);'));
            assert.equal(enginePids.length, 3);
            for (const pid of enginePids) assert.equal(alive(pid), true);
            if (mode === 'SIGTERM') {
                process.kill(process.pid, 'SIGTERM');
                await new Promise(resolve => run.signal.addEventListener('abort', resolve, { once: true }));
                run.signal.throwIfAborted();
            }
            throw new Error('Intentional failure after real engine startup');
        }));
        const report = await proveStopped(mode, enginePids);
        assert.equal(report.passed, false);
        assert.equal(await exists(path.join(report.fixture, 'failure.log')), true);
        const children = await fs.readdir(report.fixture);
        const fixture = children.find(name => name.startsWith('spacetraders-fixture-'));
        assert.ok(fixture);
        assert.equal(await exists(path.join(report.fixture, fixture, 'locks/engine/service.plist')), false);
        assert.equal((await fs.stat(report.fixture)).mode & 0o777, 0o700);
        assert.deepEqual(report.shutdown.service, 'verified');
    });
}

test('failed fixture process retains its private allocation and cannot claim a pass', async () => {
    const wrapper = path.join(output, 'failed-php');
    await fs.writeFile(wrapper, '#!/bin/sh\necho intentional-fixture-setup-failure >&2\nexit 17\n', { mode: 0o700 });
    await assert.rejects(withBrowserVerification({ root, php: wrapper, output: path.join(output, 'fixture-failure') }, () => {}));
    const report = await result('fixture-failure');
    assert.equal(report.passed, false);
    assert.deepEqual(report.shutdown, {});
    assert.match(await fs.readFile(path.join(report.fixture, 'failure.log'), 'utf8'), /intentional-fixture-setup-failure/);
});

test('partial installation failure still removes the actual bootstrapped service', { skip: process.platform !== 'darwin' }, async () => {
    const wrapper = path.join(output, 'partial-install-php');
    const quotedPhp = "'" + php.replaceAll("'", "'\\''") + "'";
    await fs.writeFile(wrapper, `#!/bin/bash
if [[ "$1" == artisan && "$2" == spacetraders:engine-service && "$3" == install ]]; then
    ${quotedPhp} "$@" || exit $?
    exit 17
fi
exec ${quotedPhp} "$@"
`, { mode: 0o700 });
    await assert.rejects(withBrowserVerification({ root, php: wrapper, engine: true, output: path.join(output, 'partial-install') }, () => {
        assert.fail('Journey must not run after a failed install');
    }));
    const report = await proveStopped('partial-install');
    assert.equal(report.passed, false);
    assert.equal(report.engineShutdown.serviceRemoved, true);
    assert.equal(await exists(path.join(report.fixture, 'failure.log')), true);
});

console.log(`Owned browser lifecycle evidence: ${output}`);
