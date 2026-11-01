"""Run the local integration checks sequentially with owned runtime state and durable evidence."""
import argparse
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import time

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument('--phase', choices=['core', 'browser', 'all'], default='all')
parser.add_argument('--only', help='Run one named check')
args = parser.parse_args()
output = root / 'storage/app/integration-evidence'
output.mkdir(parents=True, exist_ok=True)
php = os.environ.get('PHP_BIN', str(root / 'scripts/herd-php'))
checks = [
    ('verify', 'core', ['./scripts/verify.sh']),
    ('mvp-worker', 'core', [php, 'scripts/verify-mvp-worker.php']),
    ('automation-worker', 'core', [php, 'scripts/verify-automation-worker.php']),
    ('deployment-processes', 'core', [php, 'scripts/verify-deployment.php']),
    ('deployment-wall-timeout', 'core', [php, 'scripts/verify-deployment.php', 'wall-timeout']),
    ('local-engine', 'core', [php, 'scripts/verify-local-engine.php']),
    ('private-account-browser', 'browser', ['node', 'scripts/verify-private-account-browser.mjs']),
    ('pages-browser', 'browser', ['node', 'scripts/verify-pages-browser.mjs']),
    ('native-ship-browser', 'browser', ['node', 'scripts/verify-native-ship-browser.mjs']),
    ('deployment-browser', 'browser', ['node', 'scripts/verify-deployment-browser.mjs']),
    ('adviser-browser', 'browser', ['node', 'scripts/verify-adviser-browser.mjs']),
    ('exploration-browser', 'browser', ['node', 'scripts/verify-exploration-browser.mjs']),
    ('automation-browser', 'browser', ['node', 'scripts/verify-automation-browser.mjs']),
    ('opportunities-browser', 'browser', ['node', 'scripts/verify-opportunities-browser.mjs']),
    ('browser-lifecycle', 'browser', ['node', '--test', 'scripts/verify-browser-lifecycle.mjs']),
    ('combined-browser', 'browser', ['node', 'scripts/verify-batch2-browser.mjs']),
]
results = []
for name, phase, command in checks:
    if args.only and args.only != name:
        continue
    if args.phase != 'all' and phase != args.phase:
        continue
    directory = Path(tempfile.mkdtemp(prefix='st-batch2-run-'))
    os.chmod(directory, 0o700)
    storage = directory / 'storage'
    for child in ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app']:
        (storage / child).mkdir(parents=True, mode=0o700, exist_ok=True)
    env = dict(os.environ, PHP_BIN=php, TMPDIR=str(directory), APP_ENV='local', APP_DEBUG='false',
               APP_KEY='base64:' + __import__('base64').b64encode(secrets.token_bytes(32)).decode(),
               DB_CONNECTION='sqlite', DB_DATABASE=str(directory / 'unused.sqlite'), DATABASE_URL='',
               LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
               SESSION_DRIVER='file', SESSION_COOKIE='batch2_' + secrets.token_hex(8),
               CACHE_DRIVER='array', CACHE_STORE='array', MAIL_MAILER='array',
               SPACETRADERS_FIXTURE_PATH='',
               SPACETRADERS_LOCK_PATH=str(directory / 'locks'),
               SPACETRADERS_REQUEST_BUDGET_PATH=str(directory / 'budget'),
               SPACETRADERS_LOCAL_ENGINE_ENABLED='false', SPACETRADERS_AUTOMATION_ENABLED='false',
               PLAYWRIGHT_BROWSERS_PATH=str(output / 'browsers'), QA_ARTIFACT_DIR=str(output / name))
    # Each harness may override its fixture paths. Do not pin deployment/cache paths
    # here: native engine children must derive them from their own fixture identity.
    for kind in ['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS']:
        env[f'APP_{kind}_CACHE'] = str(directory / f'{kind.lower()}.php')
    if name == 'verify':
        # PHPUnit supplies its own forced environment, in-memory DB and test key.
        for key in ['APP_ENV', 'APP_DEBUG', 'APP_KEY', 'DB_CONNECTION', 'DB_DATABASE',
                    'DATABASE_URL', 'SESSION_DRIVER', 'CACHE_DRIVER', 'CACHE_STORE',
                    'SPACETRADERS_FIXTURE_PATH', 'SPACETRADERS_LOCAL_ENGINE_ENABLED',
                    'SPACETRADERS_AUTOMATION_ENABLED']:
            env.pop(key, None)
    started = time.monotonic()
    log = output / f'{name}.log'
    print(f'RUN {name}', flush=True)
    try:
        with log.open('w') as stream:
            completed = subprocess.run(command, cwd=root, env=env, stdout=stream, stderr=subprocess.STDOUT, timeout=900)
        code = completed.returncode
    except subprocess.TimeoutExpired:
        code = 124
    retained = list(directory.rglob('failure.log'))
    removable = code == 0 and not retained
    results.append(dict(name=name, exit=code, seconds=round(time.monotonic() - started, 2), log=str(log), runtime=str(directory), runtime_removed=removable, retained_diagnostics=[str(p) for p in retained]))
    if removable:
        shutil.rmtree(directory)
    print(f'{"PASS" if code == 0 else "FAIL"} {name}, exit {code}, {results[-1]["seconds"]}s', flush=True)
    (output / f'summary-{args.only or args.phase}.json').write_text(json.dumps(results, indent=2) + '\n')
if not results:
    raise SystemExit('No matching checks')
raise SystemExit(any(result['exit'] != 0 for result in results))
