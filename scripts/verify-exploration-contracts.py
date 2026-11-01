#!/usr/bin/env python3
"""Verify EXP01 against official documentation files, never the game API."""
import argparse
import hashlib
import json
from pathlib import Path
from urllib.request import urlopen

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--source-dir', type=Path, help='Read an already downloaded official documentation snapshot.')
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
base = 'https://raw.githubusercontent.com/SpaceTradersAPI/api-docs/main/'
files = ['reference/SpaceTraders.json', 'models/ScannedWaypoint.json', 'models/Cooldown.json', 'models/Chart.json', 'models/ShipMount.json']
sources, documents = [], {}
for file in files:
    raw = (args.source_dir / Path(file).name).read_bytes() if args.source_dir else urlopen(base + file, timeout=30).read()
    documents[file] = json.loads(raw)
    sources.append({'url': base + file, 'sha256': hashlib.sha256(raw).hexdigest()})
spec = documents[files[0]]
contracts = {}
for name, suffix, expected in [('scan_waypoints', 'scan/waypoints', {'cooldown', 'waypoints'}), ('chart', 'chart', {'chart', 'waypoint', 'agent'})]:
    operation = spec['paths']['/my/ships/{shipSymbol}/' + suffix]['post']
    response = operation['responses']['201']['content']['application/json']['schema']['properties']['data']
    assert set(response['required']) == expected, f'{name}: response contract changed'
    contracts[name] = {'method': 'POST', 'path': '/my/ships/{shipSymbol}/' + suffix, 'required_response': sorted(expected)}
scan = spec['paths']['/my/ships/{shipSymbol}/scan/waypoints']['post']
assert 'Sensor Array' in scan['description'], 'Sensor requirement changed'
assert {'shipSymbol', 'totalSeconds', 'remainingSeconds'} <= set(documents['models/Cooldown.json']['required'])
assert 'traits' in documents['models/ScannedWaypoint.json']['required']
mounts = documents['models/ShipMount.json']['properties']['symbol']['enum']
assert {f'MOUNT_SENSOR_ARRAY_{level}' for level in ['I', 'II', 'III']} <= set(mounts)
result = {'result': 'pass', 'api_version': spec['info']['version'], 'contracts': contracts, 'sources': sources,
          'limits': 'Documentation verification only. No fixed range, scan duration or orbit prerequisite is asserted by these endpoint schemas. Orbit and expired cooldown are conservative client requirements; live behavior is untested.'}
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result, indent=2))
