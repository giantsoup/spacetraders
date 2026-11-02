// Evaluate this file in T3 preview_evaluate on the authenticated owned map fixture.
// Real click, wheel, drag and keyboard journeys run through preview_act separately.
(async () => {
    const checks = [];
    const assert = (condition, name) => { if (!condition) throw new Error(name); checks.push(name); };
    const tick = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const canvas = document.querySelector('.system-canvas');
    assert(Boolean(canvas), 'Map canvas rendered');
    const camera = () => JSON.parse(canvas.dataset.camera);
    const before = camera();
    canvas.dispatchEvent(new WheelEvent('wheel', {deltaY:-80, clientX:180, clientY:380, bubbles:true, cancelable:true}));
    await tick();
    assert(camera().scale > before.scale, 'Wheel event changes map zoom');
    canvas.focus();
    canvas.dispatchEvent(new KeyboardEvent('keydown', {key:'ArrowRight', bubbles:true, cancelable:true}));
    await tick();
    assert(camera().x !== before.x, 'Arrow key pans map');
    canvas.dispatchEvent(new KeyboardEvent('keydown', {key:'f', bubbles:true, cancelable:true}));
    await tick();
    const originalCapture = canvas.setPointerCapture;
    // Synthetic PointerEvents have no browser pointer capture. Restore the native method afterward.
    canvas.setPointerCapture = () => {};
    try {
        const bounds = canvas.getBoundingClientRect();
        const emit = (type, id, x, y) => canvas.dispatchEvent(new PointerEvent(type, {pointerId:id, pointerType:'touch', clientX:bounds.x+x, clientY:bounds.y+y, bubbles:true, cancelable:true}));
        const start = camera();
        emit('pointerdown', 91, 100, 160); emit('pointerdown', 92, 200, 160);
        emit('pointermove', 91, 70, 170); emit('pointermove', 92, 240, 170);
        await tick();
        assert(camera().scale > start.scale * 1.5, 'Two-pointer pinch enlarges map');
        emit('pointerup', 91, 70, 170); emit('pointerup', 92, 240, 170);
        const panned = camera();
        emit('pointerdown', 93, 120, 200); emit('pointermove', 93, 180, 230); emit('pointerup', 93, 180, 230);
        await tick();
        assert(camera().x !== panned.x && camera().y !== panned.y, 'Touch pointer drag pans both axes');
        emit('pointerdown', 94, 100, 200); emit('pointercancel', 94, 100, 200);
        const cancelled = camera(); emit('pointermove', 94, 200, 300); await tick();
        assert(JSON.stringify(camera()) === JSON.stringify(cancelled), 'Cancelled touch cannot keep dragging');
    } finally { canvas.setPointerCapture = originalCapture; }
    canvas.dispatchEvent(new KeyboardEvent('keydown', {key:'f', bubbles:true, cancelable:true}));
    await tick();
    assert(document.documentElement.scrollWidth <= innerWidth, 'No horizontal page overflow');
    const buttons = [...document.querySelectorAll('.map-controls button, .map-marker, .map-directory summary')];
    assert(buttons.every(b => b.getBoundingClientRect().height >= 44), 'Map controls and markers have 44px touch targets');
    const controls = document.querySelector('.map-controls').getBoundingClientRect(), directory = document.querySelector('.map-directory').getBoundingClientRect();
    assert(controls.right <= directory.left || controls.bottom <= directory.top, 'Map controls do not overlap location directory');
    return { checks, viewport: {width:innerWidth,height:innerHeight}, pointerChecks:'Synthetic two-pointer events; native capture tested with real T3 drag separately', camera:camera() };
})()
