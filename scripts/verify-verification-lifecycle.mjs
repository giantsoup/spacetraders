import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { stopOwnedProcess, withOwnedVerification } from './verification-lifecycle.mjs';

const evidence = await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()), 'opt02-regression-results-'));
const output = name => path.join(evidence, name);
const exists = file => fs.lstat(file).then(() => true, error => { if (error.code === 'ENOENT') return false; throw error; });
const alive = pid => { try { process.kill(pid, 0); return true; } catch (error) { if (error.code === 'ESRCH') return false; throw error; } };
const read = name => fs.readFile(path.join(output(name), 'results.json'), 'utf8').then(JSON.parse);

test('success waits for actual child exit, removes only its allocation and preserves durable output and an unrelated process', async () => {
    const sentinel = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)']);
    let child, fixture;
    try {
        const result = await withOwnedVerification({ output: output('success') }, async run => {
            fixture = run.directory;
            assert.equal((await fs.stat(fixture)).mode & 0o777, 0o700);
            child = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)']);
            run.defer('child', async () => {
                assert.equal(await exists(fixture), true);
                await stopOwnedProcess(child);
                assert.equal(alive(child.pid), false);
            });
            await fs.writeFile(path.join(fixture, 'secret.txt'), 'private synthetic key');
            await fs.writeFile(path.join(run.output, 'screenshot.txt'), 'durable screenshot stand-in');
        });
        assert.equal(result.passed, true);
        assert.equal(result.fixtureRemoved, true);
        assert.equal(await exists(fixture), false);
        assert.equal(await exists(path.join(output('success'), 'screenshot.txt')), true);
        assert.equal(alive(sentinel.pid), true);
    } finally { await stopOwnedProcess(sentinel); if (child) await stopOwnedProcess(child); }
});

test('failure retains private diagnostics, redacts durable errors and still shuts down all acquired resources', async () => {
    let fixture, child;
    await assert.rejects(withOwnedVerification({ output: output('failure') }, async run => {
        fixture = run.directory;
        run.redact('private-password');
        run.onFailure(() => fs.writeFile(path.join(fixture, 'failure.txt'), 'private capture', { mode: 0o600 }));
        child = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)']);
        run.defer('child', () => stopOwnedProcess(child));
        throw new Error('assertion with private-password');
    }), /Verification failed/);
    assert.equal(alive(child.pid), false);
    assert.equal(await exists(path.join(fixture, 'failure.txt')), true);
    assert.equal((await fs.stat(path.join(fixture, 'failure.log'))).mode & 0o777, 0o600);
    assert.match(await fs.readFile(path.join(fixture, 'failure.log'), 'utf8'), /private-password/);
    assert.doesNotMatch(JSON.stringify(await read('failure')), /private-password/);
});

test('partial setup failure before acquisition retains its allocation', async () => {
    let fixture;
    await assert.rejects(withOwnedVerification({ output: output('partial') }, async run => {
        fixture = run.directory;
        throw new Error('setup stopped before browser');
    }));
    assert.equal(await exists(fixture), true);
    assert.deepEqual((await read('partial')).shutdown, {});
});

test('one shutdown failure never skips the other cleanups or removes diagnostics', async () => {
    const order = [];
    await assert.rejects(withOwnedVerification({ output: output('shutdown') }, async run => {
        run.defer('server', async () => { order.push('server'); });
        run.defer('browser', async () => { order.push('browser'); throw new Error('close failed'); });
        run.defer('service', async () => { order.push('service'); });
    }), /close failed/);
    assert.deepEqual(order, ['service', 'browser', 'server']);
    const result = await read('shutdown');
    assert.equal(result.passed, false);
    assert.equal(await exists(result.fixture), true);
    assert.deepEqual(result.shutdown, { service: 'verified', browser: 'failed', server: 'verified' });
});

test('descendant symlinks are unlinked without traversing an external target', async () => {
    const external = path.join(evidence, 'external');
    await fs.mkdir(external);
    await fs.writeFile(path.join(external, 'keep'), 'keep');
    await withOwnedVerification({ output: output('descendant') }, async run => {
        await fs.symlink(external, path.join(run.directory, 'outside'));
    });
    assert.equal(await fs.readFile(path.join(external, 'keep'), 'utf8'), 'keep');
});

