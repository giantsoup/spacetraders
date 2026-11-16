import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resourceSummary, equipmentSummary } from '../resources/js/shipFacts.js';

for (const current of [undefined, null, NaN, Infinity, 0, 12, 400]) {
    for (const capacity of [undefined, null, NaN, Infinity, 0, 40, 400]) {
        const value = resourceSummary(current, capacity, 'No fuel tank');
        const observed = number => Number.isFinite(number) ? number.toLocaleString() : 'Unknown';
        assert.equal(value.text, capacity === 0 ? 'No fuel tank' : observed(current) + ' / ' + observed(capacity));
        assert.equal(value.hasCapacity, Number.isFinite(capacity) && capacity > 0);
        assert.equal(value.percent, value.hasCapacity && Number.isFinite(current) ? Math.max(0, Math.min(100, current / capacity * 100)) : null);
    }
}
assert.equal(equipmentSummary(null), 'Not observed');
assert.equal(equipmentSummary([]), 'None installed');
assert.equal(equipmentSummary([{}, {}]), '2 installed');
const { scenarios } = JSON.parse(await readFile(new URL('../tests/Fixtures/ship-information.json', import.meta.url), 'utf8'));
const { components: { schemas } } = JSON.parse(await readFile(new URL('../docs/api/spacetraders-openapi-2.3.0.json', import.meta.url), 'utf8'));
assert.deepEqual(scenarios.filter(row => row.ship_type).map(row => row.ship_type).sort(), [...schemas.ShipType.enum].sort());
for (const [kind, schema] of [['mounts', 'ShipMount'], ['modules', 'ShipModule']]) {
    const installed = new Set(scenarios.flatMap(row => (row.ship[kind] || []).map(part => part.symbol)));
    for (const symbol of schemas[schema].properties.symbol.enum) assert.ok(installed.has(symbol), 'Missing fixture coverage: ' + symbol);
}
assert.ok(scenarios.every(row => row.synthetic && !Object.hasOwn(row.ship, 'type')));
for (const scenario of scenarios) {
    if (scenario.ship.cooldown?.shipSymbol) assert.equal(scenario.ship.cooldown.shipSymbol, scenario.ship.symbol);
}
console.log('PASS 49 resource states, equipment observation states, 13 ship types, 15 mount symbols and 20 module symbols.');
