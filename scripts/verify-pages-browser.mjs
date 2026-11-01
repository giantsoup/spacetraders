import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || 'php';
const baseline = process.env.QA_BASELINE === '1';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const created = execFileSync(php, ['scripts/mvp-fixture.php', '--isolated'], { cwd: root, encoding: 'utf8' });
const directory = created.match(/Owned fixture directory: (.+)/)?.[1];
assert.ok(directory);
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`], { cwd: root, encoding: 'utf8' }));
assert.equal(parsed.DB_DATABASE, `${directory}/database.sqlite`);
assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, `${directory}/world.json`);
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `pages_${port}`, CACHE_DRIVER: 'array', CACHE_STORE: 'array', SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: `${directory}/storage`, LOG_CHANNEL: 'stderr' };
for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) {
    await fs.mkdir(`${env.LARAVEL_STORAGE_PATH}/${child}`, { recursive: true });
}
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/style-refactor', baseline ? 'before' : 'after'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });
const errors = [], pages = [], steps = [], components = new Set();
try {
    await once(server.stderr, 'data');
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light', locale: 'en-US', timezoneId: 'America/Los_Angeles' });
    await context.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === origin) return route.continue();
        if (url.hostname === 'fonts.bunny.net') return route.fulfill({ status: 200, contentType: 'text/css', body: '' });
        return route.abort();
    });
    const page = await context.newPage();
    const login = async () => {
        await page.goto(origin + '/login');
        await page.getByLabel('Email', { exact: true }).fill(auth.email);
        await page.getByLabel('Password', { exact: true }).fill(auth.password);
        await page.getByRole('button', { name: 'Log in', exact: true }).click();
        await page.waitForURL('**/agents');
    };
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => {
        if (message.type() !== 'error') return;
        if (message.location().url === origin + '/missing-style-test-page' && message.text().includes('404')) return;
        errors.push(message.text());
    });
    page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${new URL(response.url()).pathname}`); });
    const capture = async (name, url) => {
        const response = await page.goto(origin + url);
        const pageData = (await response.text()).match(/<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/);
        assert.ok(pageData, `${name} returns an Inertia page`);
        components.add(JSON.parse(pageData[1]).component);
        await page.locator('h1, main, form').first().waitFor();
        for (const width of [1440, 390, 320]) {
            await page.setViewportSize({ width, height: 1000 });
            if (!baseline) {
                assert.equal(await page.locator('.app-shell').count(), 1, `${name} uses the shared shell`);
                assert.equal(await page.locator('.app-shell').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${name} fits ${width}px`);
                assert.equal(await page.locator('h1').count(), 1, `${name} has one primary heading`);
                assert.equal(await page.locator('a button, button a').count(), 0);
                assert.equal(await page.locator('.app-topbar').evaluate(el => el.scrollWidth <= el.clientWidth), true);
                assert.match(await page.locator('.app-topbar').evaluate(el => getComputedStyle(el).fontFamily), /sans-serif/);
                assert.deepEqual(await page.evaluate(() => {
                    const ids = [...document.querySelectorAll('[id]')].map(el => el.id);
                    return ids.filter((id, index) => ids.indexOf(id) !== index);
                }), [], `${name} has unique element IDs`);
                const light = await page.locator('.app-shell').evaluate(el => getComputedStyle(el).backgroundColor);
                await page.emulateMedia({ colorScheme: 'dark' });
                assert.equal(await page.locator('.app-shell').evaluate(el => getComputedStyle(el).backgroundColor), light);
                await page.emulateMedia({ colorScheme: 'light' });
            }
            await page.screenshot({ path: path.join(output, `${name}-${width}.png`), fullPage: true, animations: 'disabled' });
        }
        pages.push(name);
    };
    await capture('welcome', '/');
    await capture('login', '/login');
    if (!baseline) {
        assert.equal((await page.goto(origin + '/missing-style-test-page')).status(), 404);
        await page.getByRole('heading', { name: 'Not Found', exact: true }).waitFor();
        for (const width of [1440, 390, 320]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.equal(await page.locator('html').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
            await page.screenshot({ path: path.join(output, `error-404-${width}.png`), fullPage: true });
        }
        await page.getByRole('link', { name: 'Return to command', exact: false }).click();
        await page.waitForURL(origin + '/');
        steps.push('Dark missing-page fallback at all three widths, with a working return link');
        await page.goto(origin + '/login');
    }
    if (!baseline) {
        await page.getByLabel('Email', { exact: true }).fill(auth.email);
        await page.getByLabel('Password', { exact: true }).fill('incorrect-fixture-password');
        await page.getByRole('button', { name: 'Log in', exact: true }).click();
        await page.getByText('These credentials do not match our records.', { exact: true }).waitFor();
        assert.equal(await page.getByLabel('Email', { exact: true }).getAttribute('aria-invalid'), 'true');
        await page.screenshot({ path: path.join(output, 'login-error.png'), fullPage: true });
        steps.push('Invalid login displays a readable error associated with the field');
    }
    await login();
    await capture('agents', '/agents');
    await capture('create-agent', '/agents/create');
    await capture('import-agent', '/agents/create?existing=1');
    await capture('guided-setup', '/agents/create?guided=1');
    await capture('profile', '/profile');
    await capture('confirm-password', '/confirm-password');
    cli('App\\Models\\User::query()->update(["email_verified_at"=>null]); echo json_encode(true);');
    await capture('guide', '/guide');
    await capture('agent-guide', `/agents/${auth.agent_id}/guide`);
    await capture('flight-deck', `/agents/${auth.agent_id}`);
    const expectedComponents = (await fs.readdir(path.join(root, 'resources/js/Pages'), { recursive: true }))
        .filter(name => name.endsWith('.vue') && !name.includes('Partials/'))
        .map(name => name.slice(0, -4)).sort();
    assert.deepEqual([...components].sort(), expectedComponents, 'Every Inertia page must have a rendered coverage entry');
    if (!baseline) {
        await page.goto(origin + '/agents');
        await page.keyboard.press('Tab');
        assert.equal(await page.locator('.skip').evaluate(el => el === document.activeElement), true);
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('main').evaluate(el => el === document.activeElement), true);
        await page.getByRole('link', { name: 'Register new agent', exact: true }).click();
        await page.getByLabel('Account token', { exact: true }).fill('synthetic-unsaved-token');
        await page.getByRole('button', { name: 'Already have an Agent?', exact: true }).click();
        await page.getByLabel('Agent token', { exact: true }).fill('synthetic-unsaved-token');
        await page.getByRole('button', { name: 'Back to Create New Agent', exact: true }).click();
        assert.equal(await page.getByLabel('Account token', { exact: true }).inputValue(), '');
        await page.getByRole('button', { name: 'Already have an Agent?', exact: true }).click();
        assert.equal(await page.getByLabel('Agent token', { exact: true }).inputValue(), '');
        await page.getByLabel('Agent token', { exact: true }).fill('synthetic-offline-only');
        await page.getByRole('button', { name: 'Save Existing Agent', exact: true }).click();
        await page.waitForURL('**/agents');
        steps.push('Keyboard skip link, setup/import switching clears tokens, real handler imports the offline agent');

        await page.getByRole('link', { name: 'Profile', exact: true }).click();
        const profile = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Profile Information', exact: true }) });
        await profile.getByLabel('Name', { exact: true }).fill('Style QA Pilot');
        await profile.getByRole('button', { name: 'Save', exact: true }).click();
        await profile.getByText('Saved.', { exact: true }).waitFor();
        await page.reload();
        assert.equal(await profile.getByLabel('Name', { exact: true }).inputValue(), 'Style QA Pilot');
        const password = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Update Password', exact: true }) });
        await password.getByLabel('Current Password', { exact: true }).fill('incorrect-fixture-password');
        await password.getByLabel('New Password', { exact: true }).fill('style-qa-updated-password');
        await password.getByLabel('Confirm Password', { exact: true }).fill('style-qa-updated-password');
        await password.getByRole('button', { name: 'Save', exact: true }).click();
        await password.getByText('The password is incorrect.', { exact: true }).waitFor();
        await password.getByLabel('Current Password', { exact: true }).fill(auth.password);
        await password.getByRole('button', { name: 'Save', exact: true }).click();
        await password.getByText('Saved.', { exact: true }).waitFor();
        auth.password = 'style-qa-updated-password';
        steps.push('Profile save persists after reload; password error recovery and successful update');

        const modal = page.getByRole('dialog', { name: 'Are you sure you want to delete your account?', exact: true });
        const trigger = page.getByRole('button', { name: 'Delete Account', exact: true });
        await trigger.click();
        await modal.waitFor();
        assert.equal(await modal.getByLabel('Password', { exact: true }).evaluate(el => el === document.activeElement), true);
        assert.equal(await modal.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(13, 21, 36)');
        await page.keyboard.press('Tab');
        assert.equal(await modal.getByRole('button', { name: 'Cancel', exact: true }).evaluate(el => el === document.activeElement), true);
        await page.keyboard.press('Tab');
        assert.equal(await modal.getByRole('button', { name: 'Delete Account', exact: true }).evaluate(el => el === document.activeElement), true);
        assert.equal(await modal.evaluate(el => el.matches(':modal') && document.elementFromPoint(1, 1) === el), true, 'The native modal makes the background inert');
        await page.keyboard.press('Shift+Tab');
        await page.keyboard.press('Shift+Tab');
        assert.equal(await modal.getByLabel('Password', { exact: true }).evaluate(el => el === document.activeElement), true);
        await modal.getByLabel('Password', { exact: true }).fill('incorrect-fixture-password');
        await modal.getByRole('button', { name: 'Delete Account', exact: true }).click();
        await modal.getByText('The password is incorrect.', { exact: true }).waitFor();
        await page.screenshot({ path: path.join(output, 'profile-dialog-error-320.png') });
        await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
        await modal.waitFor({ state: 'hidden' });
        assert.equal(await trigger.evaluate(el => el === document.activeElement), true);
        await trigger.click();
        assert.equal(await modal.getByText('The password is incorrect.', { exact: true }).isVisible(), false);
        await page.setViewportSize({ width: 320, height: 568 });
        assert.equal(await modal.evaluate(el => el.getBoundingClientRect().height <= innerHeight - 48), true);
        await modal.getByRole('button', { name: 'Delete Account', exact: true }).click({ trial: true });
        await page.screenshot({ path: path.join(output, 'profile-dialog-small.png') });
        await page.keyboard.press('Escape');
        await modal.waitFor({ state: 'hidden' });
        await page.setViewportSize({ width: 320, height: 1000 });
        await trigger.click();
        await page.mouse.click(1, 1);
        await modal.waitFor({ state: 'hidden' });
        steps.push('Dark deletion dialog at 320px: initial focus, keyboard navigation, inert background, invalid password, Cancel, focus return, clean reopen, Escape and backdrop dismissal');

        await page.goto(origin + '/confirm-password');
        await page.getByLabel('Password', { exact: true }).fill('incorrect-fixture-password');
        await page.getByRole('button', { name: 'Confirm', exact: true }).click();
        await page.getByText('The provided password is incorrect.', { exact: true }).waitFor();
        await page.getByLabel('Password', { exact: true }).fill(auth.password);
        await page.getByRole('button', { name: 'Confirm', exact: true }).click();
        await page.waitForURL('**/agents');
        steps.push('Password confirmation error and success through the authenticated handler');
        await page.getByRole('button', { name: 'Log out', exact: true }).click();
        await page.waitForURL(origin + '/');
        assert.equal(await page.locator('a[href*="register"],a[href*="forgot-password"]').count(), 0);
        for (const uri of ['/register', '/forgot-password', '/reset-password/synthetic-token', '/verify-email']) {
            assert.equal((await context.request.get(origin + uri)).status(), 404);
        }
        for (const uri of ['/register', '/forgot-password', '/reset-password', '/email/verification-notification']) {
            assert.equal((await context.request.post(origin + uri)).status(), 404);
        }
        await login();
        steps.push('Private login navigation, closed registration/reset/verification requests and existing-account login');
    }
    if (baseline) {
        await page.goto(origin + '/agents/create');
        const background = await page.locator('body > div').first().evaluate(el => getComputedStyle(el.firstElementChild).backgroundColor);
        assert.equal(await page.locator('.app-shell').count(), 0, 'Baseline reproduces the missing shared dark shell');
        steps.push(`Baseline captured before refactor; create-page background ${background}`);
    }
    assert.deepEqual(errors, []);
    const result = { passed: true, baseline, browser: browser.version(), pages, components: [...components].sort(), steps, errors };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(result, null, 2));
    console.log(JSON.stringify({ ...result, output }, null, 2));
} catch (error) {
    await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({ error: error.message, pages, steps, errors, serverLog }, null, 2));
    throw error;
} finally {
    await browser.close();
    server.kill();
    await once(server, 'exit');
}
