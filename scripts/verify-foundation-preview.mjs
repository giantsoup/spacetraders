// Owned synthetic environment for shared T3 preview. End by creating the run's done file.
import fs from 'node:fs/promises';
import path from 'node:path';
import { withBrowserVerification } from './browser-verification.mjs';
const root = process.cwd();
await withBrowserVerification({ root, php: process.env.PHP_BIN || `${root}/scripts/herd-php`, output: `${root}/storage/app/foundation-preview` }, async ({ directory, origin, auth, env, execute, signal }) => {
    const worldFile = `${directory}/world.json`;
    const world = JSON.parse(await fs.readFile(worldFile));
    world.foundation = true;
    await fs.writeFile(worldFile, JSON.stringify(world));
    // This credential is generated solely for the disposable fixture.
    await fs.writeFile(`${root}/storage/app/foundation-preview/session.json`, JSON.stringify({ directory, origin, ...auth }), { mode: 0o600 });
    console.log(JSON.stringify({ origin, session: 'storage/app/foundation-preview/session.json' }));
    const deadline = Date.now() + 3600_000;
    while (Date.now() < deadline && !signal.aborted) {
        try { await fs.access(`${directory}/done`); break; } catch { /* Await the shared preview journey. */ }
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    return { sharedPreview: true };
});
