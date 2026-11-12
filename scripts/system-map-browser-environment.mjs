import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

export async function systemMapEnvironment(root) {
    const windows = process.platform === 'win32';
    const php = process.env.PHP_BIN || (windows
        ? process.env.HERD_PHP85_BIN || path.join(os.homedir(), '.config/herd/bin/php85/php.exe')
        : process.platform === 'darwin' ? path.join(root, 'scripts/herd-php') : 'php');
    const sites = path.join(os.homedir(), windows
        ? '.config/herd/config/valet/Sites'
        : 'Library/Application Support/Herd/config/valet/Sites');
    assert.equal(await fs.realpath(path.join(sites, 'spacetraders-map-90')), await fs.realpath(root), 'Herd must serve this checkout');

    // Use the same dotenv parser as Laravel, including quoted Windows fixture paths.
    const env = JSON.parse(execFileSync(php, ['-r', "require 'vendor/autoload.php'; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents('.env')));"], { cwd: root, encoding: 'utf8' }));
    const file = env.SPACETRADERS_FIXTURE_PATH;
    const owned = await fs.realpath(path.join(root, 'storage/app/system-map-90'));
    for (const value of [file, env.DB_DATABASE]) {
        assert.ok(value, 'An owned fixture and SQLite database are required');
        const relative = path.relative(owned, await fs.realpath(value));
        assert.ok(relative && relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative), 'Fixture and database must belong to this checkout');
    }
    assert.equal(env.DB_CONNECTION, 'sqlite');
    assert.ok(!env.DB_URL && !env.DATABASE_URL, 'Database URLs cannot override the owned fixture');
    assert.equal(env.SPACETRADERS_AUTOMATION_ENABLED, 'false');
    assert.equal(env.SPACETRADERS_LOCAL_ENGINE_ENABLED, 'false');
    const auth = JSON.parse(await fs.readFile(path.join(path.dirname(file), 'browser.json'), 'utf8'));
    return { php, file, auth };
}
