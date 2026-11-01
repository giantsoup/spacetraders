#!/usr/bin/env python3
"""Exercise the real PHP executable and preflight without booting the application."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
php = os.environ.get('PHP_BIN', 'php')
binary = subprocess.check_output([php, '-r', 'echo PHP_BINARY;'], text=True)


def probe(arguments, expected, message, env=None):
    result = subprocess.run(arguments, capture_output=True, text=True, env=env)
    assert result.returncode == expected, result.stdout + result.stderr
    assert message in result.stdout + result.stderr, result.stdout + result.stderr


probe([php, str(root / 'scripts/runtime-preflight.php')], 0, 'PASS PHP')
print('PASS real selected PHP and matching runtime pins')
with tempfile.TemporaryDirectory(prefix='ops01-runtime-') as directory:
    fixture = Path(directory)
    (fixture / 'scripts').mkdir()
    for file in ['.php-version', 'composer.json', 'composer.lock', 'scripts/runtime-preflight.php']:
        shutil.copyfile(root / file, fixture / file)
    script = str(fixture / 'scripts/runtime-preflight.php')
    lock = json.loads((fixture / 'composer.lock').read_text())
    lock['platform-overrides']['php'] = '8.4.1'
    (fixture / 'composer.lock').write_text(json.dumps(lock))
    probe([php, script], 1, 'PHP pins differ')
    print('PASS stale Composer lock pin rejected')
    shutil.copyfile(root / 'composer.lock', fixture / 'composer.lock')
    (fixture / '.php-version').write_text('8.5.0\n')
    composer = json.loads((fixture / 'composer.json').read_text())
    composer['require']['php'] = '~8.5.0'
    composer['config']['platform']['php'] = '8.5.0'
    lock['platform']['php'] = '~8.5.0'
    lock['platform-overrides']['php'] = '8.5.0'
    (fixture / 'composer.json').write_text(json.dumps(composer))
    (fixture / 'composer.lock').write_text(json.dumps(lock))
    probe([php, script], 1, 'Expected PHP 8.5.0; got')
    print('PASS untested PHP patch rejected even when manifest pins agree')
    wrapper = fixture / 'PHP wrapper with spaces'
    shutil.copyfile(root / 'scripts/herd-php', wrapper)
    wrapper.chmod(0o700)
    env = {**os.environ, 'HERD_PHP85_BIN': binary}
    output = subprocess.check_output([str(wrapper), '-r', 'echo json_encode(array_slice($argv, 1));', '--', 'a b', '$literal'], env=env, text=True)
    assert json.loads(output) == ['a b', '$literal'], output
    print('PASS executable PHP_BIN wrapper preserves paths and literal arguments with spaces')
    probe([str(wrapper), '-v'], 1, 'Herd PHP 8.5 executable not found', {**env, 'HERD_PHP85_BIN': str(fixture / 'missing')})
    print('PASS missing Herd executable fails without falling back to global PHP')
