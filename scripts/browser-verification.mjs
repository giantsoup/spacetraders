import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import { once } from 'node:events';
import path from 'node:path';
import { stopOwnedProcess, waitUntil, withOwnedVerification } from './verification-lifecycle.mjs';

export async function withBrowserVerification({ root, php, output, engine = false, persistentCache = false, resultFile }, journey) {
    return withOwnedVerification({ output, resultFile }, async run => {
        const storage = path.join(run.directory, 'storage');
        for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) {
            await fs.mkdir(path.join(storage, child), { recursive: true, mode: 0o700 });
        }
        const isolated = { ...process.env, TMPDIR: run.directory, APP_BASE_PATH: root, DATABASE_URL: '',
            LARAVEL_STORAGE_PATH: storage, APP_CONFIG_CACHE: `${run.directory}/no-config.php`,
            APP_SERVICES_CACHE: `${run.directory}/services.php`, APP_PACKAGES_CACHE: `${run.directory}/packages.php`,
            APP_ROUTES_CACHE: `${run.directory}/routes.php`, APP_EVENTS_CACHE: `${run.directory}/events.php`,
            VIEW_COMPILED_PATH: `${storage}/framework/views` };
        const execute = (args, env = isolated) => {
            try {
                return execFileSync(php, args, { cwd: root, env, encoding: 'utf8', timeout: 120_000, stdio: ['ignore', 'pipe', 'pipe'] });
            } catch (cause) {
                // Command output can contain generated credentials. Keep it in private diagnostics.
                const error = new Error(`Owned PHP command failed, exit ${cause.status ?? cause.code ?? cause.signal}`, { cause });
                error.stdout = cause.stdout;
                error.stderr = cause.stderr;
                throw error;
            }
        };
        const created = execute(['scripts/mvp-fixture.php', '--isolated']);
        await fs.writeFile(`${run.directory}/setup.log`, created, { mode: 0o600 });
        const directory = created.match(/Owned fixture directory: (.+)/)?.[1];
        assert.ok(directory && path.dirname(directory) === run.directory, 'Fixture must be created inside this run');
        assert.equal(await fs.realpath(directory), directory);
        const parsed = JSON.parse(execute(['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`]));
        assert.equal(parsed.DB_DATABASE, `${directory}/database.sqlite`);
        assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, `${directory}/world.json`);
        run.redact(parsed.APP_KEY);
        const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
        run.redact(auth.password);
        const socket = createServer();
        socket.listen(0, '127.0.0.1');
        await once(socket, 'listening');
        const port = socket.address().port;
        await new Promise((resolve, reject) => socket.close(error => error ? reject(error) : resolve()));
        const origin = `http://127.0.0.1:${port}`;
        const env = { ...isolated, ...parsed, APP_URL: origin, SESSION_COOKIE: `verification_${port}`,
            CACHE_DRIVER: persistentCache ? 'file' : 'array', CACHE_STORE: persistentCache ? 'file' : 'array',
            CACHE_PATH: `${storage}/framework/cache/data`, SESSION_DRIVER: 'file', LOG_CHANNEL: 'stderr',
            SPACETRADERS_LOCAL_ENGINE_ENABLED: engine ? 'true' : 'false', SPACETRADERS_LOCAL_HOST: '127.0.0.1',
            SPACETRADERS_OPERATOR_ID: '1', SPACETRADERS_PHP_BINARY: execute(['-r', 'echo PHP_BINARY;']) };
        const cli = code => JSON.parse(execute(['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], env));
        const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
        const browserServer = await chromium.launchServer({ headless: true, env: { ...process.env, TMPDIR: run.directory },
            ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
        run.defer('browser', async () => {
            const closing = browserServer.close().then(() => null, error => error);
            try {
                await waitUntil(() => browserServer.process().exitCode !== null || browserServer.process().signalCode !== null, 10_000);
            } catch (error) {
                await browserServer.kill();
                throw error;
            }
            const closeError = await closing;
            if (closeError) throw closeError;
            await waitUntil(() => browserServer.process().exitCode !== null || browserServer.process().signalCode !== null, 10_000);
        });
        const browser = await chromium.connect(browserServer.wsEndpoint());
        const interrupted = () => { browser.close().catch(() => {}); };
        run.signal.addEventListener('abort', interrupted, { once: true });
        run.defer('browserConnection', async () => {
            run.signal.removeEventListener('abort', interrupted);
            await browser.close();
            assert.equal(browser.isConnected(), false);
        });
        const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
            { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
        let serverError;
        server.on('error', error => { serverError = error; });
        run.details.processes = { browser: browserServer.process().pid, server: server.pid };
        let log;
        run.defer('server', async () => {
            try {
                await stopOwnedProcess(server);
                assert.ok(!server.pid || server.exitCode !== null || server.signalCode !== null);
            } finally { await log?.close(); }
        });
        log = await fs.open(`${run.directory}/server.log`, 'wx', 0o600);
        server.stdout.on('data', value => { log.write(value).catch(() => {}); });
        server.stderr.on('data', value => { log.write(value).catch(() => {}); });
        await waitUntil(async () => {
            run.signal.throwIfAborted();
            if (serverError) throw serverError;
            assert.ok(server.exitCode === null && server.signalCode === null, 'Owned fixture server exited during startup');
            return fetch(`${origin}/login`, { signal: AbortSignal.timeout(1000) }).then(response => response.ok, () => false);
        });
        if (engine) {
            // Register before install, which can fail after writing a plist or bootstrapping the job.
            run.defer('service', async () => {
                const result = cli(`$m=app(App\\Gameplay\\LocalEngineManager::class); $p=App\\Models\\LocalEngine::find(1)?->processes ?? []; $m->end();
                    $deadline=microtime(true)+50; do { $p=array_merge($p, App\\Models\\LocalEngine::find(1)?->processes ?? []);
                    $alive=array_filter($p, fn($pid)=>$m->alive($pid)); if (!$alive) break; usleep(100000); } while(microtime(true)<$deadline);
                    if ($alive) throw new RuntimeException('Owned engine processes did not exit'); $m->uninstall();
                    if (is_file($m->directory().'/service.plist')) throw new RuntimeException('Owned service plist remains');
                    echo json_encode(['processesExited'=>array_values($p),'serviceRemoved'=>true,'serviceLabel'=>$m->label()]);`);
                run.details.engineShutdown = result;
                await fs.writeFile(`${run.directory}/shutdown.json`, JSON.stringify(result), { mode: 0o600 });
            });
            execute(['artisan', 'spacetraders:engine-service', 'install'], env);
        }
        run.onFailure(async () => {
            const page = browser.contexts().flatMap(context => context.pages())[0];
            if (page) await page.screenshot({ path: `${run.directory}/failure.png`, fullPage: true, timeout: 5_000 });
        });
        const details = await journey({ ...run, directory, parsed, env, origin, browser, auth, cli, execute });
        return { ...details, browser: browser.version(), evidence: run.output,
            processes: { browser: browserServer.process().pid, server: server.pid } };
    });
}
