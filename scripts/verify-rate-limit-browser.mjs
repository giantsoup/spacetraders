import assert from 'node:assert/strict';
import path from 'node:path';
import { withBrowserVerification } from './browser-verification.mjs';

const root = path.resolve(import.meta.dirname, '..');
await withBrowserVerification({ root, php: process.env.PHP_BIN || 'php',
    output: path.join(root, 'storage/app/rate-limit-evidence'), resultFile: 'result.json',
}, async ({ browser, origin, auth, output }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'light' });
    await context.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === origin) return route.continue();
        if (url.hostname === 'fonts.bunny.net') return route.fulfill({ contentType: 'text/css', body: '' });
        return route.abort();
    });
    const page = await context.newPage();
    await page.clock.install();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${origin}/login`);
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL('**/agents');
    await page.goto(`${origin}/agents/${auth.agent_id}`);
    await page.getByRole('button', { name: /API rate limit: Practice/ }).waitFor();

    const bucket = { requests: 40, responses: 40, rate_limits: 0, deferrals: 2,
        available: 9, burst_capacity: 10, sustained_per_second: 1.9,
        requests_last_60_seconds: 10, requests_per_second_last_5: 0.4, requests_per_second_last_60: 10 / 60,
        blocked_until: 0, last_response: { status: 200, scope: 'IP address', per_second: 2, burst: 30, remaining: 1, observed_at: Date.now() / 1000 },
    };
    let host = { ...bucket }, account = { ...bucket, last_response: null }, fail = false, hold = false, requests = 0;
    const held = [];
    const body = () => JSON.stringify(fail ? { message: 'Unavailable' } : {
        as_of: Date.now() / 1000, mode: 'live', pacing_enabled: true, buckets: { host, account },
    });
    await context.route('**/rate-limit?*', async route => {
        requests++;
        assert.equal(new URL(route.request().url()).searchParams.get('agent'), String(auth.agent_id));
        if (hold) { held.push(route); return; }
        await route.fulfill({ status: fail ? 503 : 200, contentType: 'application/json',
            body: body(),
        });
    });
    await page.getByRole('button', { name: /API rate limit:/ }).click();
    const dialog = page.getByRole('dialog', { name: 'API rate limit', exact: true });
    const refresh = () => dialog.getByRole('button', { name: 'Refresh status', exact: true }).click();
    await refresh();
    await page.getByRole('button', { name: /API rate limit: Room available/ }).waitFor();
    await dialog.getByRole('heading', { name: 'Selected account budget' }).waitFor();
    await dialog.getByText('Reported remaining · advisory', { exact: true }).waitFor();
    assert.equal(await dialog.locator('meter').count(), 2);
    assert.equal(await dialog.locator('.rate-details').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
    await page.screenshot({ path: `${output}/healthy-mobile.png` });
    hold = true;
    await page.clock.fastForward(16000);
    await page.getByRole('button', { name: /API rate limit: Unavailable/ }).waitFor();
    hold = false;
    for (const route of held.splice(0)) await route.fulfill({ contentType: 'application/json', body: body() });
    await page.getByRole('button', { name: /API rate limit: Room available/ }).waitFor();
    host = { ...bucket, available: 4, requests_per_second_last_5: 2.4 };
    await refresh();
    await page.getByRole('button', { name: /API rate limit: Slow down/ }).waitFor();
    await dialog.getByText(/Local discovery requests may already need to wait/).waitFor();
    await page.screenshot({ path: `${output}/warning-mobile.png` });
    host = { ...bucket };
    account = { ...bucket, blocked_until: Date.now() / 1000 + 30 };
    await refresh();
    await page.getByRole('button', { name: /API rate limit: Cooling down/ }).waitFor();
    await dialog.getByText(/Requests are paused/).waitFor();
    await page.screenshot({ path: `${output}/cooldown-mobile.png` });
    fail = true;
    await refresh();
    await page.getByRole('button', { name: /API rate limit: Unavailable/ }).waitFor();
    await dialog.getByText(/Previous snapshot/).waitFor();
    for (let i = 0; i < 12; i++) {
        await page.keyboard.press('Tab');
        assert.ok(await dialog.evaluate(el => el.contains(document.activeElement)), 'Dialog contains focus');
    }
    await page.keyboard.press('Escape');
    assert.ok(await page.getByRole('button', { name: /API rate limit:/ }).evaluate(el => el === document.activeElement));
    for (const width of [320, 390, 1440]) {
        await page.setViewportSize({ width, height: 844 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `No overflow at ${width}px`);
        const bounds = await page.getByRole('button', { name: /API rate limit:/ }).boundingBox();
        assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width && bounds.height >= 44);
        await page.screenshot({ path: `${output}/header-${width}.png` });
    }
    assert.deepEqual(errors, []);
    assert.ok(requests >= 4);
    await context.close();
    return { verified: ['practice', 'healthy', 'stale data', 'low capacity forecast', 'account cooldown', 'unavailable', 'focus trap and return', '320/390/1440px header'], errors };
});
