import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('..', import.meta.url));
const output = path.resolve(process.env.MAP_QA_OUTPUT || path.join(root, 'storage/app/system-map-90/qa-followup-20261101/browser'));
const origin = 'http://spacetraders-map-90.test';
const php = path.join(root, 'scripts/herd-php');
const link = path.join(process.env.HOME, 'Library/Application Support/Herd/config/valet/Sites/spacetraders-map-90');
assert.equal(await fs.realpath(link), await fs.realpath(root), 'Herd must serve this checkout');
const envText = await fs.readFile(path.join(root, '.env'), 'utf8');
const fixturePath = envText.match(/^SPACETRADERS_FIXTURE_PATH=(.+)$/m)?.[1];
assert.ok(fixturePath?.startsWith(path.join(root, 'storage/app/system-map-90/')));
assert.match(envText, /^SPACETRADERS_AUTOMATION_ENABLED=false$/m);
assert.match(envText, /^SPACETRADERS_LOCAL_ENGINE_ENABLED=false$/m);
const auth = JSON.parse(await fs.readFile(path.join(path.dirname(fixturePath), 'browser.json'), 'utf8'));
const audit = () => JSON.parse(execFileSync(php, ['scripts/system-map-fixture.php', '--audit'], {cwd:root, encoding:'utf8'}));
const revision = execFileSync('git', ['rev-parse','HEAD'], {cwd:root,encoding:'utf8'}).trim();
await fs.mkdir(output, {recursive:true, mode:0o700});
const results = { revision, origin, browser:null, checks:[], errors:[], requests:[] };
const browser = await chromium.launch({headless:true});
results.browser = browser.version();
const contexts = [];
let page, current;
async function flush() { await fs.writeFile(path.join(output,'results.json'), JSON.stringify(results,null,2)+'\n'); }
async function check(id, run) {
    if(process.env.MAP_QA_ONLY && !new RegExp(process.env.MAP_QA_ONLY).test(id))return;
    current = id;
    try {
        const observed = await run();
        const badge=page.getByRole('button',{name:'Hide badge for this session',exact:true});if(await badge.isVisible())await badge.click();
        await page.screenshot({path:path.join(output,`${id}.png`)});
        results.checks.push({id,status:'pass',observed:observed || 'Expected interactions and visible state verified',screenshot:`${id}.png`});
        console.log(`PASS ${id}`);
    } catch(error) {
        const message = String(error.message).replaceAll(auth.password,'[redacted]').slice(0,1600);
        await page.screenshot({path:path.join(output,`${id}-failed.png`)}).catch(()=>{});
        results.checks.push({id,status:'failed',observed:message,screenshot:`${id}-failed.png`});
        console.log(`FAIL ${id}: ${message.slice(0,350)}`);
    }
    await flush();
}
async function newPage(options) {
    const context = await browser.newContext({viewport:{width:1440,height:900},colorScheme:'light',...options});
    contexts.push(context);
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const p = await context.newPage(); p.setDefaultTimeout(7000);
    p.on('pageerror',error=>results.errors.push({check:current,kind:'pageerror',message:error.message}));
    p.on('console',message=>{if(message.type()==='error')results.errors.push({check:current,kind:'console',message:message.text(),expected:current==='rate-tracker-states' && message.text().includes('503')});});
    p.on('requestfailed',request=>{if(request.failure()?.errorText!=='net::ERR_ABORTED')results.errors.push({check:current,kind:'requestfailed',url:new URL(request.url()).pathname,message:request.failure()?.errorText});});
    p.on('response',response=>{if(response.status()>=400)results.errors.push({check:current,kind:'response',url:new URL(response.url()).pathname,status:response.status(),expected:current==='rate-tracker-states' && response.status()===503 && new URL(response.url()).pathname==='/rate-limit'});});
    p.on('request',request=>results.requests.push({check:current,method:request.method(),path:new URL(request.url()).pathname}));
    await p.goto(`${origin}/login`); await p.getByLabel('Email',{exact:true}).fill(auth.email); await p.getByLabel('Password',{exact:true}).fill(auth.password);
    await p.getByRole('button',{name:'Log in',exact:true}).click(); await p.waitForURL(`${origin}/agents`);
    return p;
}
const mapUrl = ship => `${origin}/agents/${auth.agent_id}/map?ship=${ship}`;
async function map(ship='LOCAL-1') { await page.goto(mapUrl(ship)); await page.locator('.system-canvas').waitFor(); await page.waitForFunction(()=>document.querySelector('.system-canvas')?.viewBox.baseVal.width===document.querySelector('.map-viewport')?.clientWidth); }
const camera = () => page.locator('.system-canvas').evaluate(el=>JSON.parse(el.dataset.camera));
const button = name => page.getByRole('button',{name,exact:true});
async function openLocation(symbol) {
    if(await page.locator('.location-card').count())await button('Close location information').click();
    if(!await page.locator('.map-directory').evaluate(el=>el.open))await page.locator('.map-directory summary').click();
    await page.locator('.map-directory-list button').filter({has:page.locator('span',{hasText:new RegExp(`^${symbol}$`)})}).click();
    await page.waitForFunction(s=>document.querySelector('#location-title')?.textContent===s,symbol);
}
async function bounds() {
    return page.evaluate(()=>{
        const box=selector=>document.querySelector(selector)?.getBoundingClientRect().toJSON();
        return {width:innerWidth,height:innerHeight,scrollWidth:document.documentElement.scrollWidth,map:box('.map-viewport'),card:box('.location-card'),controls:box('.map-controls'),directory:box('.map-directory'),header:box('.app-topbar'),rate:box('.rate-trigger'),menu:box('.shell-menu')};
    });
}
function assertBounds(b) {
    assert.ok(b.scrollWidth<=b.width,`Page overflows horizontally: ${b.scrollWidth} > ${b.width}`);
    assert.ok(b.map.height>=100,`Map has only ${b.map.height}px of height`);
    assert.ok(b.header.top>=0 && b.map.bottom<=b.height+1,'Header or map extends outside viewport height');
    assert.ok(b.controls.right<=b.directory.left || b.controls.bottom<=b.directory.top || b.controls.top>=b.directory.bottom,'Map controls overlap location directory');
    if(b.card){assert.ok(b.card.left>=0 && b.card.right<=b.width,'Card is outside viewport width');assert.ok(b.card.top>=b.map.top && b.card.bottom<=b.map.bottom+1,'Card is outside map height');}
    assert.ok(b.rate.right<=b.menu.left,'Rate tracker overlaps Menu');
}
async function drag(from,to) {await page.mouse.move(from.x,from.y);await page.mouse.down();await page.mouse.move(to.x,to.y,{steps:12});await page.mouse.up();}
try {
    page = await newPage();
    await check('desktop-entry',async()=>{
        await page.locator(`a[href="${origin}/agents/${auth.agent_id}"]`).click();await page.locator('#ship-title').waitFor();
        await page.locator('.home-destinations a').click();await page.locator('.system-canvas').waitFor();
        assert.equal(await page.locator('select[aria-label="Selected ship"]').inputValue(),'LOCAL-1');
        const manifest=JSON.parse(await fs.readFile(path.join(root,'public/build/manifest.json'),'utf8'));
        const scripts=await page.locator('script[src]').evaluateAll(nodes=>nodes.map(n=>n.src));
        assert.ok(scripts.some(src=>src.endsWith(manifest['resources/js/app.js'].file)),'Served entry bundle matches this checkout build');
        return 'Agents → flight deck → map; stored selected ship and current built assets verified';
    });
    await check('desktop-camera',async()=>{
        await map(); const before=await camera(); await button('Zoom in').click();assert.ok((await camera()).scale>before.scale);
        await button('Zoom out').click();assert.ok(Math.abs((await camera()).scale-before.scale)<1e-6);
        await page.mouse.move(700,480);await page.mouse.wheel(0,-140);await page.waitForFunction(scale=>JSON.parse(document.querySelector('.system-canvas').dataset.camera).scale>scale,before.scale);
        const zoomed=await camera();const b=await bounds();await drag({x:1100,y:b.map.top+250},{x:1190,y:b.map.top+300});
        assert.notEqual((await camera()).x,zoomed.x);assert.notEqual((await camera()).y,zoomed.y);
        assert.equal(await page.evaluate(()=>String(getSelection())), '');
        await button('Fit system').click();assert.ok(Math.abs((await camera()).x-before.x)<1e-6);
        await button('Center on ship').click();assert.equal((await camera()).x,180);assert.equal((await camera()).y,0);
        await button('Fit system').click();return 'Native wheel, drag, both zoom buttons, fit and center all changed the expected camera values';
    });
    await check('desktop-marker-drag',async()=>{
        await map();const before=await camera();const box=await page.locator('.map-marker[aria-label="2 locations near X1-TEST-PORT"]').boundingBox();
        await drag({x:box.x+24,y:box.y+24},{x:box.x+100,y:box.y+60});
        assert.notEqual((await camera()).x,before.x,'Dragging from a location must pan');assert.equal(await page.locator('.location-card').count(),0,'A drag must not activate a location');
        await button('Fit system').click();await page.locator('.map-marker[aria-label="2 locations near X1-TEST-PORT"]').click();await page.locator('#location-title').waitFor();
        return 'Dragging from artwork pans; a subsequent click still opens the location';
    });
    await check('desktop-all-locations',async()=>{
        await map();await page.locator('.map-directory summary').click();
        const symbols=await page.locator('.map-directory-list button > span').allTextContents();assert.equal(symbols.length,21);
        for(const symbol of symbols){await openLocation(symbol);assert.equal(await page.locator('#location-title').textContent(),symbol);assertBounds(await bounds());await button('Close location information').click();}
        assert.equal(await page.locator(':focus').evaluate(el=>el.tagName),'SUMMARY','Directory selection close should restore focus to its visible opener');
        return `Every one of ${symbols.length} stored locations opens and closes, including the unplotted entry`;
    });
    await check('desktop-all-markers-overlaps',async()=>{
        await map();const labels=await page.locator('.map-marker').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('aria-label')));
        for(const label of labels){await page.locator(`.map-marker[aria-label="${label}"]`).click();await page.locator('#location-title').waitFor();await page.keyboard.press('Escape');}
        await page.locator('.map-marker[aria-label="2 locations near X1-TEST-PORT"]').click();
        for(const symbol of ['X1-TEST-TWIN','X1-TEST-PORT']){await page.locator('.nearby-locations').getByRole('button',{name:symbol,exact:true}).click();assert.equal(await page.locator('#location-title').textContent(),symbol);}
        await page.keyboard.press('Escape');assert.equal(await page.locator('.location-card').count(),0);assert.match(await page.locator(':focus').getAttribute('aria-label'),/2 locations near/);
        return `${labels.length} map markers and both co-located locations individually selectable; Escape restores marker focus`;
    });
    await check('desktop-card-states-scroll',async()=>{
        await map();await openLocation('X1-TEST-PORT');const text=await page.locator('.location-card').innerText();
        for(const expected of ['Ocean','Deep blue oceans','Cosmic','Invalidated by later activity','0 CR','3 CR','20,000 CR','Stale'])assert.ok(text.includes(expected),expected);
        const scroll=page.locator('.location-scroll');await scroll.focus();await page.keyboard.press('PageDown');await page.waitForFunction(()=>document.querySelector('.location-scroll').scrollTop>0);
        await scroll.evaluate(el=>new Promise(resolve=>{let last=-1,stable=0;const frame=()=>{const next=el.scrollTop;stable=next===last?stable+1:0;last=next;if(stable>=4)resolve();else requestAnimationFrame(frame);};requestAnimationFrame(frame);}));
        const scrolled=await scroll.evaluate(el=>el.scrollTop);await scroll.hover();await page.mouse.wheel(0,500);await page.waitForFunction(before=>document.querySelector('.location-scroll').scrollTop>before+30,scrolled);
        await openLocation('X1-TEST-MOON');for(const expected of ['Confirmed empty trait list','Confirmed empty ship type list','Unknown · No stored quotes'])assert.ok((await page.locator('.location-card').innerText()).includes(expected));
        assert.deepEqual(await page.locator('.map-goods p').allTextContents(),['Confirmed empty','Confirmed empty','Confirmed empty']);
        await openLocation('X1-TEST-UNPLOTTED');assert.match(await page.locator('.location-coordinates').innerText(),/Coordinates Unknown/);assert.ok((await page.locator('.location-card').innerText()).includes('Traits & terrain\n\nUnknown'));
        return 'Stored text, empty and unknown states, stale/invalidated zero-valued prices, and native card scrolling verified';
    });
    await check('desktop-keyboard-dismissal',async()=>{
        await map();await page.locator('.system-canvas').focus();const before=await camera();
        for(const key of ['ArrowLeft','ArrowUp','ArrowRight','ArrowDown'])await page.keyboard.press(key);assert.ok(Math.abs((await camera()).x-before.x)<1e-6);
        await page.keyboard.press('+');assert.ok((await camera()).scale>before.scale);await page.keyboard.press('-');await page.keyboard.press('c');assert.equal((await camera()).x,180);await page.keyboard.press('f');
        await page.keyboard.press('Tab');await page.keyboard.press('Enter');await page.locator('#location-title').waitFor();assert.equal(await page.locator(':focus').getAttribute('aria-label'),'Close location information');
        await page.keyboard.press('Escape');assert.equal(await page.locator('.location-card').count(),0);
        await openLocation('X1-TEST-PORT');await page.mouse.click(200,500);assert.equal(await page.locator('.location-card').count(),0);
        return 'Keyboard pan/zoom/fit/center, Tab+Enter, Escape focus restore and outside click verified';
    });
    await check('open-card-camera-controls',async()=>{
        await map();await openLocation('X1-TEST-PORT');await button('Zoom in').click();assert.equal(await page.locator('.location-card').count(),1,'Zoom must retain the selected location card');assert.equal(await page.locator(':focus').getAttribute('aria-label'),'Zoom in','Zoom must retain control focus');
        await page.keyboard.press('Enter');assert.equal(await page.locator('.location-card').count(),1);
        const b=await bounds();await drag({x:200,y:b.map.top+180},{x:260,y:b.map.top+220});assert.equal(await page.locator('.location-card').count(),1,'Pan must retain the selected location card');
        await button('Fit system').click();await button('Center on ship').click();assert.equal(await page.locator('.location-card').count(),1);
        await page.getByLabel('Selected ship',{exact:true}).selectOption('LOCAL-2');await page.waitForURL(/ship=LOCAL-2/);
        return 'Zoom, pan, Fit and Center retain the location card and control focus; ship picker works while a card was open';
    });
    await check('nearby-keyboard-and-scroll-reset',async()=>{
        await map();await openLocation('X1-TEST-PORT');const twin=page.locator('.nearby-locations').getByRole('button',{name:'X1-TEST-TWIN',exact:true});await twin.focus();await page.keyboard.press('Enter');assert.equal(await page.locator('#location-title').innerText(),'X1-TEST-TWIN');assert.ok(await twin.evaluate(el=>el===document.activeElement),'Nearby selection must retain focus on its button');
        await openLocation('X1-TEST-PORT');await page.locator('.location-scroll').evaluate(el=>el.scrollTop=450);await page.locator('.map-directory summary').click();await page.locator('.map-directory-list button').filter({has:page.locator('span',{hasText:/^X1-TEST-TWIN$/})}).click();assert.equal(await page.locator('.location-scroll').evaluate(el=>el.scrollTop),0,'New location must start at the top of its details');
        return 'Nearby keyboard selection retains button focus and replacing a scrolled card resets details to the top';
    });
    await check('browser-shortcuts-and-global-escape',async()=>{
        await map();await button('Zoom in').click();await page.locator('.system-canvas').focus();const before=await camera();await page.keyboard.press('Control+f');assert.deepEqual(await camera(),before,'Control+F must retain browser find behavior');await page.keyboard.press('Escape');
        await page.locator('.system-canvas').focus();await page.keyboard.press('Meta+f');assert.deepEqual(await camera(),before,'Command+F must retain browser find behavior');await page.keyboard.press('Escape');
        await openLocation('X1-TEST-PORT');await button('Zoom in').focus();await page.keyboard.press('Escape');assert.equal(await page.locator('.location-card').count(),0,'Escape must dismiss a card from map-control focus');
        await openLocation('X1-TEST-PORT');await page.locator('.rate-trigger').focus();await page.keyboard.press('Escape');assert.equal(await page.locator('.location-card').count(),0,'Escape must dismiss a card from header focus');
        await openLocation('X1-TEST-PORT');await page.locator('.rate-trigger').click();const modal=page.getByRole('dialog',{name:'API rate limit',exact:true});await modal.waitFor();await page.keyboard.press('Escape');assert.ok(await modal.isHidden());assert.equal(await page.locator('.location-card').count(),1,'Escape closes the topmost modal before the underlying location card');await page.keyboard.press('Escape');assert.equal(await page.locator('.location-card').count(),0);
        await page.locator('.system-canvas').focus();await page.keyboard.press('Tab');assert.ok(await page.locator(':focus').evaluate(el=>el.matches('.map-marker:focus-visible')));assert.ok(Number.parseFloat(await page.locator(':focus').evaluate(el=>getComputedStyle(el).outlineOffset))<0,'Marker focus ring must be drawn inside its clipped SVG hit target');
        return 'Browser modifier shortcuts remain available, Escape works from controls, and marker focus has an inset visible ring';
    });
    await check('wheel-line-units',async()=>{
        await map();const before=await camera();await page.locator('.system-canvas').evaluate(el=>el.dispatchEvent(new WheelEvent('wheel',{deltaY:-3,deltaMode:1,clientX:700,clientY:450,bubbles:true,cancelable:true})));const after=await camera();assert.ok(Math.abs(after.scale/before.scale-Math.exp(48*.005))<1e-6,'Line wheel units must normalize to pixels');return 'Line-mode wheel events normalize consistently with pixel-mode wheel input';
    });
    await check('unplotted-controls-access',async()=>{
        await map();await openLocation('X1-TEST-UNPLOTTED');const b=await bounds();assert.ok(b.card.left>=b.controls.right || b.card.top>=b.controls.bottom,'Unplotted card must not cover camera controls on desktop');
        return 'Unplotted information opens away from the desktop camera controls';
    });
    await check('ship-selection-history',async()=>{
        await map();await page.getByLabel('Selected ship',{exact:true}).selectOption('LOCAL-2');await page.waitForURL(/ship=LOCAL-2/);assert.match(await page.locator('.map-ship-note').innerText(),/arrival unconfirmed/);
        await button('Center departure').click();assert.equal((await camera()).x,-137);assert.equal((await camera()).y,156);
        await page.locator('.map-header-actions a').click();await page.locator('#ship-title').waitFor();assert.equal(await page.locator('#ship-title').innerText(),'LOCAL-2');
        await page.locator('.home-destinations a').click();await page.locator('.system-canvas').waitFor();assert.equal(await page.getByLabel('Selected ship',{exact:true}).inputValue(),'LOCAL-2');
        await page.reload();assert.equal(await page.getByLabel('Selected ship',{exact:true}).inputValue(),'LOCAL-2');await page.goto(`${origin}/agents/${auth.agent_id}/map`);await page.waitForURL(/ship=LOCAL-2/);
        await page.getByLabel('Selected ship',{exact:true}).selectOption('LOCAL-3');await page.waitForURL(/ship=LOCAL-3/);assert.match(await page.locator('.map-empty').innerText(),/blank page/);assert.ok(await button('Center on ship').isDisabled());
        await page.goBack();await page.locator('.system-canvas').waitFor();assert.equal(await page.getByLabel('Selected ship',{exact:true}).inputValue(),'LOCAL-2');
        await page.goForward();await page.locator('.system-canvas').waitFor();assert.equal(await page.getByLabel('Selected ship',{exact:true}).inputValue(),'LOCAL-3');
        await page.goto(`${origin}/agents/2/map`);assert.match(await page.locator('.map-empty').innerText(),/fleet is empty/);
        return 'All ships, empty observations/fleet, transit departure, reload, generic link, back/forward and deck round trip verified';
    });
    await check('rate-tracker-navigation',async()=>{
        await map();await page.locator('.rate-trigger').click();const dialog=page.getByRole('dialog',{name:'API rate limit',exact:true});await dialog.waitFor();await dialog.getByRole('button',{name:'Refresh status',exact:true}).click();assert.match(await dialog.innerText(),/Practice/);await page.keyboard.press('Escape');assert.ok(await dialog.isHidden());assert.equal(await page.locator(':focus').getAttribute('class'),'rate-trigger');
        await page.getByRole('button',{name:'Menu',exact:true}).click();await page.getByRole('dialog',{name:'Navigation',exact:true}).waitFor();await page.keyboard.press('Escape');assert.equal(await page.locator(':focus').innerText(),'Menu');
        await page.getByRole('button',{name:'Menu',exact:true}).click();await page.getByRole('dialog',{name:'Navigation',exact:true}).getByRole('button',{name:'Done',exact:true}).click();
        for(const [name,pathname] of [['Profile','/profile'],['Player guide','/guide'],['Flight deck',`/agents/${auth.agent_id}`]]){await map();await button('Menu').click();await page.getByRole('dialog',{name:'Navigation',exact:true}).getByRole('link',{name,exact:true}).click();await page.waitForURL(url=>url.pathname===pathname);assert.equal(await page.locator('dialog[open]').count(),0);}
        await map();await page.locator('.rate-trigger').click();const again=page.getByRole('dialog',{name:'API rate limit',exact:true});await again.waitFor();for(let i=0;i<8;i++){await page.keyboard.press('Tab');assert.ok(await again.evaluate(el=>el.contains(document.activeElement)));}await again.getByRole('button',{name:'Done',exact:true}).click();assert.ok(await again.isHidden());
        return 'API tracker refresh/Done/Escape and focus containment, Menu Done/Escape and affected navigation verified';
    });
    await check('rate-tracker-states',async()=>{
        await map();let view='ready';
        const bucket={requests:40,responses:40,rate_limits:0,deferrals:2,available:9,burst_capacity:10,sustained_per_second:1.9,requests_last_60_seconds:10,requests_per_second_last_5:.4,requests_per_second_last_60:10/60,blocked_until:0,last_response:{status:200,scope:'IP address',per_second:2,burst:30,remaining:1,observed_at:Date.now()/1000}};
        const mock=async route=>{const host={...bucket},account={...bucket,last_response:null};if(view==='slow')host.available=0;if(view==='cooldown')account.blocked_until=Date.now()/1000+30;await route.fulfill({status:view==='unavailable'?503:200,contentType:'application/json',body:JSON.stringify({as_of:Date.now()/1000,mode:'live',pacing_enabled:true,buckets:{host,account}})});};
        await page.route('**/rate-limit?*',mock);
        try{
            await page.locator('.rate-trigger').click();const dialog=page.getByRole('dialog',{name:'API rate limit',exact:true});await dialog.waitFor();
            for(const [state,label] of [['ready','Room available'],['slow','Slow down'],['cooldown','Cooling down'],['unavailable','Unavailable'],['ready','Room available']]){view=state;await dialog.getByRole('button',{name:'Refresh status',exact:true}).click();await page.getByRole('button',{name:new RegExp(`API rate limit: ${label}\\.`)}).waitFor();if(state==='cooldown')assert.equal(await dialog.locator('.rate-countdown').evaluate(el=>el.closest('[role="status"]')),null,'Countdown must be outside the live announcement region');}
            assert.equal(await dialog.locator('meter').count(),2);assert.equal(await dialog.locator('.rate-details').evaluate(el=>getComputedStyle(el).colorScheme),'dark');await dialog.getByRole('button',{name:'Done',exact:true}).click();
        }finally{await page.unroute('**/rate-limit?*',mock);}
        return 'Browser-mocked tracker snapshots show ready, low capacity, cooldown, unavailable and recovery; two budgets and dark details render without upstream calls';
    });
    await check('navigation-and-signout',async()=>{
        await map();await page.keyboard.press('Tab');await page.keyboard.press('Enter');assert.equal(await page.evaluate(()=>location.hash),'#main-content');
        await button('Menu').click();await page.getByRole('dialog',{name:'Navigation',exact:true}).getByRole('link',{name:'System map',exact:true}).click();await page.locator('.system-canvas').waitFor();await page.locator('dialog[open]').waitFor({state:'hidden'});assert.equal(await page.locator('dialog[open]').count(),0);
        await button('Menu').click();await page.getByRole('dialog',{name:'Navigation',exact:true}).getByRole('link',{name:'Agents',exact:true}).click();await page.waitForURL(`${origin}/agents`);
        await map();await page.locator('.app-brand').click();await page.waitForURL(`${origin}/agents`);
        await map();await button('Menu').click();await page.getByRole('dialog',{name:'Navigation',exact:true}).getByRole('button',{name:'Log out',exact:true}).click();await page.waitForURL(`${origin}/`);
        await page.goto(mapUrl('LOCAL-1'));await page.waitForURL(`${origin}/login`);await page.getByLabel('Email',{exact:true}).fill(auth.email);await page.getByLabel('Password',{exact:true}).fill(auth.password);await button('Log in').click();await page.waitForURL(mapUrl('LOCAL-1'));await page.locator('.system-canvas').waitFor();
        return 'Skip link, same-page System map, Agents link, brand, sign out, authentication redirect and fresh login verified';
    });
    await check('zoom-limits-directory-toggle',async()=>{
        await map();const baseSize=Number(await page.locator('.system-canvas').getAttribute('data-marker-size'));
        for(let i=0;i<18 && await button('Zoom in').isEnabled();i++)await button('Zoom in').click();
        const maximum=Number(await page.locator('.system-canvas').getAttribute('data-max-zoom'));assert.equal((await camera()).scale,maximum);assert.ok(await button('Zoom in').isDisabled());
        const closeSize=Number(await page.locator('.system-canvas').getAttribute('data-marker-size'));assert.ok(closeSize>baseSize*3);assert.ok(closeSize<=168);
        await button('Center on ship').click();const sprite=page.locator('.map-marker').filter({has:page.locator('svg')});assert.ok(await sprite.count()>0);
        await page.locator('.system-canvas').focus();await page.keyboard.press('+');assert.equal((await camera()).scale,maximum);
        const box=await page.locator('.system-canvas').boundingBox();await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await page.mouse.wheel(0,-10000);await page.waitForTimeout(80);assert.equal((await camera()).scale,maximum);
        await page.screenshot({path:path.join(output,'desktop-close-up.png')});
        for(let i=0;i<24;i++)await button('Zoom out').click();assert.equal((await camera()).scale,.05);
        assert.ok(Object.values(await camera()).every(Number.isFinite));await button('Fit system').click();
        await page.locator('.map-directory summary').click();assert.ok(await page.locator('.map-directory').evaluate(el=>el.open));await page.locator('.map-directory summary').click();assert.ok(!await page.locator('.map-directory').evaluate(el=>el.open));
        await openLocation('X1-TEST-PORT');await button('Close location information').click();
        return 'Artwork grows from 40px to 160px; button, keyboard and wheel stop at close-up limit; Fit and directory recover';
    });
    await check('map-read-only-boundary',async()=>{
        await map();const before=audit();await button('Zoom in').click();await button('Zoom out').click();await button('Fit system').click();await button('Center on ship').click();await openLocation('X1-TEST-PORT');await page.keyboard.press('Escape');await page.locator('.rate-trigger').click();await page.getByRole('dialog',{name:'API rate limit',exact:true}).getByRole('button',{name:'Refresh status',exact:true}).click();await page.keyboard.press('Escape');
        assert.deepEqual(audit(),before);await fs.writeFile(path.join(output,'boundary.json'),JSON.stringify({before,after:audit(),equal:true},null,2)+'\n');
        return 'Gameplay table hashes and fake transport calls/writes unchanged, including API tracker refresh';
    });
    for(const [name,width,height] of [['phone',390,844],['small-phone',320,568],['phone-landscape',844,390],['reflow',720,450]])await check(`${name}-layout`,async()=>{
        await page.setViewportSize({width,height});await map();await button('Fit system').click();assertBounds(await bounds());
        await openLocation('X1-TEST-PORT');assertBounds(await bounds());
        const clientHeight=await page.locator('.location-scroll').evaluate(el=>el.clientHeight);assert.ok(clientHeight>=80,`Location detail scroll region has only ${clientHeight}px of height`);
        await page.locator('.location-scroll').focus();await page.keyboard.press('PageDown');await page.waitForFunction(()=>document.querySelector('.location-scroll').scrollTop>0);
        assertBounds(await bounds());assert.equal(await page.evaluate(()=>scrollY),0,'Scrolling card must not scroll the page header away');
        return `Map, tracker, controls and open location card fit ${width}x${height}; ${clientHeight}px detail area remains keyboard-scrollable`;
    });
    page=await newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
    await check('mobile-knowledge-and-map-space',async()=>{
        await map();const b=await bounds();assert.ok(b.map.height>=740,`Expected at least 740px map height, got ${b.map.height}`);
        const toggle=page.locator('.map-knowledge-toggle');await toggle.tap();assert.equal(await toggle.getAttribute('aria-expanded'),'true');assert.ok(await page.locator('#map-knowledge-detail').isVisible());
        await page.keyboard.press('Escape');assert.equal(await toggle.getAttribute('aria-expanded'),'false');assert.ok(await toggle.evaluate(el=>el===document.activeElement));
        await openLocation('X1-TEST-PORT');await toggle.tap();await page.keyboard.press('Tab');assert.ok(await page.locator('.system-canvas').evaluate(el=>el===document.activeElement));await page.keyboard.press('Escape');
        assert.equal(await toggle.getAttribute('aria-expanded'),'false');assert.equal(await page.locator('.location-card').count(),1,'Escape from the canvas closes knowledge before the underlying card');
        await toggle.tap();await page.locator('.map-directory summary').tap();assert.equal(await toggle.getAttribute('aria-expanded'),'false');await toggle.tap();assert.ok(!await page.locator('.map-directory').evaluate(el=>el.open));
        await page.locator('.system-canvas').focus();await page.keyboard.press('Escape');await page.keyboard.press('Escape');
        for(const selector of ['.map-controls button','.map-directory summary','.map-knowledge-toggle','.map-header select','.map-deck-link'])for(const rect of await page.locator(selector).evaluateAll(nodes=>nodes.map(n=>({w:n.getBoundingClientRect().width,h:n.getBoundingClientRect().height}))))assert.ok(rect.w>=44 && rect.h>=44,`${selector} touch target is ${rect.w}x${rect.h}`);
        await page.screenshot({path:path.join(output,'phone-map-space.png')});
        return '390x844 map uses at least 740px height, touch targets are 44px, knowledge opens/closes and Escape respects overlay priority';
    });
    await check('two-finger-tap-card',async()=>{
        await map();await openLocation('X1-TEST-PORT');const client=await page.context().newCDPSession(page),b=await bounds();const y=b.map.top+160;
        await client.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:100,y,id:11},{x:170,y,id:12}]});await client.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});assert.equal(await page.locator('.location-card').count(),1,'Two-finger gesture must not count as an outside tap');
        return 'Two-finger gesture without motion retains the selected card';
    });
    await check('native-touch',async()=>{
        await map();const before=await camera();const b=await bounds();const client=await page.context().newCDPSession(page);
        const y=b.map.top+b.map.height*.35,cx=195;
        await client.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:cx-30,y,id:1},{x:cx+30,y,id:2}]});
        for(let d=35;d<=75;d+=10)await client.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:cx-d,y,id:1},{x:cx+d,y,id:2}]});
        await client.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await page.waitForFunction(scale=>JSON.parse(document.querySelector('.system-canvas').dataset.camera).scale>scale*1.5,before.scale);
        await button('Fit system').tap();const initial=await camera();
        await client.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:195,y:b.map.top+150,id:3}]});await client.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:245,y:b.map.top+180,id:3}]});await client.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});assert.notEqual((await camera()).x,initial.x);
        await button('Fit system').tap();await page.locator('.map-marker[aria-label="2 locations near X1-TEST-PORT"]').tap();await page.locator('#location-title').waitFor();await page.locator('.nearby-locations').getByRole('button',{name:'X1-TEST-TWIN',exact:true}).tap();assert.equal(await page.locator('#location-title').innerText(),'X1-TEST-TWIN');await button('Close location information').tap();assert.equal(await page.locator('.location-card').count(),0);
        for(let i=0;i<18 && await button('Zoom in').isEnabled();i++)await button('Zoom in').tap();
        const maximum=Number(await page.locator('.system-canvas').getAttribute('data-max-zoom'));assert.equal((await camera()).scale,maximum);
        await button('Center on ship').tap();await page.screenshot({path:path.join(output,'phone-close-up.png')});
        await client.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:cx-30,y,id:21},{x:cx+30,y,id:22}]});
        await client.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:cx-75,y,id:21},{x:cx+75,y,id:22}]});
        await client.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});assert.equal((await camera()).scale,maximum);
        assert.ok(await button('Zoom in').isDisabled());assert.equal(Number(await page.locator('.system-canvas').getAttribute('data-marker-size')),168);
        await button('Fit system').tap();assert.equal(Number(await page.locator('.system-canvas').getAttribute('data-marker-size')),48);
        return 'Trusted Chromium touch pinches and pans, selects overlap members, closes cards, stops pinch zoom at close-up limit and recovers with Fit';
    });
    await flush();
} finally { for(const context of contexts)await context.close().catch(()=>{});await browser.close();await flush(); }
if(results.checks.some(c=>c.status==='failed')||results.errors.some(e=>!e.expected)){console.log(`${results.checks.filter(c=>c.status==='failed').length} failed scenarios, ${results.errors.filter(e=>!e.expected).length} unexpected browser errors`);process.exitCode=1;}
else console.log(`All ${results.checks.length} browser scenarios passed with no unexpected browser errors`);
