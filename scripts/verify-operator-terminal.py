"""Drive only disposable operator commands; secret answers arrive over stdin, never argv."""
import json
import os
import pty
import select
import sys
import termios
import time

spec = json.load(sys.stdin)
pid, terminal = pty.fork()
if pid == 0:
    os.execv(sys.argv[1], sys.argv[1:])
transcript = b''
try:
    for prompt, answer, hidden in spec['answers']:
        deadline = time.monotonic() + 15
        while prompt.encode() not in transcript:
            if time.monotonic() > deadline:
                raise RuntimeError('Terminal prompt timeout')
            if select.select([terminal], [], [], 0.1)[0]:
                transcript += os.read(terminal, 65536)
        if hidden:
            while termios.tcgetattr(terminal)[3] & termios.ECHO:
                if time.monotonic() > deadline:
                    raise RuntimeError('Password input did not disable echo')
                select.select([], [], [], 0.01)
        os.write(terminal, (answer + '\n').encode())
    deadline = time.monotonic() + 15
    while True:
        if time.monotonic() > deadline:
            raise RuntimeError('Terminal completion timeout')
        if select.select([terminal], [], [], 0.1)[0]:
            try:
                data = os.read(terminal, 65536)
                if not data:
                    break
                transcript += data
            except OSError:
                break
    _, status = os.waitpid(pid, 0)
    for _, answer, hidden in spec['answers']:
        if hidden and answer.encode() in transcript:
            raise RuntimeError('Secret appeared in terminal output')
    if os.waitstatus_to_exitcode(status) != spec['exit']:
        raise RuntimeError('Unexpected command exit')
    if spec.get('message', '').encode() not in transcript:
        raise RuntimeError('Missing expected completion message')
    print(json.dumps({'passed': True, 'hidden_input': True, 'secret_absent_from_output': True}))
except BaseException:
    try:
        os.kill(pid, 9)
        os.waitpid(pid, 0)
    except ProcessLookupError:
        pass
    print('Disposable terminal verification failed; transcript withheld.', file=sys.stderr)
    sys.exit(1)
finally:
    os.close(terminal)
