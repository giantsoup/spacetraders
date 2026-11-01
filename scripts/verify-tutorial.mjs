import assert from 'node:assert/strict';
import { lessons, normalizeProgress, tutorialKey } from '../resources/js/tutorial.js';

const ids = lessons.map(lesson => lesson.id);
assert.equal(new Set(ids).size, lessons.length);
assert.equal(lessons.length, 10);
for (const lesson of lessons) {
    for (const field of ['id', 'title', 'label', 'goal', 'explanation', 'checkpoint', 'recovery']) assert.ok(lesson[field], `${lesson.id}: ${field}`);
    assert.ok(lesson.steps.length >= 3, `${lesson.id} needs actionable instructions`);
    assert.ok([null, 'connect', 'fleet', 'setup', 'waypoints', 'manual', 'market', 'activity'].includes(lesson.target));
}
assert.deepEqual(normalizeProgress(null), { active: 'orientation', completed: [] });
assert.deepEqual(normalizeProgress({ active: 'removed-lesson', completed: ['agent', 'agent', 'bogus', null, 1, 'sell'] }), { active: 'orientation', completed: ['agent', 'sell'] });
for (const value of [false, 7, 'invalid', [], { completed: 'agent' }]) assert.deepEqual(normalizeProgress(value), normalizeProgress(null));
assert.deepEqual(normalizeProgress({ active: 'sell', completed: ids }), { active: 'sell', completed: ids });
const agent = { id: 1, reset_id: '2026-10-25' };
const keys = [tutorialKey(1, agent), tutorialKey(2, agent), tutorialKey(1, { ...agent, id: 2 }), tutorialKey(1, { ...agent, reset_id: '2026-11-01' }), tutorialKey(1, null)];
assert.equal(new Set(keys).size, keys.length, 'Users, agents, resets, and preparation need separate checklists');
assert.notEqual(tutorialKey(1, { id: 1 }), tutorialKey(1, agent));
console.log('PASS: course structure, malformed progress recovery, deduplication, and user/agent/reset isolation.');
