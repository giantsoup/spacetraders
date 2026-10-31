import assert from 'node:assert/strict';
import { createHash, randomInt } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

const pnpm = process.env.PNPM_BIN || 'pnpm';
const rootFixture = await mkdtemp('verify-build-fixture-');
const viewsFixture = path.join('storage/framework/views', path.basename(rootFixture) + '.php');
const build = () => execFileSync('bash', ['-c', 'exec "$@"', 'verify-build', pnpm, 'build'], { stdio: 'inherit' });
async function digest(directory) {
    const hash = createHash('sha256');
    for (const name of (await readdir(directory, { recursive: true, withFileTypes: true }))
        .filter(entry => entry.isFile()).map(entry => path.join(entry.parentPath, entry.name)).sort()) {
        hash.update(path.relative(directory, name));
        hash.update(await readFile(name));
    }
    return hash.digest('hex');
}

try {
    build();
    const baseline = await digest('public/build');
    const utility = `top-[${randomInt(100000, 999999)}px]`;
    const utilities = `<div class="${utility}">owned scan fixture</div>`;
    await writeFile(path.join(rootFixture, 'notes.md'), utilities);
    await writeFile(viewsFixture, utilities, { flag: 'wx' });
    build();
    assert.equal(await digest('public/build'), baseline,
        'Unrelated Markdown and generated views must not change production assets.');
    console.log('Production assets are identical with unrelated Markdown and generated-view fixtures.');
} finally {
    await rm(rootFixture, { recursive: true });
    await rm(viewsFixture, { force: true });
}
