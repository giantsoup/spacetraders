import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import os from 'node:os';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || path.join(root, 'scripts/herd-php');
const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'st-ops02-'));
await fs.chmod(directory, 0o700);
for (const part of ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
    await fs.mkdir(path.join(directory, 'storage', part), { recursive: true, mode: 0o700 });
}
await fs.writeFile(path.join(directory, 'database.sqlite'), '', { mode: 0o600 });
const env = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    LARAVEL_STORAGE_PATH: path.join(directory, 'storage'), DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(directory, 'database.sqlite'), DATABASE_URL: '',
    SESSION_DRIVER: 'file', SESSION_COOKIE: `ops02_${randomBytes(8).toString('hex')}`, CACHE_STORE: 'array', CACHE_DRIVER: 'array',
    QUEUE_CONNECTION: 'database', MAIL_MAILER: 'log', SPACETRADERS_FIXTURE_PATH: path.join(directory, 'world.json'),
    SPACETRADERS_LOCK_PATH: path.join(directory, 'locks'), SPACETRADERS_REQUEST_BUDGET_PATH: path.join(directory, 'budget'),
    SPACETRADERS_AUTOMATION_ENABLED: 'false', SPACETRADERS_LOCAL_ENGINE_ENABLED: 'false', LOG_CHANNEL: 'single' };
