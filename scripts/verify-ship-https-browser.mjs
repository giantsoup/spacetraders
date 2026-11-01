import https from 'node:https';
import http from 'node:http';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { shipView } from './ship-browser-navigation.mjs';
const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || 'php';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const created = execFileSync(php, ['scripts/mvp-fixture.php', '--isolated'], { cwd: root, encoding: 'utf8' });
const directory = created.match(/Owned fixture directory: (.+)/)?.[1];
assert.ok(directory);
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`], { cwd: root, encoding: 'utf8' }));
assert.equal(parsed.DB_DATABASE, `${directory}/database.sqlite`);
assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, `${directory}/world.json`);
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `pages_${port}`, CACHE_DRIVER: 'array', CACHE_STORE: 'array', SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: `${directory}/storage`, LOG_CHANNEL: 'stderr' };
for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) {
    await fs.mkdir(`${env.LARAVEL_STORAGE_PATH}/${child}`, { recursive: true });
}
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/native-ui/https'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });

const errors = [], steps = [];
let failure, proxy;
try {
    await once(server.stderr, 'data');
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=localhost', '-days', '1', '-keyout', `${directory}/tls.key`, '-out', `${directory}/tls.crt`], {stdio:'pipe'});
    await fs.chmod(`${directory}/tls.key`, 0o600);
    proxy = https.createServer({ key: await fs.readFile(`${directory}/tls.key`), cert: await fs.readFile(`${directory}/tls.crt`) }, (request, response) => {
        const upstream = http.request({ hostname:'127.0.0.1', port, path:request.url, method:request.method, headers:{...request.headers,'x-forwarded-proto':'https'} }, result => {
            response.writeHead(result.statusCode, result.headers); result.pipe(response);
        });
        upstream.on('error', () => {response.writeHead(502); response.end();}); request.pipe(upstream);
    });
    proxy.listen(0,'127.0.0.1'); await once(proxy,'listening');
    const secureOrigin = `https://127.0.0.1:${proxy.address().port}`;
    const context = await browser.newContext({ignoreHTTPSErrors:true, viewport:{width:390,height:844},colorScheme:'light'});
    await context.route('**/*', route => {
        if(new URL(route.request().url()).origin===secureOrigin) return route.continue();
        if(new URL(route.request().url()).hostname==='fonts.bunny.net') return route.fulfill({status:200,contentType:'text/css',body:''});
        errors.push(`Unexpected origin ${new URL(route.request().url()).origin}`);return route.abort();
    });
    const page=await context.newPage();
    page.on('pageerror',error=>errors.push(error.message));
    page.on('response',r=>{if(r.status()>=400)errors.push(`${r.status()} ${new URL(r.url()).pathname}`);});
    await page.goto(secureOrigin+'/login');
    await page.getByLabel('Email',{exact:true}).fill(auth.email); await page.getByLabel('Password',{exact:true}).fill(auth.password);
    await page.getByRole('button',{name:'Log in',exact:true}).click(); await page.waitForURL(secureOrigin+'/agents');
    await page.getByRole('link',{name:'Open flight deck for LOCAL',exact:true}).click();
    await page.waitForURL(`${secureOrigin}/agents/${auth.agent_id}`);
    await page.getByRole('button',{name:'Report',exact:true}).click(); await page.getByRole('heading',{name:'Ship report',exact:true}).waitFor();
    await page.getByRole('button',{name:'Done',exact:true}).click();
    await shipView(page,'commands');
    const waiting=page.waitForResponse(r=>new URL(r.url()).pathname.endsWith('/commands/preview'));
    await page.getByRole('button',{name:'Preview orbit',exact:true}).click(); assert.equal((await waiting).status(),202);
    await page.getByRole('heading',{name:'Review manual command',exact:true}).waitFor();
    assert.ok((await page.locator('script[src],link[href*="/build/"]').evaluateAll(els=>els.map(el=>el.src||el.href))).every(url=>url.startsWith('https://')));
    await page.screenshot({path:path.join(output,'https-command-preview.png'),animations:'disabled'});
    await page.getByRole('button',{name:'Done',exact:true}).click(); await page.getByRole('button',{name:'Menu',exact:true}).click();
    await page.getByRole('link',{name:'Player guide',exact:true}).click(); await page.waitForURL(`${secureOrigin}/agents/${auth.agent_id}/guide`);
    steps.push('Login, Agents to ship, Report, CSRF-protected manual preview, shared Menu to guide and all build assets use forwarded HTTPS on an owned loopback TLS proxy.');
    assert.deepEqual(errors,[]);
} catch(error) {failure=error;}
finally {
    const result={passed:!failure,steps,errors,failure:failure?.message,browser:browser.version(),fixture:directory};
    await fs.writeFile(path.join(output,'results.json'),JSON.stringify(result,null,2));await browser.close();
    if(proxy) await new Promise(resolve=>proxy.close(resolve));server.kill();await once(server,'exit');console.log(JSON.stringify({...result,output},null,2));
}
if(failure)process.exitCode=1;
