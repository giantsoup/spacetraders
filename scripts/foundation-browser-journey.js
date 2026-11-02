async (scenario) => {
    const wait = async check => {
        const deadline = Date.now() + 8000;
        while (!check()) { if (Date.now() > deadline) throw new Error('UI condition timed out'); await new Promise(resolve => setTimeout(resolve, 30)); }
    };
    await wait(() => document.querySelector('.manual-api'));
    const root = document.querySelector('.manual-api');
    const set = (element, value) => {
        if (element.type === 'checkbox') { element.checked = value; element.dispatchEvent(new Event('change', { bubbles: true })); }
        else { element.value = value; element.dispatchEvent(new Event(element.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); }
    };
    set(root.querySelector('select'), scenario.command);
    await new Promise(resolve => setTimeout(resolve, 50));
    for (const [key, value] of Object.entries(scenario.arguments)) {
        const control = root.querySelector(`[name="${key}"]`);
        if (!control) throw new Error(`Missing manual input: ${key}`);
        set(control, value);
    }
    root.querySelector('button[type=submit]').click();
    await wait(() => document.querySelector('.approval') || document.querySelector('.notice.fault'));
    if (!document.querySelector('.approval')) return { command: scenario.command, previewed: false, error: document.querySelector('.notice.fault')?.innerText };
    const approval = document.querySelector('.approval');
    const focused = approval === document.activeElement;
    const button = Array.from(approval.querySelectorAll('button')).find(button => button.textContent.trim() === (scenario.stop ? 'Stop manual command' : 'Approve command') || (!scenario.stop && button.textContent.trim() === 'Approve purchase'));
    if (!button || button.disabled) throw new Error('Approval/Stop button unavailable');
    button.click();
    await wait(() => !document.querySelector('.approval') || document.querySelector('.notice.fault'));
    return { command: scenario.command, previewed: true, focused, approved: !scenario.stop, stopped: !!scenario.stop, error: document.querySelector('.notice.fault')?.innerText };
}
