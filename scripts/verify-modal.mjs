import assert from 'node:assert/strict';
import fs from 'node:fs/promises';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const origin = process.env.QA_URL;
assert.ok(origin && process.env.QA_EMAIL && process.env.QA_PASSWORD,
    'Set QA_URL, QA_EMAIL and QA_PASSWORD for an owned local fixture.');
assert.ok(new URL(origin).hostname.endsWith('.test'), 'Use a local .test fixture.');
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1280, height: 960 } });
await context.route('**/*', route => new URL(route.request().url()).origin === new URL(origin).origin
    ? route.continue() : route.abort());
const page = await context.newPage();
page.setDefaultTimeout(10000);
const modal = page.locator('.fixed.z-50');
const confirm = modal.getByRole('button', { name: 'Delete Account', exact: true });

async function openModal() {
    await page.getByRole('button', { name: 'Delete Account', exact: true }).click();
    await modal.getByPlaceholder('Password', { exact: true }).waitFor();
    await page.waitForFunction(() => {
        const panel = document.querySelector('.fixed.z-50 .mb-6');
        return panel && getComputedStyle(panel).opacity === '1'
            && !panel.getAnimations().some(animation => animation.playState === 'running');
    });
    assert.equal(await confirm.evaluate(button => {
        const box = button.getBoundingClientRect();
        const hit = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);
        return button === hit || button.contains(hit);
    }), true, 'The settled modal panel must receive pointer events above the backdrop.');
    await confirm.click({ trial: true });
}

try {
    await page.goto(origin + '/login');
    await page.getByLabel('Email', { exact: true }).fill(process.env.QA_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(process.env.QA_PASSWORD);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL('**/agents');
    await page.goto(origin + '/profile');
    await openModal();
    if (process.env.QA_ARTIFACT_DIR) {
        await page.screenshot({ path: process.env.QA_ARTIFACT_DIR + '/modal-settled.png', fullPage: true, animations: 'disabled' });
    }
    await modal.getByPlaceholder('Password', { exact: true }).fill('intentionally-incorrect-password');
    await confirm.click();
    await modal.getByText('The password is incorrect.', { exact: true }).waitFor();
    await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
    await modal.waitFor({ state: 'hidden' });
    await openModal();
    await page.keyboard.press('Escape');
    await modal.waitFor({ state: 'hidden' });
    console.log('PASS: settled modal receives clicks, rejects an incorrect password, cancels and reopens, and closes with Escape.');
} catch (error) {
    if (process.env.QA_ARTIFACT_DIR) {
        await fs.mkdir(process.env.QA_ARTIFACT_DIR, { recursive: true });
        await page.screenshot({ path: process.env.QA_ARTIFACT_DIR + '/modal-failure.png', fullPage: true, animations: 'disabled' });
    }
    throw error;
} finally {
    await browser.close();
}
