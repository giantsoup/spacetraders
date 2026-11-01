// Follow the same visible destinations as a player. No DOM mutation or forced clicks.
export async function shipView(page, view) {
    await page.locator('#ship-home').waitFor();
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const title = { adviser: 'Adviser policy', commands: 'Commands', automation: 'Automation', intelligence: 'Intelligence', activity: 'Activity history', guide: 'Guided flight', fleet: 'Your fleet' }[view];
    if (await page.getByRole('dialog', { name: title, exact: true }).isVisible()) return;
    const back = page.getByRole('button', { name: 'Back to ship', exact: true });
    if (await back.isVisible()) {
        await back.click();
        await page.waitForFunction(() => !document.querySelector('dialog[open]'));
    }
    if (view === 'fleet') await page.locator('.identity-button').click();
    else await page.getByRole('navigation', { name: 'Ship destinations' }).getByRole('button', { name: view === 'activity' ? /^Activity/ : title, exact: view !== 'activity' }).click();
    await page.getByRole('dialog', { name: title, exact: true }).waitFor();
}
