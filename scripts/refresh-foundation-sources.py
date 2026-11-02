#!/usr/bin/env python3
"""Refresh hashes and the readable inventory after reviewing actual source changes.

Does not award acceptance, alter the pinned schema, or change the verifier.
"""
import hashlib
import json
from pathlib import Path
root = Path(__file__).resolve().parents[1]
path = root / 'docs/api/base-api-coverage.json'
manifest = json.loads(path.read_text())
for file in ['app/Gameplay/ManualReads.php', 'resources/js/Components/ManualReadBrowser.vue', 'app/Http/Controllers/AgentReadController.php', 'app/Gameplay/ShipCommandExecutor.php', 'config/foundation.php']:
    manifest['source_files'].setdefault(file, '')
for file in manifest['source_files']:
    manifest['source_files'][file] = hashlib.sha256((root / file).read_bytes()).hexdigest()
for row in manifest['operations']:
    if row['method'] == 'GET':
        row['implementation'] = 'source_mapped'
        row['notes'] = 'Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending.'
        row['source_evidence'] = [{'path': 'app/Gameplay/ManualReads.php', 'contains': "'" + row['id'] + "' =>"}, {'path': 'app/Gameplay/SpaceTradersApi.php', 'contains': 'public function manualRead('}, {'path': 'resources/js/Components/ManualReadBrowser.vue', 'contains': 'Refresh selected read'}]
    if row['id'] == 'patch-ship-nav':
        row['implementation'] = 'partial'
        row['notes'] = 'Explicit PATCH dispatch, exact flight-mode preview and manual Stop implemented; mode travel variants and live calibration pending.'
        row['source_evidence'] = [{'path': 'app/Gameplay/SpaceTradersApi.php', 'contains': "'flight_mode' =>"}]
path.write_text(json.dumps(manifest, indent=2) + '\n')
inventory = root / 'docs/api/base-api-inventory.md'
text = inventory.read_text()
start = text.index('| `get-status` |')
end = text.find('\n\n', start)
if end < 0: end = len(text.rstrip())
rows = []
for row in manifest['operations']:
    rows.append('| `' + row['id'] + '` | `' + row['method'] + ' ' + row['path'] + '` | ' + row['implementation'] + ' | ' + row['task'] + ' | ' + row['notes'] + ' |')
inventory.write_text(text[:start] + '\n'.join(rows) + text[end:])
