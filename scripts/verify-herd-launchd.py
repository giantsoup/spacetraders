#!/usr/bin/env python3
"""Probe the actual Herd PHP-FPM identity with a disposable, inert launchd job."""
import os, pathlib, plistlib, socket, struct, tempfile, json

with tempfile.TemporaryDirectory(prefix='st-herd-launchd-') as directory:
    root = pathlib.Path(directory)
    label = 'io.spacetraders.probe.' + root.name.split('-')[-1]
    plist = root / 'probe.plist'
    plist.write_bytes(plistlib.dumps({'Label': label, 'ProgramArguments': ['/usr/bin/true'], 'RunAtLoad': False, 'KeepAlive': False}))
    script = root / 'probe.php'
    script.write_text('''<?php
$label = %s; $file = %s; $domain = 'gui/'.posix_geteuid();
function operation(array $args): int { $p=proc_open(array_merge(['/bin/launchctl'], $args), [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes); return proc_close($p); }
try { $bootstrap=operation(['bootstrap',$domain,$file]); $start=operation(['kickstart',$domain.'/'.$label]); $print=operation(['print',$domain.'/'.$label]); echo json_encode(['uid'=>posix_geteuid(),'php'=>PHP_VERSION,'bootstrap'=>$bootstrap,'kickstart'=>$start,'print'=>$print]); }
finally { operation(['bootout',$domain.'/'.$label]); }
''' % (json.dumps(label), json.dumps(str(plist))))
    params = {'SCRIPT_FILENAME': str(script), 'SCRIPT_NAME': '/probe.php', 'REQUEST_METHOD': 'GET', 'SERVER_PROTOCOL': 'HTTP/1.1', 'CONTENT_LENGTH': '0'}
    def length(n): return bytes([n]) if n < 128 else struct.pack('!I', n | 0x80000000)
    def record(kind, data): return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data
    encoded = b''.join(length(len(k))+length(len(v))+k.encode()+v.encode() for k,v in params.items())
    client = socket.socket(socket.AF_UNIX); client.settimeout(15)
    client.connect(os.path.expanduser('~/Library/Application Support/Herd/herd84.sock'))
    client.sendall(record(1, struct.pack('!HB5x', 1, 0))+record(4,encoded)+record(4,b'')+record(5,b''))
    def receive(n):
        data=b''
        while len(data)<n:
            part=client.recv(n-len(data))
            if not part: raise RuntimeError('Incomplete FastCGI response')
            data+=part
        return data
    output=b''
    while True:
        version, kind, request, count, padding, _ = struct.unpack('!BBHHBB', receive(8))
        data=receive(count); receive(padding)
        if kind==6: output+=data
        if kind==3: break
    client.close()
    result=json.loads(output.split(b'\r\n\r\n',1)[1])
    assert result['uid']==os.getuid() and all(result[k]==0 for k in ['bootstrap','kickstart','print']), result
    print(json.dumps({**result, 'temporary_service_removed': True}))
