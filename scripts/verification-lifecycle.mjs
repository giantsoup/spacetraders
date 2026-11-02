import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

export async function waitUntil(check, timeoutMs = 20_000) {
    const deadline = Date.now() + timeoutMs;
    while (!await check()) {
        if (Date.now() >= deadline) throw new Error('Timed out waiting for owned verification state');
        await new Promise(resolve => setTimeout(resolve, 100));
    }
}

export async function stopOwnedProcess(child, timeoutMs = 5_000) {
    if (!child.pid || child.exitCode !== null || child.signalCode !== null) return;
    child.kill('SIGTERM');
    try {
        await waitUntil(() => child.exitCode !== null || child.signalCode !== null, timeoutMs);
    } catch {
        child.kill('SIGKILL');
        await waitUntil(() => child.exitCode !== null || child.signalCode !== null, timeoutMs);
        throw new Error('Owned process required SIGKILL; diagnostics retained');
    }
}

// No caller-supplied deletion path. The closure retains the identity of its own mkdtemp allocation.
export async function withOwnedVerification({ output, resultFile = 'results.json' }, work) {
    await fs.mkdir(output, { recursive: true });
    output = await fs.realpath(output);
    assert.equal(path.basename(resultFile), resultFile);
    assert.ok(!['.', '..'].includes(resultFile));
    const parent = await fs.realpath(os.tmpdir());
    const directory = await fs.mkdtemp(path.join(parent, 'spacetraders-verification-'));
    await fs.chmod(directory, 0o700);
    const identity = await fs.lstat(directory);
    const token = randomBytes(32).toString('hex');
    const marker = path.join(directory, '.verification-owner');
    await fs.writeFile(marker, token, { flag: 'wx', mode: 0o600 });
    const cleanups = [], failures = [], privateCaptures = [], secrets = [];
    const controller = new AbortController();
    const interrupt = signal => controller.abort(new Error(`Verification interrupted by ${signal}`));
    const signals = ['SIGINT', 'SIGTERM'].map(signal => [signal, () => interrupt(signal)]);
    for (const [signal, handler] of signals) process.once(signal, handler);
    const redact = value => secrets.reduce((text, secret) => text.replaceAll(secret, '[redacted]'), String(value));
    const owned = async () => {
        const current = await fs.lstat(directory);
        assert.ok(current.isDirectory() && !current.isSymbolicLink(), 'Owned fixture was replaced by a symlink');
        assert.equal(current.ino, identity.ino, 'Owned fixture directory identity changed');
        assert.equal(current.dev, identity.dev, 'Owned fixture device changed');
        assert.equal(current.uid, process.getuid(), 'Owned fixture owner changed');
        assert.equal(current.mode & 0o777, 0o700, 'Owned fixture is no longer private');
        assert.equal(await fs.realpath(directory), directory, 'Owned fixture path changed');
        const stamp = await fs.lstat(marker);
        assert.ok(stamp.isFile() && !stamp.isSymbolicLink(), 'Owned fixture marker changed');
        assert.equal(await fs.readFile(marker, 'utf8'), token, 'Owned fixture marker mismatch');
    };
    const details = {};
    try {
        Object.assign(details, await work({
            directory, output, details, signal: controller.signal,
            defer: (name, cleanup) => cleanups.push({ name, cleanup }),
            onFailure: capture => privateCaptures.push(capture),
            redact: secret => { if (secret) secrets.push(secret); },
        }) || {});
        controller.signal.throwIfAborted();
    } catch (error) { failures.push(error); }
    if (failures.length) {
        for (const capture of privateCaptures) {
            try { await capture(); } catch (error) { failures.push(error); }
        }
    }
    const shutdown = {};
    for (const { name, cleanup } of cleanups.reverse()) {
        try { await cleanup(); shutdown[name] = 'verified'; }
        catch (error) { shutdown[name] = 'failed'; failures.push(error); }
    }
    if (controller.signal.aborted && !failures.includes(controller.signal.reason)) failures.push(controller.signal.reason);
    let result;
    let ownershipVerified = false;
    try {
        await owned();
        ownershipVerified = true;
        assert.equal(await fs.realpath(output), output, 'Durable output path changed');
        assert.ok(output !== directory && !output.startsWith(directory + path.sep), 'Durable output must be outside the fixture');
        result = { ...details, passed: failures.length === 0, shutdown, fixture: directory,
            fixtureRemoved: false, diagnostics: failures.length ? directory : null,
            failures: failures.map(error => redact(error.message)) };
        // Prove the durable destination is writable before removing any private evidence.
        await fs.writeFile(path.join(output, resultFile), JSON.stringify({ ...result, passed: false }, null, 2));
        if (!failures.length) {
            await owned();
            await fs.rm(directory, { recursive: true });
            assert.equal(await fs.lstat(directory).then(() => true, error => { if (error.code === 'ENOENT') return false; throw error; }), false);
            result.fixtureRemoved = true;
        }
    } catch (error) { failures.push(error); }
    finally { for (const [signal, handler] of signals) process.removeListener(signal, handler); }
    if (failures.length) {
        // Do not write through a replaced directory or ownership marker.
        ownershipVerified = false;
        try {
            await owned();
            ownershipVerified = true;
            await fs.writeFile(path.join(directory, 'failure.log'), failures.map(error =>
                `${error.stack || error}\n${error.stdout || ''}\n${error.stderr || ''}`).join('\n'), { flag: 'wx', mode: 0o600 });
        } catch { /* The original allocation may have been moved. Never follow its replacement. */ }
        result = { ...details, passed: false, shutdown, fixture: directory, fixtureRemoved: false,
            diagnostics: ownershipVerified ? directory : null, failures: failures.map(error => redact(error.message)) };
        console.error(ownershipVerified ? `Private failed-run diagnostics retained at: ${directory}`
            : `Cleanup refused at ${directory}. Ownership is unverified; the original allocation may have moved.`);
    }
    // A reporting failure also fails the run. It must never be printed as a successful cleanup.
    assert.equal(await fs.realpath(output), output, 'Durable output path changed');
    await fs.writeFile(path.join(output, resultFile), JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
    if (failures.length) throw new Error(`Verification failed: ${result.failures.join('; ')}. ${ownershipVerified ? 'Private diagnostics' : 'Cleanup refused at'}: ${directory}`);
    return result;
}
