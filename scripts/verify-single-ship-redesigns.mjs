import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';

const root = path.resolve(import.meta.dirname, '..');
const source = path.join(root, 'docs/single-ship-redesigns.html');
const output = path.join(root, 'storage/app/ship-redesign-evidence');
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const sourceBytes = await fs.readFile(source);
const evidence = {
  source, sourceSha256: createHash('sha256').update(sourceBytes).digest('hex'),
  timestamp: new Date().toISOString(), browser: browser.version(),
  checks: [], previews: [], errors: [], externalRequests: [],
};
await fs.mkdir(output, { recursive: true });
try {
  const context = await browser.newContext({ colorScheme: 'light', timezoneId: 'America/Los_Angeles' });
  await context.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith('file:') || url.startsWith('data:')) return route.continue();
    evidence.externalRequests.push(url);
    return route.abort();
  });
  const page = await context.newPage();
  page.on('pageerror', error => evidence.errors.push(error.message));
  const reportUrl = pathToFileURL(source).href;
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.clock.install({ time: new Date('2026-11-01T20:00:00Z') });
  await page.goto(reportUrl);
  assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme), 'dark');
  assert.equal(await page.locator('h1').count(), 1);
  assert.equal(await page.evaluate(() => {
    const ids = [...document.querySelectorAll('[id]')].map(e => e.id);
    return ids.length === new Set(ids).size;
  }), true);
  const links = await page.locator('a[href*="preview="]').evaluateAll(elements => elements.map(e => e.href));
  const previews = [...new Map(links.map(link => [new URL(link).searchParams.get('preview'), link])).values()];
  assert.equal(previews.length, 3, 'Three directly linkable full-screen concepts are required');
  for (const width of [1440, 728, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `Report overflows at ${width}px`);
    await page.screenshot({ path: path.join(output, `report-${width}.png`), fullPage: true });
  }
  evidence.checks.push('Dark report under light OS preference; unique IDs; no horizontal overflow at 320, 390, 728, and 1440 pixels.');
  await page.setViewportSize({ width: 1440, height: 1000 });
  for (const slug of ['horizon', 'logbook', 'voyage']) {
    const screen = page.locator(`[data-screen="${slug}"]`);
    const opener = screen.locator('[data-action="report"]').first();
    for (const state of ['transit', 'orbit', 'docked', 'stale']) {
      await page.locator(`[data-slug="${slug}"] [data-set-state="${state}"]`).click();
      assert.equal(await screen.getAttribute('data-state'), state);
      await opener.click();
      const report = slug === 'logbook' ? screen.locator('[data-view="report"]') : page.locator(`#${slug}-report`);
      assert.equal(await report.isVisible(), true);
      assert.match(await report.innerText(), /Cargo|Fuel|Systems/);
      await page.screenshot({ path: path.join(output, `report-${slug}-${state}.png`), fullPage: false });
      await page.keyboard.press('Escape');
      assert.equal(await report.isVisible(), false);
      assert.equal(await opener.evaluate(e => e === document.activeElement), true);
      if (slug === 'voyage') {
        await opener.click();
        await page.locator('#voyage-report [data-action="primary"]').click();
      } else {
        await screen.locator('[data-action="primary"]').click();
      }
      if (state === 'stale') {
        assert.equal(await screen.getAttribute('data-state'), 'transit');
      } else {
        const dialog = page.locator('#action-dialog');
        assert.equal(await dialog.isVisible(), true);
        if (state === 'orbit' || state === 'docked') {
          await dialog.getByRole('button', { name: 'Approve (demo)', exact: true }).click();
          assert.match(await dialog.innerText(), /Nothing was sent/);
        } else {
          assert.equal(await dialog.getByRole('button', { name: 'Approve (demo)', exact: true }).count(), 0);
        }
        await page.keyboard.press('Escape');
      }
    }
  }
  evidence.checks.push('Each concept supports all four states, report open/close and focus return, action previews, demo approvals and stale refresh.');
  await page.locator('[data-control="pause"]').first().click();
  const arrivalBefore = await page.locator('[data-global-time="arrival"]').getAttribute('datetime');
  const countdownBefore = await page.locator('[data-screen="horizon"] [data-bind="big"]').innerText();
  await page.clock.runFor(3100);
  assert.equal(await page.locator('[data-screen="horizon"] [data-bind="big"]').innerText(), countdownBefore);
  await page.locator('[data-control="pause"]').first().click();
  assert.equal(await page.locator('[data-global-time="arrival"]').getAttribute('datetime'), arrivalBefore);
  await page.locator('[data-control="pause"]').first().click();
  await page.clock.runFor(600000);
  await page.locator('[data-control="restart"]').first().click();
  assert.match(await page.locator('[data-screen="horizon"] [data-bind="big"]').innerText(), /^0?2:18$/);
  await page.locator('[data-control="pause"]').first().click();
  await page.clock.runFor(139000);
  for (const slug of ['horizon', 'logbook', 'voyage']) {
    const screen = page.locator(`[data-screen="${slug}"]`);
    assert.equal(await screen.getAttribute('data-state'), 'transit');
    assert.match(await screen.innerText(), /Checking|Waiting for a fresh observation/);
  }
  evidence.checks.push('Pause preserves the absolute arrival, paused restart rebases presentation, and zero remains checking until a new observation.');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.waitForFunction(() => document.body.classList.contains('still'));
  assert.equal(await page.locator('[data-screen="horizon"] .ship-btn svg').evaluate(e => getComputedStyle(e).animationName), 'none');
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  evidence.checks.push('OS reduced-motion preference stops decorative ship animation.');

  for (const url of previews) {
    const slug = new URL(url).searchParams.get('preview');
    for (const [width, height] of [[390, 844], [375, 548], [320, 667], [844, 390]]) {
      await page.setViewportSize({ width, height });
      await page.goto(url);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${slug} overflows at ${width}px`);
      const geometry = await page.evaluate(() => ({ width: innerWidth, height: innerHeight, scrollHeight: document.documentElement.scrollHeight }));
      evidence.previews.push({ slug, width, height, ...geometry });
      const screen = page.locator(`[data-screen="${slug}"]`);
      const rect = await screen.boundingBox();
      assert.ok(Math.abs(rect.x) < 1 && Math.abs(rect.width - width) < 1, `${slug} is not edge to edge`);
      assert.ok(rect.y + rect.height <= height + 1, `${slug} extends below its viewport`);
      const clippedChrome = await page.locator('.preview-bar a:visible,.preview-bar button:visible,.preview-bar select:visible').evaluateAll(elements => elements.map(e => ({ text: e.textContent.trim(), left: e.getBoundingClientRect().left, right: e.getBoundingClientRect().right })).filter(e => e.left < -1 || e.right > innerWidth + 1));
      assert.deepEqual(clippedChrome, [], `${slug} has clipped preview controls`);
      if (slug === 'horizon' && height > width) {
        const essentials = await screen.locator('[data-bind="fuel"],[data-bind="cargo"]').evaluateAll(elements => elements.map(e => {
          const r = (e.closest('button') || e).getBoundingClientRect(), clip = e.closest('.scroll')?.getBoundingClientRect();
          return { name: e.dataset.bind, visible: r.top >= (clip?.top ?? 0) - 1 && r.bottom <= (clip?.bottom ?? innerHeight) + 1 };
        }));
        assert.equal(essentials.length, 2);
        assert.ok(essentials.every(e => e.visible), `Horizon essentials below the fold at ${width}x${height}`);
      }

      await page.screenshot({ path: path.join(output, `${slug}-${width}x${height}.png`), fullPage: true });
      const controls = page.locator('[data-action="preview-controls"]');
      const controlsDialog = page.locator('#preview-dialog');
      for (const state of ['transit', 'orbit', 'docked', 'stale']) {
        await controls.click();
        assert.equal(await controlsDialog.isVisible(), true);
        assert.equal(await controls.getAttribute('aria-expanded'), 'true');
        await controlsDialog.locator(`[data-set-state="${state}"]`).click();
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => document.querySelector('[data-action="preview-controls"]').getAttribute('aria-expanded') === 'false');
        assert.equal(await controls.evaluate(e => e === document.activeElement), true);
        assert.equal(await controls.getAttribute('aria-expanded'), 'false');
        assert.equal(await screen.getAttribute('data-state'), state);
        if (slug === 'horizon' && height > width) {
          const visible = await screen.locator('[data-bind="fuel"],[data-bind="cargo"]').evaluateAll(elements => elements.every(e => {
            const r = (e.closest('button') || e).getBoundingClientRect(), clip = e.closest('.scroll').getBoundingClientRect();
            return r.top >= clip.top - 1 && r.bottom <= clip.bottom + 1;
          }));
          assert.ok(visible, `Horizon ${state} hides essentials at ${width}x${height}`);
        }
      }
      await controls.click();
      await controlsDialog.locator('[data-control="pause"]').click();
      assert.equal(await controlsDialog.locator('[data-control="pause"]').getAttribute('aria-pressed'), 'true');
      await controlsDialog.locator('[data-control="restart"]').click();
      await controlsDialog.locator('[data-control="pause"]').click();
      await controlsDialog.locator('[data-control="still"]').click();
      assert.equal(await page.locator('body').evaluate(e => e.classList.contains('still')), true);
      const shortControls = await controlsDialog.locator('button:visible,a:visible').evaluateAll(elements => elements.map(e => ({text:e.textContent.trim(),width:e.getBoundingClientRect().width,height:e.getBoundingClientRect().height})).filter(e=>e.width<43.9 || e.height<43.9));
      assert.deepEqual(shortControls, [], 'Preview controls must meet the 44px touch target');
      await controlsDialog.locator('[data-control="still"]').click();
      await controlsDialog.locator('[data-set-state="transit"]').click();
      await page.keyboard.press('Escape');

    }
  }
  evidence.checks.push('All three direct preview links load at four portrait/landscape sizes without page overflow.');
  evidence.checks.push('Full-screen preview controls, four state choices, pause/restart/still controls, focus return, and touch sizes pass. Horizon fuel and cargo remain visible in all portrait states.');
  assert.deepEqual(evidence.errors, []);
  assert.deepEqual(evidence.externalRequests, []);
  evidence.checks.push('No JavaScript errors or external runtime dependencies.');
} finally {
  await browser.close();
  await fs.writeFile(path.join(output, 'verification.json'), JSON.stringify(evidence, null, 2) + '\n');
}
console.log(JSON.stringify(evidence, null, 2));