for (const replacement of ['symlink', 'directory', 'marker', 'marker-symlink', 'permissions']) {
    test(`refuses cleanup when the owned ${replacement} changes`, async () => {
        const external = path.join(evidence, `external-${replacement}`);
        await fs.mkdir(external);
        await fs.writeFile(path.join(external, 'keep'), 'keep');
        let fixture;
        await assert.rejects(withOwnedVerification({ output: output(replacement) }, async run => {
            fixture = run.directory;
            if (replacement === 'symlink' || replacement === 'directory') {
                await fs.rename(fixture, fixture + '-original');
                if (replacement === 'symlink') await fs.symlink(external, fixture);
                else await fs.mkdir(fixture, { mode: 0o700 });
            } else if (replacement === 'marker') await fs.writeFile(path.join(fixture, '.verification-owner'), 'unowned');
            else if (replacement === 'marker-symlink') {
                await fs.rename(path.join(fixture, '.verification-owner'), path.join(external, 'marker'));
                await fs.symlink(path.join(external, 'marker'), path.join(fixture, '.verification-owner'));
            } else await fs.chmod(fixture, 0o755);
        }));
        assert.equal(await exists(fixture), true);
        assert.equal(await fs.readFile(path.join(external, 'keep'), 'utf8'), 'keep');
        assert.equal(await exists(path.join(external, 'failure.log')), false);
    });
}

test('a child that already exited or failed to spawn cannot hang shutdown', async () => {
    const exited = spawn(process.execPath, ['-e', '']);
    await once(exited, 'exit');
    await stopOwnedProcess(exited);
    const missing = spawn(path.join(evidence, 'missing-executable'));
    await once(missing, 'error');
    await stopOwnedProcess(missing);
});

test('an unresponsive owned child is reaped, and escalation fails the run', async () => {
    const child = spawn(process.execPath, ['-e', "process.on('SIGTERM',()=>{}); console.log('ready'); setInterval(()=>{},1000)"]);
    await once(child.stdout, 'data');
    await assert.rejects(withOwnedVerification({ output: output('escalation') }, async run => {
        run.defer('child', () => stopOwnedProcess(child, 250));
    }), /SIGKILL/);
    assert.equal(alive(child.pid), false);
    assert.equal((await read('escalation')).fixtureRemoved, false);
});

test('SIGTERM enters shutdown, retains diagnostics and does not skip subsequent cleanups', async () => {
    let cleaned = false;
    await assert.rejects(withOwnedVerification({ output: output('signal') }, async run => {
        run.defer('resource', async () => { cleaned = true; });
        process.kill(process.pid, 'SIGTERM');
        await new Promise(resolve => run.signal.addEventListener('abort', resolve, { once: true }));
        run.signal.throwIfAborted();
    }), /SIGTERM/);
    assert.equal(cleaned, true);
    assert.equal((await read('signal')).passed, false);
});

test('a user-supplied output directory is never a deletion target', async () => {
    const durable = output('user-supplied');
    await fs.mkdir(durable, { recursive: true });
    await fs.writeFile(path.join(durable, 'keep'), 'keep');
    await withOwnedVerification({ output: durable }, async () => {});
    assert.equal(await fs.readFile(path.join(durable, 'keep'), 'utf8'), 'keep');
});

test('a reporting failure retains the owned fixture and still verifies resource shutdown', async () => {
    let fixture, cleaned = false;
    await assert.rejects(withOwnedVerification({ output: output('report-failure') }, async run => {
        fixture = run.directory;
        run.defer('resource', async () => { cleaned = true; });
        await fs.mkdir(path.join(run.output, 'results.json'));
    }));
    assert.equal(cleaned, true);
    assert.equal(await exists(path.join(fixture, 'failure.log')), true);
});

test('replaced durable output cannot redirect reporting into the private fixture', async () => {
    let fixture;
    const destination = output('output-replaced');
    await assert.rejects(withOwnedVerification({ output: destination }, async run => {
        fixture = run.directory;
        await fs.rename(destination, destination + '-original');
        await fs.symlink(fixture, destination);
    }), /Durable output path changed/);
    assert.equal(await exists(path.join(fixture, 'results.json')), false);
    assert.equal(await exists(path.join(fixture, 'failure.log')), true);
});

test('result filenames cannot escape the durable output directory', async () => {
    await assert.rejects(withOwnedVerification({ output: output('bad-result'), resultFile: '../outside.json' }, async () => {}));
    assert.equal(await exists(path.join(evidence, 'outside.json')), false);
});

test('failed-run diagnostics never follow an injected file symlink', async () => {
    const sentinel = path.join(evidence, 'external-diagnostic');
    await fs.writeFile(sentinel, 'keep');
    await assert.rejects(withOwnedVerification({ output: output('diagnostic-symlink') }, async run => {
        await fs.symlink(sentinel, path.join(run.directory, 'failure.log'));
        throw new Error('intentional failure');
    }));
    assert.equal(await fs.readFile(sentinel, 'utf8'), 'keep');
});

console.log(`Owned lifecycle regression results: ${evidence}`);