for (const key of ['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS']) env[`APP_${key}_CACHE`] = path.join(directory, `${key.toLowerCase()}.php`);
const cli = code => execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' });
execFileSync(php, ['-r', `require 'vendor/autoload.php'; require 'tests/Support/FakeSpaceTraders.php'; file_put_contents(getenv('SPACETRADERS_FIXTURE_PATH'), json_encode(Tests\\Support\\FakeSpaceTraders::world()));`], { cwd: root, env });
execFileSync(php, ['artisan', 'migrate', '--force'], { cwd: root, env, stdio: 'pipe' });
const password = randomBytes(24).toString('hex');
const recovered = randomBytes(24).toString('hex');
const changed = randomBytes(24).toString('hex');
const terminal = (action, answers, exit = 0, message = `Account ${action} completed.`) => {
    const result = JSON.parse(execFileSync('python3', ['scripts/verify-operator-terminal.py', php, 'artisan', 'spacetraders:operator', action], {
        cwd: root, env, input: JSON.stringify({ answers, exit, message }), encoding: 'utf8', timeout: 45000,
    }));
    assert.equal(result.passed, true);
};
let browser, server;
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/ops02'));
await fs.mkdir(output, { recursive: true });
try {
    terminal('create', [['Account email', 'operator@example.test', false], ['Account name', 'Disposable Operator', false], ['Apply create', 'yes', false], ['New password (at least 12 characters)', password, true], ['Confirm new password', password, true]]);
    terminal('create', [], 1, 'An account already exists.');
    const noInteraction = spawn(php, ['artisan', 'spacetraders:operator', 'recover', '--no-interaction'], { cwd: root, env, stdio: 'pipe' });
    assert.equal((await once(noInteraction, 'exit'))[0], 1);
    try {
        execFileSync(php, ['artisan', 'spacetraders:operator', 'recover'], { cwd: root, env, input: `operator@example.test\nyes\n${recovered}\n${recovered}\n`, stdio: ['pipe', 'pipe', 'pipe'] });
        assert.fail('A pipe must not replace hidden terminal input');
    } catch (error) {
        assert.equal(error.status, 1);
        assert.ok(!String(error.stdout).includes(recovered));
        assert.ok(!String(error.stderr).includes(recovered));
    }
    execFileSync(php, ['artisan', 'config:cache'], { cwd: root, env, stdio: 'pipe' });
    execFileSync(php, ['artisan', 'route:cache'], { cwd: root, env, stdio: 'pipe' });
    assert.equal(cli("echo config('mail.default');"), 'array');
    const socket = createServer();
    socket.listen(0, '127.0.0.1');
    await once(socket, 'listening');
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const origin = `http://127.0.0.1:${port}`;
    env.APP_URL = origin;
    server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
    await once(server.stderr, 'data');
    console.log(`Owned OPS02 browser origin: ${origin}`);
    const { chromium } = await import('playwright');
    browser = await chromium.launch({ ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
    const context = await browser.newContext({ colorScheme: 'light', viewport: { width: 1280, height: 1000 }, timezoneId: 'America/Los_Angeles' });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(origin);
    assert.equal(await page.locator('.app-shell').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
    assert.equal(await page.locator('a[href*="register"],a[href*="forgot-password"]').count(), 0);
    await page.getByRole('link', { name: 'Log in', exact: true }).first().click();
    await page.getByLabel('Email', { exact: true }).fill('operator@example.test');
    await page.getByLabel('Password', { exact: true }).fill(password);
    // Capture no filled passwords in evidence.
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL('**/agents');
    await page.goto(`${origin}/profile`);
    assert.equal(await page.locator('a[href*="verify"],button:has-text("verification")').count(), 0);
    await page.screenshot({ path: path.join(output, 'profile-desktop.png'), fullPage: true });
    terminal('recover', [['Account email', 'operator@example.test', false], ['Apply recover', 'yes', false], ['New password (at least 12 characters)', recovered, true], ['Confirm new password', recovered, true]]);
    await page.reload();
    await page.getByRole('heading', { name: 'Profile', exact: true }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/profile', 'Existing session survives CLI recovery');
    for (const uri of ['/register', '/forgot-password', '/reset-password/old-token', '/verify-email', '/verify-email/1/old-hash']) {
        assert.equal((await context.request.get(origin + uri)).status(), 404);
    }
    for (const uri of ['/register', '/forgot-password', '/reset-password', '/email/verification-notification']) {
        assert.equal((await context.request.post(origin + uri)).status(), 404);
    }
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await page.waitForURL(origin + '/');
    await page.goto(origin + '/login');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(output, 'login-mobile.png'), fullPage: true });
    assert.equal(await page.locator('a[href*="register"],a[href*="forgot-password"]').count(), 0);
    const login = async value => {
        await page.getByLabel('Email', { exact: true }).fill('operator@example.test');
        await page.getByLabel('Password', { exact: true }).fill(value);
        await page.getByRole('button', { name: 'Log in', exact: true }).click();
    };
    await login(password);
    await page.getByText('These credentials do not match our records.', { exact: true }).waitFor();
    await login(recovered);
    await page.waitForURL('**/agents');
    await page.goto(origin + '/profile');
    await page.getByLabel('Current Password', { exact: true }).fill(recovered);
    await page.getByLabel('New Password', { exact: true }).fill(changed);
    await page.getByLabel('Confirm Password', { exact: true }).fill(changed);
    const passwordForm = page.locator('form').filter({ has: page.getByLabel('Current Password', { exact: true }) });
    await passwordForm.getByRole('button', { name: 'Save', exact: true }).click();
    await passwordForm.getByText('Saved.', { exact: true }).waitFor();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.getByRole('heading', { name: 'Profile', exact: true }).click();
    await page.evaluate(() => scrollTo(0, 0));
    assert.equal(await page.locator('.app-shell').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
    await page.screenshot({ path: path.join(output, 'profile-mobile.png'), fullPage: true });
    assert.deepEqual(errors, []);
    const files = await fs.readdir(path.join(directory, 'storage/logs'));
    for (const file of files) {
        const text = await fs.readFile(path.join(directory, 'storage/logs', file), 'utf8');
        for (const secret of [password, recovered, changed]) assert.ok(!text.includes(secret), 'No secret in logs');
    }
    assert.equal(cli("echo App\\Models\\User::count();"), '1');
    console.log('PASS: cached config/routes, redirected-input refusal, real hidden-terminal one-time creation and recovery, no echoed/logged secrets, no-interaction refusal, closed direct routes, existing file session, recovered login, authenticated password change, dark desktop/mobile UI.');
} catch (error) {
    let message = String(error.stack || error);
    for (const secret of [password, recovered, changed, env.APP_KEY]) message = message.replaceAll(secret, '[redacted]');
    throw new Error(message);
} finally {
    await browser?.close();
    if (server) { server.kill('SIGTERM'); await once(server, 'exit'); }
    await fs.rm(directory, { recursive: true, force: true });
}
