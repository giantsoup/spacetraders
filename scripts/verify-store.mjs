import assert from 'node:assert/strict';
import { createApp, nextTick } from 'vue';
import { createPinia } from 'pinia';
import persistedState from 'pinia-plugin-persistedstate';
import useStore from '../resources/js/store.js';

const saved = new Map([['elementsStore', '{"activeAgentAccountID":42}']]);
globalThis.window = { localStorage: {
    getItem: key => saved.get(key) ?? null,
    setItem: (key, value) => saved.set(key, value),
} };

function restore() {
    const pinia = createPinia().use(persistedState);
    createApp({}).use(pinia);
    return useStore(pinia);
}

const store = restore();
assert.equal(store.activeAgentAccountID, 42);
store.activeAgentAccountID = 73;
await nextTick();
assert.deepEqual(JSON.parse(saved.get('elementsStore')), { activeAgentAccountID: 73 });
store.$dispose();
const restored = restore();
assert.equal(restored.activeAgentAccountID, 73);
restored.$dispose();
console.log('Pinia restores and persists the existing elementsStore key and JSON state.');
