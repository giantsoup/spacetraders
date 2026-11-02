import assert from 'node:assert/strict';
import { rateLimitSummary } from '../resources/js/rateLimit.js';

const bucket = overrides => ({
    responses: 10, available: 10, burst_capacity: 10, sustained_per_second: 1.9,
    requests_per_second_last_5: 0.2, requests_per_second_last_60: 0.1, blocked_until: 0,
    ...overrides,
});
const snapshot = (host = {}, account = {}) => ({
    as_of: 1000, mode: 'live', pacing_enabled: true, buckets: { host: bucket(host), account: bucket(account) },
});
assert.equal(rateLimitSummary(null).level, 'unknown');
assert.equal(rateLimitSummary(snapshot({ responses: 0 }, { responses: 0 })).level, 'unknown');
assert.equal(rateLimitSummary(snapshot()).level, 'ok');
assert.equal(rateLimitSummary(snapshot({ available: 4 })).label, 'Slow down');
assert.equal(rateLimitSummary(snapshot({}, { available: 4 })).label, 'Slow down');
assert.equal(rateLimitSummary(snapshot({ requests_per_second_last_5: 1.6 })).level, 'warning');
assert.equal(rateLimitSummary(snapshot({ requests_per_second_last_60: 1.6 })).level, 'warning');
assert.match(rateLimitSummary(snapshot({ available: 8, requests_per_second_last_5: 2.4 })).message, /about 8 seconds/);
assert.match(rateLimitSummary(snapshot({ available: 4, requests_per_second_last_5: 2.4 })).message, /already need to wait/);
assert.equal(rateLimitSummary(snapshot({ blocked_until: 1030 })).cooldown, 30);
assert.equal(rateLimitSummary(snapshot({}, { blocked_until: 1030 })).level, 'danger');
assert.equal(rateLimitSummary(snapshot({ blocked_until: 999 })).level, 'ok');
assert.equal(rateLimitSummary({ ...snapshot(), mode: 'offline' }).label, 'Practice');
assert.equal(rateLimitSummary({ ...snapshot(), pacing_enabled: false }).label, 'Pacing off');
console.log('Rate-limit warnings, forecasts, cooldowns, unknown state and practice mode pass.');
