import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';

const root = path.resolve(import.meta.dirname, '..');
const source = path.join(root, 'docs/single-ship-vision.html');
const output = path.join(root, 'storage/app/vision-board-evidence');
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const sourceBytes=await fs.readFile(source);
const evidence = { source, sourceSha256:createHash('sha256').update(sourceBytes).digest('hex'), timestamp:new Date().toISOString(), browser:browser.version(), checks: [], layouts: [], errors: [], externalRequests: [] };
await fs.mkdir(output, { recursive: true });
try {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'light', timezoneId: 'America/Los_Angeles' });
  await context.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith('file:') || url.startsWith('data:') || url.startsWith('http://spacetraders.test/design/')) return route.continue();
    evidence.externalRequests.push(url);
    return route.abort();
  });
  const page = await context.newPage();
  await page.clock.install({ time: new Date('2026-11-01T19:00:00Z') });
  page.on('pageerror', error => evidence.errors.push(error.message));
  await page.goto(process.env.VISION_URL || pathToFileURL(source).href);
  await page.locator('#arrival-text').filter({ hasText: 'America/Los_Angeles' }).waitFor();
  assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme), 'dark');
  assert.equal(await page.evaluate(() => { const ids = [...document.querySelectorAll('[id]')].map(e => e.id); return ids.length === new Set(ids).size; }), true);
  evidence.checks.push('Dark theme under light OS preference; unique IDs; local timezone shown.');
  for (const [width, height] of [[1440,1000],[728,1000],[390,844],[320,667]]) {
    await page.setViewportSize({ width, height });
    await page.evaluate(() => document.getElementById('phone-content').scrollTop = 0);
    const measure = await page.evaluate(() => {
      const pane = document.getElementById('phone-content');
      const metric = document.querySelector('.metrics').getBoundingClientRect();
      const rect = pane.getBoundingClientRect();
      return { overflow: document.documentElement.scrollWidth > innerWidth + 1, contentHeight: pane.clientHeight, contentScroll: pane.scrollHeight, metricsBottom: metric.bottom - rect.top, metricsVisible: metric.bottom <= rect.bottom + 1 };
    });
    assert.equal(measure.overflow, false, `Page overflow at ${width}`);
    assert.equal(measure.metricsVisible, true, `Bridge metrics clipped at ${width}`);
    assert.ok(measure.contentScroll <= measure.contentHeight + 1, `Bridge requires inner scrolling at ${width}`);
    evidence.layouts.push({ width, height, ...measure });
    await page.screenshot({ path: path.join(output, `board-${width}.png`), fullPage: true });
    if (width === 390) await page.locator('#phone').screenshot({ path: path.join(output, 'phone-390.png') });
  }
  await page.setViewportSize({ width: 390, height: 844 });
  for(const width of [390,320]){
  await page.setViewportSize({width,height:844});
  for (const layout of ['bridge','ledger','map']) {
    await page.locator(`#layout-controls [data-layout="${layout}"]`).click();
    assert.equal(await page.locator('#phone').getAttribute('data-layout'), layout);
    for (const state of ['transit','orbit','docked','cooldown','stale','review']) {
      await page.locator('#state-select').selectOption(state);
      const fit=await page.locator('#phone-content').evaluate(e=>({height:e.clientHeight,scroll:e.scrollHeight}));
      evidence.layouts.push({layout,state,width,...fit});
      if(layout!=='map')assert.ok(fit.scroll<=fit.height+1, `${layout}/${state} needs inner scrolling`);
      await page.locator('#phone').screenshot({path:path.join(output, `phone-${width}-${layout}-${state}.png`)});
      assert.equal(await page.locator('#phone').getAttribute('data-state'), state);
      await page.locator('#primary-action').click();
      assert.equal(await page.locator('#action-dialog').evaluate(e => e.open), true);
      if(layout==='bridge'&&width===390)await page.locator('#action-dialog').screenshot({path:path.join(output, `action-${state}.png`)});
      if (state === 'orbit') {
        await page.getByRole('button', { name: 'Simulate approval', exact: true }).click();
        assert.match(await page.locator('#command-result').innerText(), /upstream confirmation/);
      }
      if (state === 'stale') assert.equal(await page.locator('#phone').getAttribute('data-state'), 'transit');
      await page.keyboard.press('Escape');
      assert.equal(await page.locator('#action-dialog').evaluate(e => e.open), false);
    }
  }
  }
  await page.setViewportSize({width:390,height:844});
  evidence.checks.push('All 36 width/layout/state combinations and contextual sheets; simulated approval and stale-state recovery.');
  await page.locator('#layout-controls [data-layout="bridge"]').click();
  await page.locator('#state-select').selectOption('transit');
  await page.locator('#ship-target').click();
  assert.equal(await page.locator('#report-dialog').evaluate(e => e.open), true);
  for (const section of ['overview','cargo','systems','full']) {
    await page.locator(`[data-report="${section}"]`).click();
    assert.equal(await page.locator(`#report-${section}`).isVisible(), true);
  }
  for(const group of ['Identity','Fuel','Cargo','Navigation','Cooldown','Crew','Frame','Reactor','Engine','Modules','Mounts']){const detail=page.locator('.full-group').filter({has:page.locator('summary',{hasText:new RegExp('^'+group+'$')})});await detail.locator('summary').click();assert.equal(await detail.locator('dl').isVisible(),true);}
  assert.match(await page.locator('#report-full').innerText(), /registration.factionSymbol/);
  const navGroup=page.locator('.full-group').filter({has:page.locator('summary',{hasText:/^Navigation$/})});
  assert.equal(await navGroup.locator('dl div').filter({has:page.locator('dt',{hasText:/^waypointSymbol$/})}).locator('dd').innerText(),'X1-DEMO-A7');
  assert.match(await page.locator('#report-full').innerText(), /Unknown/);
  await page.locator('#report-dialog').screenshot({path:path.join(output,'full-report.png')});
  await page.locator('#full-report-back').click();
  assert.equal(await page.locator('#report-overview').isVisible(),true);
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('#ship-target').evaluate(e => e === document.activeElement), true);
  await page.locator('#cargo-trigger').click();
  assert.equal(await page.locator('#report-cargo').isVisible(), true);
  await page.getByRole('button', { name: 'Close ship report', exact: true }).click();
  for (const section of ['cargo','activity','bridge']) {
    await page.locator(`[data-page="${section}"]`).click();
    assert.equal(await page.locator(`#${section}-panel`).isVisible(), true);
  }
  await page.locator('#fleet-trigger').click();
  assert.equal(await page.locator('#fleet-dialog').evaluate(e => e.open), true);
  await page.keyboard.press('Escape');
  evidence.checks.push('Ship/cargo report, report sections, Escape and close, focus restoration, main navigation, future fleet sheet.');
  await page.locator('#palette-select').selectOption('lavender');
  assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--primary')), '#c9c3ff');
  await page.locator('#palette-select').selectOption('mint');
  await page.locator('#pause-motion').check();
  const clock = await page.locator('#eta').innerText();
  const arrivalBefore=await page.locator('#arrival-text').innerText();
  await page.clock.runFor(3100);
  assert.equal(await page.locator('#eta').innerText(), clock);
  await page.locator('#pause-motion').uncheck();
  assert.equal(await page.locator('#arrival-text').innerText(),arrivalBefore);
  assert.notEqual(await page.locator('#eta').innerText(),clock);
  await page.locator('#state-select').selectOption('cooldown');
  assert.equal(await page.locator('#eta').innerText(),'00:46');
  await page.clock.runFor(2000);
  await page.evaluate(()=>tick());
  assert.equal(await page.locator('#eta').innerText(),'00:44');
  await page.clock.runFor(45000);
  assert.equal(await page.locator('#eta').innerText(),'Checking');
  assert.equal(await page.locator('#status-title').innerText(),'Checking readiness');
  await page.locator('#state-select').selectOption('transit');
  await page.clock.runFor(240000);
  assert.equal(await page.locator('#status-title').innerText(),'Checking arrival');
  assert.equal(await page.locator('#phone').getAttribute('data-state'),'transit');
  evidence.checks.push('Absolute arrival stays unchanged across pause; arrival and action cooldown reach a checking state without unlocking actions.');
  await page.locator('#pause-motion').check();
  await page.clock.runFor(600000);
  await page.locator('#state-select').selectOption('cooldown');
  assert.equal(await page.locator('#eta').innerText(),'00:46');
  await page.locator('#ship-target').click();
  assert.equal(await page.locator('#report-readiness').innerText(),'00:46 · action cooldown');
  await page.clock.runFor(60000);
  assert.equal(await page.locator('#eta').innerText(),'00:46');
  assert.equal(await page.locator('#report-readiness').innerText(),'00:46 · action cooldown');
  await page.keyboard.press('Escape');
  await page.locator('#state-select').selectOption('stale');
  await page.locator('#primary-action').click();
  assert.equal(await page.locator('#eta').innerText(),'02:18');
  await page.keyboard.press('Escape');
  await page.locator('#pause-motion').uncheck();
  assert.equal(await page.locator('#eta').innerText(),'02:18');
  evidence.checks.push('New cooldown and refreshed-transit deadlines rebase the paused snapshot; readiness and bridge stay consistent. In-transit waypoint is the destination.');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.waitForFunction(() => document.getElementById('phone').classList.contains('still'));
  assert.equal(await page.locator('#phone').evaluate(e => e.classList.contains('still')), true);
  assert.equal(await page.locator('#ship-target svg').evaluate(e => getComputedStyle(e).animationName), 'none');
  evidence.checks.push('Palette selection; pause freezes presentation only; OS reduced motion stops the ship animation.');
  await page.locator('#ship-target').click();
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.getElementById('report-dialog').contains(document.activeElement)), true);
  }
  await page.locator('[data-report="systems"]').click();
  await page.locator('#report-dialog').screenshot({ path: path.join(output, 'ship-report.png') });
  await page.keyboard.press('Escape');
  const undersized = await page.locator('button:visible,select:visible').evaluateAll(elements => elements.map(e => ({ id: e.id || e.textContent.trim(), w: e.getBoundingClientRect().width, h: e.getBoundingClientRect().height })).filter(e => e.w < 43.9 || e.h < 43.9));
  assert.deepEqual(undersized, []);
  evidence.checks.push('Native dialog focus remains contained; visible buttons and selects meet the 44px design target.');
  await page.setViewportSize({ width: 320, height: 667 });
  await page.addStyleTag({ content: 'p,li,td,th,button,select,.label,.caption,.small{font-size:20px!important}' });
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
  await page.locator('#primary-action').click();
  assert.equal(await page.locator('#action-dialog').evaluate(e => e.open), true);
  await page.keyboard.press('Escape');
  evidence.checks.push('320px enlarged-text stress check allows scrolling and keeps the action sheet reachable.');
  assert.deepEqual(evidence.errors, []);
  assert.deepEqual(evidence.externalRequests, []);
  evidence.checks.push('No browser JavaScript errors or external runtime requests.');
} finally {
  await browser.close();
  await fs.writeFile(path.join(output, 'verification.json'), JSON.stringify(evidence, null, 2) + '\n');
}
console.log(JSON.stringify(evidence, null, 2));
