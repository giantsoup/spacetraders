import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { formatLocalTime, formatLocalClock, formatLocalJson, localTimeZone } from '../resources/js/dateTime.js';
import { flightStep, newFlight } from '../resources/js/guidedFlight.js';

if (!process.argv.includes('--timezone')) {
    for (const timezone of ['America/Los_Angeles', 'America/New_York', 'Asia/Tokyo', 'UTC']) {
        execFileSync(process.execPath, [fileURLToPath(import.meta.url), '--timezone'], {
            env: { ...process.env, TZ: timezone, LANG: 'en_US.UTF-8', LC_ALL: 'en_US.UTF-8' }, stdio: 'inherit',
        });
    }
} else {
    const utc = '2026-11-01T06:57:16.395Z';
    const expected = {
        'America/Los_Angeles': 'Oct 31, 2026, 11:57:16 PM PDT',
        'America/New_York': 'Nov 1, 2026, 1:57:16 AM EST',
        'Asia/Tokyo': 'Nov 1, 2026, 3:57:16 PM GMT+9',
        UTC: 'Nov 1, 2026, 6:57:16 AM UTC',
    }[process.env.TZ];
    assert.equal(formatLocalClock(utc), expected.split(', ').slice(2).join(', '));
    assert.equal(formatLocalClock(null), 'Unknown');
    assert.equal(localTimeZone, process.env.TZ);
    assert.equal(formatLocalTime(utc), expected);
    assert.equal(formatLocalTime('2026-10-31T23:57:16.395-07:00'), expected);
    assert.equal(formatLocalTime('2026-11-01 06:57:16.395'), expected, 'Zone-less database timestamps represent UTC');
    assert.equal(formatLocalTime('2026-11-01 06:57:16.395+00:00'), expected);
    for (const missing of [null, undefined, '', 'bad-date', '2026-99-99T06:00:00Z']) assert.equal(formatLocalTime(missing), 'Unknown');
    assert.equal(formatLocalTime(null, 'none'), 'none');
    assert.equal(formatLocalTime('2026-10-25', '2026-10-25'), '2026-10-25', 'Reset dates are calendar identifiers');

    const evidence = { reconciled_at: utc, ship: { nav: { route: { arrival: utc } } }, transactions: [{ timestamp: utc }], note: 'Keep this note.', reset: '2026-10-25', amount: 100, absent: null };
    const original = structuredClone(evidence);
    const displayed = JSON.parse(formatLocalJson(evidence));
    assert.equal(displayed.reconciled_at, expected);
    assert.equal(displayed.ship.nav.route.arrival, expected);
    assert.equal(displayed.transactions[0].timestamp, expected);
    assert.equal(displayed.reset, evidence.reset);
    assert.equal(displayed.note, evidence.note);
    assert.equal(displayed.amount, evidence.amount);
    assert.equal(displayed.absent, null);
    assert.deepEqual(evidence, original, 'Rendering must never replace UTC in source state');

    const ship = { symbol: 'LOCAL-1', nav: { status: 'IN_TRANSIT', waypointSymbol: 'X1-TEST-PORT', route: { arrival: utc } }, cooldown: { expiration: utc }, cargo: { units: 0 } };
    const context = { agent: {}, ships: [{ symbol: ship.symbol, snapshot: ship }], actions: [], runs: [], form: {}, selected: ship.symbol, commandPreview: null };
    const session = { ...newFlight(), phase: 'outbound', plan: { kind: 'mining', ship: ship.symbol, site: ship.nav.waypointSymbol } };
    const now = Date.parse('2026-11-01T06:54:00Z');
    const arrival = flightStep(session, context, now);
    assert.equal(arrival.kind, 'wait');
    assert.ok(arrival.body.includes(expected));
    assert.ok(!arrival.body.includes(utc));
    assert.equal(flightStep(session, context, Date.parse(utc)).kind, 'sync', 'Arrival comparisons still use the UTC instant');
    ship.nav.status = 'IN_ORBIT';
    session.phase = 'extract';
    const cooldown = flightStep(session, context, now);
    assert.equal(cooldown.kind, 'wait');
    assert.ok(cooldown.body.includes(expected));
    assert.equal(ship.cooldown.expiration, utc);

    if (process.env.TZ === 'America/Los_Angeles') {
        assert.equal(formatLocalTime('2026-11-01T08:30:00Z'), 'Nov 1, 2026, 1:30:00 AM PDT');
        assert.equal(formatLocalTime('2026-11-01T09:30:00Z'), 'Nov 1, 2026, 1:30:00 AM PST');
        assert.equal(formatLocalTime('2026-03-08T09:59:00Z'), 'Mar 8, 2026, 1:59:00 AM PST');
        assert.equal(formatLocalTime('2026-03-08T10:00:00Z'), 'Mar 8, 2026, 3:00:00 AM PDT');
    }
    console.log(`PASS: ${localTimeZone} timestamps, guided waits, nested evidence, and unchanged UTC source values.`);
}
