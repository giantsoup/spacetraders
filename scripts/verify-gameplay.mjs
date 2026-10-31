import assert from 'node:assert/strict';
import { submissionKey } from '../resources/js/gameplay.js';

// HTTP .test origins expose getRandomValues but do not expose randomUUID.
const insecureOriginCrypto = { getRandomValues: array => crypto.getRandomValues(array) };
const keys = Array.from({ length: 1000 }, () => submissionKey(insecureOriginCrypto));
assert.equal(new Set(keys).size, 1000);
for (const key of keys) assert.match(key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
console.log('PASS: HTTP-origin submission keys are valid independent UUIDs without randomUUID.');
