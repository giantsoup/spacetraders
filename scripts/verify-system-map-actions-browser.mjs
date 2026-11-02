import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root=fileURLToPath(new URL('..',import.meta.url)), origin='http://spacetraders-map-90.test';
const php=path.join(root,'scripts/herd-php'), fixtureScript=path.join(root,'scripts/system-map-action-fixture.php');
assert.equal(await fs.realpath(path.join(process.env.HOME,'Library/Application Support/Herd/config/valet/Sites/spacetraders-map-90')),await fs.realpath(root));
const env=await fs.readFile(path.join(root,'.env'),'utf8'), file=env.match(/^SPACETRADERS_FIXTURE_PATH=(.+)$/m)?.[1];
assert.ok(file?.startsWith(path.join(root,'storage/app/system-map-90/')));
assert.match(env,/^SPACETRADERS_AUTOMATION_ENABLED=false$/m);assert.match(env,/^SPACETRADERS_LOCAL_ENGINE_ENABLED=false$/m);
const auth=JSON.parse(await fs.readFile(path.join(path.dirname(file),'browser.json'),'utf8'));
const output=path.resolve(process.env.MAP_QA_OUTPUT || path.join(root,'storage/app/system-map-90/qa-travel/actions'));
await fs.mkdir(output,{recursive:true,mode:0o700});
const results={revision:execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8',cwd:root}).trim(),origin,checks:[],errors:[],requests:[],browser:null};
const browser=await chromium.launch({headless:true});results.browser=browser.version();
const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,colorScheme:'light'});
await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
const page=await context.newPage();page.setDefaultTimeout(10000);let current='login';
page.on('pageerror',error=>results.errors.push({check:current,message:error.message}));
page.on('request',request=>results.requests.push({check:current,method:request.method(),path:new URL(request.url()).pathname}));
page.on('response',response=>{if(response.status()>=400&&!['read-error-recovery','travel-fuel-rejection','expired-preview'].includes(current))results.errors.push({check:current,status:response.status(),path:new URL(response.url()).pathname});});
const world=async()=>JSON.parse(await fs.readFile(file,'utf8'));
async function change(update){const value=await world();update(value);await fs.writeFile(file,JSON.stringify(value));}
const prepare=scenario=>execFileSync(php,[fixtureScript,scenario],{cwd:root,encoding:'utf8'});
const drain=()=>execFileSync(php,[fixtureScript,'--drain'],{cwd:root,encoding:'utf8'});
const b=name=>page.getByRole('button',{name,exact:true});
const task=()=>page.locator('.map-flight-task');
const mapUrl=location=>`${origin}/agents/${auth.agent_id}/map?ship=LOCAL-1&location=${location}`;
async function map(location='X1-TEST-ROCK'){await page.goto(mapUrl(location));await page.locator('#location-title').waitFor();}
async function plan(action='travel',location='X1-TEST-ROCK'){
    await map(location);
    if(action==='travel')await page.getByRole('link',{name:'Travel here',exact:true}).click();
    else{await b('Get information').click();await page.getByRole('link',{name:'Review discovery options',exact:true}).click();}
    await task().waitFor();assert.match(await task().innerText(),new RegExp(`LOCAL-1 → ${location}`));
}
async function screenshot(name){const badge=b('Hide badge for this session');if(!await page.locator('dialog[open]').count() && await badge.isVisible())await badge.click();await page.screenshot({path:path.join(output,`${name}.png`)});}
async function check(id,run){if(process.env.MAP_QA_ONLY && !new RegExp(process.env.MAP_QA_ONLY).test(id))return;current=id;try{await page.waitForTimeout(5000);const observed=await run();await screenshot(id);results.checks.push({id,status:'pass',observed,screenshot:`${id}.png`});console.log(`PASS ${id}`);}catch(error){await screenshot(`${id}-failed`).catch(()=>{});const message=String(error.message).replaceAll(auth.password,'[redacted]').slice(0,1800);results.checks.push({id,status:'failed',observed:message,screenshot:`${id}-failed.png`});console.log(`FAIL ${id}: ${message.slice(0,400)}`);}await fs.writeFile(path.join(output,'results.json'),JSON.stringify(results,null,2)+'\n');}
async function review(command){await page.locator('.approval:visible').waitFor();assert.match(await page.locator('.approval:visible').innerText(),new RegExp(command,'i'));}
async function approve(){await b('Approve command').click();await task().waitFor();await page.getByRole('region',{name:'Latest ship action',exact:true}).waitFor();}
async function refresh(){await b('Refresh ship status').click();await page.waitForFunction(()=>!document.querySelector('main[aria-busy=true]'));}
try{
    await page.goto(`${origin}/login`);await page.getByLabel('Email',{exact:true}).fill(auth.email);await page.getByLabel('Password',{exact:true}).fill(auth.password);await b('Log in').click();await page.waitForURL(`${origin}/agents`);
    await check('read-intent-and-unknown-traits',async()=>{
        prepare('scan');await map();const before=await world();await b('Get information').click();await page.getByRole('dialog',{name:'Get location information',exact:true}).waitFor();assert.equal((await world()).calls.length,before.calls.length,'Opening options must not fetch');
        await b('Fetch waypoint details').click();await page.getByRole('status').filter({hasText:'Traits are still unknown'}).waitFor();assert.equal((await world()).writes.length,0);assert.equal((await world()).calls.length,before.calls.length+2,'Reset guard and exactly one waypoint read');
        await page.keyboard.press('Escape');assert.ok(await page.locator('.location-card').isVisible());assert.equal(await page.locator(':focus').innerText(),'Get information');return 'Options are stored-only; explicit detail GET preserves hidden traits, saves observation and restores focus on Escape.';
    });
    await check('read-error-recovery',async()=>{
        prepare('scan');await map();await b('Get information').click();await change(w=>w.failure='read_503');await b('Fetch waypoint details').click();await page.getByRole('alert').waitFor();assert.equal((await world()).writes.length,0);await change(w=>w.failure=null);await page.waitForTimeout(6500);await b('Fetch waypoint details').click();await page.getByRole('status').filter({hasText:'Traits are still unknown'}).waitFor();return 'Real local read handler reports fake upstream failure, retains the map and succeeds on explicit retry.';
    });
    await check('market-and-shipyard-reads',async()=>{
        prepare('travel');await map('X1-TEST-PORT');await b('Get information').click();await b('Fetch market information').click();await page.getByRole('status').filter({hasText:'Saved the returned information'}).waitFor();await b('Fetch shipyard information').click();await page.getByRole('status').filter({hasText:'Saved the returned information'}).waitFor();await b('Done').click();assert.ok((await page.locator('.location-card').innerText()).includes('5 CR'));assert.equal((await world()).writes.length,0);return 'Separate explicit market and shipyard reads update rendered stored data without gameplay writes.';
    });
    await check('travel-orbit-preview-cancel-stop',async()=>{
        prepare('travel');await plan();assert.ok(await b('Preview travel').isDisabled());assert.equal((await world()).calls.length,0,'GET handoff cannot fetch');await b('Preview entering orbit').click();await review('Orbit');assert.equal((await world()).writes.length,0);await b('Cancel preview').click();await task().waitFor();await b('Preview entering orbit').click();await review('Orbit');await b('Stop manual command').click();await task().waitFor();assert.equal((await world()).writes.length,0);return 'Map handoff preselects ship and target; docked travel explains orbit. Preview, cancel and Stop perform no gameplay writes.';
    });
    await check('travel-approval-queue-transit-arrival',async()=>{
        prepare('travel');await plan();await page.getByLabel('Protected credits in CR',{exact:true}).fill('4321');await b('Preview entering orbit').click();await review('Orbit');await screenshot('orbit-review');await approve();assert.equal((await world()).writes.length,0,'Approval queues rather than executes in browser');drain();await refresh();assert.ok(await b('Preview travel').isEnabled());assert.equal(await page.getByLabel('Protected credits in CR',{exact:true}).inputValue(),'4321','Safety input must survive the orbit approval round trip');
        await page.getByLabel('Protected credits in CR',{exact:true}).fill('4321');await b('Preview travel').click();await review('Navigate');assert.ok((await page.locator('.approval:visible').innerText()).includes('4,321'),'Caller reserve must be preserved');assert.ok((await page.locator('.approval:visible').innerText()).includes('X1-TEST-ROCK'));await screenshot('travel-review');await approve();assert.equal((await world()).writes.length,1);drain();await refresh();assert.equal((await world()).writes.length,2);assert.ok((await task().innerText()).includes('Arrival unconfirmed'));
        await page.getByRole('link',{name:'Back to selected map location',exact:true}).click();await page.locator('#location-title').waitFor();assert.equal(await page.getByLabel('Selected ship',{exact:true}).inputValue(),'LOCAL-1');assert.equal(await page.locator('#location-title').innerText(),'X1-TEST-ROCK');assert.match(await page.locator('.map-ship-note').innerText(),/In transit.*arrival unconfirmed/);await screenshot('map-in-transit');
        await change(w=>w.ships[0].nav.route.arrival='2020-01-01T00:00:00Z');await page.reload();assert.match(await page.locator('.map-ship-note').innerText(),/In transit/,'Client deadline cannot confirm arrival');
        await page.goto(`${origin}/agents/${auth.agent_id}?ship=LOCAL-1&map_action=travel&map_waypoint=X1-TEST-ROCK`);await task().waitFor();await refresh();assert.ok((await task().innerText()).includes('This is your observed location'));assert.ok(await b('Preview travel').isDisabled());return 'Separate orbit and travel approvals execute through the real fixture queue. Destination/reserve survive; returned transit stays unconfirmed until explicit upstream refresh.';
    });
    await check('travel-fuel-rejection',async()=>{
        prepare('scan');await change(w=>w.ships[0].fuel.current=1);await plan();await b('Preview travel').click();await page.locator('dialog[open] [role=alert]').waitFor();assert.equal((await world()).writes.length,0);assert.equal(await b('Approve command').count(),0);return 'Server rejects unsafe fuel continuation before approval with a visible error and zero gameplay writes.';
    });
    await check('expired-preview',async()=>{
        prepare('travel');await plan();await b('Preview entering orbit').click();await review('Orbit');execFileSync(php,[fixtureScript,'--expire'],{cwd:root});await b('Approve command').click();await page.locator('dialog[open] [role=alert]').waitFor();assert.equal((await world()).writes.length,0);return 'Expired approval reports server rejection without a write.';
    });
    await check('pending-stop-and-uncertain-reconciliation',async()=>{
        prepare('travel');await plan();await b('Preview entering orbit').click();await review('Orbit');await approve();await task().getByRole('button',{name:'Stop manual command',exact:true}).click();drain();await refresh();assert.equal((await world()).writes.length,0,'Stopping queued intent must prevent transport');
        prepare('travel');await plan();await b('Preview entering orbit').click();await review('Orbit');await approve();await change(w=>w.failure='timeout_after');drain();await refresh();assert.match(await task().innerText(),/Uncertain/);assert.ok(await b('Preview travel').isDisabled());assert.equal((await world()).writes.length,1);await b('Reconcile action').click();await page.waitForFunction(()=>!document.querySelector('main[aria-busy=true]'));assert.equal((await world()).writes.length,1,'Reconciliation cannot replay a write');return 'Stop prevents queued transport; a lost write response shows uncertainty and blocks new commands. Explicit reconciliation performs reads without replay.';
    });
    await check('explicit-travel-limits',async()=>{
        prepare('scan');await plan();await page.getByLabel('Use explicit fuel and time review limits',{exact:true}).check();await page.getByLabel('Maximum tank fuel use for review',{exact:true}).fill('10');await page.getByLabel('Maximum travel seconds for review',{exact:true}).fill('300');await page.getByLabel('I acknowledge uncalibrated travel and no verified continuation',{exact:true}).check();await b('Preview travel').click();await review('Navigate');assert.match(await page.locator('.approval:visible').innerText(),/Uncalibrated travel can strand/);assert.equal((await world()).writes.length,0);await b('Cancel preview').click();await task().waitFor();return 'Explicit fuel/time thresholds require acknowledgement, appear in existing server preview and remain separate from approval.';
    });
    await check('discovery-capabilities-and-cooldown',async()=>{
        prepare('no-sensor');await plan('discover');assert.ok(await b('Preview nearby scan').isDisabled());assert.match(await task().innerText(),/No Sensor Array/);assert.ok(await b('Preview charting location').isDisabled());assert.match(await task().innerText(),/Travel to this location/);await screenshot('discovery-no-sensor');
        prepare('cooldown');await plan('discover');assert.ok(await b('Preview nearby scan').isDisabled());assert.match(await task().innerText(),/Cooldown readiness is not confirmed/);assert.equal((await world()).writes.length,0);return 'No-sensor, wrong-location charting and cooldown states explain unavailable actions.';
    });
    await check('discovery-scan-and-map-observations',async()=>{
        prepare('scan');await plan('discover');await b('Preview nearby scan').click();await review('Scan');await screenshot('scan-review');await approve();assert.equal((await world()).writes.length,0);drain();await refresh();assert.equal((await world()).writes.length,1);assert.ok(await b('Preview nearby scan').isDisabled());await page.getByRole('link',{name:'Back to selected map location',exact:true}).click();await page.locator('#location-title').waitFor();assert.match(await page.locator('.location-card').innerText(),/Mineral deposits/);await page.locator('.location-scroll section').filter({has:page.getByRole('heading',{name:'Traits & terrain',exact:true})}).scrollIntoViewIfNeeded();return 'Guarded exploration preview/approval/queue performs one scan, applies cooldown and displays normalized revealed traits on the original map location.';
    });
    await check('discovery-missing-target',async()=>{
        prepare('scan');await change(w=>w.scan_waypoints=[]);await plan('discover');await b('Preview nearby scan').click();await review('Scan');await approve();drain();await refresh();await page.getByRole('link',{name:'Back to selected map location',exact:true}).click();await page.locator('#location-title').waitFor();assert.match(await page.locator('.location-card').innerText(),/Traits & terrain\n\nUnknown/);assert.equal((await world()).writes.length,1);return 'A scan that returns no target leaves traits unknown and stops after one write.';
    });
    await check('discovery-chart-current-location',async()=>{
        prepare('chart');await plan('discover');assert.ok(await b('Preview charting location').isEnabled());await b('Preview charting location').click();await review('Chart');await approve();drain();await refresh();assert.equal((await world()).writes.length,1);await page.getByRole('link',{name:'Back to selected map location',exact:true}).click();await page.locator('#location-title').waitFor();assert.match(await page.locator('.location-card').innerText(),/Mineral Deposits|Mineral deposits/);await page.locator('.location-scroll section').filter({has:page.getByRole('heading',{name:'Traits & terrain',exact:true})}).scrollIntoViewIfNeeded();return 'Current uncharted location is charted through separate approval and queue execution; the map displays the returned traits.';
    });
    await check('map-task-normal-command-return-and-history',async()=>{
        prepare('travel');await plan();await b('Back to ship').click();await page.locator('dialog[open]').waitFor({state:'hidden'});await page.locator('.home-destinations').getByRole('button',{name:'Commands',exact:true}).click();await page.getByRole('button',{name:'Preview orbit',exact:true}).click();await review('Orbit');await b('Cancel preview').click();await page.locator('#manual:visible').waitFor();assert.equal(await task().isVisible(),false,'Unrelated preview must return to its own Commands flow');await b('Done').click();await page.locator('.home-destinations').getByRole('button',{name:'Commands',exact:true}).click();await page.goBack();await page.locator('dialog[open]').waitFor({state:'hidden'});return 'Map context does not take over unrelated command cancellation or reopen on browser Back to ship.';
    });
    await check('blank-reserve-and-phone-back-selection',async()=>{
        prepare('scan');await page.goto(`${origin}/agents/${auth.agent_id}/map?ship=LOCAL-1`);await page.locator('.map-directory summary').click();await page.locator('.map-directory-list button').filter({has:page.locator('span',{hasText:/^X1-TEST-ROCK$/})}).click();await page.getByRole('link',{name:'Travel here',exact:true}).click();await task().waitFor();await page.goBack();await page.locator('#location-title').waitFor();assert.equal(await page.locator('#location-title').innerText(),'X1-TEST-ROCK');await page.getByRole('link',{name:'Travel here',exact:true}).click();await task().waitFor();await page.getByLabel('Protected credits in CR',{exact:true}).fill('');await b('Preview travel').click();await review('Navigate');assert.match(await page.locator('.approval:visible').innerText(),/2,000 CR/);assert.equal((await world()).writes.length,0);return 'Browser Back restores the selected map card, and blank reserve retains the configured floor rather than silently becoming zero.';
    });
    await check('short-phone-action-layout',async()=>{
        prepare('travel');await page.setViewportSize({width:360,height:640});await plan();const dialog=page.locator('dialog[open]');await b('Preview entering orbit').scrollIntoViewIfNeeded();assert.ok(await b('Preview entering orbit').isVisible());assert.ok(await dialog.evaluate(el=>el.scrollHeight>el.clientHeight));assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),360);return '360px phone uses scrollable modal, readable controls and no horizontal page overflow.';
    });
}finally{await context.close();await browser.close();await fs.writeFile(path.join(output,'results.json'),JSON.stringify(results,null,2)+'\n');}
if(results.checks.some(c=>c.status==='failed')||results.errors.length){console.log(`${results.checks.filter(c=>c.status==='failed').length} failed scenarios; ${results.errors.length} unexpected browser errors`);process.exitCode=1;}else console.log(`All ${results.checks.length} action browser scenarios passed`);
