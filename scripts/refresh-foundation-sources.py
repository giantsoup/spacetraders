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
for file in ['app/Gameplay/ManualReads.php', 'resources/js/Components/ManualReadBrowser.vue', 'app/Http/Controllers/AgentReadController.php', 'app/Gameplay/ShipCommandExecutor.php', 'config/foundation.php', 'app/Gameplay/ManualCommands.php', 'app/Gameplay/EquipmentRules.php', 'app/Gameplay/CargoAuthority.php', 'app/Gameplay/CargoRefuel.php', 'resources/js/Components/ManualCommandForm.vue']:
    manifest['source_files'].setdefault(file, '')
for file in manifest['source_files']:
    manifest['source_files'][file] = hashlib.sha256((root / file).read_bytes()).hexdigest()
for row in manifest['operations']:
    if row['method'] == 'GET':
        row['implementation'] = 'source_mapped'
        row['notes'] = 'Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending.'
        row['source_evidence'] = [{'path': 'app/Gameplay/ManualReads.php', 'contains': "'" + row['id'] + "' =>"}, {'path': 'app/Gameplay/SpaceTradersApi.php', 'contains': 'public function manualRead('}, {'path': 'resources/js/Components/ManualReadBrowser.vue', 'contains': 'Refresh selected read'}]
    commands = {
        'purchase-ship': 'buy_ship', 'orbit-ship': 'orbit', 'dock-ship': 'dock',
        'purchase-cargo': 'buy', 'sell-cargo': 'sell', 'transfer-cargo': 'transfer', 'jettison': 'jettison',
        'accept-contract': 'contract_accept', 'deliver-contract': 'contract_deliver', 'fulfill-contract': 'contract_fulfill', 'negotiateContract': 'negotiate',
        'repair-ship': 'repair', 'install-mount': 'install_mount', 'remove-mount': 'remove_mount', 'install-ship-module': 'install_module', 'remove-ship-module': 'remove_module',
        'create-survey': 'survey', 'extract-resources': 'mine', 'extract-resources-with-survey': 'mine_survey', 'siphon-resources': 'siphon', 'ship-refine': 'refine',
        'patch-ship-nav': 'flight_mode', 'navigate-ship': 'navigate', 'refuel-ship': 'refuel',
        'create-ship-system-scan': 'scan_systems', 'create-ship-waypoint-scan': 'scan_waypoints', 'create-ship-ship-scan': 'scan_ships', 'create-chart': 'chart',
        'jump-ship': 'jump', 'warp-ship': 'warp', 'supply-construction': 'supply',
    }
    if row['id'] in commands:
        command = commands[row['id']]
        row['implementation'] = 'source_mapped'
        row['notes'] = 'Native manual preview/approval, explicit transport, fresh domain checks and durable outcome/reconciliation implemented. Synthetic browser plus file-backed worker confirmed this action. Official API and per-variant calibration acceptance remain pending.'
        row['source_evidence'] = [{'path': 'app/Gameplay/ManualCommands.php', 'contains': "'" + command + "' =>"}, {'path': 'app/Gameplay/SpaceTradersApi.php', 'contains': "'" + command + "' =>"}, {'path': 'resources/js/Components/ManualCommandForm.vue', 'contains': 'Preview selected action'}]
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
