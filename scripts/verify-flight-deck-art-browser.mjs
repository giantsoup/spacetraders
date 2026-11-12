import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { waypointFamilies } from '../resources/js/waypointSprites.js';
import { systemMapEnvironment } from './system-map-browser-environment.mjs';

const root=fileURLToPath(new URL('..',import.meta.url)),origin='http://spacetraders-map-90.test';
const output=path.resolve(process.env.MAP_QA_OUTPUT||path.join(root,'storage/app/system-map-90/qa-deck-art'));
const { php, auth } = await systemMapEnvironment(root);
const audit=()=>JSON.parse(execFileSync(php,['scripts/system-map-fixture.php','--audit'],{cwd:root,encoding:'utf8'}));
const scene=(symbol,type='',status='IN_TRANSIT')=>execFileSync(php,['scripts/system-map-action-fixture.php','--scene',symbol,type,status],{cwd:root});
await fs.mkdir(output,{recursive:true,mode:0o700});
const sourcePaths=['app/Http/Controllers/AgentDashboardController.php','resources/js/Components/ShipScene.vue','resources/js/Pages/Agent/Dashboard.vue','resources/js/shipPresentation.js','resources/js/Components/SystemMap/WaypointSprite.vue','resources/js/waypointSprites.js','public/build/manifest.json'];
const results={base_revision:execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim(),origin,checks:[],errors:[],sources:Object.fromEntries(await Promise.all(sourcePaths.map(async p=>[p,createHash('sha256').update(await fs.readFile(path.join(root,p))).digest('hex')])))};
const browser=await chromium.launch({headless:true});results.browser=browser.version();
const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,colorScheme:'light'});
await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
const page=await context.newPage();page.setDefaultTimeout(10000);let current='login';
page.on('pageerror',e=>results.errors.push({check:current,message:e.message}));
page.on('response',r=>{if(r.status()>=400)results.errors.push({check:current,status:r.status(),path:new URL(r.url()).pathname});});
const b=name=>page.getByRole('button',{name,exact:true});
const deck=async()=>{await page.goto(`${origin}/agents/${auth.agent_id}?ship=LOCAL-2`);await page.locator('.scene-location').waitFor();};
const pixels=locator=>locator.evaluate(el=>[...el.querySelectorAll('rect')].map(r=>['x','y','fill'].map(k=>r.getAttribute(k))));
async function capture(id){if(!await page.locator('dialog[open]').count()&&await b('Hide badge for this session').isVisible())await b('Hide badge for this session').click();await page.screenshot({path:path.join(output,`${id}.png`)});}
async function check(id,run){if(process.env.MAP_QA_ONLY&&!new RegExp(process.env.MAP_QA_ONLY).test(id))return;current=id;try{const observed=await run();await capture(id);results.checks.push({id,status:'pass',observed,screenshot:`${id}.png`});console.log(`PASS ${id}`);}catch(e){await capture(`${id}-failed`).catch(()=>{});results.checks.push({id,status:'failed',observed:String(e.message).replaceAll(auth.password,'[redacted]').slice(0,1400)});console.log(`FAIL ${id}: ${e.message.slice(0,300)}`);}await fs.writeFile(path.join(output,'results.json'),JSON.stringify(results,null,2)+'\n');}
try{
 await page.goto(`${origin}/login`);await page.getByLabel('Email',{exact:true}).fill(auth.email);await page.getByLabel('Password',{exact:true}).fill(auth.password);await b('Log in').click();await page.waitForURL(`${origin}/agents`);
 for(const [index,[type,family]] of Object.entries(waypointFamilies).entries())await check(`family-${family}`,async()=>{
  const symbol='X1-TEST-'+(index===0?'PORT':index===5?'ROCK':type.replaceAll('_',''));scene(symbol);const before=audit();await deck();assert.equal(await page.locator('.scene-location').getAttribute('data-location-symbol'),symbol);assert.equal(await page.locator('.scene-location svg').getAttribute('data-family'),family);assert.equal(await page.locator('.scene-location-context').innerText(),'Destination');
  const deckPixels=await pixels(page.locator('.scene-location svg'));await capture(`deck-${family}`);
  await page.goto(`${origin}/agents/${auth.agent_id}/map?ship=LOCAL-2&location=${symbol}`);await page.locator('#location-title').waitFor();assert.deepEqual(await pixels(page.locator('.location-heading svg')),deckPixels,'Deck and map must render the same pixels for the same identity and knowledge');assert.deepEqual(audit(),before,'Art browsing cannot refresh or write gameplay');
  await deck();return `${type} uses the same ${deckPixels.length} pixels as its map card, including saved traits; no gameplay or upstream changes.`;
 });
 await check('destination-brief-and-motion',async()=>{
  scene('X1-TEST-PORT');const before=audit();await deck();const expected=await pixels(page.locator('.scene-location svg'));await page.locator('.home-destinations').getByRole('button',{name:'Intelligence',exact:true}).click();await page.locator('.destination-art svg').waitFor();assert.deepEqual(await pixels(page.locator('.destination-art svg')),expected);await capture('destination-brief');await b('Done').click();await b('Pause motion').click();assert.equal(await page.locator('.scene-ship svg').evaluate(el=>getComputedStyle(el).animationPlayState),'paused');await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await page.locator('.scene-ship svg').evaluate(el=>getComputedStyle(el).animationName),'none');await page.locator('.scene-ship').click();await page.getByRole('dialog',{name:'Ship report',exact:true}).waitFor();await b('Done').click();assert.match(await page.locator('.status-location').innerText(),/Checking arrival/);assert.equal(await page.locator('.scene-location-context').innerText(),'Destination');assert.deepEqual(audit(),before);return 'Destination brief matches the hero; ship report, pause and reduced motion work; expired ETA still shows the destination without confirming arrival.';
 });
 await check('route-only-and-unknown',async()=>{
  scene('X2-UNSEEN-MOON','MOON');let before=audit();await deck();assert.equal(await page.locator('.scene-location svg').getAttribute('data-family'),'moon');assert.equal(await page.locator('.scene-location').getAttribute('data-location-symbol'),'X2-UNSEEN-MOON');assert.deepEqual(audit(),before);await capture('route-only');scene('X2-UNSEEN-UNKNOWN');before=audit();await deck();assert.equal(await page.locator('.scene-location svg').getAttribute('data-family'),'unknown');assert.deepEqual(audit(),before);return 'Route destination metadata supplies only the known type; a completely unknown destination gets neutral art.';
 });
 await check('stationary-and-fleet-switch',async()=>{
  scene('X1-TEST-MOON','','DOCKED');const before=audit();await deck();assert.equal(await page.locator('.scene-location-context').innerText(),'Observed location');assert.equal(await page.locator('.scene-location svg').getAttribute('data-family'),'moon');await page.locator('.identity-button').click();await page.locator('.ship-choice').filter({hasText:'LOCAL-1'}).click();assert.equal(await page.locator('.scene-location').getAttribute('data-location-symbol'),'X1-TEST-PORT');assert.equal(await page.locator('.scene-location svg').getAttribute('data-family'),'planet');assert.deepEqual(audit(),before);return 'Stationary ships use their observed location; changing owned ships updates the artwork without an upstream request.';
 });
 for(const [id,width,height] of [['small-phone',320,568],['phone-landscape',844,390],['desktop',1440,900]])await check(id,async()=>{
  scene('X1-TEST-PORT');const before=audit();await page.setViewportSize({width,height});await deck();const boxes=await page.evaluate(()=>{const box=s=>document.querySelector(s).getBoundingClientRect().toJSON();return {hero:box('.ship-scene'),art:box('.scene-location'),ship:box('.scene-ship'),width:document.documentElement.scrollWidth};});assert.equal(boxes.width,width);assert.ok(boxes.art.top>=boxes.hero.top&&boxes.art.bottom<=boxes.hero.bottom+1,'Location art/label must fit the hero');assert.ok(boxes.ship.right<boxes.art.left,'Ship report control must not overlap destination art');assert.ok(boxes.ship.height>=44);assert.deepEqual(audit(),before);return `Matching destination art and label fit ${width}×${height}; report button stays separate and at least 44px high.`;
 });
}finally{await context.close();await browser.close();await fs.writeFile(path.join(output,'results.json'),JSON.stringify(results,null,2)+'\n');}
if(results.checks.some(x=>x.status!=='pass')||results.errors.length){console.log('Flight deck art browser QA failed');process.exitCode=1;}else console.log(`All ${results.checks.length} flight deck art scenarios passed`);
